<?php
declare(strict_types=1);

// Presentation of the application (OnBoarding/), for logged-in users only: the slides, their
// screenshots and the PDF. The folder itself is never served directly (router.php, .htaccess);
// only the files below are delivered, through this page.
require __DIR__ . '/lib/bootstrap.php';
app_require_login();

const ONBOARDING_DIRECTORY = __DIR__ . '/OnBoarding';

$requested = $_GET['f'] ?? 'presentation.html';
$requested = is_string($requested) ? $requested : '';
$types = [
    'presentation.html' => 'text/html; charset=utf-8',
    'Deizi-presentation.pdf' => 'application/pdf',
];
$isScreenshot = preg_match('#^visuels/[a-z0-9-]+\.png$#', $requested) === 1;
$path = isset($types[$requested]) || $isScreenshot ? realpath(ONBOARDING_DIRECTORY . '/' . $requested) : false;
if ($path === false || !str_starts_with($path, realpath(ONBOARDING_DIRECTORY) . DIRECTORY_SEPARATOR) || !is_file($path)) {
    http_response_code(404);
    exit(__('fichier_introuvable_phrase'));
}

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: private, max-age=3600');

if ($requested === 'presentation.html') {
    // The slides load their screenshots through this page; a link leads back to the application.
    $html = (string) file_get_contents($path);
    $html = str_replace('src="visuels/', 'src="onboarding.php?f=visuels/', $html);
    $html = str_replace(
        '<div class="controls">',
        '<div class="controls"><a class="back-link" href="updates.php">← ' . app_escape(__('retour_app', ['app' => app_site_nickname()])) . '</a><a class="back-link" href="onboarding.php?f=Deizi-presentation.pdf" target="_blank" rel="noopener">PDF</a>',
        $html
    );
    $html = str_replace('</style>', '  .controls .back-link { padding: 7px 12px; color: #fff; background: #3a3c3f; border-radius: 6px; font: 600 13px var(--sans); text-decoration: none; }' . "\n" . '  .controls .back-link:hover { background: #4a4d50; }' . "\n" . '</style>', $html);
    header('Content-Type: ' . $types[$requested]);
    if (!APP_LOCAL_EMBED) {
        header('X-Frame-Options: SAMEORIGIN');
    }
    echo $html;
    exit;
}

header('Content-Type: ' . ($isScreenshot ? 'image/png' : $types[$requested]));
header('Content-Length: ' . filesize($path));
if (!$isScreenshot) {
    header('Content-Disposition: inline; filename="Deizi-presentation.pdf"');
}
readfile($path);
