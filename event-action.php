<?php
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';
$user = app_require_roles(EVENT_MANAGER_ROLES);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit(__('methode_non_autorisee_phrase'));
}

app_verify_csrf();
$action = $_POST['action'] ?? '';
$eventId = $_POST['id'] ?? '';
// Deleting a date is done from the administrators' console (delete-events.php).
if (!is_string($action) || $action !== 'save' || !is_string($eventId)) {
    http_response_code(400);
    exit(__('demande_invalide_phrase'));
}

$path = app_data_path('events.json');
// Creations and modifications of dates go to the history, with the attendance answers.
$impersonator = app_impersonator();

$titleInput = $_POST['title'] ?? '';
$date = $_POST['date'] ?? '';
$endDate = $_POST['end_date'] ?? '';
$timeInput = $_POST['time'] ?? '';
$locationInput = $_POST['location'] ?? '';
$urlInput = $_POST['url'] ?? '';
$endTimeInput = $_POST['end_time'] ?? '';
$status = $_POST['status'] ?? 'pending';
$allDayInput = $_POST['all_day'] ?? '';
$scalarInputs = [$titleInput, $date, $endDate, $timeInput, $locationInput, $urlInput, $endTimeInput, $status, $allDayInput];
if (array_filter($scalarInputs, static fn (mixed $value): bool => !is_string($value)) !== []) {
    http_response_code(400);
    exit(__('champs_levenement_sont_invalides_phrase'));
}
// Form to come back to after an error: the date being edited, or the "add a date" tab.
$formUrl = 'manage-events.php' . ($eventId !== '' ? '?edit=' . rawurlencode($eventId) : '?onglet=ajouter');
$title = trim($titleInput);
$time = trim($timeInput);
$location = trim($locationInput);
$url = trim($urlInput);
$endTime = trim($endTimeInput);
$allDay = $allDayInput === '1';
if (!in_array($allDayInput, ['', '1'], true)) {
    app_flash(__('choix_journee_entiere_invalide_phrase'), 'error');
    app_redirect($formUrl);
}
if (!array_key_exists($status, EVENT_STATUSES)) {
    app_flash(__('selectionnez_statut_valide_cette_date_phrase'), 'error');
    app_redirect($formUrl);
}
$rawSections = $_POST['sections'] ?? [];
$selectedSections = is_array($rawSections)
    ? array_values(array_intersect(app_expand_sections($rawSections), app_section_leaves()))
    : [];
// Managers only create and edit dates of the sub-sections they manage, and their new dates are
// reserved to these sub-sections; organizers and administrators choose freely.
$isScopedManager = $user['role'] === 'manager';
if ($isScopedManager && ($selectedSections === [] || array_diff($selectedSections, app_user_managed_leaves($user)) !== [])) {
    app_flash(__('choisissez_moins_sous_section_uniquement_parmi'), 'error');
    app_redirect($formUrl);
}
// A manager may open a reserved date to sub-sections outside their scope (checked against the
// stored date below for an existing one, a manager's new date being always reserved).
$guestSections = [];
if ($isScopedManager) {
    $guestInput = $_POST['guest_sections'] ?? [];
    $guestSections = is_array($guestInput) ? array_values(array_unique(array_filter($guestInput, 'is_string'))) : [];
    if (array_diff($guestSections, app_section_leaves()) !== [] || array_intersect($guestSections, app_user_managed_leaves($user)) !== []) {
        app_flash(__('choisissez_uniquement_sous_sections_hors_votre_phrase'), 'error');
        app_redirect($formUrl);
    }
    $selectedSections = [...$selectedSections, ...$guestSections];
}

$eventDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
$dateErrors = DateTimeImmutable::getLastErrors();
$validDate = $eventDate !== false
    && ($dateErrors === false || ($dateErrors['warning_count'] === 0 && $dateErrors['error_count'] === 0))
    && $eventDate->format('Y-m-d') === $date;
