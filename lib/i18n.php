<?php
declare(strict_types=1);

// Languages of the interface. Each text of the interface has a key ("mon_compte"), given to __();
// lang/<code>.php holds the texts of a language ("key" => "text"), lang/fr.php having all of
// them (French is the language of reference). A text missing in a language is shown in French.
// Placeholders are written {name} and given to __().
// The language of a page: the member's own choice (Mon compte), otherwise the default language of
// the site (Configuration > Langues); before logging in, the language of the browser when available.
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

// Default language of the site, set in Configuration > Langues.
function app_site_language(): string
{
    $language = read_json_file(app_data_path('settings.json'))['site']['language'] ?? null;
    return app_is_language($language) ? $language : DEFAULT_LANGUAGE;
}

// Default language of a new installation: on the first login of its only account (the
// administrator created by setup-admin.php), the language of their browser becomes the language of
// the site, unless one is already set. An installation with members is never changed.
function app_adopt_site_language(array $user): void
{
    if (($user['role'] ?? '') !== 'admin') {
        return;
    }
    $language = app_browser_language();
    if ($language === null || count(read_json_file(app_data_path('users.json'))) !== 1) {
        return;
    }
    update_json_file(app_data_path('settings.json'), static function (array $settings) use ($language): array {
        if (!app_is_language($settings['site']['language'] ?? null)) {
            $settings['site'] = (is_array($settings['site'] ?? null) ? $settings['site'] : []);
            $settings['site']['language'] = $language;
        }
        return $settings;
    });
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

// Texts of a language: "key" => "text": those of the code (lang/<code>.php), or for a created
// language those of its base, with the corrections made in Configuration > Langues
// (data/translations-<code>.json) over them.
function app_translations(string $language, bool $refresh = false): array
{
    static $loaded = [];
    if (!isset($loaded[$language]) || $refresh) {
        $inherited = app_inherited_translations($language);
        $overrides = app_is_language($language) ? read_json_file(app_data_path('translations-' . $language . '.json')) : [];
        $loaded[$language] = array_merge($inherited, array_filter($overrides, static fn (mixed $value): bool => is_string($value) && $value !== ''));
    }
    return $loaded[$language];
}

// Texts a language starts from: lang/<code>.php for a language of the code, the texts of its
// base for a created language.
function app_inherited_translations(string $language): array
{
    $custom = app_custom_languages();
    if (isset($custom[$language])) {
        return app_translations($custom[$language]['base']);
    }
    return isset(LANGUAGES[$language]) ? app_code_texts($language) : [];
}

// lang/<code>.php as shipped with the code.
function app_code_texts(string $language): array
{
    static $files = [];
    if (!isset($files[$language])) {
        $file = dirname(__DIR__) . '/lang/' . $language . '.php';
        $texts = isset(LANGUAGES[$language]) && is_file($file) ? require $file : [];
        $files[$language] = is_array($texts) ? $texts : [];
    }
    return $files[$language];
}

// Key of a French label of the code (the fixed lists of the code, such as roles or months, keep
// their French labels), or null.
function app_label_key(string $french): ?string
{
    static $byFrench = null;
    if ($byFrench === null) {
        $byFrench = [];
        foreach (app_code_texts(DEFAULT_LANGUAGE) as $key => $value) {
            $byFrench[str_replace("\u{00A0}", ' ', $value)] ??= $key;
        }
    }
    return $byFrench[str_replace("\u{00A0}", ' ', $french)] ?? null;
}

// A French label of the code in the language of the page; any other text is shown as it is.
function app_label(string $french, array $values = []): string
{
    $key = app_label_key($french);
    return $key !== null ? __($key, $values) : $french;
}

// Text of $key in the language of the page (French when the language lacks it), with its
// {placeholders} replaced by $values.
function __(string $key, array $values = []): string
{
    $translated = app_translations(app_locale())[$key] ?? '';
    if ($translated === '') {
        $translated = app_translations(DEFAULT_LANGUAGE)[$key] ?? $key;
    }
    if ($values !== []) {
        $translated = strtr($translated, array_combine(
            array_map(static fn (string|int $key): string => '{' . $key . '}', array_keys($values)),
            array_map(static fn (mixed $value): string => (string) $value, array_values($values))
        ));
    }
    return $translated;
}

// Text of $key in the default language of the site, whoever reads it: for the documents the
// association sends (quotes), whose fixed labels go with their content.
function app_site_text(string $key, array $values = []): string
{
    $current = app_locale();
    app_locale(app_site_language());
    $text = __($key, $values);
    app_locale($current);
    return $text;
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
    $name = app_label(WEEKDAY_NAMES[$day] ?? '');
    return $capital ? app_ucfirst($name) : $name;
}

function app_month_name(int $month, bool $capital = false): string
{
    $name = app_label(MONTH_NAMES[$month] ?? '');
    return $capital ? app_ucfirst($name) : $name;
}

const SHORT_WEEKDAY_NAMES = ['', 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.', 'dim.'];

// "lun." (1 = Monday) in the language of the page.
function app_weekday_short(int $day): string
{
    return app_label(SHORT_WEEKDAY_NAMES[$day] ?? '');
}

const SHORT_MONTH_NAMES = ['', 'janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];

function app_month_short(int $month): string
{
    // Their own keys: "mars", "mai", "juin" and "août" are also the full names in French.
    return match ($month) {
        1 => __('janv_phrase'), 2 => __('fevr_phrase'), 3 => __('mars_court'), 4 => __('avr_phrase'),
        5 => __('mai_court'), 6 => __('juin_court'), 7 => __('juil_phrase'), 8 => __('aout_court'),
        9 => __('sept_phrase'), 10 => __('oct_phrase'), 11 => __('nov_phrase'), 12 => __('dec_phrase'),
        default => '',
    };
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
        return __('format_weekday_day_month_year', $values);
    }
    if ($weekday) {
        return __('format_weekday_day_month', $values);
    }
    return $year ? __('format_day_month_year', $values) : __('format_day_month', $values);
}

function app_ucfirst(string $text): string
{
    return function_exists('mb_strtoupper') ? mb_strtoupper(mb_substr($text, 0, 1)) . mb_substr($text, 1) : ucfirst($text);
}

// Fixed lists of labels, translated (same keys as the constants, which keep the French labels and
// stay used for validation): attendance answers and filters, roles.
function app_translated(array $labels): array
{
    return array_map(static fn (string $label): string => app_label($label), $labels);
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
    return app_label(ATTENDANCE_NO_RESPONSE_LABEL);
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
    $key = app_label_key($text);
    $translated = $key !== null ? app_code_texts('en')[$key] ?? '' : '';
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
    static $byEnglish = null;
    if ($byEnglish === null) {
        $byEnglish = array_flip(array_reverse(app_code_texts('en'), true));
    }
    return isset($byEnglish[$text]) ? __($byEnglish[$text]) : app_label($text);
}

// Texts used by the scripts (menu.js…), given to them in the language of the page.
const JS_TEXTS = [
    'afficher',
    'masquer',
    'afficher_mot_passe',
    'masquer_mot_passe',
    'fermer',
    'photo_name',
    // Menu, forms, notifications and announcements (menu.js).
    'ouvrir_menu',
    'fermer_menu',
    'tout_monde',
    'js_date_fin_posterieure',
    'js_cochez_section_geree',
    'js_lien_copie',
    'js_n_sur_total_affiche',
    'js_n_sur_total_affiches',
    'js_notifications_installez_application',
    'js_notifications_navigateur',
    'js_notifications_bloquees',
    'js_notifications_activees',
    'js_notifications_non_activees',
    'js_activation_echouee',
    'js_activation_echouee_erreur',
    'js_notifications_indisponibles_cloche',
    'js_notifications_indisponibles',
    'js_annonce_visible_par',
    'js_annonce_personne',
    'js_annonce_du_au',
    'js_annonce_du_sans_fin',
    // Attendance grid (attendance-matrix.js).
    'present',
    'absent',
    'je_sais',
    'sans_reponse',
    'matrice_enregistrement_en_cours',
    'matrice_case_label',
    'matrice_non_modifiable',
    'matrice_cliquer_changer',
    'matrice_enregistrement',
    'matrice_derniere_enregistree',
    'matrice_enregistrement_automatique',
    'matrice_session_expiree',
    'matrice_reponse_non_enregistree',
    'matrice_connexion_impossible',
];

function app_js_translations(): array
{
    $translations = [];
    foreach (JS_TEXTS as $key) {
        $translations[$key] = __($key);
    }
    return $translations;
}
