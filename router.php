<?php
declare(strict_types=1);

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (is_string($requestPath) && preg_match('#^/(?:data(?:/|$)|OnBoarding(?:/|$)|\.|config\.json$|README\.md$)#', $requestPath) === 1) {
    http_response_code(404);
    exit;
}

if ($requestPath === '/') {
    require __DIR__ . '/index.php';
    return true;
}

$requestedFile = is_string($requestPath) ? __DIR__ . $requestPath : '';
if ($requestPath !== '/' && $requestedFile !== '' && is_file($requestedFile)) {
    return false;
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo 'Page introuvable.';
