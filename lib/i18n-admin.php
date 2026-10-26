<?php
declare(strict_types=1);

// Editing the translations (Configuration > Langues, tools/i18n-check.php). The translations of
// the code (lang/<code>.php) can be overridden per installation: the corrections are kept in
// data/translations-<code>.json ("French text" => "translation"), so that an update of the
// application keeps them; an export gives back a complete lang/<code>.php to share.

// Texts to translate: the literal French strings given to __() and __n() in the code, and the
// lists translated when shown. Text => file where it was found ("lists" for the lists).
function app_i18n_keys(): array
{
    static $keys = null;
    if ($keys !== null) {
        return $keys;
    }
    $root = dirname(__DIR__);
    $keys = [];
    foreach (array_merge(glob($root . '/*.php') ?: [], glob($root . '/lib/*.php') ?: []) as $file) {
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
                    $text = app_php_string_value($piece[1]);
                    if ($text !== '') {
                        $keys[$text] = basename($file);
                    }
                    $wanted--;
                } elseif ((is_array($piece) && in_array($piece[0], [T_WHITESPACE, T_COMMENT], true)) || $piece === '(' || $piece === ',') {
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
    uksort($keys, static fn (string $first, string $second): int => strcmp(app_lowercase($first), app_lowercase($second)));
    return $keys;
}

// Value of a PHP single- or double-quoted string literal without escape sequences other than \\ and \'.
function app_php_string_value(string $literal): string
{
    $quote = $literal[0];
    $body = substr($literal, 1, -1);
    if ($quote === "'") {
        return strtr($body, ['\\\\' => '\\', "\\'" => "'"]);
    }
    return stripcslashes($body);
}

// Short identifier of a text, used in the forms and the exported files.
function app_translation_id(string $text): string
{
    return substr(hash('sha256', $text), 0, 12);
}

// Translations a language starts from, without its own corrections: lang/<code>.php, or those
// of its base for a created language.
function app_code_translations(string $language): array
{
    return app_inherited_translations($language);
}

// Translation a text has before the corrections of $language: inherited, otherwise the French
// text itself for a language coming from French ("fr-ca"), otherwise "" (to translate).
function app_inherited_translation(string $language, string $french): string
{
    $inherited = app_code_translations($language)[$french] ?? '';
    return $inherited !== '' ? $inherited : (app_language_root($language) === DEFAULT_LANGUAGE ? $french : '');
}

// Corrections of this installation (data/translations-<code>.json).
function app_translation_overrides(string $language): array
{
    if (!app_is_language($language) || $language === DEFAULT_LANGUAGE) {
        return [];
    }
    $stored = read_json_file(app_data_path('translations-' . $language . '.json'));
    return array_filter($stored, static fn (mixed $value, mixed $key): bool => is_string($key) && is_string($value) && $value !== '', ARRAY_FILTER_USE_BOTH);
}

// Placeholders of a text ("{name}"), sorted.
function app_text_placeholders(string $text): array
{
    preg_match_all('/\{[a-z]+\}/', $text, $matches);
    $placeholders = array_values(array_unique($matches[0]));
    sort($placeholders);
    return $placeholders;
}

// Why $translation cannot be used for $french, or null: its placeholders must be those of the French text.
function app_translation_error(string $french, string $translation): ?string
{
    $expected = app_text_placeholders($french);
    $found = app_text_placeholders($translation);
    if ($expected !== $found) {
        return $expected === []
            ? __('ne doit pas contenir de valeur entre accolades')
            : __('doit contenir exactement {placeholders}', ['placeholders' => implode(' ', $expected)]);
    }
    return null;
}

// Saves corrections of $language: text => new translation ("" or the translation of the code
// removes the correction). Returns [saved, unchanged, refused (text => reason)].
function app_save_translations(string $language, array $changes): array
{
    $saved = 0;
    $unchanged = 0;
    $refused = [];
    $valid = [];
    foreach ($changes as $french => $translation) {
        $translation = trim(str_replace(["\r\n", "\r"], "\n", (string) $translation));
        if ($translation !== '' && ($error = app_translation_error($french, $translation)) !== null) {
            $refused[$french] = $error;
            continue;
        }
        $valid[$french] = $translation === app_inherited_translation($language, $french) ? '' : $translation;
    }
    update_json_file(app_data_path('translations-' . $language . '.json'), static function (array $stored) use ($valid, &$saved, &$unchanged): array {
        foreach ($valid as $french => $translation) {
            if (($stored[$french] ?? '') === $translation) {
                $unchanged++;
                continue;
            }
            if ($translation === '') {
                unset($stored[$french]);
            } else {
                $stored[$french] = $translation;
            }
            $saved++;
        }
        ksort($stored);
        return $stored;
    });
    return [$saved, $unchanged, $refused];
}

// Complete lang/<code>.php of $language: the translations of the code with the corrections of
// this installation, for the texts still in the code.
function app_translation_php(string $language): string
{
    $translations = app_translations($language);
    $quote = static fn (string $text): string => "'" . strtr($text, ['\\' => '\\\\', "'" => "\\'"]) . "'";
    $lines = ['<?php', 'declare(strict_types=1);', '', '// Translations into ' . app_languages()[$language] . ': "French text of the code" => "translation".', '// Exported from Configuration > Langues on ' . date('Y-m-d') . '; keep the {placeholders} unchanged.', 'return ['];
    foreach (array_keys(app_i18n_keys()) as $french) {
        if (($translations[$french] ?? '') !== '') {
            $lines[] = '    ' . $quote($french) . ' => ' . $quote($translations[$french]) . ',';
        }
    }
    $lines[] = '];';
    return implode("\n", $lines) . "\n";
}

// Rows of an uploaded CSV file (UTF-8 with or without BOM, or Windows-1252; ";" or "," separated;
// cells may span several lines).
function app_read_translation_csv(string $content): array
{
    if (str_starts_with($content, "\u{FEFF}")) {
        $content = substr($content, 3);
    }
    if (!mb_check_encoding($content, 'UTF-8')) {
        $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
    }
    $firstLine = strtok($content, "\n") ?: '';
    $separator = substr_count($firstLine, ';') >= substr_count($firstLine, ',') ? ';' : ',';
    $stream = fopen('php://memory', 'w+');
    fwrite($stream, $content);
    rewind($stream);
    $rows = [];
    while (($cells = fgetcsv($stream, null, $separator, '"', '')) !== false) {
        if ($cells !== [null]) {
            $rows[] = array_map(static fn (?string $cell): string => (string) $cell, $cells);
        }
    }
    fclose($stream);
    return $rows;
}
