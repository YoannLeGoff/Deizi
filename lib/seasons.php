<?php
declare(strict_types=1);

// Seasons of the group: a season starts every year on the same day (1 September by default) and
// ends the day before the next start. Administrators set that day in Configuration > Saisons
// (data/settings.json, key "season": start_day, start_month). Used to group the dates by season.

const SEASON_DEFAULT_START = ['day' => 1, 'month' => 9];
const SEASON_MONTHS = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

// First day of the seasons: ['day' => …, 'month' => …]. A 29 February start is not accepted.
function app_season_start(): array
{
    static $start = null;
    if ($start !== null) {
        return $start;
    }
    $stored = read_json_file(app_data_path('settings.json'))['season'] ?? null;
    $day = is_array($stored) && is_int($stored['start_day'] ?? null) ? $stored['start_day'] : null;
    $month = is_array($stored) && is_int($stored['start_month'] ?? null) ? $stored['start_month'] : null;
    $start = $day !== null && $month !== null && app_season_start_is_valid($day, $month)
        ? ['day' => $day, 'month' => $month]
        : SEASON_DEFAULT_START;
    return $start;
}

function app_season_start_is_valid(int $day, int $month): bool
{
    return $month >= 1 && $month <= 12 && $day >= 1 && checkdate($month, $day, 2023);
}

// Season of a day: key (year of its first day), label ("Saison 2026-2027", or "Saison 2026" when
// seasons start on 1 January), first and last day.
function app_season_of(DateTimeImmutable $date, ?array $start = null): array
{
    $start ??= app_season_start();
    $year = (int) $date->format('Y');
    $startThisYear = $date->setDate($year, $start['month'], $start['day'])->setTime(0, 0);
    $firstYear = $date->setTime(0, 0) < $startThisYear ? $year - 1 : $year;
    $first = $date->setDate($firstYear, $start['month'], $start['day'])->setTime(0, 0);
    $last = $first->modify('+1 year')->modify('-1 day');
    $calendarYear = $start['day'] === 1 && $start['month'] === 1;
    return [
        'key' => (string) $firstYear,
        'label' => 'Saison ' . $firstYear . ($calendarYear ? '' : '-' . ($firstYear + 1)),
        'first' => $first,
        'last' => $last,
    ];
}

// "du 1 septembre 2026 au 31 août 2027"
function app_season_range(array $season): string
{
    $format = static fn (DateTimeImmutable $day): string => $day->format('j') . ($day->format('j') === '1' ? 'er' : '') . ' ' . SEASON_MONTHS[(int) $day->format('n')] . ' ' . $day->format('Y');
    return 'du ' . $format($season['first']) . ' au ' . $format($season['last']);
}
