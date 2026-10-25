<?php
declare(strict_types=1);

// Changes or removes a profile photo: an artist their own (Mon compte), a manager theirs and those
// of their scope, an administrator any photo (Mon compte, or the account's page). Traced in the
// audit journal.
require __DIR__ . '/lib/bootstrap.php';
$user = app_require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Méthode non autorisée.');
}
app_verify_csrf();
$action = $_POST['action'] ?? '';
$targetId = is_string($_POST['user_id'] ?? null) && $_POST['user_id'] !== '' ? $_POST['user_id'] : (string) $user['id'];
$return = app_safe_return_path($_POST['return'] ?? null) ?? 'account.php';

$target = null;
foreach (read_json_file(app_data_path('users.json')) as $account) {
    if (is_array($account) && ($account['id'] ?? null) === $targetId) {
        $target = $account;
        break;
    }
}
// Not while an administrator is logged in as a member.
if ($target === null || !in_array($action, ['upload', 'remove'], true) || !app_can_edit_photo($user, $target) || app_impersonator() !== null) {
    http_response_code(403);
    exit('Vous ne pouvez pas modifier cette photo.');
}

$newPhoto = null;
if ($action === 'upload') {
    try {
        $newPhoto = app_store_photo($_FILES['photo'] ?? null);
    } catch (InvalidArgumentException $exception) {
        app_flash($exception->getMessage(), 'error');
        app_redirect($return);
    }
    if ($newPhoto === null) {
        app_flash('Choisissez une photo.', 'error');
        app_redirect($return);
    }
}

$previous = null;
update_json_file(app_data_path('users.json'), static function (array $users) use ($targetId, $newPhoto, &$previous): array {
    foreach ($users as &$account) {
        if (is_array($account) && ($account['id'] ?? null) === $targetId) {
            $previous = is_string($account['photo'] ?? null) ? $account['photo'] : null;
            if ($newPhoto !== null) {
                $account['photo'] = $newPhoto;
            } else {
                unset($account['photo']);
            }
        }
    }
    unset($account);
    return $users;
});
if ($previous !== null && $previous !== $newPhoto) {
    app_delete_photo($previous);
}
if ($newPhoto !== null || $previous !== null) {
    app_attendance_log_append([app_account_log_entry($target, $newPhoto === null ? 'photo_remove' : ($previous === null ? 'photo_add' : 'photo_change'), '', '', $user)]);
}
$own = $targetId === (string) $user['id'];
app_flash($newPhoto !== null ? ($own ? 'Votre photo a été enregistrée.' : 'La photo a été enregistrée.') : ($own ? 'Votre photo a été retirée.' : 'La photo a été retirée.'));
app_redirect($return);
