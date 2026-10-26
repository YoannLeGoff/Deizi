<?php
declare(strict_types=1);

// Languages of the interface. French is the language the application is written in: its texts are
// the keys of the translations, kept in lang/<code>.php ("French text" => "translation"). A text
// without translation is shown in French. Placeholders are written {name} and given to __().
// The language of a page: the member's own choice (Mon compte), otherwise the default language of
// the site (Identité du site); before logging in, the language of the browser when available.
// Languages of the code (lang/<code>.php); others are created in Configuration > Langues.
const LANGUAGES = [
    'fr' => 'Français',
    'en' => 'English',
    'br' => 'Brezhoneg',
    'es' => 'Español',
];
const DEFAULT_LANGUAGE = 'fr';
// Code of a language: "fr-ca", "en-us", "br-gwened"…
const LANGUAGE_CODE_PATTERN = '/^[a-z]{2,3}(-[a-z0-9]{2,8})?$/';
const LANGUAGE_NAME_MAX_LENGTH = 40;

// Languages created in Configuration > Langues (settings.json, "languages"): code => [name, base],
// each based on an existing language, whose translations it takes until it changes them.
function app_custom_languages(bool $refresh = false): array
{
    static $languages = null;
    if ($languages !== null && !$refresh) {
        return $languages;
    }
    $stored = read_json_file(app_data_path('settings.json'))['languages'] ?? [];
    $languages = [];
    foreach (is_array($stored) ? $stored : [] as $code => $language) {
        if (is_string($code) && preg_match(LANGUAGE_CODE_PATTERN, $code) === 1 && !isset(LANGUAGES[$code])
            && is_string($language['name'] ?? null) && trim($language['name']) !== '' && is_string($language['base'] ?? null)) {
            $languages[$code] = ['name' => trim($language['name']), 'base' => $language['base']];
        }
    }
    // A language whose chain of bases does not end on a language of the code is left out.
    foreach (array_keys($languages) as $code) {
        $current = $code;
        for ($depth = 0; isset($languages[$current]) && $depth < 10; $depth++) {
            $current = $languages[$current]['base'];
        }
        if (!isset(LANGUAGES[$current])) {
            unset($languages[$code]);
        }
    }
    return $languages;
}

// Every language: code => name (those of the code, then those created).
function app_languages(): array
{
    return LANGUAGES + array_map(static fn (array $language): string => $language['name'], app_custom_languages());
}

function app_is_language(mixed $code): bool
{
    return is_string($code) && isset(app_languages()[$code]);
}

// Language of the code a language comes from ("fr" for "fr-ca"): it sets how dates are written.
function app_language_root(string $code): string
{
    $custom = app_custom_languages();
    for ($depth = 0; isset($custom[$code]) && $depth < 10; $depth++) {
        $code = $custom[$code]['base'];
    }
    return isset(LANGUAGES[$code]) ? $code : DEFAULT_LANGUAGE;
}

// Default language of the site, set in Configuration > Identité du site.
function app_site_language(): string
{
    $language = read_json_file(app_data_path('settings.json'))['site']['language'] ?? null;
    return app_is_language($language) ? $language : DEFAULT_LANGUAGE;
}

// Preferred language of the browser among the languages ("Accept-Language"), or null: the exact
// one ("fr-CA" for "fr-ca") first, otherwise the main one ("fr").
function app_browser_language(): ?string
{
    $header = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
    if (!is_string($header) || $header === '') {
        return null;
    }
    $best = null;
    $bestWeight = 0.0;
    foreach (explode(',', $header) as $part) {
        $pieces = explode(';', trim($part));
        $tag = strtolower(trim($pieces[0]));
        $weight = 1.0;
        foreach (array_slice($pieces, 1) as $parameter) {
            if (str_starts_with(trim($parameter), 'q=')) {
                $weight = (float) substr(trim($parameter), 2);
            }
        }
        // The exact language weighs a little more than its main one given with the same weight.
        foreach ([[$tag, $weight + 0.001], [explode('-', $tag)[0], $weight]] as [$code, $score]) {
            if (app_is_language($code) && $score > $bestWeight) {
                $best = $code;
                $bestWeight = $score;
            }
        }
    }
    return $best;
}

