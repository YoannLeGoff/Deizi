<?php
declare(strict_types=1);

// "Vues avancées" (menu "Vues"), for every member, one tab per view over 12 rolling months:
// the list of the dates, and the planning (one column per month, one page per six months).
// As on the agenda, artists only see the dates and sections that concern them.
require __DIR__ . '/lib/bootstrap.php';
$user = app_require_login();

const REPORTING_VIEWS = [
    'liste' => 'Liste des dates',
    'planning' => 'Planning annuel',
];

$timezone = new DateTimeZone('Europe/Paris');
$today = new DateTimeImmutable('today', $timezone);
$viewInput = $_GET['vue'] ?? '';
$view = is_string($viewInput) && isset(REPORTING_VIEWS[$viewInput]) ? $viewInput : 'liste';

// Period: 12 months from "debut" (YYYY-MM), from January of "annee" for older links, or from the current month.
$currentMonthStart = $today->modify('first day of this month');
$periodStart = $currentMonthStart;
$startInput = $_GET['debut'] ?? '';
$yearInput = $_GET['annee'] ?? '';
if (is_string($startInput) && preg_match('/^(\d{4})-(\d{2})$/', $startInput, $match) === 1 && (int) $match[1] >= 2000 && (int) $match[1] <= 2100 && (int) $match[2] >= 1 && (int) $match[2] <= 12) {
    $periodStart = new DateTimeImmutable($match[1] . '-' . $match[2] . '-01', $timezone);
} elseif (is_string($yearInput) && preg_match('/^\d{4}$/', $yearInput) === 1 && (int) $yearInput >= 2000 && (int) $yearInput <= 2100) {
    $periodStart = new DateTimeImmutable($yearInput . '-01-01', $timezone);
}
$periodEnd = $periodStart->modify('+12 months')->modify('-1 day');
$months = [];
for ($index = 0; $index < 12; $index++) {
    $first = $periodStart->modify('+' . $index . ' months');
    $months[$first->format('Y-m')] = $first;
}

// Filters shared by both tabs: statuses and sections, all checked by default except leads, cancelled and refused dates.
$sectionLabels = app_section_labels();
$tuttiColor = app_tutti_color();
// Every member sees the dates of all sections here (reserved dates aside); only the codes of the
// annual planning and the links to the date pages depend on their sub-sections.
$allLeaves = app_section_leaves();
$filterTree = [];
foreach (app_section_tree() as $branch => $definition) {
    $children = array_intersect_key($definition['children'], array_flip($allLeaves));
    if ($children !== []) {
        $filterTree[$branch] = ['children' => $children] + $definition;
    }
}
$defaultStatuses = array_values(array_diff(array_keys(EVENT_STATUSES), ['lead', 'cancelled', 'refused']));
$filterActive = isset($_GET['filtre']);
$selectedStatuses = $filterActive ? app_clean_choices($_GET['statut'] ?? [], EVENT_STATUSES) : $defaultStatuses;
// Sections: a sub-section box shows the dates concerning it; a branch box ("section mère") shows the
// dates of the whole branch (all its sub-sections), independently of its sub-section boxes.
// By default: the member's own sub-sections and their branches (everything for an account without
// section), plus the "Information" dates of every section.
$allBranches = array_keys($filterTree);
$memberLeaves = array_values(array_intersect($allLeaves, app_expand_sections(app_user_sections($user))));
$defaultLeaves = $memberLeaves !== [] ? $memberLeaves : $allLeaves;
$defaultBranches = $memberLeaves !== []
    ? array_values(array_intersect($allBranches, array_map('app_section_branch', $memberLeaves)))
    : $allBranches;
