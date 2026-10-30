<?php
declare(strict_types=1);

// Push notifications (Web Push): a member who turned them on for a device (page Mises à jour) is
// told when a date of their sections is created, or when a date they have already opened changes
// (fields of EVENT_CHANGE_FIELDS). data/push.json keeps the server keys (VAPID) and the
// subscriptions of the devices. Messages are encrypted for each device (RFC 8291, aes128gcm) and
// signed (RFC 8292, VAPID), with PHP's openssl only; they are sent once the page has answered.

const PUSH_TTL_SECONDS = 86400;
const PUSH_MAX_PER_USER = 10;
const PUSH_HTTP_TIMEOUT = 8;
// DER header of an uncompressed P-256 public key (SubjectPublicKeyInfo).
const PUSH_P256_SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

function app_base64url_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function app_base64url_decode(string $data): string
{
    return (string) base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4), true);
}

// Uncompressed public point (0x04 || X || Y) of an EC key.
function app_push_public_point(OpenSSLAsymmetricKey $key): string
{
    $details = openssl_pkey_get_details($key);
    return "\x04" . str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);
}

function app_push_public_key_from_point(string $point): OpenSSLAsymmetricKey|false
{
    $der = hex2bin(PUSH_P256_SPKI_PREFIX) . $point;
    return openssl_pkey_get_public("-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n");
}

// Server keys (VAPID), created on first use: ['public' => base64url point, 'private' => PEM].
function app_push_vapid(): array
{
    $stored = read_json_file(app_data_path('push.json'))['vapid'] ?? null;
    if (is_array($stored) && is_string($stored['public'] ?? null) && is_string($stored['private'] ?? null)) {
        return $stored;
    }
    $vapid = null;
    update_json_file(app_data_path('push.json'), static function (array $data) use (&$vapid): array {
        if (!is_array($data['vapid'] ?? null)) {
            $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
            openssl_pkey_export($key, $pem);
            $data['vapid'] = ['public' => app_base64url_encode(app_push_public_point($key)), 'private' => $pem];
        }
        $vapid = $data['vapid'];
        return $data;
    });
    return $vapid;
}

function app_push_subscriptions(): array
{
    $subscriptions = read_json_file(app_data_path('push.json'))['subscriptions'] ?? [];
    return array_values(array_filter(is_array($subscriptions) ? $subscriptions : [], static fn (mixed $subscription): bool => is_array($subscription)
        && is_string($subscription['endpoint'] ?? null) && is_string($subscription['p256dh'] ?? null)
        && is_string($subscription['auth'] ?? null) && is_string($subscription['user_id'] ?? null)));
}

// Push services accepted for the endpoints (the browsers' own services, over HTTPS).
function app_push_endpoint_is_valid(string $endpoint): bool
{
    $host = (string) parse_url($endpoint, PHP_URL_HOST);
    return str_starts_with($endpoint, 'https://') && strlen($endpoint) <= 1000
        && preg_match('/(^|\.)(googleapis\.com|mozilla\.com|mozaws\.net|windows\.com|notify\.windows\.com|push\.apple\.com)$/', $host) === 1;
}

function app_push_subscribe(string $userId, string $endpoint, string $p256dh, string $auth): bool
{
    if (!app_push_endpoint_is_valid($endpoint) || strlen(app_base64url_decode($p256dh)) !== 65 || strlen(app_base64url_decode($auth)) !== 16) {
        return false;
    }
    update_json_file(app_data_path('push.json'), static function (array $data) use ($userId, $endpoint, $p256dh, $auth): array {
        $subscriptions = array_values(array_filter(
            is_array($data['subscriptions'] ?? null) ? $data['subscriptions'] : [],
            static fn (mixed $subscription): bool => is_array($subscription) && ($subscription['endpoint'] ?? null) !== $endpoint
        ));
        $subscriptions[] = ['endpoint' => $endpoint, 'p256dh' => $p256dh, 'auth' => $auth, 'user_id' => $userId, 'created' => time()];
        $own = array_keys(array_filter($subscriptions, static fn (array $subscription): bool => $subscription['user_id'] === $userId));
        foreach (array_slice($own, 0, max(0, count($own) - PUSH_MAX_PER_USER)) as $index) {
            unset($subscriptions[$index]);
        }
        $data['subscriptions'] = array_values($subscriptions);
        return $data;
    });
    return true;
}

// Removes subscriptions: by endpoint (this device), or every one of an account.
function app_push_unsubscribe(?string $endpoint = null, ?string $userId = null): void
{
    update_json_file(app_data_path('push.json'), static function (array $data) use ($endpoint, $userId): array {
        $data['subscriptions'] = array_values(array_filter(
            is_array($data['subscriptions'] ?? null) ? $data['subscriptions'] : [],
            static fn (mixed $subscription): bool => is_array($subscription)
                && ($endpoint === null || ($subscription['endpoint'] ?? null) !== $endpoint)
                && ($userId === null || ($subscription['user_id'] ?? null) !== $userId)
        ));
        return $data;
    });
}