// Language of the current page (computed once per request), or the one set by app_set_locale().
function app_locale(?string $set = null): string
{
    static $locale = null;
    if ($set !== null) {
        return $locale = $set;
    }
    if ($locale !== null) {
        return $locale;
    }
    $user = app_current_user();
    if ($user !== null && app_is_language($user['language'] ?? null)) {
        return $locale = $user['language'];
    }
    if ($user === null && ($browser = app_browser_language()) !== null) {
        return $locale = $browser;
    }
    return $locale = app_site_language();
}

// Language of a page made for a given member without session (iCal feed of their link): theirs,
// otherwise the default language of the site.
function app_set_locale_for(array $user): void
{
    app_locale(app_is_language($user['language'] ?? null) ? $user['language'] : app_site_language());
}

// Translations of a language: "French text" => "translated text": those of the code
// (lang/<code>.php), or for a created language those of its base, with the corrections made in
// Configuration > Langues (data/translations-<code>.json) over them.
function app_translations(string $language, bool $refresh = false): array
{
    static $loaded = [];
    if (!isset($loaded[$language]) || $refresh) {
        $inherited = app_inherited_translations($language);
        $overrides = $language !== DEFAULT_LANGUAGE && app_is_language($language) ? read_json_file(app_data_path('translations-' . $language . '.json')) : [];
        $loaded[$language] = array_merge($inherited, array_filter($overrides, static fn (mixed $value): bool => is_string($value) && $value !== ''));
    }
    return $loaded[$language];
}

// Translations a language starts from: lang/<code>.php for a language of the code, the
// translations of its base for a created language (none when the base is French).
function app_inherited_translations(string $language): array
{
    $custom = app_custom_languages();
    if (isset($custom[$language])) {
        return $custom[$language]['base'] === DEFAULT_LANGUAGE ? [] : app_translations($custom[$language]['base']);
    }
    $file = dirname(__DIR__) . '/lang/' . $language . '.php';
    $translations = $language !== DEFAULT_LANGUAGE && isset(LANGUAGES[$language]) && is_file($file) ? require $file : [];
    return is_array($translations) ? $translations : [];
}

// $text (French) in the language of the page, with its {placeholders} replaced by $values.
function __(string $text, array $values = []): string
{
    $translated = app_translations(app_locale())[$text] ?? $text;
    if ($translated === '') {
        $translated = $text;
    }
    if ($values !== []) {
        $translated = strtr($translated, array_combine(
            array_map(static fn (string|int $key): string => '{' . $key . '}', array_keys($values)),
            array_map(static fn (mixed $value): string => (string) $value, array_values($values))
        ));
    }
    return $translated;
}

// One of two texts depending on $count ("1 date" / "3 dates"), {n} being replaced by $count.
function __n(string $one, string $many, int $count, array $values = []): string
{
    return __($count === 1 ? $one : $many, ['n' => $count] + $values);
}

// Names of the days (1 = Monday) and months (1 = January) in the language of the page.
const WEEKDAY_NAMES = ['', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];
const MONTH_NAMES = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

function app_weekday_name(int $day, bool $capital = false): string
{
    $name = __(WEEKDAY_NAMES[$day] ?? '');
    return $capital ? app_ucfirst($name) : $name;
}

function app_month_name(int $month, bool $capital = false): string
{
    $name = __(MONTH_NAMES[$month] ?? '');
    return $capital ? app_ucfirst($name) : $name;
}

