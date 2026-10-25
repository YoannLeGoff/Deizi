<?php
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Méthode non autorisée.');
}

app_verify_csrf();
$action = $_POST['action'] ?? '';

if ($action === 'stop') {
    $impersonator = app_impersonator();
    if ($impersonator !== null) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $impersonator['id'];
        unset($_SESSION['impersonator_id']);
    }
    app_redirect('index.php');
}

if ($action !== 'start') {
    http_response_code(400);
    exit('Action inconnue.');
}

$admin = app_require_roles(['admin']);
if (app_impersonator() !== null) {
    app_flash('Revenez d’abord à votre compte avant d’en impersonifier un autre.', 'error');
    app_redirect('index.php');
}
$targetId = $_POST['user_id'] ?? '';
$target = null;
foreach (read_json_file(app_data_path('users.json')) as $account) {
    if (is_array($account) && is_string($targetId) && ($account['id'] ?? null) === $targetId) {
        $target = $account;
        break;
    }
}
if ($target === null || $target['id'] === $admin['id'] || !app_can_be_impersonated($target)) {
    app_flash('Seuls les comptes actifs d’artistes et de managers peuvent être impersonifiés.', 'error');
    app_redirect('users.php');
}

session_regenerate_id(true);
$_SESSION['impersonator_id'] = $admin['id'];
$_SESSION['user_id'] = $target['id'];
// The red banner shown on every page is the reminder; no flash message.
app_redirect('index.php');
