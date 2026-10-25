<?php
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

// Public: the logos appear on every page, including the login page.
// Only the currently configured logos are served, never another upload.
$kind = $_GET['kind'] ?? 'logo';
$logo = is_string($kind) && array_key_exists($kind, SITE_LOGOS) ? app_site_identity()[$kind] : null;
$path = $logo !== null ? app_upload_path($logo) : null;
if ($path === null || !is_file($path)) {
    http_response_code(404);
    exit;
}

$types = [];
foreach (ROADMAP_UPLOAD_TYPES as [$extension, $mime]) {
    $types[$extension] = $mime;
}
header('Content-Type: ' . $types[pathinfo($path, PATHINFO_EXTENSION)]);
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Content-Security-Policy: default-src \'none\'');
// The URL carries the file name (?v=…), so a new logo gets a new URL.
header_remove('Pragma');
header_remove('Expires');
header('Cache-Control: public, max-age=604800');
readfile($path);
