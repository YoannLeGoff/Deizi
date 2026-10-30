<?php
declare(strict_types=1);

// "Logistique" page, for logisticians and administrators: administrative follow-up of the dates.
// Without ?event, the list of the dates with their fee and quotes; with ?event=…, the follow-up
// of one date (fee, quotes sent to the organizers, quotes received from carriers).
require __DIR__ . '/lib/bootstrap.php';
$user = app_require_roles(LOGISTICS_ROLES);

$events = array_values(array_filter(
    read_json_file(app_data_path('events.json')),
    static fn (mixed $event): bool => is_array($event) && is_string($event['id'] ?? null) && is_string($event['title'] ?? null) && app_parse_date($event['date'] ?? null) !== null
));
usort($events, 'app_compare_events');
$logistics = app_logistics_read();
$logisticsStatuses = app_logistics_statuses();
$eventStatus = static fn (array $event): string => is_string($event['status'] ?? null) && isset(EVENT_STATUSES[$event['status']]) ? $event['status'] : 'pending';
$weekdayNames = ['', 'Lun.', 'Mar.', 'Mer.', 'Jeu.', 'Ven.', 'Sam.', 'Dim.'];
$formatDate = static fn (string $value): string => ($date = app_parse_date($value)) !== null ? $weekdayNames[(int) $date->format('N')] . ' ' . $date->format('d/m/Y') : '';
$statusBadge = static fn (string $status): string => '<span class="status-badge status-badge-' . app_escape($status) . '">' . app_escape(app_statuses()[$status]) . '</span>';

$requestedEventId = $_GET['event'] ?? '';
$event = null;
if (is_string($requestedEventId) && $requestedEventId !== '') {
    foreach ($events as $candidate) {
        if ($candidate['id'] === $requestedEventId) {
            $event = $candidate;
        }
    }
}

if ($event === null):
    // Dates without logistics follow-up (option of the date) and dates "Information" (no contract
    // nor quote) are left out of the list and the report, and so
    // are the logistics statuses that give that status.
    $events = array_values(array_filter($events, static fn (array $item): bool => $eventStatus($item) !== 'information' && app_event_needs_logistics($item)));
    $listStatuses = array_filter($logisticsStatuses, static fn (array $status): bool => $status[2] !== 'information');
    // List of the dates: the current season and the following ones by default (past dates of the
    // current season included: they may still be invoiced or paid), all seasons with ?toutes=1; ?statut=… keeps the dates
    // of one logistics status. Reserved dates are left out unless ?reservees=1.
    $showAll = ($_GET['toutes'] ?? '') === '1';
    $showReserved = ($_GET['reservees'] ?? '') === '1';
    // Tabs of the page: the dates ("Dates") or the dashboard of a season (?onglet=rapport).
    $listTab = ($_GET['onglet'] ?? '') === 'rapport' ? 'rapport' : 'dates';
    $statusFilter = is_string($_GET['statut'] ?? null) && isset($listStatuses[$_GET['statut']]) ? $_GET['statut'] : '';
    $currentSeasonStart = app_season_of(new DateTimeImmutable('today', new DateTimeZone('Europe/Paris')))['first']->format('Y-m-d');
    $candidates = array_values(array_filter(
        $events,
        static fn (array $item): bool => ($showAll || max($item['date'], is_string($item['end_date'] ?? null) ? $item['end_date'] : '') >= $currentSeasonStart)
            && ($statusFilter === '' || app_event_logistics_status($item, $logistics) === $statusFilter)
    ));
    $listed = $showReserved ? $candidates : array_values(array_filter($candidates, static fn (array $item): bool => !app_event_is_reserved($item)));
    $reservedHidden = count($candidates) - count($listed);
    // Links of the page keep the other filters.
    $listUrl = static fn (array $changes): string => 'logistics.php' . (($query = http_build_query(array_filter(array_merge(['toutes' => $showAll ? '1' : '', 'statut' => $statusFilter, 'reservees' => $showReserved ? '1' : ''], $changes), static fn (string $value): bool => $value !== ''))) !== '' ? '?' . $query : '');
    $rows = [];
    foreach ($listed as $item) {
        $followUp = app_event_logistics($logistics, $item['id']);
        $row = ['event' => $item, 'contract' => $followUp['contract'], 'fee' => $followUp['fee'], 'formula' => $followUp['formula'], 'status' => app_event_logistics_status($item, $logistics)];
        foreach (array_keys(QUOTE_KINDS) as $kind) {
            $quotes = app_logistics_quotes($followUp, $kind);
            $row[$kind] = ['quotes' => $quotes, 'count' => count($quotes), 'accepted' => app_logistics_accepted_total($quotes), 'pending' => count(array_filter($quotes, static fn (array $quote): bool => in_array($quote['status_type'], ['pending', 'published'], true)))];
        }
        $rows[] = $row;
    }
    // Organizer quotes of a date: each quote with its number and status (no accepted total).
    $quoteSummary = static function (array $summary): string {
        if ($summary['count'] === 0) {
            return '<span class="log-sub">—</span>';
        }
        $html = '';
        foreach ($summary['quotes'] as $quote) {
            $html .= '<span class="logistics-quote-status">'
                . app_quote_status_badge($quote)
                . ' <span class="log-sub">' . app_escape($quote['number'] !== '' ? $quote['number'] : $quote['party']) . '</span>'
                . '</span>';
        }
        return $html;
    };

    app_header('Logistique', $user);
