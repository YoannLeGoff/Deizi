<?php
declare(strict_types=1);

// Image of an announcement: served to the members it is meant for and to those who may edit it.
require __DIR__ . '/lib/bootstrap.php';
$user = app_require_login();

$id = $_GET['id'] ?? '';
$announcement = is_string($id) ? app_announcement($id) : null;
$path = $announcement !== null && $announcement['image'] !== null ? app_upload_path($announcement['image']) : null;
if ($path === null || !is_file($path) || !(app_announcement_targets($announcement, $user) || app_is_announcement_author($announcement, $user) || app_can_edit_announcement($user, $announcement))) {
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
header('Cache-Control: private, max-age=86400');
readfile($path);