$eventEndDate = $endDate === '' ? null : DateTimeImmutable::createFromFormat('!Y-m-d', $endDate);
$endDateErrors = $endDate === '' ? false : DateTimeImmutable::getLastErrors();
$validEndDate = $endDate === '' || (
    $eventEndDate !== false
    && ($endDateErrors === false || ($endDateErrors['warning_count'] === 0 && $endDateErrors['error_count'] === 0))
    && $eventEndDate->format('Y-m-d') === $endDate
    && $validDate
    && $eventEndDate >= $eventDate
);
$validUrl = $url === '' || (
    filter_var($url, FILTER_VALIDATE_URL) !== false
    && in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)
);

if ($title === '' || strlen($title) > 200 || !$validDate || !$validEndDate || strlen($time) > 50 || strlen($endTime) > 50 || strlen($location) > 300 || !$validUrl || strlen($url) > 2000) {
    app_flash(__('verifiez_champs_titre_date_requis_date'), 'error');
    app_redirect($formUrl);
}

$editUrl = $formUrl . '#roadmap';
$roadmapInput = is_array($_POST['roadmap'] ?? null) ? $_POST['roadmap'] : [];
$roadmap = app_normalize_roadmap($roadmapInput);
// Only the repertoires of the sections of the date (Configuration > Sections) are kept.
$roadmap['repertoire'] = array_intersect_key($roadmap['repertoire'], app_event_repertoire_sections($selectedSections));
foreach (['location_url' => $roadmap['location_url'], 'qr' => $roadmap['qr']['link']] as $field => $cleaned) {
    $raw = $field === 'qr' ? ($roadmapInput['qr']['link'] ?? '') : ($roadmapInput[$field] ?? '');
    if (is_string($raw) && trim($raw) !== '' && $cleaned === '') {
        app_flash(__('liens_feuille_route_doivent_etre_adresses'), 'error');
        app_redirect($editUrl);
    }
}
try {
    $uploadedQr = app_store_uploaded_image($_FILES['qr_image'] ?? null);
} catch (InvalidArgumentException $exception) {
    app_flash($exception->getMessage(), 'error');
    app_redirect($editUrl);
}
$resetQr = ($_POST['qr_reset'] ?? '') === '1';
$obsoleteQr = null;

// Documents of the roadmap: each row has a label, and a link or a file (a new upload, or the file it
// already had, kept only if it belongs to this date: checked when saving). New uploads are removed
// again if the date is not saved.
$documentRows = is_array($roadmapInput['documents'] ?? null) ? $roadmapInput['documents'] : [];
$documentFiles = is_array($_FILES['roadmap_document_files']['name'] ?? null) ? $_FILES['roadmap_document_files'] : null;
$newDocumentFiles = [];
$postedDocuments = [];
foreach ($documentRows as $index => $row) {
    if (!is_array($row)) {
        continue;
    }
    $label = app_clean_line($row['label'] ?? '', ROADMAP_DOCUMENT_LABEL_MAX);
    $urlInput = is_string($row['url'] ?? null) ? trim($row['url']) : '';
    $keptFile = is_string($row['file'] ?? null) && app_roadmap_document_path($row['file']) !== null ? $row['file'] : null;
    try {
        $uploadedDocument = $documentFiles !== null ? app_store_roadmap_document([
            'name' => $documentFiles['name'][$index] ?? null,
            'tmp_name' => $documentFiles['tmp_name'][$index] ?? null,
            'error' => $documentFiles['error'][$index] ?? UPLOAD_ERR_NO_FILE,
            'size' => $documentFiles['size'][$index] ?? 0,
        ]) : null;
    } catch (InvalidArgumentException $exception) {
        array_map('app_delete_roadmap_document', $newDocumentFiles);
        app_delete_upload($uploadedQr);
        app_flash($exception->getMessage(), 'error');
        app_redirect($editUrl);
    }
    if ($uploadedDocument !== null) {
        $newDocumentFiles[] = $uploadedDocument;
    }
    $file = $uploadedDocument ?? $keptFile;
    $documentError = match (true) {
        $file === null && $urlInput !== '' && app_clean_url($urlInput) === '' => __('lien_document_label_doit_etre_adresse', ['label' => $label !== '' ? $label : $urlInput]),
        $file === null && $urlInput === '' && $label !== '' => __('ajoutez_lien_fichier_document_label', ['label' => $label]),
        default => null,
    };
    if ($documentError !== null) {
        array_map('app_delete_roadmap_document', $newDocumentFiles);
        app_delete_upload($uploadedQr);
        app_flash($documentError, 'error');
        app_redirect($editUrl);
    }
    if ($file !== null || $urlInput !== '') {
        $postedDocuments[] = ['label' => $label, 'url' => $file === null ? $urlInput : '', 'file' => $file, 'new' => $uploadedDocument !== null];
    }
}
$obsoleteDocuments = [];

