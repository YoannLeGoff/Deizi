<?php
declare(strict_types=1);

// Editing the texts of the interface (Configuration > Langues, tools/i18n-check.php). The texts
// of the code (lang/<code>.php) can be corrected per installation, French included: the
// corrections are kept in data/translations-<code>.json ("key" => "text"), so that an update of
// the application keeps them; an export gives back a complete lang/<code>.php to share.

// Keys of the texts: the literal keys given to __() and __n() in the code, and those of the
// lists translated when shown (their French labels). Key => file where it was found ("lists").
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
                $keys[app_label_key($text) ?? $text] ??= 'lists';
            }
        }
    }
    ksort($keys);
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

// French text of a key, as in the code (lang/fr.php).
function app_french_text(string $key): string
{
    return app_code_texts(DEFAULT_LANGUAGE)[$key] ?? $key;
}

// Translations a language starts from, without its own corrections: lang/<code>.php, or those
// of its base for a created language.
function app_code_translations(string $language): array
{
    return app_inherited_translations($language);
}

// Text a key has in $language before its own corrections ("" when it is still to translate).
function app_inherited_translation(string $language, string $key): string
{
    return app_code_translations($language)[$key] ?? '';
}

// Corrections of this installation (data/translations-<code>.json).
function app_translation_overrides(string $language): array
{
    if (!app_is_language($language)) {
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

// Why $translation cannot be used for the text $key, or null: its placeholders must be those of
// the French text.
function app_translation_error(string $key, string $translation): ?string
{
    $expected = app_text_placeholders(app_french_text($key));
    $found = app_text_placeholders($translation);
    if ($expected !== $found) {
        return $expected === []
            ? __('doit_contenir_valeur_entre_accolades')
            : __('doit_contenir_exactement_placeholders', ['placeholders' => implode(' ', $expected)]);
    }
    return null;
}

// Saves corrections of $language: key => new text ("" or the inherited text removes the
// correction). Returns [saved, unchanged, refused (key => reason)].
function app_save_translations(string $language, array $changes): array
{
    $saved = 0;
    $unchanged = 0;
    $refused = [];
    $valid = [];
    foreach ($changes as $key => $translation) {
        $translation = trim(str_replace(["\r\n", "\r"], "\n", (string) $translation));
        if ($translation !== '' && ($error = app_translation_error($key, $translation)) !== null) {
            $refused[$key] = $error;
            continue;
        }
        $valid[$key] = $translation === app_inherited_translation($language, $key) ? '' : $translation;
    }
    update_json_file(app_data_path('translations-' . $language . '.json'), static function (array $stored) use ($valid, &$saved, &$unchanged): array {
        foreach ($valid as $key => $translation) {
            if (($stored[$key] ?? '') === $translation) {
                $unchanged++;
                continue;
            }
            if ($translation === '') {
                unset($stored[$key]);
            } else {
                $stored[$key] = $translation;
            }
            $saved++;
        }
        ksort($stored);
        return $stored;
    });
    return [$saved, $unchanged, $refused];
}

// Complete lang/<code>.php of $language: the texts of the code with the corrections of this
// installation, for the keys still in the code.
function app_translation_php(string $language): string
{
    $translations = app_translations($language);
    $quote = static fn (string $text): string => "'" . strtr($text, ['\\' => '\\\\', "'" => "\\'"]) . "'";
    $lines = ['<?php', 'declare(strict_types=1);', '', '// Texts in ' . app_languages()[$language] . ': "key" => "text".', '// Exported from Configuration > Langues on ' . date('Y-m-d') . '; keep the {placeholders} unchanged.', 'return ['];
    foreach (array_keys(app_i18n_keys()) as $key) {
        if (($translations[$key] ?? '') !== '') {
            $lines[] = '    ' . $quote($key) . ' => ' . $quote($translations[$key]) . ',';
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
