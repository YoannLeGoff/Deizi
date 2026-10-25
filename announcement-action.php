<?php
declare(strict_types=1);

// Announcements: saving and deleting (announcements.php), hiding one from the home page (any member).
require __DIR__ . '/lib/bootstrap.php';
$user = app_require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Méthode non autorisée.');
}
app_verify_csrf();
$action = $_POST['action'] ?? '';
$id = is_string($_POST['id'] ?? null) ? $_POST['id'] : '';
$existing = $id !== '' ? app_announcement($id) : null;

// "Masquer": the member no longer sees the announcement on their home page (not while an
// administrator is logged in as the member).
if ($action === 'dismiss') {
    if ($existing !== null && (app_announcement_targets($existing, $user) || app_is_announcement_author($existing, $user)) && app_impersonator() === null) {
        $userId = (string) $user['id'];
        app_update_announcements(static function (array $announcements) use ($id, $userId): array {
            foreach ($announcements as &$raw) {
                if (is_array($raw) && ($raw['id'] ?? null) === $id) {
                    $raw['dismissed'] = array_values(array_unique([...(is_array($raw['dismissed'] ?? null) ? $raw['dismissed'] : []), $userId]));
                }
            }
            unset($raw);
            return $announcements;
        });
    }
    app_redirect('index.php');
}

if (!app_can_publish_announcements($user) || !in_array($action, ['save', 'delete'], true)
    || ($id !== '' && ($existing === null || !app_can_edit_announcement($user, $existing)))) {
    http_response_code(403);
    exit('Vous ne pouvez pas modifier cette annonce.');
}

if ($action === 'delete') {
    app_update_announcements(static fn (array $announcements): array => array_filter(
        $announcements,
        static fn (mixed $raw): bool => !is_array($raw) || ($raw['id'] ?? null) !== $id
    ));
    app_delete_upload($existing['image']);
    app_flash('L’annonce « ' . $existing['title'] . ' » a été supprimée.');
    app_redirect(($_POST['return'] ?? '') === 'administrateur' && $user['role'] === 'admin' ? 'announcements.php?onglet=administrateur' : 'announcements.php');
}

$formUrl = 'announcements.php?' . ($id !== '' ? 'modifier=' . rawurlencode($id) : 'nouvelle=1');
$fail = static function (string $message) use ($formUrl): never {
    app_flash($message, 'error');
    app_redirect($formUrl);
};

$title = app_clean_line($_POST['title'] ?? '', ANNOUNCEMENT_TITLE_MAX);
$body = app_clean_text($_POST['body'] ?? '', ANNOUNCEMENT_BODY_MAX);
$linkInput = app_clean_line($_POST['link_url'] ?? '', 2000);
$linkLabel = app_clean_line($_POST['link_label'] ?? '', ANNOUNCEMENT_LINK_LABEL_MAX);
$starts = is_string($_POST['starts'] ?? null) && $_POST['starts'] !== '' ? $_POST['starts'] : app_today();
$ends = is_string($_POST['ends'] ?? null) ? $_POST['ends'] : '';
$push = ($_POST['push'] ?? '') === '1';
// Everybody: organizers, logisticians and administrators only; managers choose within their scope.
$isScopedManager = $user['role'] === 'manager';
$everyone = !$isScopedManager && ($_POST['everyone'] ?? '') === '1';
$rawSections = is_array($_POST['sections'] ?? null) ? array_filter($_POST['sections'], 'is_string') : [];
$sections = $everyone ? [] : array_values(array_intersect(app_expand_sections($rawSections), app_section_leaves()));

if ($title === '') {
    $fail('Indiquez le titre de l’annonce.');
}
if ($linkInput !== '' && !app_is_http_url($linkInput)) {
    $fail('Le lien doit être une adresse http(s) valide.');
}
if (!$everyone && $sections === []) {
    $fail('Choisissez les sections qui verront l’annonce.');
}
if ($isScopedManager && array_diff($sections, app_user_managed_leaves($user)) !== []) {
    $fail('Choisissez uniquement des sous-sections de votre périmètre.');
}
$startDate = app_parse_date($starts);
$endDate = $ends !== '' ? app_parse_date($ends) : null;
if ($startDate === null || $startDate->format('Y-m-d') !== $starts || ($ends !== '' && ($endDate === null || $endDate->format('Y-m-d') !== $ends || $ends < $starts))) {
    $fail('Vérifiez les dates : la fin d’affichage doit être le jour du début ou après.');
}

try {
    $uploaded = app_store_uploaded_image($_FILES['image'] ?? null, ANNOUNCEMENT_UPLOAD_PREFIX);
} catch (InvalidArgumentException $exception) {
    $fail($exception->getMessage());
}
$previousImage = $existing['image'] ?? null;
$image = $uploaded ?? (($_POST['remove_image'] ?? '') === '1' ? null : $previousImage);

$now = (new DateTimeImmutable('now', new DateTimeZone('Europe/Paris')))->format(DATE_ATOM);
$savedId = $id !== '' ? $id : bin2hex(random_bytes(16));
$fields = [
    'title' => $title,
    'body' => $body,
    'link_url' => $linkInput,
    'link_label' => $linkLabel,
    'image' => $image,
    'everyone' => $everyone,
    'sections' => $sections,
    'starts' => $starts,
    'ends' => $ends !== '' ? $ends : null,
    'push' => $push,
];
app_update_announcements(static function (array $announcements) use ($id, $savedId, $fields, $user, $now): array {
    if ($id === '') {
        // Newest first.
        return [['id' => $savedId, ...$fields, 'push_sent_at' => null, 'author' => (string) $user['id'], 'created_at' => $now, 'dismissed' => []], ...$announcements];
    }
    foreach ($announcements as &$raw) {
        if (is_array($raw) && ($raw['id'] ?? null) === $id) {
            $raw = array_merge($raw, $fields, ['updated_at' => $now]);
        }
    }
    unset($raw);
    return $announcements;
});
if ($previousImage !== null && $previousImage !== $image) {
    app_delete_upload($previousImage);
}
// The notification leaves now when the announcement is already shown, otherwise on its first day.
app_announcements_dispatch_push();

app_flash($id === '' ? 'L’annonce a été publiée.' : 'L’annonce a été mise à jour.');
app_redirect('announcements.php');
