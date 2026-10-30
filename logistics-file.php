<?php
declare(strict_types=1);

// Serves the file attached to a quote, to logisticians and administrators only.
require __DIR__ . '/lib/bootstrap.php';
app_require_roles(LOGISTICS_ROLES);

$eventId = $_GET['event'] ?? '';
$quoteId = $_GET['devis'] ?? '';
$quote = null;
if (is_string($eventId) && is_string($quoteId) && $eventId !== '' && $quoteId !== '') {
    foreach (app_event_logistics(app_logistics_read(), $eventId)['quotes'] as $candidate) {
        if ($candidate['id'] === $quoteId) {
            $quote = $candidate;
        }
    }
}
$path = $quote !== null && $quote['file'] !== null ? app_quote_file_path($quote['file']) : null;
if ($path === null || !is_file($path)) {
    http_response_code(404);
    exit(__('fichier_introuvable_phrase'));
}

$extension = pathinfo($path, PATHINFO_EXTENSION);
$fileName = $quote['file_name'] !== '' ? $quote['file_name'] : 'devis.' . $extension;
header('Content-Type: ' . QUOTE_FILE_TYPES[$extension]);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9._-]+/', '_', $fileName) . '"; filename*=UTF-8\'\'' . rawurlencode($fileName));
header('X-Content-Type-Options: nosniff');
// No restrictive CSP on PDF files: the browser's own viewer would not open them.
if ($extension !== 'pdf') {
    header('Content-Security-Policy: default-src \'none\'');
}
header('Cache-Control: private, no-store');
readfile($path);