?>
      <section class="management-section">
        <div class="eyebrow-row">
          <p class="eyebrow"><span class="eyebrow-line"></span> <?= app_escape(__('gestion_administrative_majuscules')) ?></p>
          <?php app_page_links([['Retour à l’agenda', 'index.php'], $listTab === 'rapport' ? null : ($showAll ? [__('saison_cours_suivantes'), $listUrl(['toutes' => ''])] : [__('afficher_aussi_saisons_passees'), $listUrl(['toutes' => '1'])]), ['Paramètres des devis', 'logistics-settings.php']]); ?>
        </div>
        <div class="section-heading">
          <div>
            <h1><?= app_escape(__('logistique')) ?><span class="heading-dot">.</span></h1>
          </div>
        </div>

        <nav class="detail-tabs" aria-label="<?= app_escape(__('logistique')) ?>">
          <a class="detail-tab" href="<?= app_escape($listUrl([])) ?>"<?= $listTab === 'dates' ? ' aria-current="page"' : '' ?>><?= app_escape(__('dates')) ?></a>
          <a class="detail-tab" href="<?= app_escape('logistics.php?' . http_build_query(['onglet' => 'rapport'] + ($showReserved ? ['reservees' => '1'] : []))) ?>"<?= $listTab === 'rapport' ? ' aria-current="page"' : '' ?>><?= app_escape(__('rapport')) ?></a>
        </nav>

        <?php if ($listTab === 'rapport'): ?>
        <?php
            $requestedSeason = is_string($_GET['saison'] ?? null) && ctype_digit($_GET['saison']) ? $_GET['saison'] : null;
            $report = app_logistics_report($events, $logistics, $requestedSeason, $showReserved);
            $figures = $report['season'];
            $money = static fn (int $cents): string => app_escape(app_format_amount($cents));
        ?>
        <section class="management-panel detail-panel has-tabs logistics-report">
          <form method="get" action="logistics.php" class="logistics-filter">
            <input type="hidden" name="onglet" value="rapport">
            <label for="report-season"><?= app_escape(__('saison')) ?></label>
            <select id="report-season" name="saison" onchange="this.form.submit()">
              <?php foreach ($report['seasons'] as $season): ?>
              <option value="<?= app_escape($season['key']) ?>"<?= $figures !== null && $figures['season']['key'] === $season['key'] ? ' selected' : '' ?>><?= app_escape($season['label']) ?></option>
              <?php endforeach; ?>
            </select>
            <label class="active-toggle logistics-reserved-toggle"><input type="checkbox" name="reservees" value="1"<?= $showReserved ? ' checked' : '' ?> onchange="this.form.submit()"> <?= app_escape(__('inclure_dates_reservees')) ?></label>
            <noscript><button class="secondary-button compact-button" type="submit"><?= app_escape(__('afficher')) ?></button></noscript>
          </form>

          <?php if ($figures === null): ?>
          <p class="muted-copy"><?= app_escape(__('aucune_date_enregistree_phrase')) ?></p>
          <?php else: ?>
          <h2 class="detail-heading"><?= app_escape($figures['season']['label']) ?> <span class="report-range"><?= app_escape(app_season_range($figures['season'])) ?></span></h2>

          <div class="report-tiles">
            <div class="report-tile"><p class="report-tile-label"><?= app_escape(__('dates')) ?></p><p class="report-tile-value"><?= $figures['dates'] ?></p><p class="report-tile-note"><?= app_escape($showReserved ? __('dates_reservees_comprises') : __('hors_dates_reservees')) ?></p></div>
            <div class="report-tile"><p class="report-tile-label"><?= app_escape(__('montant_contrats')) ?></p><p class="report-tile-value"><?= $money($figures['contract']) ?></p><p class="report-tile-note"><?= app_escape(__n('date_renseignee_total', 'dates_renseignees_total', $figures['with_contract'], ['total' => $figures['dates']])) ?></p></div>
            <div class="report-tile"><p class="report-tile-label"><?= app_escape(__('cachets')) ?></p><p class="report-tile-value"><?= $money($figures['fee']) ?></p><p class="report-tile-note"><?= app_escape(__n('date_renseignee_total', 'dates_renseignees_total', $figures['with_fee'], ['total' => $figures['dates']])) ?></p></div>
            <div class="report-tile"><p class="report-tile-label"><?= app_escape(__('frais_charge_groupe')) ?></p><p class="report-tile-value"><?= $money($figures['costs']) ?></p><p class="report-tile-note"><?= app_escape(__n('date_renseignee_total', 'dates_renseignees_total', $figures['with_both'], ['total' => $figures['dates']])) ?></p></div>
            <div class="report-tile"><p class="report-tile-label"><?= app_escape(__('devis_organisateurs')) ?></p><p class="report-tile-value"><?= $figures['quotes'] ?></p><p class="report-tile-note"><?= app_escape(__('repartition_statut_ci_dessous')) ?></p></div>
            <div class="report-tile"><p class="report-tile-label"><?= app_escape(__('devis_annexes_retenus')) ?></p><p class="report-tile-value"><?= $money(array_sum($figures['needs'])) ?></p><p class="report-tile-note"><?= app_escape(__('devis_acceptes_tous_besoins_confondus')) ?></p></div>
          </div>

          <div class="report-grid">
            <section class="report-block" aria-labelledby="report-statuses-title">
              <h3 id="report-statuses-title"><?= app_escape(__('dates_statut')) ?></h3>
              <?php
                  // Statuses of the dates as seen in the agenda; "Information" dates are not listed here.
                  $shownStatuses = array_diff_key(EVENT_STATUSES, ['information' => true]);
                  $maxStatus = max([1, ...array_values(array_intersect_key($figures['event_statuses'], $shownStatuses))]);
              ?>
              <ul class="report-bars">
                <?php foreach ($shownStatuses as $statusKey => $statusLabel): ?>
                <?php $count = $figures['event_statuses'][$statusKey] ?? 0; ?>
                <li title="<?= app_escape(__n('label_date_total', 'label_dates_total', $count, ['label' => $statusLabel, 'total' => $figures['dates']])) ?>"<?= $count === 0 ? ' class="is-empty"' : '' ?>>
                  <span class="report-bar-label"><span class="status-badge status-badge-<?= $statusKey ?>"><?= app_escape($statusLabel) ?></span></span>
                  <span class="report-bar-track"><span class="report-bar" style="width: <?= round($count / $maxStatus * 100, 1) ?>%; --bar: var(--status-<?= $statusKey ?>-accent)"></span></span>
                  <span class="report-bar-value"><?= $count ?></span>
                </li>
                <?php endforeach; ?>
              </ul>
            </section>

            <section class="report-block" aria-labelledby="report-quotes-title">
              <h3 id="report-quotes-title"><?= app_escape(__('devis_organisateurs_statut')) ?></h3>
              <?php if ($figures['quotes'] === 0): ?>
              <p class="muted-copy"><?= app_escape(__('aucun_devis_organisateur_cette_saison_phrase')) ?></p>
              <?php else: ?>
              <table class="log-table report-table">
                <tbody>
                  <?php foreach (app_quote_statuses() as $statusKey => [$statusLabel, $statusType]): ?>
                  <tr><td><span class="quote-status quote-status-<?= app_escape($statusType) ?>"><?= app_escape($statusLabel) ?></span></td><td class="amount-cell"><?= $figures['quote_statuses'][$statusKey] ?? 0 ?></td></tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
              <?php endif; ?>

              <h3><?= app_escape(__('devis_annexes_retenus_besoin')) ?></h3>
              <?php if ($figures['needs'] === []): ?>
              <p class="muted-copy"><?= app_escape(__('aucun_devis_annexe_accepte_cette_saison_phrase')) ?></p>
              <?php else: ?>
              <table class="log-table report-table">
                <tbody>
                  <?php foreach ($figures['needs'] as $need => $amount): ?>
                  <tr><td><?= app_escape($need) ?></td><td class="amount-cell"><?= $money($amount) ?></td></tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
              <?php endif; ?>
            </section>
          </div>

          <div class="report-grid">
          <section class="report-block" aria-labelledby="report-formulas-title">
            <h3 id="report-formulas-title"><?= app_escape(__('dates_cachets_formule')) ?></h3>
            <p class="field-help"><?= app_escape(__('chaque_date_compte_fois_dans_formule')) ?> <a class="page-switch" href="logistics-settings.php?onglet=formules"><?= app_escape(__('gerer_formules')) ?></a></p>
            <?php
                $formulaLabels = app_logistics_formulas();
                $maxFormulaDates = max([1, ...array_column($figures['formulas'], 'dates')]);
                $maxFormulaFee = max([1, ...array_map(static fn (array $values): int => abs($values['fee']), $figures['formulas'])]);
            ?>
            <table class="log-table report-table report-sections-table">
              <thead><tr><th scope="col"><?= app_escape(__('formule')) ?></th><th scope="col" class="amount-cell"><?= app_escape(__('dates')) ?></th><th scope="col"><span class="sr-only"><?= app_escape(__('part_dates')) ?></span></th><th scope="col" class="amount-cell"><?= app_escape(__('cachets')) ?></th><th scope="col"><span class="sr-only"><?= app_escape(__('part_cachets')) ?></span></th></tr></thead>
              <tbody>
                <?php foreach ($figures['formulas'] as $formulaKey => $values): ?>
                <?php $formulaLabel = $formulaKey === '' ? __('sans_formule') : ($formulaLabels[$formulaKey] ?? $formulaKey); ?>
                <tr<?= $formulaKey === '' ? ' class="is-subsection"' : '' ?>>
                  <td><?= $formulaKey === '' ? '<em>' . app_escape($formulaLabel) . '</em>' : '<strong>' . app_escape($formulaLabel) . '</strong>' ?></td>
                  <td class="amount-cell"><?= $values['dates'] ?></td>
                  <td class="report-section-bar" title="<?= app_escape(__n('label_date_total', 'label_dates_total', $values['dates'], ['label' => $formulaLabel, 'total' => $figures['dates']])) ?>"><span class="report-bar" style="width: <?= round($values['dates'] / $maxFormulaDates * 100, 1) ?>%; --bar: var(--sea)"></span></td>
                  <td class="amount-cell"><?= $values['with_fee'] > 0 ? app_escape(app_format_amount($values['fee'])) : '<span class="log-sub">—</span>' ?><?php if ($values['with_fee'] > 0 && $values['with_fee'] < $values['dates']): ?><span class="log-sub"><?= app_escape(__n('date_total', 'dates_total', $values['with_fee'], ['total' => $values['dates']])) ?></span><?php endif; ?></td>
                  <td class="report-section-bar" title="<?= app_escape(__('label_amount_cachets', ['label' => $formulaLabel, 'amount' => app_format_amount($values['fee'])])) ?>"><?php if ($values['with_fee'] > 0): ?><span class="report-bar<?= $values['fee'] < 0 ? ' is-negative' : '' ?>" style="width: <?= round(abs($values['fee']) / $maxFormulaFee * 100, 1) ?>%; --bar: var(--status-confirmed-accent)"></span><?php endif; ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
              <tfoot>
                <tr>
                  <th scope="row"><?= app_escape(__('total')) ?></th>
                  <td class="amount-cell"><?= array_sum(array_column($figures['formulas'], 'dates')) ?></td>
                  <td></td>
                  <td class="amount-cell"><?= $money(array_sum(array_column($figures['formulas'], 'fee'))) ?></td>
                  <td></td>
                </tr>
              </tfoot>
            </table>
          </section>

          <section class="report-block" aria-labelledby="report-sections-title">
            <h3 id="report-sections-title"><?= app_escape(__('dates_section')) ?></h3>
            <p class="field-help"><?= app_escape(__('date_compte_chaque_section_mere_concernee')) ?></p>
            <?php if ($figures['sections'] === []): ?>
            <p class="muted-copy"><?= app_escape(__('aucune_section_concernee_phrase')) ?></p>
            <?php else: ?>
            <?php $sectionLabels = app_section_labels(); $maxSection = max($figures['sections']); ?>
            <table class="log-table report-table">
              <thead><tr><th scope="col"><?= app_escape(__('section')) ?></th><th scope="col" class="amount-cell"><?= app_escape(__('dates')) ?></th><th scope="col"><span class="sr-only"><?= app_escape(__('part')) ?></span></th></tr></thead>
              <tbody>
                <?php foreach ($figures['sections'] as $section => $count): ?>
                <?php $branch = app_section_branch($section); $isBranch = $branch === $section; ?>
                <tr<?= $isBranch ? '' : ' class="is-subsection"' ?>>
                  <td><?= $isBranch ? '<strong>' . app_escape($sectionLabels[$section] ?? $section) . '</strong>' : app_escape(($branch !== null ? ($sectionLabels[$branch] ?? $branch) . ' › ' : '') . ($sectionLabels[$section] ?? $section)) ?></td>
                  <td class="amount-cell"><?= $count ?></td>
                  <td class="report-section-bar" title="<?= app_escape(__n('label_date_total', 'label_dates_total', $count, ['label' => $sectionLabels[$section] ?? $section, 'total' => $figures['dates']])) ?>"><span class="report-bar" style="width: <?= round($count / $maxSection * 100, 1) ?>%; --bar: var(--sea)"></span></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
            <?php endif; ?>
          </section>
          </div>

          <section class="report-block" aria-labelledby="report-months-title">
            <h3 id="report-months-title"><?= app_escape(__('mois_mois')) ?></h3>
            <div class="log-scroll">
              <table class="log-table report-table">
                <thead><tr><th scope="col"><?= app_escape(__('mois')) ?></th><th scope="col" class="amount-cell"><?= app_escape(__('dates')) ?></th><th scope="col" class="amount-cell"><?= app_escape(__('contrats')) ?></th><th scope="col" class="amount-cell"><?= app_escape(__('cachets')) ?></th></tr></thead>
                <tbody>
                  <?php foreach ($figures['months'] as $month => $values): ?>
                  <tr>
                    <td><?= app_escape(app_month_name((int) substr($month, 5, 2), true) . ' ' . substr($month, 0, 4)) ?></td>
                    <td class="amount-cell"><?= $values['dates'] ?></td>
                    <td class="amount-cell"><?= $values['contract'] !== 0 ? $money($values['contract']) : '<span class="log-sub">—</span>' ?></td>
                    <td class="amount-cell"><?= $values['fee'] !== 0 ? $money($values['fee']) : '<span class="log-sub">—</span>' ?></td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
                <tfoot>
                  <tr>
                    <th scope="row"><?= app_escape(__('total')) ?></th>
                    <td class="amount-cell"><?= array_sum(array_column($figures['months'], 'dates')) ?></td>
                    <td class="amount-cell"><?= $money(array_sum(array_column($figures['months'], 'contract'))) ?></td>
                    <td class="amount-cell"><?= $money(array_sum(array_column($figures['months'], 'fee'))) ?></td>
                  </tr>
                </tfoot>
              </table>
            </div>
          </section>
          <?php endif; ?>
        </section>
        <?php else: ?>

        <section class="management-panel detail-panel has-tabs">
          <form method="get" action="logistics.php" class="logistics-filter">
            <?php if ($showAll): ?><input type="hidden" name="toutes" value="1"><?php endif; ?>
            <label for="logistics-status-filter"><?= app_escape(__('statut_logistique')) ?></label>
            <select id="logistics-status-filter" name="statut">
              <option value=""><?= app_escape(__('tous_statuts')) ?></option>
              <?php foreach ($listStatuses as $statusKey => [$code, $label, $mapped]): ?>
              <option value="<?= $statusKey ?>"<?= $statusFilter === $statusKey ? ' selected' : '' ?>><?= app_escape($code . ' · ' . $label . ' (' . app_statuses()[$mapped] . ')') ?></option>
              <?php endforeach; ?>
            </select>
            <label class="active-toggle logistics-reserved-toggle"><input type="checkbox" name="reservees" value="1"<?= $showReserved ? ' checked' : '' ?> onchange="this.form.submit()"> <?= app_escape(__('inclure_dates_reservees')) ?><?= $reservedHidden > 0 ? ' <span class="log-sub logistics-reserved-count">' . app_escape(__n('masquee_parentheses', 'masquees_parentheses', $reservedHidden)) . '</span>' : '' ?></label>
            <button class="secondary-button compact-button" type="submit"><?= app_escape(__('filtrer')) ?></button>
          </form>

          <?php if ($rows === []): ?>
          <p class="muted-copy"><?= app_escape($statusFilter !== '' ? __('aucune_date_avec_ce_statut_logistique_phrase') : ($showAll ? __('aucune_date_enregistree_phrase') : __('aucune_date_dans_saison_cours_ni'))) ?></p>
          <?php else: ?>
          <div class="log-scroll">
            <table class="log-table logistics-table">
              <thead>
                <tr>
                  <th scope="col"><?= app_escape(__('date')) ?></th>
                  <th scope="col"><?= app_escape(__('evenement')) ?></th>
                  <th scope="col"><?= app_escape(__('statut_logistique')) ?></th>
                  <th scope="col" class="amount-cell"><?= app_escape(__('contrat')) ?></th>
                  <th scope="col" class="amount-cell"><?= app_escape(__('cachet')) ?></th>
                  <th scope="col"><?= app_escape(__('devis_organisateurs')) ?></th>
                </tr>
              </thead>
              <?php
                  // Dates grouped by season, each season with its subtotals.
                  $seasonStart = app_season_start();
                  $seasons = [];
                  foreach ($rows as $row) {
                      $season = app_season_of(app_parse_date($row['event']['date']), $seasonStart);
                      $seasons[$season['key']] ??= ['season' => $season, 'rows' => [], 'contract' => 0, 'fee' => 0, 'sent' => 0, 'received' => 0];
                      $seasons[$season['key']]['rows'][] = $row;
                      foreach (['contract', 'fee'] as $amount) {
                          $seasons[$season['key']][$amount] += $row[$amount] ?? 0;
                      }
                      foreach (['sent', 'received'] as $kind) {
                          $seasons[$season['key']][$kind] += $row[$kind]['accepted'] ?? 0;
                      }
                  }
                  // Most recent season first; the dates of a season stay in chronological order.
                  krsort($seasons, SORT_NUMERIC);
              ?>
              <?php foreach ($seasons as $group): ?>
              <tbody class="logistics-season">
                <tr class="report-month-row logistics-season-row">
                  <th scope="rowgroup" colspan="3"><?= app_escape($group['season']['label']) ?> <span><?= app_escape(__n('date_2', 'n_dates', count($group['rows']))) ?> · <?= app_escape(app_season_range($group['season'])) ?></span></th>
                  <td class="amount-cell"><?= app_escape(app_format_amount($group['contract'])) ?></td>
                  <td class="amount-cell"><?= app_escape(app_format_amount($group['fee'])) ?></td>
                  <td></td>
                </tr>
                <?php foreach ($group['rows'] as $row): ?>
                <?php $item = $row['event']; ?>
                <tr>
                  <td class="report-nowrap"><?= app_escape($formatDate($item['date'])) ?></td>
                  <td><a class="event-title-link" href="logistics.php?event=<?= rawurlencode($item['id']) ?>"><?= app_escape($item['title']) ?></a><?php if (app_event_is_reserved($item)): ?> <span class="reserved-tag"><?= app_escape(__('reservee')) ?></span><?php endif; ?><?php if ($row['formula'] !== null): ?><span class="log-sub"><?= app_escape(app_logistics_formulas()[$row['formula']]) ?></span><?php endif; ?></td>
                  <td><?= app_logistics_status_badge($row['status']) ?></td>
                  <td class="amount-cell"><?= $row['contract'] !== null ? app_escape(app_format_amount($row['contract'])) : '<span class="log-sub">—</span>' ?></td>
                  <td class="amount-cell"><?= $row['fee'] !== null ? app_escape(app_format_amount($row['fee'])) : '<span class="log-sub">—</span>' ?></td>
                  <td><?= $quoteSummary($row['sent']) ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
              <?php endforeach; ?>
            </table>
          </div>
          <?php endif; ?>
        </section>
        <?php endif; ?>
      </section>
