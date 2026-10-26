<?php
declare(strict_types=1);

// Translations check, from the command line only:
//   php tools/i18n-check.php            missing and unused texts of each language
//   php tools/i18n-check.php --keys     every text to translate, one per line (JSON strings)
// The texts are the literal French strings given to __() and __n() in the code, plus the lists
// translated when shown (roles, days, months, Configuration themes…).
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require $root . '/lib/bootstrap.php';

$keys = [];
$files = array_merge(glob($root . '/*.php') ?: [], glob($root . '/lib/*.php') ?: []);
foreach ($files as $file) {
    $tokens = token_get_all((string) file_get_contents($file));
    $count = count($tokens);
    for ($index = 0; $index < $count; $index++) {
        $token = $tokens[$index];
        if (!is_array($token) || $token[0] !== T_STRING || !in_array($token[1], ['__', '__n'], true)) {
            continue;
        }
        // Literal arguments: __('text') and __n('one', 'many', …).
        $wanted = $token[1] === '__n' ? 2 : 1;
        for ($next = $index + 1; $next < $count && $wanted > 0; $next++) {
            $piece = $tokens[$next];
            if (is_array($piece) && $piece[0] === T_CONSTANT_ENCAPSED_STRING) {
                $text = eval('return ' . $piece[1] . ';');
                if ($text !== '') {
                    $keys[$text] = basename($file);
                }
                $wanted--;
            } elseif (is_array($piece) && in_array($piece[0], [T_WHITESPACE, T_COMMENT], true)) {
                continue;
            } elseif ($piece === '(' || $piece === ',') {
                continue;
            } else {
                break;
            }
        }
    }
}
$lists = [
    array_values(USER_ROLES), WEEKDAY_NAMES, MONTH_NAMES, SHORT_MONTH_NAMES, JS_TEXTS, [USERNAME_RULE],
    array_values(ATTENDANCE_RESPONSES), array_values(ATTENDANCE_FILTERS), [ATTENDANCE_NO_RESPONSE_LABEL],
    array_values(EVENT_CHANGE_FIELDS), array_values(ROADMAP_TRANSPORT), array_values(ROADMAP_OUTFITS), array_values(POLL_USER_INFO), LOG_YES_NO, LOG_LEGACY_WORDS,
    array_merge(...array_map(static fn (array $group): array => [$group[0], $group[1], ...array_column($group[2], 1)], array_values(SETTINGS_GROUPS))),
];
foreach ($lists as $list) {
    foreach ($list as $text) {
        if (is_string($text) && $text !== '') {
            $keys[$text] ??= 'lists';
        }
    }
}
ksort($keys);

if (in_array('--keys', $argv, true)) {
    foreach (array_keys($keys) as $key) {
        echo json_encode($key, JSON_UNESCAPED_UNICODE), "\n";
    }
    exit;
}

$status = 0;
echo count($keys), " texts to translate\n";
foreach (array_keys(LANGUAGES) as $language) {
    if ($language === DEFAULT_LANGUAGE) {
        continue;
    }
    $translations = app_translations($language);
    $missing = array_filter(array_keys($keys), static fn (string $key): bool => ($translations[$key] ?? '') === '');
    $unused = array_diff(array_keys($translations), array_keys($keys));
    printf("%s: %d translated, %d missing, %d unused\n", $language, count($keys) - count($missing), count($missing), count($unused));
    foreach (array_slice($missing, 0, 20) as $key) {
        echo '  missing: ', json_encode($key, JSON_UNESCAPED_UNICODE), ' (', $keys[$key], ")\n";
    }
    $status = $missing !== [] ? 1 : $status;
}
exit($status);
