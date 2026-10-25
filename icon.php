<?php
declare(strict_types=1);

// Square icon of the installable application (home screen, app list): the "Icône de l'application"
// of "Identité du site", filling the icon; otherwise the header logo (or the Kerlenn Pondi logo)
// centred on the paper background, with the margin the "maskable" icons need. Public, like the
// logos: the phone fetches it without a session.
require __DIR__ . '/lib/bootstrap.php';

const APP_ICON_SIZES = [180, 192, 512];
const APP_ICON_BACKGROUND = [0xf5, 0xf1, 0xe8];
// Share of the icon the logo may take (the rest is the safe zone of maskable icons).
const APP_ICON_LOGO_SHARE = 0.62;

$size = (int) ($_GET['size'] ?? 192);
$size = in_array($size, APP_ICON_SIZES, true) ? $size : 192;

// Candidates in order: [file, share of the icon]; the first one the server can read is used.
$identity = app_site_identity();
$candidates = [];
foreach (['app_icon' => 1.0, 'logo' => APP_ICON_LOGO_SHARE] as $key => $share) {
    $path = $identity[$key] !== null ? app_upload_path($identity[$key]) : null;
    if ($path !== null && is_file($path)) {
        $candidates[] = [$path, $share];
    }
}
$candidates[] = [__DIR__ . '/assets/kerlenn-pondi-logo.png', APP_ICON_LOGO_SHARE];
$logoImage = false;
foreach ($candidates as [$source, $share]) {
    $logoImage = @imagecreatefromstring((string) file_get_contents($source));
    if ($logoImage !== false) {
        break;
    }
}

$etag = '"' . sha1($source . '|' . filemtime($source) . '|' . $size) . '"';
header('Cache-Control: public, max-age=86400');
header('ETag: ' . $etag);
if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    http_response_code(304);
    exit;
}

$image = imagecreatetruecolor($size, $size);
imagefill($image, 0, 0, imagecolorallocate($image, ...APP_ICON_BACKGROUND));
if ($logoImage !== false) {
    $width = imagesx($logoImage);
    $height = imagesy($logoImage);
    $scale = $size * $share / max($width, $height);
    $targetWidth = max(1, (int) round($width * $scale));
    $targetHeight = max(1, (int) round($height * $scale));
    imagealphablending($image, true);
    imagecopyresampled($image, $logoImage, intdiv($size - $targetWidth, 2), intdiv($size - $targetHeight, 2), 0, 0, $targetWidth, $targetHeight, $width, $height);
}

header('Content-Type: image/png');
header('X-Content-Type-Options: nosniff');
imagepng($image);
