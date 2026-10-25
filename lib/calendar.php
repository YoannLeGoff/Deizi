<?php
declare(strict_types=1);

// Public holidays and school holidays shown in the annual planning, administered from the
// Configuration. Stored in data/calendar.json:
// {"disabled_holidays": [ruleKey], "custom_holidays": [{"id", "date", "label", "recurring"}],
//  "school_holidays": [{"id", "label", "start", "end"}]}.

// French public holidays: fixed day ("MM-DD") or offset in days from Easter Sunday.
const PUBLIC_HOLIDAY_RULES = [
    'new_year' => ['Jour de l’an', '01-01'],
    'easter_monday' => ['Lundi de Pâques', 1],
    'labour_day' => ['Fête du Travail', '05-01'],
    'victory_1945' => ['Victoire 1945', '05-08'],
    'ascension' => ['Ascension', 39],
    'whit_monday' => ['Lundi de Pentecôte', 50],
    'bastille_day' => ['Fête nationale', '07-14'],
    'assumption' => ['Assomption', '08-15'],
    'all_saints' => ['Toussaint', '11-01'],
    'armistice' => ['Armistice 1918', '11-11'],
    'christmas' => ['Noël', '12-25'],
];
const SCHOOL_HOLIDAY_NAMES = [
    'Vacances de la Toussaint',
    'Vacances de Noël',
    'Vacances d’hiver',
    'Vacances de printemps',
    'Pont de l’Ascension',
    'Vacances d’été',
];
const SCHOOL_HOLIDAY_MAX_DAYS = 120;

function app_calendar_settings(): array
{
    $stored = read_json_file(app_data_path('calendar.json'));
    $custom = [];
    foreach (is_array($stored['custom_holidays'] ?? null) ? $stored['custom_holidays'] : [] as $holiday) {
        $date = app_parse_date($holiday['date'] ?? null);
        $label = is_string($holiday['label'] ?? null) ? trim($holiday['label']) : '';
        if (is_string($holiday['id'] ?? null) && $date !== null && $label !== '') {
            $custom[] = ['id' => $holiday['id'], 'date' => $date, 'label' => $label, 'recurring' => ($holiday['recurring'] ?? false) === true];
        }
    }
    usort($custom, static fn (array $first, array $second): int => $first['date']->format('m-d') <=> $second['date']->format('m-d'));
    $school = [];
    foreach (is_array($stored['school_holidays'] ?? null) ? $stored['school_holidays'] : [] as $period) {
        $start = app_parse_date($period['start'] ?? null);
        $end = app_parse_date($period['end'] ?? null);
        $label = is_string($period['label'] ?? null) ? trim($period['label']) : '';
        if (is_string($period['id'] ?? null) && $start !== null && $end !== null && $end >= $start && $label !== '') {
            $school[] = ['id' => $period['id'], 'label' => $label, 'start' => $start, 'end' => $end];
        }
    }
    usort($school, static fn (array $first, array $second): int => $first['start'] <=> $second['start']);
    return [
        'disabled_holidays' => app_clean_choices($stored['disabled_holidays'] ?? [], PUBLIC_HOLIDAY_RULES),
        'custom_holidays' => $custom,
        'school_holidays' => $school,
    ];
}

// Applies $update to the raw content of data/calendar.json.
function app_update_calendar_settings(callable $update): void
{
    update_json_file(app_data_path('calendar.json'), static fn (array $data): array => $update($data));
}

function app_easter_sunday(int $year): DateTimeImmutable
{
    // Anonymous Gregorian algorithm.
    $golden = $year % 19;
    $century = intdiv($year, 100);
    $h = ($century - intdiv($century, 4) - intdiv(8 * $century + 13, 25) + 19 * $golden + 15) % 30;
    $i = $h - intdiv($h, 28) * (1 - intdiv($h, 28) * intdiv(29, $h + 1) * intdiv(21 - $golden, 11));
    $j = ($year + intdiv($year, 4) + $i + 2 - $century + intdiv($century, 4)) % 7;
    $day = 28 + $i - $j;
    return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $day > 31 ? 4 : 3, $day > 31 ? $day - 31 : $day), new DateTimeZone('Europe/Paris'));
}

function app_public_holiday_rule_date(string $rule, int $year): DateTimeImmutable
{
    $when = PUBLIC_HOLIDAY_RULES[$rule][1];
    return is_int($when)
        ? app_easter_sunday($year)->modify('+' . $when . ' days')
        : new DateTimeImmutable($year . '-' . $when, new DateTimeZone('Europe/Paris'));
}

// Public holidays of a year: active legal holidays and custom days, ["Y-m-d" => label].
function app_public_holidays(int $year, ?array $settings = null): array
{
    $settings ??= app_calendar_settings();
    $holidays = [];
    foreach (PUBLIC_HOLIDAY_RULES as $rule => [$label]) {
        if (!in_array($rule, $settings['disabled_holidays'], true)) {
            $holidays[app_public_holiday_rule_date($rule, $year)->format('Y-m-d')] = $label;
        }
    }
    foreach ($settings['custom_holidays'] as $holiday) {
        if ($holiday['recurring']) {
            if (checkdate((int) $holiday['date']->format('m'), (int) $holiday['date']->format('d'), $year)) {
                $holidays[$year . '-' . $holiday['date']->format('m-d')] = $holiday['label'];
            }
        } elseif ((int) $holiday['date']->format('Y') === $year) {
            $holidays[$holiday['date']->format('Y-m-d')] = $holiday['label'];
        }
    }
    ksort($holidays);
    return $holidays;
}

// School holiday days of a year: ["Y-m-d" => period name].
function app_school_holiday_days(int $year, ?array $settings = null): array
{
    $settings ??= app_calendar_settings();
    $days = [];
    foreach ($settings['school_holidays'] as $period) {
        for ($day = $period['start']; $day <= $period['end']; $day = $day->modify('+1 day')) {
            if ((int) $day->format('Y') === $year) {
                $days[$day->format('Y-m-d')] = $period['label'];
            }
        }
    }
    return $days;
}
