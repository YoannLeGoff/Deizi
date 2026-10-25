<?php
declare(strict_types=1);

// Profile photo of a member, for logged-in members only (Trombinoscope, Mon compte, accounts).
require __DIR__ . '/lib/bootstrap.php';
app_require_login();

$userId = $_GET['id'] ?? '';
$path = null;
foreach (is_string($userId) && $userId !== '' ? read_json_file(app_data_path('users.json')) : [] as $account) {
    if (is_array($account) && ($account['id'] ?? null) === $userId) {
        $photo = app_user_photo($account);
        // "format=portrait": the portrait version, or the square for photos saved before it existed.
        $path = $photo !== null ? (($_GET['format'] ?? '') === 'portrait' ? app_photo_portrait_path($photo) : null) ?? app_photo_path($photo) : null;
        break;
    }
}
if ($path === null) {
    http_response_code(404);
    exit;
}
// Nothing else than the image: a notice printed by the server would spoil it.
while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: image/jpeg');
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Content-Security-Policy: default-src \'none\'');
// The address changes with the photo ("v"), so it can be kept a while.
header('Cache-Control: private, max-age=86400');
readfile($path);
