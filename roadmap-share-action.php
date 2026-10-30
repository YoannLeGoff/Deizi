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
$eventId = $_POST['event_id'] ?? '';
$action = $_POST['action'] ?? '';
if (!is_string($eventId) || $eventId === '' || !in_array($action, ['create', 'renew', 'revoke'], true)) {
    http_response_code(400);
    exit(__('demande_invalide_phrase'));
}

$found = false;
update_json_file(app_data_path('events.json'), static function (array $events) use ($eventId, $action, $user, &$found): array {
    foreach ($events as &$event) {
        if (!is_array($event) || ($event['id'] ?? null) !== $eventId || !app_user_can_see_event($user, $event)) {
            continue;
        }
        $found = true;
        if ($action === 'revoke') {
            unset($event['roadmap_share_token']);
        } elseif ($action === 'renew' || app_roadmap_share_token($event) === null) {
            $event['roadmap_share_token'] = bin2hex(random_bytes(24));
        }
        break;
    }
    unset($event);
    return $events;
});

if (!$found) {
    app_flash(__('cette_date_nexiste_plus_phrase'), 'error');
    app_redirect('index.php');
}
app_flash([
    'create' => __('lien_public_feuille_route_ete_cree'),
    'renew' => __('nouveau_lien_public_ete_cree_lancien'),
    'revoke' => __('lien_public_ete_desactive_phrase'),
][$action]);
app_redirect('event.php?id=' . rawurlencode($eventId) . '&tab=feuille-de-route');