const SHORT_MONTH_NAMES = ['', 'janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];

function app_month_short(int $month): string
{
    return __(SHORT_MONTH_NAMES[$month] ?? '');
}

// Time of day: "14h30" in French, "14:30" otherwise.
function app_time(DateTimeInterface $date): string
{
    return $date->format(app_language_root(app_locale()) === 'fr' ? 'H\hi' : 'H:i');
}

// "Dimanche 8 novembre 2026" in the language of the page ({weekday} {day} {month} {year}, an
// order each language sets); without the weekday or the year when asked. French writes "1er".
function app_long_date(DateTimeInterface $date, bool $weekday = true, bool $year = true, bool $capital = true): string
{
    $day = (int) $date->format('j');
    $values = [
        'weekday' => app_weekday_name((int) $date->format('N'), $capital),
        'day' => app_language_root(app_locale()) === 'fr' && $day === 1 ? '1er' : (string) $day,
        'month' => app_month_name((int) $date->format('n')),
        'year' => $date->format('Y'),
    ];
    if ($weekday && $year) {
        return __('{weekday} {day} {month} {year}', $values);
    }
    if ($weekday) {
        return __('{weekday} {day} {month}', $values);
    }
    return $year ? __('{day} {month} {year}', $values) : __('{day} {month}', $values);
}

function app_ucfirst(string $text): string
{
    return function_exists('mb_strtoupper') ? mb_strtoupper(mb_substr($text, 0, 1)) . mb_substr($text, 1) : ucfirst($text);
}

// Fixed lists of labels, translated (same keys as the constants, which keep the French labels and
// stay used for validation): attendance answers and filters, roles.
function app_translated(array $labels): array
{
    return array_map(static fn (string $label): string => __($label), $labels);
}

// Statuses of the dates: the labels set in Configuration > Statuts (settings.json, "status_labels"),
// otherwise those of EVENT_STATUSES. Content of the association: never translated.
function app_statuses(): array
{
    static $labels = null;
    if ($labels === null) {
        $stored = read_json_file(app_data_path('settings.json'))['status_labels'] ?? [];
        $labels = EVENT_STATUSES;
        foreach (is_array($stored) ? $stored : [] as $status => $label) {
            if (isset(EVENT_STATUSES[$status]) && is_string($label) && trim($label) !== '') {
                $labels[$status] = trim($label);
            }
        }
    }
    return $labels;
}

const STATUS_LABEL_MAX_LENGTH = 40;

function app_attendance_responses(): array
{
    return app_translated(ATTENDANCE_RESPONSES);
}

function app_attendance_filters(): array
{
    return app_translated(ATTENDANCE_FILTERS);
}

function app_no_response_label(): string
{
    return __(ATTENDANCE_NO_RESPONSE_LABEL);
}

function app_roles(): array
{
    return app_translated(USER_ROLES);
}

// Audit journal: its words (yes/no) are recorded in English, whatever the language of the author,
// and shown in the language of the page; entries recorded in French before are shown the same
// way. Statuses are recorded as codes (app_journal_value()).
const LOG_YES_NO = ['Non', 'Oui'];
// Former status of the dates, still found in older entries.
const LOG_LEGACY_WORDS = ['Annulé-Refusé'];

function app_in_english(string $text): string
{
    $translated = app_translations('en')[$text] ?? '';
    return $translated !== '' ? $translated : $text;
}

// A recorded value of a field: a status code with its current label, otherwise app_from_journal().
function app_journal_value(string $field, string $value): string
{
    if ($field === 'status' && isset(app_statuses()[$value])) {
        return app_statuses()[$value];
    }
    return app_from_journal($value);
}

// A recorded word (English, or French for older entries) in the language of the page.
function app_from_journal(string $text): string
{
    static $french = null;
    if ($french === null) {
        $french = [];
        foreach (app_translations('en') as $source => $english) {
            $french[$english] ??= $source;
        }
    }
    return __($french[$text] ?? $text);
}

// Texts used by the scripts (menu.js…), given to them in the language of the page.
const JS_TEXTS = [
    'Afficher',
    'Masquer',
    'Afficher le mot de passe',
    'Masquer le mot de passe',
    'Fermer',
    'Photo de {name}',
];

function app_js_translations(): array
{
    $translations = [];
    foreach (JS_TEXTS as $text) {
        $translated = __($text);
        if ($translated !== $text) {
            $translations[$text] = $translated;
        }
    }
    return $translations;
}
