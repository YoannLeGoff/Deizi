<?php
declare(strict_types=1);

// Languages of the interface. French is the language the application is written in: its texts are
// the keys of the translations, kept in lang/<code>.php ("French text" => "translation"). A text
// without translation is shown in French. Placeholders are written {name} and given to __().
// The language of a page: the member's own choice (Mon compte), otherwise the default language of
// the site (Identité du site); before logging in, the language of the browser when available.
const LANGUAGES = [
    'fr' => 'Français',
    'en' => 'English',
    'br' => 'Brezhoneg',
    'es' => 'Español',
];
const DEFAULT_LANGUAGE = 'fr';

function app_is_language(mixed $code): bool
{
    return is_string($code) && isset(LANGUAGES[$code]);
}

// Default language of the site, set in Configuration > Identité du site.
function app_site_language(): string
{
    $language = read_json_file(app_data_path('settings.json'))['site']['language'] ?? null;
    return app_is_language($language) ? $language : DEFAULT_LANGUAGE;
}

// Preferred language of the browser among LANGUAGES ("Accept-Language"), or null.
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
        $code = strtolower(substr(trim($pieces[0]), 0, 2));
        $weight = 1.0;
        foreach (array_slice($pieces, 1) as $parameter) {
            if (str_starts_with(trim($parameter), 'q=')) {
                $weight = (float) substr(trim($parameter), 2);
            }
        }
        if (app_is_language($code) && $weight > $bestWeight) {
            $best = $code;
            $bestWeight = $weight;
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

// Translations of a language: "French text" => "translated text".
function app_translations(string $language): array
{
    static $loaded = [];
    if (!isset($loaded[$language])) {
        $file = dirname(__DIR__) . '/lang/' . $language . '.php';
        $translations = $language !== DEFAULT_LANGUAGE && is_file($file) ? require $file : [];
        $loaded[$language] = is_array($translations) ? $translations : [];
    }
    return $loaded[$language];
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
    return $date->format(app_locale() === 'fr' ? 'H\hi' : 'H:i');
}

// "Dimanche 8 novembre 2026" in the language of the page ({weekday} {day} {month} {year}, an
// order each language sets); without the weekday or the year when asked. French writes "1er".
function app_long_date(DateTimeInterface $date, bool $weekday = true, bool $year = true, bool $capital = true): string
{
    $day = (int) $date->format('j');
    $values = [
        'weekday' => app_weekday_name((int) $date->format('N'), $capital),
        'day' => app_locale() === 'fr' && $day === 1 ? '1er' : (string) $day,
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
// stay used for validation): statuses of the dates, attendance answers and filters, roles.
function app_translated(array $labels): array
{
    return array_map(static fn (string $label): string => __($label), $labels);
}

function app_statuses(): array
{
    return app_translated(EVENT_STATUSES);
}

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
