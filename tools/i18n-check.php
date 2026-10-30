<?php
declare(strict_types=1);

// Translations check, from the command line only:
//   php tools/i18n-check.php            missing and unused texts of each language
//   php tools/i18n-check.php --keys     every text to translate, one per line (JSON strings)
// The keys are those given to __() and __n() in the code, plus those of the lists translated when
// shown (roles, days, months, Configuration themes…); lang/fr.php must have them all.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require $root . '/lib/bootstrap.php';

require $root . '/lib/i18n-admin.php';
$keys = app_i18n_keys();

if (in_array('--keys', $argv, true)) {
    foreach (array_keys($keys) as $key) {
        echo json_encode($key, JSON_UNESCAPED_UNICODE), "\n";
    }
    exit;
}

$status = 0;
echo count($keys), " texts to translate\n";
foreach (array_keys(LANGUAGES) as $language) {
    // The translations of the code only (corrections of an installation are not counted).
    $translations = app_code_translations($language);
    $missing = array_filter(array_keys($keys), static fn (string $key): bool => ($translations[$key] ?? '') === '');
    $unused = array_diff(array_keys($translations), array_keys($keys));
    printf("%s: %d translated, %d missing, %d unused\n", $language, count($keys) - count($missing), count($missing), count($unused));
    foreach (array_slice($missing, 0, 20) as $key) {
        echo '  missing: ', json_encode($key, JSON_UNESCAPED_UNICODE), ' (', $keys[$key], ")\n";
    }
    $status = $missing !== [] ? 1 : $status;
}
exit($status);
