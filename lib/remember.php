<?php
declare(strict_types=1);

// "Rester connecté sur cet appareil": a long-lived cookie that logs the member in again when the
// session has ended (browser or installed application closed). The cookie holds a selector and a
// secret; data/remember.json keeps only the SHA-256 of the secret, the account and the expiry. The
// secret changes each time it is used, and the tokens of an account are withdrawn on logout (this
// device), on a password change and when the account is deactivated.

const REMEMBER_COOKIE = 'deizi_remember';
const REMEMBER_DAYS = 90;
// Tokens kept per account (one per device); the oldest ones go first.
const REMEMBER_MAX_PER_USER = 10;

function app_remember_cookie(string $value, int $expires): void
{
    $isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off';
    setcookie(REMEMBER_COOKIE, $value, [
        'expires' => $expires,
        'path' => '/',
        'secure' => $isHttps || APP_LOCAL_EMBED,
        'httponly' => true,
        // Lax: sent when the installed application or a link (iCal, e-mail) opens the site.
        'samesite' => APP_LOCAL_EMBED ? 'None' : 'Lax',
    ]);
}

// Tokens still valid, as stored (expired ones are dropped when the file is written).
function app_remember_valid_tokens(array $tokens): array
{
    $now = time();
    return array_values(array_filter($tokens, static fn (mixed $token): bool => is_array($token)
        && is_string($token['selector'] ?? null) && is_string($token['hash'] ?? null)
        && is_string($token['user_id'] ?? null) && is_int($token['expires'] ?? null) && $token['expires'] > $now));
}

// Gives this device a token for $userId (after a login with the box ticked).
function app_remember_issue(string $userId): void
{
    $selector = bin2hex(random_bytes(12));
    $secret = bin2hex(random_bytes(32));
    $expires = time() + REMEMBER_DAYS * 86400;
    update_json_file(app_data_path('remember.json'), static function (array $tokens) use ($selector, $secret, $userId, $expires): array {
        $tokens = app_remember_valid_tokens($tokens);
        $tokens[] = ['selector' => $selector, 'hash' => hash('sha256', $secret), 'user_id' => $userId, 'expires' => $expires, 'created' => time()];
        $own = array_keys(array_filter($tokens, static fn (array $token): bool => $token['user_id'] === $userId));
        foreach (array_slice($own, 0, max(0, count($own) - REMEMBER_MAX_PER_USER)) as $index) {
            unset($tokens[$index]);
        }
        return array_values($tokens);
    });
    app_remember_cookie($selector . ':' . $secret, $expires);
}

// Account of the token of this device, or null; a valid token gets a new secret. A token whose
// secret does not match is withdrawn (it may have been copied).
function app_remember_login(): ?string
{
    $cookie = $_COOKIE[REMEMBER_COOKIE] ?? null;
    if (!is_string($cookie) || preg_match('/^([0-9a-f]{24}):([0-9a-f]{64})$/', $cookie, $match) !== 1) {
        return null;
    }
    [, $selector, $secret] = $match;
    $userId = null;
    $newSecret = bin2hex(random_bytes(32));
    $expires = time() + REMEMBER_DAYS * 86400;
    update_json_file(app_data_path('remember.json'), static function (array $tokens) use ($selector, $secret, $newSecret, $expires, &$userId): array {
        $tokens = app_remember_valid_tokens($tokens);
        foreach ($tokens as $index => $token) {
            if (!hash_equals($token['selector'], $selector)) {
                continue;
            }
            if (!hash_equals($token['hash'], hash('sha256', $secret))) {
                unset($tokens[$index]);
                break;
            }
            $userId = $token['user_id'];
            $tokens[$index]['hash'] = hash('sha256', $newSecret);
            $tokens[$index]['expires'] = $expires;
            break;
        }
        return array_values($tokens);
    });
    if ($userId === null) {
        app_remember_cookie('', time() - 3600);
        return null;
    }
    app_remember_cookie($selector . ':' . $newSecret, $expires);
    return $userId;
}

// Withdraws the token of this device (logout).
function app_remember_forget_current(): void
{
    $cookie = $_COOKIE[REMEMBER_COOKIE] ?? null;
    if (is_string($cookie) && preg_match('/^([0-9a-f]{24}):/', $cookie, $match) === 1) {
        $selector = $match[1];
        update_json_file(app_data_path('remember.json'), static fn (array $tokens): array => array_values(array_filter(
            app_remember_valid_tokens($tokens),
            static fn (array $token): bool => !hash_equals($token['selector'], $selector)
        )));
    }
    app_remember_cookie('', time() - 3600);
}

// Withdraws every token of an account (password changed, account deactivated).
function app_remember_forget_user(string $userId): void
{
    update_json_file(app_data_path('remember.json'), static fn (array $tokens): array => array_values(array_filter(
        app_remember_valid_tokens($tokens),
        static fn (array $token): bool => $token['user_id'] !== $userId
    )));
}
