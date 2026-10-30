<?php
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';
$user = app_require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit(__('methode_non_autorisee_phrase'));
}

app_verify_csrf();
$eventId = $_POST['event_id'] ?? '';
$response = $_POST['response'] ?? '';
$returnPage = $_POST['return_page'] ?? 'index';
$requestedSection = $_POST['section'] ?? '';
$validResponses = ATTENDANCE_RESPONSES;
if (
    !is_string($eventId)
    || $eventId === ''
    || !is_string($response)
    || !isset($validResponses[$response])
    || !is_string($returnPage)
    || !in_array($returnPage, ['index', 'agenda', 'event'], true)
    || !is_string($requestedSection)
) {
    http_response_code(400);
    exit(__('reponse_invalide_phrase'));
}

$userSections = app_expand_sections(app_user_sections($user));
$returnSection = $requestedSection === 'Toutes' || isset(app_section_labels()[$requestedSection])
    ? $requestedSection
    : app_user_default_view($user);
$path = app_data_path('events.json');
$found = false;
$authorized = false;
$locked = false;
$impersonator = app_impersonator();
update_json_file($path, static function (array $events) use (
    $eventId,
    $response,
    $user,
    $userSections,
    $impersonator,
    &$found,
    &$authorized,
    &$locked
): array {
    foreach ($events as &$event) {
        if (!is_array($event) || ($event['id'] ?? null) !== $eventId) {
            continue;
        }

        $found = true;
        $eventSections = isset($event['sections']) && is_array($event['sections'])
            ? app_expand_sections(array_filter($event['sections'], 'is_string'))
            : [];
        if (array_intersect($eventSections, $userSections) === []) {
            break;
        }
        // No more answers on a finished, cancelled or refused date.
        if (app_event_attendance_locked($event)) {
            $locked = true;
            break;
        }

        $authorized = true;
        $event['attendance'] = isset($event['attendance']) && is_array($event['attendance'])
            ? $event['attendance']
            : [];
        $previous = $event['attendance'][$user['id']] ?? null;
        if ($previous !== $response) {
            app_attendance_log_append([app_attendance_log_entry($event, $user, $previous, $response, $user, 'self', $impersonator)]);
            $event['attendance'][$user['id']] = $response;
        }
        break;
    }
    unset($event);

    return $events;
});

if ($locked) {
    app_flash(__('cette_date_terminee_annulee_refusee_presence'), 'error');
    app_redirect($returnPage === 'event' ? 'event.php?id=' . rawurlencode($eventId) : ($returnPage === 'agenda' ? 'agenda.php' : 'index.php') . '#event-' . rawurlencode($eventId));
}
if (!$found || !$authorized) {
    http_response_code($found ? 403 : 404);
    exit($found ? __('cette_date_concerne_vos_sections_phrase') : __('cette_date_nexiste_plus_phrase'));
}

app_flash(__('votre_reponse_ete_enregistree_answer_phrase', ['answer' => app_label($validResponses[$response])]));
if ($returnPage === 'event') {
    app_redirect('event.php?id=' . rawurlencode($eventId));
}
$page = $returnPage === 'agenda' ? 'agenda.php' : 'index.php';
$presenceInput = $_POST['presence'] ?? '';
$presenceQuery = is_string($presenceInput) && isset(ATTENDANCE_FILTERS[$presenceInput]) ? '&presence=' . $presenceInput : '';
$yearsInput = $_POST['annees'] ?? null;
$yearsQuery = $page === 'agenda.php' && is_string($yearsInput) && preg_match('/^[0-9,]{0,100}$/', $yearsInput) === 1 ? '&annees=' . $yearsInput : '';
app_redirect($page . '?section=' . rawurlencode($returnSection) . $presenceQuery . $yearsQuery . '#event-' . rawurlencode($eventId));
