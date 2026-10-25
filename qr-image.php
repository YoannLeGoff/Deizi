<?php
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

$name = $_GET['file'] ?? '';
$path = is_string($name) ? app_upload_path($name) : null;
if ($path === null || !is_file($path)) {
    http_response_code(404);
    exit;
}

// Without a session, only the QR image of a shared roadmap is served, against its share token.
if (app_current_user() === null) {
    $shareToken = $_GET['share'] ?? '';
    $event = is_string($shareToken) ? app_event_by_share_token($shareToken) : null;
    $sharedImage = $event !== null ? app_roadmap_qr(app_event_roadmap($event), app_settings())['image'] : null;
    if ($sharedImage === null || $sharedImage !== $name) {
        http_response_code(404);
        exit;
    }
}

$types = [];
foreach (ROADMAP_UPLOAD_TYPES as [$extension, $mime]) {
    $types[$extension] = $mime;
}
header('Content-Type: ' . $types[pathinfo($path, PATHINFO_EXTENSION)]);
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Content-Security-Policy: default-src \'none\'');
header('Cache-Control: private, max-age=86400');
readfile($path);