// Encrypted body of a message for one subscription (RFC 8291, a single aes128gcm record).
function app_push_encrypt(string $payload, string $p256dh, string $auth): ?string
{
    $userPoint = app_base64url_decode($p256dh);
    $authSecret = app_base64url_decode($auth);
    $userKey = app_push_public_key_from_point($userPoint);
    $localKey = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    if ($userKey === false || $localKey === false) {
        return null;
    }
    $localPoint = app_push_public_point($localKey);
    $sharedSecret = openssl_pkey_derive($userKey, $localKey, 32);
    if ($sharedSecret === false) {
        return null;
    }
    $ikm = hash_hkdf('sha256', $sharedSecret, 32, "WebPush: info\0" . $userPoint . $localPoint, $authSecret);
    $salt = random_bytes(16);
    $contentKey = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
    $cipher = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $contentKey, OPENSSL_RAW_DATA, $nonce, $tag);
    if ($cipher === false) {
        return null;
    }
    return $salt . pack('N', 4096) . chr(65) . $localPoint . $cipher . $tag;
}

// VAPID authorization header for the push service of $endpoint (JWT signed in ES256).
function app_push_authorization(string $endpoint, array $vapid): string
{
    $audience = parse_url($endpoint, PHP_URL_SCHEME) . '://' . parse_url($endpoint, PHP_URL_HOST);
    $header = app_base64url_encode('{"typ":"JWT","alg":"ES256"}');
    // Contact of the sender for the push services: the site, when it is served over HTTPS.
    $site = app_absolute_url('');
    $subject = str_starts_with($site, 'https://') ? $site : 'mailto:notifications@deizi.invalid';
    $claims = app_base64url_encode((string) json_encode(['aud' => $audience, 'exp' => time() + 12 * 3600, 'sub' => $subject], JSON_UNESCAPED_SLASHES));
    openssl_sign($header . '.' . $claims, $der, $vapid['private'], OPENSSL_ALGO_SHA256);
    // DER signature (SEQUENCE of two INTEGERs) to the 64-byte R || S form of JWS.
    $offset = 3 + (ord($der[1]) & 0x80 ? (ord($der[1]) & 0x7f) : 0);
    $rLength = ord($der[$offset]);
    $r = substr($der, $offset + 1, $rLength);
    $sLength = ord($der[$offset + 2 + $rLength]);
    $s = substr($der, $offset + 3 + $rLength, $sLength);
    $raw = str_pad(ltrim($r, "\0"), 32, "\0", STR_PAD_LEFT) . str_pad(ltrim($s, "\0"), 32, "\0", STR_PAD_LEFT);
    return 'vapid t=' . $header . '.' . $claims . '.' . app_base64url_encode($raw) . ', k=' . $vapid['public'];
}

// Sends [subscription, payload array] pairs in parallel; subscriptions the push service no longer
// knows (404, 410) are removed. Returns the number of messages accepted.
function app_push_send(array $messages): int
{
    if ($messages === []) {
        return 0;
    }
    $vapid = app_push_vapid();
    $multi = curl_multi_init();
    $handles = [];
    foreach ($messages as [$subscription, $payload]) {
        $body = app_push_encrypt((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $subscription['p256dh'], $subscription['auth']);
        if ($body === null) {
            continue;
        }
        $handle = curl_init($subscription['endpoint']);
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => PUSH_HTTP_TIMEOUT,
            CURLOPT_HTTPHEADER => [
                'Authorization: ' . app_push_authorization($subscription['endpoint'], $vapid),
                'Content-Encoding: aes128gcm',
                'Content-Type: application/octet-stream',
                'TTL: ' . PUSH_TTL_SECONDS,
                'Urgency: normal',
            ],
        ]);
        curl_multi_add_handle($multi, $handle);
        $handles[] = [$handle, $subscription['endpoint']];
    }
    do {
        $status = curl_multi_exec($multi, $running);
        if ($running) {
            curl_multi_select($multi, 1.0);
        }
    } while ($running && $status === CURLM_OK);
    $accepted = 0;
    $gone = [];
    foreach ($handles as [$handle, $endpoint]) {
        $code = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        if ($code >= 200 && $code < 300) {
            $accepted++;
        } elseif ($code === 404 || $code === 410) {
            $gone[] = $endpoint;
        }
        curl_multi_remove_handle($multi, $handle);
        curl_close($handle);
    }
    curl_multi_close($multi);
    foreach ($gone as $endpoint) {
        app_push_unsubscribe($endpoint);
    }
    return $accepted;
}

// Dates created or changed during the request (event-action.php, logistics-action.php): the
// messages are sent once the page has answered, outside the lock of the dates file.
// $type: 'new' (creation, announced to the members of its sections) or 'changed' (announced to the
// members who have already opened the date).
function app_push_queue_event(string $eventId, string $type): void
{
    $GLOBALS['app_push_queue'][$eventId] ??= $type;
    app_push_register_flush();
}

