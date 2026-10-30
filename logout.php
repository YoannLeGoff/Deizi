<?php
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit(__('methode_non_autorisee_phrase'));
}

app_verify_csrf();
// "Rester connecté": this device forgets its token.
app_remember_forget_current();
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => $params['path'],
        'secure' => $params['secure'],
        'httponly' => $params['httponly'],
        'samesite' => $params['samesite'],
    ]);
}
session_destroy();
app_redirect('login.php');
