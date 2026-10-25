<?php
declare(strict_types=1);

// Web app manifest: lets members install the site on their phone like an application (home screen
// icon, full-screen window). Public: browsers fetch it without a session.
require __DIR__ . '/lib/bootstrap.php';

$identity = app_site_identity();
$iconVersion = app_icon_version();
$icon = static fn (int $size, string $purpose): array => [
    'src' => 'icon.php?size=' . $size . '&v=' . $iconVersion,
    'sizes' => $size . 'x' . $size,
    'type' => 'image/png',
    'purpose' => $purpose,
];

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');
echo json_encode([
    'id' => './',
    'name' => $identity['title'],
    'short_name' => app_site_nickname(),
    'description' => $identity['tagline'] !== '' ? $identity['tagline'] : $identity['title'],
    'lang' => 'fr',
    'start_url' => 'index.php',
    'scope' => './',
    'display' => 'standalone',
    'background_color' => '#f5f1e8',
    'theme_color' => '#f5f1e8',
    'icons' => [$icon(192, 'any'), $icon(512, 'any'), $icon(192, 'maskable'), $icon(512, 'maskable')],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
