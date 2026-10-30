<?php
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';
$admin = app_require_roles(['admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit(__('methode_non_autorisee_phrase'));
}

app_verify_csrf();
$userId = $_POST['user_id'] ?? $admin['id'];
if (!is_string($userId) || $userId === '') {
    http_response_code(400);
    exit(__('compte_invalide_phrase'));
}

$target = null;
foreach (read_json_file(app_data_path('users.json')) as $account) {
    if (is_array($account) && ($account['id'] ?? null) === $userId) {
        $target = $account;
        break;
    }
}
if ($target === null) {
    app_flash(__('ce_compte_nexiste_plus_phrase'), 'error');
    app_redirect('users.php');
}

app_calendar_token($target, true);
if ($userId === $admin['id']) {
    app_flash(__('votre_lien_dabonnement_ical_ete_renouvele'));
    app_redirect('settings.php');
}
app_flash(__('lien_ical_name_ete_renouvele_lancien', ['name' => (string) ($target['username'] ?? '')]));
app_redirect('user.php?id=' . rawurlencode($userId));