<?php
    app_footer();
    exit;
endif;

// Follow-up of one date.
$followUp = app_event_logistics($logistics, $event['id']);
$editedQuoteId = is_string($_GET['devis'] ?? null) ? $_GET['devis'] : '';
$editedQuote = null;
foreach ($followUp['quotes'] as $quote) {
    if ($quote['id'] === $editedQuoteId) {
        $editedQuote = $quote;
    }
}
$pageUrl = 'logistics.php?event=' . rawurlencode($event['id']);
$csrf = app_csrf_token();
// Tab shown: the one asked for, the tab of the quote being edited, or the fee.
$requestedTab = is_string($_GET['onglet'] ?? null) ? $_GET['onglet'] : '';
$tab = match (true) {
    $editedQuote !== null => QUOTE_KIND_TABS[$editedQuote['kind']],
    isset(LOGISTICS_TABS[$requestedTab]) => $requestedTab,
    default => 'cachet',
};
$tabCounts = ['organisateurs' => count(app_logistics_quotes($followUp, 'sent')), 'annexes' => count(app_logistics_quotes($followUp, 'received')), 'documents' => count($followUp['documents'])];

app_header(__('logistique_title', ['title' => $event['title']]), $user);
?>
      <section class="management-section">
        <div class="eyebrow-row">
          <p class="eyebrow"><span class="eyebrow-line"></span> <?= app_escape(__('logistique_majuscules')) ?></p>
          <?php app_page_links([[__('toutes_dates'), 'logistics.php'], [__('voir_date'), 'event.php?id=' . rawurlencode($event['id'])]]); ?>
        </div>
        <div class="section-heading">
          <div>
            <h1><?= app_escape($event['title']) ?><span class="heading-dot">.</span></h1>
            <p class="section-note"><?= app_escape($formatDate($event['date'])) ?><?= is_string($event['location'] ?? null) && trim($event['location']) !== '' ? ' · ' . app_escape(trim($event['location'])) : '' ?> <?= app_logistics_status_badge(app_event_logistics_status($event, $logistics)) ?> <?= $statusBadge($eventStatus($event)) ?></p>
          </div>
        </div>

        <?php $logisticsStatus = app_event_logistics_status($event, $logistics); ?>
        <form method="post" action="logistics-action.php" class="logistics-status-form">
          <input type="hidden" name="csrf_token" value="<?= app_escape($csrf) ?>">
          <input type="hidden" name="action" value="save_status">
          <input type="hidden" name="event_id" value="<?= app_escape($event['id']) ?>">
          <input type="hidden" name="return_tab" value="<?= app_escape($tab) ?>">
          <label for="logistics-status"><?= app_escape(__('statut_logistique')) ?></label>
          <select id="logistics-status" name="logistics_status">
            <?php foreach ($logisticsStatuses as $statusKey => [$code, $label, $mapped]): ?>
            <option value="<?= $statusKey ?>"<?= $logisticsStatus === $statusKey ? ' selected' : '' ?>><?= app_escape($code . ' · ' . $label . ' → ' . app_statuses()[$mapped]) ?></option>
            <?php endforeach; ?>
          </select>
          <button class="primary-button compact-button" type="submit"><?= app_escape(__('enregistrer_statut')) ?></button>
          <p class="field-help"><?= app_escape(__('statut_date_vu_artistes_managers_organisateurs')) ?></p>
        </form>

        <form method="post" action="logistics-action.php" class="admin-note-field logistics-admin-note">
          <input type="hidden" name="csrf_token" value="<?= app_escape($csrf) ?>">
          <input type="hidden" name="action" value="save_admin_note">
          <input type="hidden" name="event_id" value="<?= app_escape($event['id']) ?>">
          <input type="hidden" name="return_tab" value="<?= app_escape($tab) ?>">
          <label for="logistics-admin-note"><?= app_escape(__('note_admin')) ?> <span class="admin-only-badge"><?= app_escape(__('visible_organisateurs_logisticiens_administrateurs_uniquemen')) ?></span></label>
          <textarea id="logistics-admin-note" name="admin_note" rows="3" maxlength="3000" placeholder="<?= app_escape(__('suivi_interne_devis_echanges_avec_lorganisateur')) ?>"><?= app_escape(app_event_admin_note($event)) ?></textarea>
          <div class="form-actions"><button class="secondary-button compact-button" type="submit"><?= app_escape(__('enregistrer_note')) ?></button></div>
        </form>

        <nav class="detail-tabs" aria-label="<?= app_escape(__('suivi_date')) ?>">
          <?php foreach (LOGISTICS_TABS as $tabKey => $tabLabel): ?>
          <a class="detail-tab" href="<?= app_escape(app_logistics_tab_url($event['id'], $tabKey)) ?>"<?= $tab === $tabKey ? ' aria-current="page"' : '' ?>>
            <?= app_escape(app_label($tabLabel)) ?>
            <?php if ($tabKey === 'cachet' && $followUp['fee'] !== null): ?><span class="count-badge" title="<?= app_escape(__('cachet_apres_frais')) ?>"><?= app_escape(app_format_amount($followUp['fee'])) ?></span><?php endif; ?>
            <?php if (isset($tabCounts[$tabKey])): ?><span class="count-badge" title="<?= app_escape($tabKey === 'documents' ? __('nombre_documents') : __('nombre_devis')) ?>"><?= $tabCounts[$tabKey] ?></span><?php endif; ?>
          </a>
          <?php endforeach; ?>
        </nav>

        <?php if ($tab === 'cachet'): ?>
        <section class="management-panel detail-panel has-tabs" aria-labelledby="fee-title">
          <h2 class="detail-heading" id="fee-title"><?= app_escape(__('contrat_cachet')) ?></h2>
          <?php
              $annexAccepted = app_logistics_accepted_total(app_logistics_quotes($followUp, 'received'));
          ?>
          <?php if ($followUp['legacy_fee']): ?>
          <p class="change-notice"><?= app_escape(__('cachet_amount_ete_saisi_sans_contrat', ['amount' => app_format_amount($followUp['fee'])])) ?></p>
          <?php endif; ?>
          <form method="post" action="logistics-action.php" class="form-stack" data-fee-calculator>
            <input type="hidden" name="csrf_token" value="<?= app_escape($csrf) ?>">
            <input type="hidden" name="action" value="save_fee">
            <input type="hidden" name="event_id" value="<?= app_escape($event['id']) ?>">
            <div class="form-stack fee-formula">
              <label for="fee-formula"><?= app_escape(__('formule')) ?></label>
              <?php $formulas = app_logistics_formulas(); ?>
              <select id="fee-formula" name="formula">
                <option value=""><?= app_escape(__('aucune')) ?></option>
                <?php foreach ($formulas as $formulaKey => $formulaLabel): ?>
                <option value="<?= app_escape($formulaKey) ?>"<?= $followUp['formula'] === $formulaKey ? ' selected' : '' ?>><?= app_escape($formulaLabel) ?></option>
                <?php endforeach; ?>
              </select>
              <p class="field-help"><?= app_escape($formulas === [] ? __('aucune_formule_nest_encore_definie_label') . ' ' : __('ce_groupe_propose_cette_date_phrase') . ' ') ?><a class="page-switch" href="logistics-settings.php?onglet=formules"><?= app_escape(__('gerer_formules')) ?></a></p>
            </div>
            <div class="form-grid logistics-quote-grid align-top">
              <div class="form-stack">
                <label for="contract-amount"><?= app_escape(__('montant_contrat')) ?></label>
                <input id="contract-amount" name="contract" placeholder="2400,00" value="<?= app_escape(app_amount_input($followUp['contract'])) ?>" data-fee-contract>
                <p class="field-help"><?= app_escape(__('paye_lorganisateur_cachet_frais_compris_peut')) ?></p>
              </div>
              <div class="form-stack">
                <label for="costs-amount"><?= app_escape(__('frais_a_la_charge_du_groupe')) ?></label>
                <input id="costs-amount" name="costs" inputmode="decimal" placeholder="900,00" value="<?= app_escape(app_amount_input($followUp['costs'])) ?>" data-fee-costs>
                <p class="field-help"><?= app_escape(__('transport_hebergement_repas')) ?><?= $annexAccepted !== null ? ' ' . app_escape(__('devis_annexes_acceptes_amount_phrase', ['amount' => app_format_amount($annexAccepted)])) : '' ?></p>
              </div>
              <div class="form-stack">
                <span class="quote-line-total-label"><?= app_escape(__('montant_cachet')) ?></span>
                <p class="fee-costs" data-fee-result><?= $followUp['fee'] !== null && !$followUp['legacy_fee'] ? app_escape(app_format_amount($followUp['fee'])) : '<span class="log-sub">—</span>' ?></p>
                <p class="field-help"><?= app_escape(__('calcule_contrat_frais_negatif_quand_frais')) ?></p>
              </div>
            </div>
            <label for="fee-note"><?= app_escape(__('note')) ?></label>
            <textarea id="fee-note" name="fee_note" rows="2" maxlength="1000" placeholder="<?= app_escape(__('conditions_paiement_facture')) ?>"><?= app_escape($followUp['fee_note']) ?></textarea>
            <p class="field-help"><?= app_escape(__('laissez_montant_vide_tant_quil_nest')) ?></p>
            <div class="form-actions"><button class="primary-button" type="submit"><?= app_escape(__('enregistrer')) ?></button></div>
          </form>
        </section>
        <?php endif; ?>

        <?php if ($tab === 'documents'): ?>
        <?php $documentRows = [...$followUp['documents'], ['label' => '', 'url' => '']]; ?>
        <section class="management-panel detail-panel has-tabs" aria-labelledby="documents-title">
          <h2 class="detail-heading" id="documents-title"><?= app_escape(__('documents_annexes')) ?> <span class="count-badge"><?= count($followUp['documents']) ?></span></h2>
          <p class="muted-copy"><?= app_escape(__('liens_vers_contrat_autres_documents_date')) ?></p>
          <?php if ($followUp['documents'] !== []): ?>
          <ul class="logistics-documents">
            <?php foreach ($followUp['documents'] as $document): ?>
            <li><a href="<?= app_escape($document['url']) ?>" target="_blank" rel="noopener noreferrer"><?= app_escape($document['label']) ?> <span aria-hidden="true">↗</span></a> <span class="log-sub"><?= app_escape((string) parse_url($document['url'], PHP_URL_HOST)) ?></span></li>
            <?php endforeach; ?>
          </ul>
          <?php endif; ?>
          <form method="post" action="logistics-action.php" class="form-stack">
            <input type="hidden" name="csrf_token" value="<?= app_escape($csrf) ?>">
            <input type="hidden" name="action" value="save_documents">
            <input type="hidden" name="event_id" value="<?= app_escape($event['id']) ?>">
            <div class="roadmap-rows logistics-document-rows" data-roadmap-rows>
              <?php foreach ($documentRows as $documentIndex => $document): ?>
              <div class="roadmap-row logistics-document-row" data-roadmap-row>
                <input name="documents[<?= $documentIndex ?>][label]" maxlength="<?= LOGISTICS_DOCUMENT_LABEL_MAX ?>" placeholder="<?= app_escape(__('intitule_ex_contrat_signe')) ?>" aria-label="<?= app_escape(__('intitule_document')) ?>" value="<?= app_escape($document['label']) ?>">
                <input name="documents[<?= $documentIndex ?>][url]" type="url" maxlength="2000" placeholder="<?= app_escape(__('https')) ?>" aria-label="<?= app_escape(__('lien_document')) ?>" value="<?= app_escape($document['url']) ?>">
                <button class="text-button roadmap-remove" type="button" data-remove-row aria-label="<?= app_escape(__('retirer_ce_document')) ?>"><?= app_escape(__('retirer')) ?></button>
              </div>
              <?php endforeach; ?>
              <template>
                <div class="roadmap-row logistics-document-row" data-roadmap-row>
                  <input name="documents[__INDEX__][label]" maxlength="<?= LOGISTICS_DOCUMENT_LABEL_MAX ?>" placeholder="<?= app_escape(__('intitule_ex_contrat_signe')) ?>" aria-label="<?= app_escape(__('intitule_document')) ?>">
                  <input name="documents[__INDEX__][url]" type="url" maxlength="2000" placeholder="<?= app_escape(__('https')) ?>" aria-label="<?= app_escape(__('lien_document')) ?>">
                  <button class="text-button roadmap-remove" type="button" data-remove-row aria-label="<?= app_escape(__('retirer_ce_document')) ?>"><?= app_escape(__('retirer')) ?></button>
                </div>
              </template>
              <button class="secondary-button compact-button" type="button" data-add-row><?= app_escape(__('ajouter_document')) ?></button>
            </div>
            <p class="field-help"><?= app_escape(__('documents_eux_memes_restent_ils_sont')) ?></p>
            <div class="form-actions"><button class="primary-button" type="submit"><?= app_escape(__('enregistrer_documents')) ?></button></div>
          </form>
        </section>
        <?php endif; ?>

        <?php foreach (QUOTE_KINDS as $kind => $kindLabel): ?>
        <?php if ($tab !== QUOTE_KIND_TABS[$kind]) { continue; } ?>
        <?php
            $quotes = app_logistics_quotes($followUp, $kind);
            $accepted = app_logistics_accepted_total($quotes);
            $formQuote = $editedQuote !== null && $editedQuote['kind'] === $kind ? $editedQuote : null;
            $partyLabel = app_label(QUOTE_PARTY_LABELS[$kind]);
            // Organizers: one list. Annexes: one block per need, each with its quotes and its form.
            $blocks = $kind === 'sent' ? [['need' => null, 'quotes' => $quotes]] : app_logistics_need_blocks($followUp);
            $needNames = array_values(array_filter(array_column($blocks, 'need'), static fn (?string $need): bool => $need !== null && $need !== ''));
        ?>
        <section class="management-panel detail-panel has-tabs" aria-labelledby="quotes-<?= $kind ?>-title">
          <div class="detail-panel-heading">
            <h2 class="detail-heading" id="quotes-<?= $kind ?>-title"><?= app_escape(app_label($kindLabel)) ?> <span class="count-badge"><?= count($quotes) ?></span></h2>
            <?php if ($kind === 'received' && $accepted !== null): ?><p class="section-note"><?= app_escape(__('total_accepte_label')) ?> <strong><?= app_escape(app_format_amount($accepted)) ?></strong></p><?php endif; ?>
            <?php if ($kind === 'sent'): ?><a class="primary-button compact-button" href="logistics-quote.php?event=<?= rawurlencode($event['id']) ?>"><?= app_escape(__('plus_nouveau_devis')) ?></a><?php endif; ?>
          </div>
          <?php if ($kind === 'sent'): ?>
          <p class="muted-copy"><?= app_escape(__('chaque_devis_propose_plusieurs_options_exemple')) ?></p>
          <?php endif; ?>
          <?php if ($kind === 'received'): ?>
          <p class="muted-copy"><?= app_escape(__('bloc_besoin_transport_hebergement_avec_devis')) ?></p>
          <?php if ($blocks === []): ?><p class="muted-copy"><?= app_escape(__('aucun_besoin_cette_date_ajoutez_ci')) ?></p><?php endif; ?>
          <?php endif; ?>
          <?php foreach ($blocks as $blockIndex => ['need' => $need, 'quotes' => $blockQuotes]): ?>
          <?php
              $formKey = $kind . '-' . $blockIndex;
              $blockForm = $kind === 'sent' || ($formQuote !== null && $formQuote['need'] === $need) ? $formQuote : null;
              $blockAccepted = app_logistics_accepted_total($blockQuotes);
          ?>
          <?php if ($need !== null): ?>
          <section class="need-block" id="besoin-<?= app_escape(rawurlencode($need)) ?>" aria-labelledby="need-<?= $blockIndex ?>-title">
            <div class="need-heading">
              <h3 id="need-<?= $blockIndex ?>-title"><?= app_escape($need !== '' ? $need : __('autres_devis')) ?> <span class="count-badge"><?= count($blockQuotes) ?></span></h3>
              <p class="need-retained"><?= app_escape(__('retenu_label')) ?> <?= $blockAccepted !== null ? '<strong>' . app_escape(app_format_amount($blockAccepted)) . '</strong>' : '<span class="log-sub">' . app_escape(__('aucun_devis_accepte')) . '</span>' ?></p>
              <?php if ($blockQuotes === [] && $need !== ''): ?>
              <form method="post" action="logistics-action.php">
                <input type="hidden" name="csrf_token" value="<?= app_escape($csrf) ?>">
                <input type="hidden" name="action" value="remove_need">
                <input type="hidden" name="event_id" value="<?= app_escape($event['id']) ?>">
                <input type="hidden" name="need" value="<?= app_escape($need) ?>">
                <button class="text-button" type="submit"><?= app_escape(__('retirer_ce_besoin')) ?></button>
              </form>
              <?php endif; ?>
            </div>
          <?php endif; ?>
          <?php if ($blockQuotes === []): ?>
          <p class="muted-copy"><?= app_escape($kind === 'sent' ? __('aucun_devis_envoye_cette_date_phrase') : __('aucun_devis_ce_besoin_phrase')) ?></p>
          <?php else: ?>
          <div class="log-scroll">
            <table class="log-table logistics-table">
              <thead>
                <tr>
                  <th scope="col"><?= app_escape($kind === 'sent' ? __('envoye') : __('recu')) ?></th>
                  <th scope="col"><?= app_escape($partyLabel) ?></th>
                  <th scope="col"><?= app_escape(__('objet')) ?></th>
                  <th scope="col" class="amount-cell"><?= app_escape($kind === 'sent' ? __('options') : __('montant')) ?></th>
                  <th scope="col"><?= app_escape(__('statut')) ?></th>
                  <th scope="col"><?= app_escape(__('fichier')) ?></th>
                  <th scope="col"><span class="sr-only"><?= app_escape(__('actions')) ?></span></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($blockQuotes as $quote): ?>
                <tr<?= $formQuote !== null && $formQuote['id'] === $quote['id'] ? ' class="is-edited"' : '' ?>>
                  <td class="report-nowrap"><?= $quote['date'] !== '' ? app_escape($formatDate($quote['date'])) : '<span class="log-sub">—</span>' ?></td>
                  <td><strong><?= app_escape($quote['party']) ?></strong><?php if ($quote['number'] !== ''): ?><span class="log-sub"><?= app_escape(__('numero_number', ['number' => $quote['number']])) ?></span><?php endif; ?></td>
                  <td><?= $quote['subject'] !== '' ? app_escape($quote['subject']) : '<span class="log-sub">—</span>' ?><?php if ($quote['note'] !== ''): ?><span class="log-sub"><?= nl2br(app_escape($quote['note']), false) ?></span><?php endif; ?></td>
                  <td class="amount-cell">
                    <?php $quoteOptions = app_quote_options($quote); ?>
                    <?php if ($quoteOptions === []): ?><span class="log-sub">—</span><?php endif; ?>
                    <?php foreach ($quoteOptions as $optionIndex => $option): ?>
                    <?php $isAccepted = $quote['status_type'] === 'accepted' && (count($quoteOptions) === 1 || $quote['accepted_option'] === $optionIndex); ?>
                    <span class="quote-option-amount<?= $isAccepted ? ' is-accepted' : '' ?>"<?= $isAccepted ? ' title="' . app_escape(__('option_retenue')) . '"' : '' ?>><?= $option['title'] !== '' ? app_escape(__('option_titre_label', ['label' => $option['title']])) : '' ?><?= app_escape(app_format_amount($option['amount'])) ?><?= $isAccepted ? ' ✓' : '' ?></span>
                    <?php endforeach; ?>
                    <?php if ($quote['status_type'] === 'accepted' && count($quoteOptions) > 1 && app_quote_accepted_amount($quote) === null): ?><span class="log-sub"><?= app_escape(__('option_retenue_preciser')) ?></span><?php endif; ?>
                  </td>
                  <td><?= app_quote_status_badge($quote) ?><?php if ($quote['share'] !== null && $quote['status_type'] !== 'published'): ?> <span class="quote-published-badge" title="<?= app_escape(__('lien_public_actif_devis_fige')) ?>"><?= app_escape(__('lien_public')) ?></span><?php endif; ?></td>
                  <td><?php if (app_quote_is_generated($quote)): ?><a class="page-switch" href="<?= app_escape('logistics-quote.php?' . http_build_query(['event' => $event['id'], 'devis' => $quote['id']])) ?>"><?= app_escape(__('voir_devis')) ?></a><?php endif; ?><?php if ($quote['file'] !== null): ?><a class="page-switch quote-file-link" href="logistics-file.php?<?= app_escape(http_build_query(['event' => $event['id'], 'devis' => $quote['id']])) ?>" target="_blank" rel="noopener"><?= app_escape(app_truncate($quote['file_name'], 28)) ?></a><?php elseif (!app_quote_is_generated($quote)): ?><span class="log-sub">—</span><?php endif; ?></td>
                  <td class="logistics-actions">
                    <a class="secondary-button compact-button" href="<?= app_escape($kind === 'sent' ? 'logistics-quote.php?' . http_build_query(['event' => $event['id'], 'devis' => $quote['id']] + ($quote['share'] === null ? ['modifier' => '1'] : [])) : app_logistics_tab_url($event['id'], QUOTE_KIND_TABS[$kind], ['devis' => $quote['id']])) ?>"><?= $quote['share'] !== null ? __('voir') : __('modifier') ?></a>
                    <?php if ($quote['share'] === null): ?>
                    <form method="post" action="logistics-action.php" onsubmit="return confirm('Supprimer ce devis ?');">
                      <input type="hidden" name="csrf_token" value="<?= app_escape($csrf) ?>">
                      <input type="hidden" name="action" value="delete_quote">
                      <input type="hidden" name="event_id" value="<?= app_escape($event['id']) ?>">
                      <input type="hidden" name="quote_id" value="<?= app_escape($quote['id']) ?>">
                      <button class="danger-button compact-button" type="submit"><?= app_escape(__('supprimer')) ?></button>
                    </form>
                    <?php endif; ?>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php endif; ?>

          <?php if ($kind === 'received'): ?>
          <details class="need-add"<?= $blockForm !== null || $blockQuotes === [] ? ' open' : '' ?>>
            <summary><?= app_escape($blockForm !== null ? __('modifier_devis') : __('plus_ajouter_devis')) ?></summary>
          <?php endif; ?>
          <?php if ($kind === 'received'): ?>
          <form method="post" action="logistics-action.php" enctype="multipart/form-data" class="form-stack logistics-quote-form">
            <?php if ($blockForm !== null): ?>
            <h3 class="logistics-form-title"><?= app_escape(__('modifier_devis')) ?></h3>
            <?php endif; ?>
            <input type="hidden" name="csrf_token" value="<?= app_escape($csrf) ?>">
            <input type="hidden" name="action" value="save_quote">
            <input type="hidden" name="event_id" value="<?= app_escape($event['id']) ?>">
            <input type="hidden" name="kind" value="<?= $kind ?>">
            <input type="hidden" name="quote_id" value="<?= app_escape($blockForm['id'] ?? '') ?>">
            <?php if ($kind === 'received' && ($blockForm === null || count($needNames) < 2)): ?>
            <input type="hidden" name="need" value="<?= app_escape($need) ?>">
            <?php elseif ($kind === 'received'): ?>
            <div class="form-stack">
              <label for="quote-<?= $formKey ?>-need"><?= app_escape(__('besoin')) ?></label>
              <select id="quote-<?= $formKey ?>-need" name="need">
                <?php foreach ($needNames as $needName): ?>
                <option value="<?= app_escape($needName) ?>"<?= $needName === $need ? ' selected' : '' ?>><?= app_escape($needName) ?></option>
                <?php endforeach; ?>
                <?php if ($need === ''): ?><option value="" selected><?= app_escape(__('autres_devis')) ?></option><?php endif; ?>
              </select>
            </div>
            <?php endif; ?>
            <div class="form-grid logistics-quote-grid">
              <div class="form-stack">
                <label for="quote-<?= $formKey ?>-party"><?= app_escape($partyLabel) ?></label>
                <input id="quote-<?= $formKey ?>-party" name="party" maxlength="120" required placeholder="<?= app_escape($kind === 'sent' ? __('festival_cornouaille') : __('autocars_louest')) ?>" value="<?= app_escape($blockForm['party'] ?? '') ?>">
              </div>
              <div class="form-stack">
                <label for="quote-<?= $formKey ?>-subject"><?= app_escape(__('objet')) ?></label>
                <input id="quote-<?= $formKey ?>-subject" name="subject" maxlength="160" placeholder="<?= app_escape($kind === 'sent' ? __('prestation_samedi_soir') : __('car_50_places_aller_retour_hebergement')) ?>" value="<?= app_escape($blockForm['subject'] ?? '') ?>">
              </div>
              <?php if ($kind === 'received'): ?>
              <div class="form-stack">
                <label for="quote-<?= $formKey ?>-amount"><?= app_escape(__('montant_2')) ?></label>
                <input id="quote-<?= $formKey ?>-amount" name="amount" inputmode="decimal" placeholder="1250,00" value="<?= app_escape(app_amount_input($blockForm['amount'] ?? null)) ?>">
              </div>
              <?php endif; ?>
              <div class="form-stack">
                <label for="quote-<?= $formKey ?>-date"><?= app_escape($kind === 'sent' ? __('envoye') : __('recu')) ?></label>
                <input id="quote-<?= $formKey ?>-date" name="date" type="date" value="<?= app_escape($blockForm['date'] ?? '') ?>">
              </div>
              <div class="form-stack">
                <label for="quote-<?= $formKey ?>-status"><?= app_escape(__('statut')) ?></label>
                <select id="quote-<?= $formKey ?>-status" name="status">
                  <?php foreach (QUOTE_STATUSES as $status => $statusLabel): ?>
                  <option value="<?= $status ?>"<?= ($blockForm['status'] ?? 'pending') === $status ? ' selected' : '' ?>><?= app_escape($statusLabel) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-stack">
                <label for="quote-<?= $formKey ?>-file"><?= app_escape(($blockForm['file'] ?? null) !== null ? __('remplacer_fichier_pdf_image_5_mo') : __('fichier_devis_pdf_image_5_mo')) ?></label>
                <input id="quote-<?= $formKey ?>-file" name="file" type="file" accept="application/pdf,image/png,image/jpeg,image/webp">
                <?php if (($blockForm['file'] ?? null) !== null): ?>
                <label class="active-toggle"><input type="checkbox" name="remove_file" value="1"> <?= app_escape(__('retirer_name', ['name' => $blockForm['file_name']])) ?></label>
                <?php endif; ?>
              </div>
            </div>
            <label for="quote-<?= $formKey ?>-note"><?= app_escape(__('note')) ?></label>
            <textarea id="quote-<?= $formKey ?>-note" name="note" rows="2" maxlength="1000" placeholder="<?= app_escape(__('relance_conditions_contact')) ?>"><?= app_escape($blockForm['note'] ?? '') ?></textarea>
            <div class="form-actions">
              <button class="primary-button" type="submit"><?= app_escape($blockForm !== null ? __('enregistrer_devis') : __('ajouter_devis')) ?></button>
              <?php if ($blockForm !== null): ?><a class="secondary-button" href="<?= app_escape(app_logistics_tab_url($event['id'], QUOTE_KIND_TABS[$kind])) ?>"><?= app_escape(__('annuler')) ?></a><?php endif; ?>
            </div>
            <?php if ($blockForm !== null && $blockForm['updated_by'] !== ''): ?>
            <p class="field-help"><?= app_escape(__('derniere_modification_name_time_phrase', ['name' => $blockForm['updated_by'], 'time' => app_format_log_time($blockForm['updated_at'])])) ?></p>
            <?php endif; ?>
          </form>
          <?php endif; ?>
          <?php if ($kind === 'received'): ?>
          </details>
          <?php endif; ?>
          <?php if ($need !== null): ?>
          </section>
          <?php endif; ?>
          <?php endforeach; ?>

          <?php if ($kind === 'received'): ?>
          <form method="post" action="logistics-action.php" class="need-new-form">
            <input type="hidden" name="csrf_token" value="<?= app_escape($csrf) ?>">
            <input type="hidden" name="action" value="add_need">
            <input type="hidden" name="event_id" value="<?= app_escape($event['id']) ?>">
            <label for="need-new"><?= app_escape(__('nouveau_besoin')) ?></label>
            <input id="need-new" name="need" maxlength="60" required list="need-suggestions" placeholder="<?= app_escape(__('transport_hebergement_restauration')) ?>">
            <datalist id="need-suggestions"><?php foreach (array_diff(LOGISTICS_NEED_SUGGESTIONS, $needNames) as $suggestion): ?><option value="<?= app_escape($suggestion) ?>"><?php endforeach; ?></datalist>
            <button class="secondary-button compact-button" type="submit"><?= app_escape(__('plus_ajouter_besoin')) ?></button>
          </form>
          <?php endif; ?>
        </section>
        <?php endforeach; ?>
      </section>
<?php app_footer(); ?>
