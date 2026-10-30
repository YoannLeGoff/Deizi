<?php
declare(strict_types=1);

// Notifications of a member: turning push messages on or off for this device (sent by menu.js),
// and "Tout marquer comme lu" in the bell of the menu.
require __DIR__ . '/lib/bootstrap.php';
$user = app_require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit(__('methode_non_autorisee_phrase'));
}
app_verify_csrf();
$action = $_POST['action'] ?? '';

if ($action === 'mark_all_seen') {
    // Not while an administrator is logged in as the member, like the opening of a date.
    if (app_impersonator() === null) {
        app_mark_events_seen($user, array_column(array_column(app_unread_events($user), 'event'), 'id'));
    }
    $return = app_safe_return_path($_POST['return'] ?? null);
    app_redirect($return ?? 'index.php');
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$endpoint = $_POST['endpoint'] ?? '';
if (!is_string($endpoint) || $endpoint === '' || !in_array($action, ['subscribe', 'unsubscribe'], true)) {
    http_response_code(400);
    exit('{"ok":false}');
}
if ($action === 'unsubscribe') {
    app_push_unsubscribe($endpoint);
    exit('{"ok":true}');
}
$p256dh = $_POST['p256dh'] ?? '';
$auth = $_POST['auth'] ?? '';
$saved = app_impersonator() === null && is_string($p256dh) && is_string($auth)
    && app_push_subscribe((string) $user['id'], $endpoint, $p256dh, $auth);
http_response_code($saved ? 200 : 400);
echo $saved ? '{"ok":true}' : '{"ok":false}';