// Announcement to send to the members it is meant for (lib/announcements.php).
function app_push_queue_announcement(array $announcement): void
{
    $GLOBALS['app_push_announcements'][$announcement['id']] = $announcement;
    app_push_register_flush();
}

function app_push_register_flush(): void
{
    static $registered = false;
    if (!$registered) {
        $registered = true;
        register_shutdown_function(static function (): void {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } elseif (!headers_sent() && in_array(ob_get_length(), [false, 0], true)) {
                // Redirections (no body): the browser gets the answer before the messages leave.
                header('Content-Length: 0');
                header('Connection: close');
                while (ob_get_level() > 0) {
                    ob_end_flush();
                }
                flush();
            }
            ignore_user_abort(true);
            app_push_flush_queue();
        });
    }
}

function app_push_flush_queue(): void
{
    $queue = $GLOBALS['app_push_queue'] ?? [];
    $announcements = $GLOBALS['app_push_announcements'] ?? [];
    $GLOBALS['app_push_queue'] = [];
    $GLOBALS['app_push_announcements'] = [];
    $subscriptions = $queue !== [] || $announcements !== [] ? app_push_subscriptions() : [];
    if ($subscriptions === []) {
        return;
    }
    $users = [];
    foreach (read_json_file(app_data_path('users.json')) as $user) {
        if (is_array($user) && app_is_active_user($user)) {
            $users[$user['id']] = $user;
        }
    }
    $events = read_json_file(app_data_path('events.json'));
    // Per member, the dates to point out: the same ones as the bell of the menu (new dates of
    // their sections, changes of dates they have opened, never their own changes). Their number
    // becomes the badge of the installed application.
    $unread = [];
    $messages = [];
    // Each notification is written in the language of the member who receives it.
    $requestLocale = app_locale();
    foreach ($subscriptions as $subscription) {
        $user = $users[$subscription['user_id']] ?? null;
        if ($user === null) {
            continue;
        }
        app_set_locale_for($user);
        $unread[$user['id']] ??= app_unread_events($user, $events);
        foreach ($unread[$user['id']] as $item) {
            if (($queue[$item['event']['id']] ?? null) === $item['notice']['type']) {
                $payload = app_push_event_payload($item['event'], $item['notice']);
                $payload['badge'] = count($unread[$user['id']]);
                $messages[] = [$subscription, $payload];
            }
        }
        // Announcements: to the members of their sections, except their author.
        foreach ($announcements as $announcement) {
            if ($user['id'] !== $announcement['author'] && app_announcement_targets($announcement, $user)
                && !in_array((string) $user['id'], $announcement['dismissed'], true)) {
                $payload = app_push_announcement_payload($announcement);
                $payload['badge'] = count($unread[$user['id']]);
                $messages[] = [$subscription, $payload];
            }
        }
    }
    app_locale($requestLocale);
    app_push_send($messages);
}

// Text of the notification, in the language of the page (that of its recipient): "Nouvelle date :
// Festival Kleg" / "Date modifiée : …", the day, times and place (or what changed), and the address
// of the date.
function app_push_event_payload(array $event, array $notice): array
{
    $isNew = $notice['type'] === 'new';
    $date = app_parse_date($event['date'] ?? null);
    $day = $date !== null ? app_long_date($date, true, false) : '';
    $time = ($event['all_day'] ?? false) === true ? __('push_journee_entiere') : app_event_time_range($event);
    $location = is_string($event['location'] ?? null) ? trim($event['location']) : '';
    $title = (string) ($event['title'] ?? '');
    $body = $isNew
        ? implode(' · ', array_filter([$day, $time, $location], static fn (string $part): bool => $part !== ''))
        : __('push_modifie_champs', ['fields' => implode(', ', array_map('app_label', array_unique($notice['fields'])))]) . ($day !== '' ? ' — ' . $day : '');
    return [
        'title' => $isNew ? __('push_nouvelle_date_title', ['title' => $title]) : __('push_date_modifiee_title', ['title' => $title]),
        'body' => $body,
        'url' => app_absolute_url('event.php?id=' . rawurlencode((string) $event['id'])),
        'tag' => 'date-' . $event['id'],
    ];
}

// Text of the notification of an announcement: "Annonce : <title>", the start of its text, and the
// home page, where it is shown.
function app_push_announcement_payload(array $announcement): array
{
    $body = trim(preg_replace('/\s+/u', ' ', $announcement['body']) ?? '');
    return [
        'title' => __('push_annonce_title', ['title' => $announcement['title']]),
        'body' => $body !== '' ? app_truncate($body, 160) : __('push_lire_page_accueil'),
        'url' => app_absolute_url('index.php#annonce-' . $announcement['id']),
        'tag' => 'annonce-' . $announcement['id'],
    ];
}
