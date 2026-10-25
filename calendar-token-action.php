<?php
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';
$admin = app_require_roles(['admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Méthode non autorisée.');
}

app_verify_csrf();
$userId = $_POST['user_id'] ?? $admin['id'];
if (!is_string($userId) || $userId === '') {
    http_response_code(400);
    exit('Compte invalide.');
}

$target = null;
foreach (read_json_file(app_data_path('users.json')) as $account) {
    if (is_array($account) && ($account['id'] ?? null) === $userId) {
        $target = $account;
        break;
    }
}
if ($target === null) {
    app_flash('Ce compte n’existe plus.', 'error');
    app_redirect('users.php');
}

app_calendar_token($target, true);
if ($userId === $admin['id']) {
    app_flash('Votre lien d’abonnement iCal a été renouvelé. Mettez à jour l’abonnement dans votre application de calendrier.');
    app_redirect('settings.php');
}
app_flash('Le lien iCal de ' . (string) ($target['username'] ?? '') . ' a été renouvelé. L’ancien lien ne fonctionne plus ; le membre doit recopier le nouveau depuis l’agenda.');
app_redirect('user.php?id=' . rawurlencode($userId));