$sectionInput = $filterActive && is_array($_GET['section'] ?? null) ? array_filter($_GET['section'], 'is_string') : [];
$selectedLeaves = $filterActive ? array_values(array_intersect($allLeaves, $sectionInput)) : $defaultLeaves;
$selectedBranches = $filterActive ? array_values(array_intersect($allBranches, $sectionInput)) : $defaultBranches;
// "Information" dates of every section, whatever the section boxes.
$showAllInformation = !$filterActive || ($_GET['infos'] ?? '') === '1';
// Reserved dates (shown only to those who may see them, app_user_can_see_event()) can be left out.
$showReserved = !$filterActive || ($_GET['reservees'] ?? '') === '1';
$isDefaultFilter = $selectedStatuses === $defaultStatuses && $selectedLeaves === $defaultLeaves
    && $selectedBranches === $defaultBranches && $showAllInformation && $showReserved;
// Whether a date passes the section filter.
$matchesSections = static function (array $event) use ($selectedLeaves, $selectedBranches, $showAllInformation): bool {
    $leaves = app_event_leaves($event);
    if (array_intersect($leaves, $selectedLeaves) !== []) {
        return true;
    }
    if ($showAllInformation && ($event['status'] ?? null) === 'information') {
        return true;
    }
    foreach ($selectedBranches as $branch) {
        if (array_diff(array_keys(app_section_tree()[$branch]['children']), $leaves) === []) {
            return true;
        }
    }
    return false;
};

// Dates of the period (a date over several days counts if one of its days falls in it),
// and the same dates by day ("Y-m-d" => list) for the planning.
$periodEvents = [];
$eventsByDay = [];
$statusCounts = array_fill_keys(array_keys(EVENT_STATUSES), 0);
$monthCounts = array_fill_keys(array_keys($months), 0);
$sourceEvents = read_json_file(app_data_path('events.json'));
// Codes written in the planning labels: every concerned section for organizers and administrators;
// for the others, only their own concerned sub-sections (no code when none is theirs).
$seesAllCodes = in_array($user['role'], ALL_SECTIONS_ROLES, true);
$seenState = app_seen_state($user);
// Sorted first so that several dates on the same day appear by start time in the planning cells.
usort($sourceEvents, 'app_compare_events');
foreach ($sourceEvents as $event) {
    $start = is_array($event) ? app_parse_date($event['date'] ?? null) : null;
    if ($start === null || !is_string($event['id'] ?? null) || !is_string($event['title'] ?? null)) {
        continue;
    }
    if (
        !$matchesSections($event)
        || (app_event_is_reserved($event) && (!$showReserved || !app_user_can_see_event($user, $event)))
    ) {
        continue;
    }
    $end = app_parse_date($event['end_date'] ?? null);
    $end = $end !== null && $end >= $start ? $end : $start;
    if ($end < $periodStart || $start > $periodEnd) {
        continue;
    }
    $status = is_string($event['status'] ?? null) && isset(EVENT_STATUSES[$event['status']]) ? $event['status'] : 'pending';
    // Every status is counted in the summary, even those hidden by the filter.
    $statusCounts[$status]++;
    if (!in_array($status, $selectedStatuses, true)) {
        continue;
    }
    $displaySections = app_section_display_items(app_event_leaves($event));
    $isTutti = ($event['tutti'] ?? false) === true;
    $codedSections = $isTutti ? [] : ($seesAllCodes
        ? $displaySections
        : app_section_display_items(array_values(array_intersect(app_event_leaves($event), $memberLeaves))));
    $location = is_string($event['location'] ?? null) ? trim($event['location']) : '';
    // Planning label, e.g. "[B+b.ig] Pontivy, Fêtes de la musique".
    $label = ($isTutti ? '[Tutti] ' : ($codedSections !== [] ? '[' . implode('+', array_map('app_section_code', $codedSections)) . '] ' : ''))
        . ($location !== '' ? $location . ', ' : '') . $event['title'];
    // Dates of other sub-sections are listed for artists, without a link to a page they cannot open.
    $item = ['id' => $event['id'], 'title' => $event['title'], 'status' => $status, 'label' => $label, 'linked' => app_user_can_see_event($user, $event)];
    // "Nouveau" / "Modifié" only on the dates the member can open.
    $item['change'] = $item['linked'] ? app_event_change_notice($event, $user, $seenState) : null;
    $shown = false;
    for ($day = max($start, $periodStart); $day <= min($end, $periodEnd, $start->modify('+60 days')); $day = $day->modify('+1 day')) {
        // The label is written on the first day of the date within the period; following days are only coloured.
        $eventsByDay[$day->format('Y-m-d')][] = $item + ['first' => !$shown];
        $shown = true;
    }
    $listedMonth = max($start, $periodStart)->format('Y-m');
    $monthCounts[$listedMonth]++;
    $periodEvents[] = $item + [
        'start' => $start,
        'sort' => app_event_sort_key($event),
        'end' => $end > $start ? $end : null,
        'month' => $listedMonth,
        'all_day' => ($event['all_day'] ?? false) === true,
        'time' => app_event_time_range($event),
        'location' => $location,
        'sections' => $displaySections,
        // Sections whose code is written in the planning label (none for a "Tutti" date).
        'coded_sections' => $codedSections,
        'tutti' => $isTutti,
    ];
}
usort($periodEvents, static fn (array $first, array $second): int => $first['sort'] <=> $second['sort']);
$totalEvents = count($periodEvents);