$updated = [
    'date' => $date,
    'title' => $title,
    'status' => $status,
    'all_day' => $allDay,
    // Display only: the reporting calendars show "Tutti" instead of the sections.
    'tutti' => ($_POST['tutti'] ?? '') === '1',
    // Hides the roadmap tab, the agenda link and the public link of this date.
    'roadmap_disabled' => ($_POST['roadmap_disabled'] ?? '') === '1',
    // "Date réservée" (see app_user_can_see_event()); a manager's existing date keeps its value below.
    'reserved' => $isScopedManager || ($_POST['reserved'] ?? '') === '1',
    // Logistics follow-up: chosen by organizers, logisticians and administrators; a manager's new
    // date has none and a manager's save keeps the value of an existing date (below).
    'logistics' => !$isScopedManager && ($_POST['logistics'] ?? '') === '1',
    'time' => $time,
    'location' => $location,
    'sections' => $selectedSections,
];
if ($endDate !== '') {
    $updated['end_date'] = $endDate;
}
if ($url !== '') {
    $updated['url'] = $url;
}
if ($endTime !== '') {
    $updated['end_time'] = $endTime;
}
// Only organizers, logisticians and administrators see and edit the admin note; other saves keep it unchanged.
$comment = app_clean_text($_POST['comment'] ?? '', 3000);
if ($comment !== '') {
    $updated['comment'] = $comment;
}
$adminNote = app_can_see_admin_note($user) ? app_clean_text($_POST['admin_note'] ?? '', 3000) : null;
if ($adminNote !== null && $adminNote !== '') {
    $updated['admin_note'] = $adminNote;
}

