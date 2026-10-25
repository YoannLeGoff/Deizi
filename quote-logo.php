<?php
declare(strict_types=1);

// Logo of the generated quotes, for logisticians and administrators; on the public page of a
// published quote (?t=…), the logo recorded with that publication, without an account.
require __DIR__ . '/lib/bootstrap.php';

$token = $_GET['t'] ?? null;
if ($token !== null) {
    $found = app_quote_by_public_token($token);
    $logo = $found !== null ? $found[1]['share']['document']['issuer']['logo'] : null;
} else {
    app_require_roles(LOGISTICS_ROLES);
    // ?v=… names the logo: the current one, or the one recorded with a publication.
    $requested = $_GET['v'] ?? null;
    $logo = is_string($requested) && app_upload_path($requested) !== null && str_starts_with($requested, 'logo-') ? $requested : app_quote_issuer()['logo'];
}
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
header('Cache-Control: private, max-age=604800');
readfile($path);