// Public holidays and school holidays of the years covered, administered from the Configuration.
$calendarSettings = app_calendar_settings();
$holidays = [];
$schoolHolidayDays = [];
foreach (array_unique([(int) $periodStart->format('Y'), (int) $periodEnd->format('Y')]) as $coveredYear) {
    $holidays += app_public_holidays($coveredYear, $calendarSettings);
    $schoolHolidayDays += app_school_holiday_days($coveredYear, $calendarSettings);
}

// Month titles of the planning: in French, bilingual Breton / French as on the group's paper
// planning; texts of their own, so that each installation can adapt them (Configuration > Langues).
$monthNames = ['', __('planning_mois_1'), __('planning_mois_2'), __('planning_mois_3'), __('planning_mois_4'), __('planning_mois_5'), __('planning_mois_6'),
    __('planning_mois_7'), __('planning_mois_8'), __('planning_mois_9'), __('planning_mois_10'), __('planning_mois_11'), __('planning_mois_12')];
// In the planning, the year is shown on the first month of each page and on January.
$monthTitle = static fn (DateTimeImmutable $month, bool $withYear): string => $monthNames[(int) $month->format('n')] . ($withYear ? ' ' . $month->format('Y') : '');
$monthYear = static fn (DateTimeImmutable $month): string => app_month_name((int) $month->format('n')) . ' ' . $month->format('Y');
$periodLabel = $monthYear($periodStart) . ' – ' . $monthYear($periodEnd);
// Links keep the period and, when they differ from the defaults, the filters.
$filterQuery = $isDefaultFilter ? [] : ['filtre' => '1', 'statut' => $selectedStatuses, 'section' => [...$selectedBranches, ...$selectedLeaves]]
    + ($showAllInformation ? ['infos' => '1'] : []) + ($showReserved ? ['reservees' => '1'] : []);
$reportUrl = static fn (string $targetView, DateTimeImmutable $targetStart): string => 'reporting.php?'
    . http_build_query(['vue' => $targetView, 'debut' => $targetStart->format('Y-m')] + $filterQuery);
$sectionSummary = $selectedLeaves === $allLeaves && $selectedBranches === $allBranches
    ? __('toutes_sections_2')
    : ($selectedLeaves === [] && $selectedBranches === []
        ? __('aucune_section_2')
        : implode(', ', [
            ...array_map(static fn (string $id): string => __('section_toute_branche_2', ['section' => $sectionLabels[$id]]), $selectedBranches),
            ...array_map(static fn (string $id): string => $sectionLabels[$id], $selectedLeaves),
        ]));
$sectionSummary .= $showAllInformation && ($selectedLeaves !== $allLeaves) ? __('dates_information_de_toutes_les_sections') : '';
$sectionSummary .= $showReserved ? '' : __('sans_dates_reservees');