$found = false;
$forbidden = false;
$notReserved = false;
$savedEventId = $eventId !== '' ? $eventId : bin2hex(random_bytes(16));
$now = (new DateTimeImmutable('now', new DateTimeZone('Europe/Paris')))->format(DATE_ATOM);
$events = update_json_file($path, static function (array $events) use ($eventId, $savedEventId, $now, $user, $impersonator, $updated, $isScopedManager, $guestSections, $endDate, $url, $endTime, $comment, $adminNote, $roadmap, $uploadedQr, $resetQr, $postedDocuments, &$obsoleteQr, &$obsoleteDocuments, &$found, &$forbidden, &$notReserved): array {
    // Files of the documents: new uploads, and the files this date already had.
    $keepDocuments = static function (array $previousFiles) use ($postedDocuments): array {
        return app_roadmap_documents(array_map(
            static fn (array $document): array => $document['new'] || in_array($document['file'], $previousFiles, true) ? $document : ['file' => null] + $document,
            $postedDocuments
        ));
    };
    foreach ($events as &$event) {
        if (is_array($event) && ($event['id'] ?? null) === $eventId && $eventId !== '') {
            if (!app_user_can_edit_event($user, $event)) {
                $forbidden = true;
                return $events;
            }
            if ($isScopedManager) {
                $updated['reserved'] = app_event_is_reserved($event);
                $updated['logistics'] = app_event_needs_logistics($event);
                if ($guestSections !== [] && !$updated['reserved']) {
                    $notReserved = true;
                    return $events;
                }
            }
            $attendance = isset($event['attendance']) && is_array($event['attendance']) ? $event['attendance'] : null;
            $previousQr = app_event_roadmap($event)['qr']['image'];
            $roadmap['qr']['image'] = $uploadedQr ?? ($resetQr ? null : $previousQr);
            if ($previousQr !== $roadmap['qr']['image']) {
                $obsoleteQr = $previousQr;
            }
            $previousFiles = array_values(array_filter(array_column(app_event_roadmap($event)['documents'], 'file')));
            $roadmap['documents'] = $keepDocuments($previousFiles);
            $obsoleteDocuments = array_values(array_diff($previousFiles, array_filter(array_column($roadmap['documents'], 'file'))));
            $previousFingerprint = app_roadmap_fingerprint($event);
            $before = $event;
            $event = array_merge($event, $updated, ['id' => $eventId, 'roadmap' => $roadmap]);
            if ($endDate === '') {
                unset($event['end_date']);
            }
            if ($url === '') {
                unset($event['url']);
            }
            if ($endTime === '') {
                unset($event['end_time']);
            }
            if ($comment === '') {
                unset($event['comment']);
            }
            if ($adminNote === '') {
                unset($event['admin_note']);
            }
            // The duration of earlier versions is replaced by the end time.
            unset($event['duration_minutes']);
            if ($attendance !== null) {
                $event['attendance'] = $attendance;
            } else {
                unset($event['attendance']);
            }
            if (app_roadmap_fingerprint($event) !== $previousFingerprint) {
                $event['roadmap_updated_at'] = $now;
            }
            // "Modifié" label for the members who have not opened the date since.
            $event = app_event_record_change($before, $event, $user, $now);
            $logEntry = app_event_log_entry($before, $event, $user, $impersonator);
            if ($logEntry !== null) {
                app_attendance_log_append([$logEntry]);
            }
            $found = true;
            break;
        }
    }
    unset($event);

    if ($eventId === '') {
        $updated['id'] = $savedEventId;
        $roadmap['qr']['image'] = $uploadedQr;
        $roadmap['documents'] = $keepDocuments([]);
        $updated['roadmap'] = $roadmap;
        $updated['roadmap_updated_at'] = $now;
        $updated = app_event_record_change(null, $updated, $user, $now);
        $events[] = $updated;
        app_attendance_log_append([app_event_log_entry(null, $updated, $user, $impersonator)]);
        $found = true;
    }
    return $events;
});

if ($forbidden) {
    app_delete_upload($uploadedQr);
    array_map('app_delete_roadmap_document', $newDocumentFiles);
    app_flash(__('cette_date_concerne_sous_sections_hors'), 'error');
    app_redirect('manage-events.php');
}
if ($notReserved) {
    app_delete_upload($uploadedQr);
    array_map('app_delete_roadmap_document', $newDocumentFiles);
    app_flash(__('seule_date_reservee_peut_etre_ouverte'), 'error');
    app_redirect($formUrl);
}
if (!$found) {
    app_delete_upload($uploadedQr);
    array_map('app_delete_roadmap_document', $newDocumentFiles);
    app_flash(__('cette_date_nexiste_plus_aucune_modification'), 'error');
    app_redirect('manage-events.php');
}
app_delete_upload($obsoleteQr);
array_map('app_delete_roadmap_document', $obsoleteDocuments);
app_flash(
    $eventId === '' ? __('date_ete_ajoutee_phrase') : __('date_ete_mise_jour_phrase'),
    'success',
    ['url' => 'event.php?id=' . rawurlencode($savedEventId), 'label' => __('consultez_date')]
);
app_redirect('manage-events.php');