// Names of the views, in the language of the page.
$viewLabel = static fn (string $view): string => $view === 'planning' ? __('planning_annuel') : __('liste_dates');

app_header(__('vues_avancees_view', ['view' => $viewLabel($view)]), $user);
?>
      <section class="management-section reporting">
        <div class="eyebrow-row">
          <p class="eyebrow"><span class="eyebrow-line"></span> <?= app_escape(__('agenda_majuscules')) ?></p>
          <?php app_page_links([['Retour à l’agenda', 'index.php']]); ?>
        </div>
        <div class="section-heading">
          <div>
            <h1><?= app_escape(__('vues_avancees_2')) ?><span class="print-only"> — <?= app_escape($viewLabel($view)) ?></span><span class="heading-dot">.</span></h1>
          </div>
          <div class="page-actions">
            <button class="secondary-button compact-button" type="button" data-print><?= app_escape(__('imprimer')) ?></button>
          </div>
        </div>

        <div class="planning-toolbar">
          <nav class="planning-years" aria-label="<?= app_escape(__('changer_periode')) ?>">
            <a class="secondary-button compact-button" href="<?= app_escape($reportUrl($view, $periodStart->modify('-12 months'))) ?>" title="<?= app_escape(__('reculer_dun_an')) ?>"><?= app_escape(__('1_an_2')) ?></a>
            <a class="secondary-button compact-button" href="<?= app_escape($reportUrl($view, $periodStart->modify('-1 month'))) ?>" title="<?= app_escape(__('reculer_dun_mois')) ?>"><?= app_escape(__('1_mois_2')) ?></a>
            <strong class="planning-year planning-period"><?= app_escape($periodLabel) ?></strong>
            <a class="secondary-button compact-button" href="<?= app_escape($reportUrl($view, $periodStart->modify('+1 month'))) ?>" title="<?= app_escape(__('avancer_dun_mois')) ?>"><?= app_escape(__('1_mois')) ?></a>
            <a class="secondary-button compact-button" href="<?= app_escape($reportUrl($view, $periodStart->modify('+12 months'))) ?>" title="<?= app_escape(__('avancer_dun_an')) ?>"><?= app_escape(__('1_an')) ?></a>
            <?php if ($periodStart != $currentMonthStart): ?>
            <a class="section-note page-switch planning-reset" href="<?= app_escape($reportUrl($view, $currentMonthStart)) ?>"><?= app_escape(__('revenir_mois_cours')) ?></a>
            <?php endif; ?>
          </nav>
        </div>

        <?php // Kept open after a change of the filter (each box applies it at once, see menu.js). ?>
        <details class="report-filters"<?= isset($_GET['ouvert']) ? ' open' : '' ?>>
          <summary>
            <span class="report-filters-title"><?= app_escape(__('filtres')) ?></span>
            <span class="report-filters-summary"><?= app_escape(__n('statut_total', 'statuts_total', count($selectedStatuses), ['total' => count(EVENT_STATUSES)])) ?> · <?= app_escape($sectionSummary) ?></span>
          </summary>
          <form class="report-filters-form" method="get" action="reporting.php" data-autosubmit>
            <input type="hidden" name="ouvert" value="1">
            <input type="hidden" name="vue" value="<?= $view ?>">
            <input type="hidden" name="debut" value="<?= $periodStart->format('Y-m') ?>">
            <input type="hidden" name="filtre" value="1">
            <fieldset class="report-filter-row">
              <legend class="report-filter-label"><?= app_escape(__('statut_label')) ?></legend>
              <div class="report-filter-options">
                <?php foreach (app_statuses() as $status => $label): ?>
                <label class="section-assignment-option"><input type="checkbox" name="statut[]" value="<?= $status ?>"<?= in_array($status, $selectedStatuses, true) ? ' checked' : '' ?>> <span class="planning-dot planning-dot-<?= $status ?>" aria-hidden="true"></span><?= app_escape($label) ?></label>
                <?php endforeach; ?>
              </div>
            </fieldset>
            <fieldset class="report-filter-row">
              <legend class="report-filter-label"><?= app_escape(__('sections_label')) ?></legend>
              <div class="report-filter-branches">
                <?php foreach ($filterTree as $branch => $definition): ?>
                <div class="section-assignment-group report-filter-branch">
                  <label class="section-assignment-option section-assignment-parent" title="<?= app_escape(__('dates_concernant_toute_branche')) ?>"><input type="checkbox" name="section[]" value="<?= app_escape($branch) ?>"<?= in_array($branch, $selectedBranches, true) ? ' checked' : '' ?>> <?= app_escape($definition['label']) ?> <span class="field-optional"><?= app_escape(__('toute_branche_parentheses')) ?></span> :</label>
                  <div class="section-assignment-children">
                    <?php foreach ($definition['children'] as $leaf => $label): ?>
                    <label class="section-assignment-option"><input type="checkbox" name="section[]" value="<?= app_escape($leaf) ?>"<?= in_array($leaf, $selectedLeaves, true) ? ' checked' : '' ?>> <?= app_escape($label) ?></label>
                    <?php endforeach; ?>
                  </div>
                </div>
                <?php endforeach; ?>
              </div>
            </fieldset>
            <fieldset class="report-filter-row">
              <legend class="report-filter-label"><?= app_escape(__('informations_label')) ?></legend>
              <div class="report-filter-options">
                <label class="section-assignment-option"><input type="checkbox" name="infos" value="1"<?= $showAllInformation ? ' checked' : '' ?>> <?= app_escape(__('dates_information_toutes_sections')) ?></label>
              </div>
            </fieldset>
            <fieldset class="report-filter-row">
              <legend class="report-filter-label"><?= app_escape(__('dates_reservees_label')) ?></legend>
              <div class="report-filter-options">
                <label class="section-assignment-option"><input type="checkbox" name="reservees" value="1"<?= $showReserved ? ' checked' : '' ?>> <?= app_escape(__('afficher_dates_reservees')) ?> <span class="field-optional"><?= app_escape(__('seulement_celles_vous_pouvez_voir_parentheses')) ?></span></label>
              </div>
            </fieldset>
            <div class="report-filter-actions">
              <button class="primary-button compact-button" type="submit" data-autosubmit-button><?= app_escape(__('appliquer')) ?></button>
              <?php if (!$isDefaultFilter): ?>
              <a class="section-note page-switch" href="reporting.php?<?= app_escape(http_build_query(['vue' => $view, 'debut' => $periodStart->format('Y-m')])) ?>"><?= app_escape(__('reinitialiser')) ?></a>
              <?php endif; ?>
            </div>
          </form>
        </details>

        <nav class="detail-tabs" aria-label="<?= app_escape(__('rapports')) ?>">
          <?php foreach (array_keys(REPORTING_VIEWS) as $tabView): ?>
          <a class="detail-tab" href="<?= app_escape($reportUrl($tabView, $periodStart)) ?>"<?= $view === $tabView ? ' aria-current="page"' : '' ?>><?= app_escape($viewLabel($tabView)) ?></a>
          <?php endforeach; ?>
        </nav>

        <section class="management-panel detail-panel has-tabs reporting-panel" aria-label="<?= app_escape($viewLabel($view)) ?>">
          <div class="planning-summary">
            <p><?= app_escape(__n('date_affichee_periode_period', 'dates_affichees_periode_period', $totalEvents, ['period' => $periodLabel])) ?> · <?= app_escape($sectionSummary) ?></p>
            <ul class="planning-legend" aria-label="<?= app_escape(__('legende_statuts')) ?>">
              <?php foreach (app_statuses() as $status => $label): ?>
              <li class="<?= in_array($status, $selectedStatuses, true) ? '' : 'is-hidden-status' ?>"<?= in_array($status, $selectedStatuses, true) ? '' : ' title="' . app_escape(__('masque_filtre')) . '"' ?>><span class="planning-dot planning-dot-<?= $status ?>" aria-hidden="true"></span><?= app_escape($label) ?> <span class="planning-legend-count"><?= $statusCounts[$status] ?></span></li>
              <?php endforeach; ?>
            </ul>
          </div>

          <?php if ($view === 'liste'): ?>
          <?php if ($periodEvents === []): ?>
          <p class="muted-copy"><?= app_escape(__('aucune_date_correspond_filtres_periode_period', ['period' => $periodLabel])) ?></p>
          <?php else: ?>
          <div class="log-scroll">
            <table class="report-list">
              <thead>
                <tr>
                  <th scope="col"><?= app_escape(__('date')) ?></th>
                  <th scope="col"><?= app_escape(__('evenement')) ?></th>
                  <th scope="col"><?= app_escape(__('horaire')) ?></th>
                  <th scope="col"><?= app_escape(__('lieu')) ?></th>
                  <th scope="col"><?= app_escape(__('sections')) ?></th>
                  <th scope="col"><?= app_escape(__('statut')) ?></th>
                </tr>
              </thead>
              <?php $currentMonth = null; ?>
              <?php foreach ($periodEvents as $item): ?>
              <?php if ($item['month'] !== $currentMonth): ?>
              <?php if ($currentMonth !== null): ?></tbody><?php endif; ?>
              <?php $currentMonth = $item['month']; ?>
              <tbody>
                <tr class="report-month-row"><th scope="rowgroup" colspan="6"><?= app_escape($monthNames[(int) substr($currentMonth, 5)] . ' ' . substr($currentMonth, 0, 4)) ?> <span><?= app_escape(__n('date_2', 'n_dates', $monthCounts[$currentMonth])) ?></span></th></tr>
              <?php endif; ?>
                <tr class="report-row status-<?= $item['status'] ?>">
                  <td class="report-date">
                    <?= app_escape(app_weekday_short((int) $item['start']->format('N')) . ' ' . $item['start']->format('d/m/Y')) ?>
                    <?php if ($item['end'] !== null): ?><span class="log-sub">→ <?= app_escape(app_weekday_short((int) $item['end']->format('N')) . ' ' . $item['end']->format('d/m/Y')) ?></span><?php endif; ?>
                  </td>
                  <td><?php if ($item['linked']): ?><a class="event-title-link" href="event.php?id=<?= rawurlencode($item['id']) ?>"><?= app_escape($item['title']) ?></a><?php else: ?><?= app_escape($item['title']) ?><?php endif; ?><?= app_change_badge($item['change']) ?></td>
                  <td class="report-nowrap"><?= $item['all_day'] ? app_escape(__('journee_entiere')) : ($item['time'] !== '' ? app_escape($item['time']) : '<span class="log-sub">—</span>') ?></td>
                  <td><?= $item['location'] !== '' ? app_escape($item['location']) : '<span class="log-sub">—</span>' ?></td>
                  <td>
                    <div class="report-sections">
                      <?php if ($item['tutti']): ?>
                      <span class="section-tag tag-tutti tag-<?= app_escape($tuttiColor) ?>"><?= app_escape(__('tutti')) ?></span>
                      <?php else: ?>
                      <?php foreach ($item['sections'] as $sectionId): ?>
                      <span class="section-tag <?= app_escape(app_section_css_class($sectionId)) ?>"><?= app_escape($sectionLabels[$sectionId]) ?></span>
                      <?php endforeach; ?>
                      <?php endif; ?>
                    </div>
                  </td>
                  <td><span class="status-badge status-badge-<?= $item['status'] ?>"><?= app_escape(app_statuses()[$item['status']]) ?></span></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php endif; ?>

          <?php else: ?>
          <?php $weekdayLetters = ['', __('planning_lettre_lundi'), __('planning_lettre_mardi'), __('planning_lettre_mercredi'), __('planning_lettre_jeudi'), __('planning_lettre_vendredi'), __('planning_lettre_samedi'), __('planning_lettre_dimanche')]; ?>
          <?php foreach (array_chunk($months, 6, true) as $pageIndex => $pageMonths): ?>
          <?php $pageFirst = reset($pageMonths); $pageLast = end($pageMonths); ?>
          <section class="semester" aria-label="<?= app_escape($monthYear($pageFirst) . ' – ' . $monthYear($pageLast)) ?>">
            <div class="semester-heading">
              <h2 class="semester-title"><?= app_escape(app_site_title()) ?> <span><?= app_escape($monthYear($pageFirst) . ' – ' . $monthYear($pageLast)) ?></span></h2>
              <ul class="semester-legend" aria-label="<?= app_escape(__('legende')) ?>">
                <li><span class="semester-swatch semester-swatch-confirmed"></span><?= app_escape(app_statuses()['confirmed']) ?></li>
                <li><span class="semester-swatch semester-swatch-lead"></span><?= app_escape(app_statuses()['lead']) ?></li>
                <li><span class="semester-swatch semester-swatch-pending"></span><?= app_escape(app_statuses()['pending']) ?></li>
                <li><span class="semester-swatch semester-swatch-information"></span><?= app_escape(app_statuses()['information']) ?></li>
                <li><span class="semester-swatch semester-swatch-holiday"></span><?= app_escape(__('jour_ferie')) ?></li>
                <li><span class="semester-swatch semester-swatch-school"></span><?= app_escape(__('vacances_scolaires')) ?></li>
              </ul>
            </div>
            <div class="semester-scroll">
              <table class="semester-table">
                <colgroup>
                  <?php // A narrow empty column separates the months. ?>
                  <?php foreach (array_values($pageMonths) as $monthIndex => $monthStart): ?>
                  <?php if ($monthIndex > 0): ?><col class="semester-col-gap"><?php endif; ?>
                  <col class="semester-col-day"><col class="semester-col-events">
                  <?php endforeach; ?>
                </colgroup>
                <thead>
                  <tr>
                    <?php foreach ($pageMonths as $monthKey => $monthStart): ?>
                    <?php if ($monthStart != $pageFirst): ?><td class="semester-gap" aria-hidden="true"></td><?php endif; ?>
                    <th scope="colgroup" colspan="2" class="semester-month semester-month-<?= (int) $monthStart->format('n') ?>"><?= app_escape($monthTitle($monthStart, $monthStart == $pageFirst || $monthStart->format('n') === '1')) ?><?= $monthCounts[$monthKey] > 0 ? ' <span>' . $monthCounts[$monthKey] . '</span>' : '' ?></th>
                    <?php endforeach; ?>
                  </tr>
                </thead>
                <tbody>
                  <?php for ($dayNumber = 1; $dayNumber <= 31; $dayNumber++): ?>
                  <tr>
                    <?php foreach ($pageMonths as $monthStart): ?>
                    <?php if ($monthStart != $pageFirst): ?><td class="semester-gap" aria-hidden="true"></td><?php endif; ?>
                    <?php
                        if ($dayNumber > (int) $monthStart->format('t')) {
                            echo '<td class="semester-day is-empty"></td><td class="semester-events is-empty"></td>';
                            continue;
                        }
                        $day = $monthStart->setDate((int) $monthStart->format('Y'), (int) $monthStart->format('n'), $dayNumber);
                        $dayKey = $day->format('Y-m-d');
                        $dayEvents = $eventsByDay[$dayKey] ?? [];
                        $dayClasses = ['semester-day'];
                        // A solid line closes each month under its last day.
                        $lastDayClass = $dayNumber === (int) $monthStart->format('t') ? ' is-last-day' : '';
                        if ((int) $day->format('N') >= 6) {
                            $dayClasses[] = 'is-weekend';
                        }
                        if (isset($holidays[$dayKey])) {
                            $dayClasses[] = 'is-holiday';
                        }
                        $dayTitle = implode(' · ', array_filter([isset($holidays[$dayKey]) ? app_label($holidays[$dayKey]) : null, $schoolHolidayDays[$dayKey] ?? null]));
                        if ($dayKey === $today->format('Y-m-d')) {
                            $dayClasses[] = 'is-today';
                        }
                        $cellStatus = $dayEvents !== [] ? ' status-' . $dayEvents[0]['status'] : '';
                    ?>
                    <td class="<?= implode(' ', $dayClasses) . $lastDayClass ?>"<?= $dayTitle !== '' ? ' title="' . app_escape($dayTitle) . '"' : '' ?>><span class="semester-weekday"><?= $weekdayLetters[(int) $day->format('N')] ?></span> <span class="semester-day-number"<?= $dayKey === $today->format('Y-m-d') ? ' title="Aujourd’hui"' : '' ?>><?= $dayNumber ?></span></td>
                    <td class="semester-events<?= $cellStatus ?><?= (int) $day->format('N') >= 6 ? ' is-weekend' : '' ?><?= isset($schoolHolidayDays[$dayKey]) ? ' is-school-holiday' : '' ?><?= $lastDayClass ?>"<?= isset($schoolHolidayDays[$dayKey]) ? ' title="' . app_escape($schoolHolidayDays[$dayKey]) . '"' : '' ?>>
                      <?php foreach ($dayEvents as $item): ?>
                      <?php [$tag, $href] = $item['linked'] ? ['a', ' href="event.php?id=' . rawurlencode($item['id']) . '"'] : ['span', '']; ?>
                      <?php if ($item['first']): ?>
                      <<?= $tag ?> class="semester-event status-<?= $item['status'] ?>"<?= $href ?> title="<?= app_escape($item['label'] . ' — ' . app_statuses()[$item['status']]) ?>"><?= app_escape($item['label']) ?></<?= $tag ?>>
                      <?php else: ?>
                      <<?= $tag ?> class="semester-event semester-continued status-<?= $item['status'] ?>"<?= $href ?> title="<?= app_escape(__('label_suite', ['label' => $item['label']]) . ' — ' . app_statuses()[$item['status']]) ?>"><span class="sr-only"><?= app_escape(__('label_suite', ['label' => $item['title']])) ?></span></<?= $tag ?>>
                      <?php endif; ?>
                      <?php endforeach; ?>
                    </td>
                    <?php endforeach; ?>
                  </tr>
                  <?php endfor; ?>
                </tbody>
              </table>
            </div>
            <?php
                // Legend of the codes written in this semester's labels only.
                $usedSections = [];
                foreach ($periodEvents as $periodEvent) {
                    if (isset($pageMonths[$periodEvent['month']])) {
                        $usedSections += array_flip($periodEvent['coded_sections']);
                    }
                }
                $codeGroups = [];
                foreach (app_section_tree() as $branch => $definition) {
                    $codes = isset($usedSections[$branch]) ? ['<b>' . app_escape($definition['code']) . '</b> ' . app_escape($definition['label'])] : [];
                    foreach ($definition['children'] as $leaf => $leafLabel) {
                        if (isset($usedSections[$leaf])) {
                            $codes[] = '<b>' . app_escape($definition['child_codes'][$leaf]) . '</b> ' . app_escape($leafLabel);
                        }
                    }
                    if ($codes !== []) {
                        $codeGroups[] = implode(' · ', $codes);
                    }
                }
            ?>
            <?php if ($codeGroups !== []): ?>
            <p class="semester-codes"><strong><?= app_escape(__('codes_label')) ?></strong>
              <?php foreach ($codeGroups as $codeGroup): ?>
              <span class="semester-code-group"><?= $codeGroup ?></span>
              <?php endforeach; ?>
            </p>
            <?php endif; ?>
          </section>
          <?php endforeach; ?>
          <?php endif; ?>
        </section>
      </section>
<?php app_footer(); ?>
