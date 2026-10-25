<?php
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';
$admin = app_require_roles(ACCOUNT_MANAGER_ROLES);

const ATTENDANCE_LOG_DISPLAY_LIMIT = 300;

$existingEventIds = [];
foreach (read_json_file(app_data_path('events.json')) as $event) {
    if (is_array($event) && is_string($event['id'] ?? null)) {
        $existingEventIds[$event['id']] = true;
    }
}
$entries = app_attendance_log_read(true);

$eventOptions = [];
$memberOptions = [];
foreach ($entries as $entry) {
    if (!app_is_account_log_entry($entry) && !app_is_login_log_entry($entry)) {
        $eventOptions[$entry['event_id']] = ($entry['event_date'] ?? '') . ' — ' . ($entry['event_title'] ?? '');
    }
    if (!app_is_event_log_entry($entry)) {
        $memberOptions[$entry['member_id']] = (string) ($entry['member'] ?? $entry['member_id']);
    }
}
asort($eventOptions);
natcasesort($memberOptions);

$filterEvent = is_string($_GET['event'] ?? null) && isset($eventOptions[$_GET['event']]) ? $_GET['event'] : '';
$filterMember = is_string($_GET['member'] ?? null) && isset($memberOptions[$_GET['member']]) ? $_GET['member'] : '';
$filterSource = is_string($_GET['source'] ?? null) && isset(ATTENDANCE_LOG_SOURCES[$_GET['source']]) ? $_GET['source'] : '';

$entries = array_reverse(array_values(array_filter(
    $entries,
    static fn (array $entry): bool => ($filterEvent === '' || $entry['event_id'] === $filterEvent)
        && ($filterMember === '' || ($entry['member_id'] ?? '') === $filterMember)
        && ($filterSource === '' || ($entry['source'] ?? '') === $filterSource)
)));

$filterQuery = array_filter(['event' => $filterEvent, 'member' => $filterMember, 'source' => $filterSource], static fn (string $value): bool => $value !== '');

if (($_GET['format'] ?? '') === 'csv') {
    app_send_csv(
        'journal-audit.csv',
        ['Le', 'Date concernée', 'Événement', 'Membre', 'Action ou ancienne réponse', 'Nouvelle réponse', 'Par', 'Source'],
        array_map(static function (array $entry): array {
            $eventDate = app_parse_date($entry['event_date'] ?? null);
            if (app_is_login_log_entry($entry)) {
                return [
                    app_format_log_time($entry['at']),
                    '',
                    '',
                    (string) ($entry['member'] ?? ''),
                    LOGIN_LOG_ACTIONS[$entry['action']],
                    '',
                    (string) ($entry['by'] ?? ''),
                    ATTENDANCE_LOG_SOURCES['login'],
                ];
            }
            if (app_is_account_log_entry($entry)) {
                // A change to a profile: the old and new values take the answer columns.
                $isPhoto = str_starts_with((string) $entry['action'], 'photo_');
                return [
                    app_format_log_time($entry['at']),
                    '',
                    '',
                    (string) ($entry['member'] ?? ''),
                    ACCOUNT_LOG_ACTIONS[$entry['action']] . ($isPhoto ? '' : ' : ' . (string) ($entry['from'] ?? '')),
                    $isPhoto ? '' : (string) ($entry['to'] ?? ''),
                    (string) ($entry['by'] ?? ''),
                    ATTENDANCE_LOG_SOURCES['account'],
                ];
            }
            if (app_is_event_log_entry($entry)) {
                // A change to the date itself: the action and its details take the answer columns.
                return [
                    app_format_log_time($entry['at']),
                    $eventDate !== null ? $eventDate->format('d/m/Y') : '',
                    (string) ($entry['event_title'] ?? ''),
                    '',
                    EVENT_LOG_ACTIONS[$entry['action']],
                    app_event_log_changes_text($entry),
                    (string) ($entry['by'] ?? ''),
                    ATTENDANCE_LOG_SOURCES['event'],
                ];
            }
            return [
                app_format_log_time($entry['at']),
                $eventDate !== null ? $eventDate->format('d/m/Y') : '',
                (string) ($entry['event_title'] ?? ''),
                (string) ($entry['member'] ?? ''),
                app_attendance_response_label($entry['previous'] ?? null),
                app_attendance_response_label($entry['response']),
                (string) ($entry['by'] ?? ''),
                ATTENDANCE_LOG_SOURCES[$entry['source'] ?? ''] ?? '',
            ];
        }, $entries)
    );
}

$totalEntries = count($entries);
$entries = array_slice($entries, 0, ATTENDANCE_LOG_DISPLAY_LIMIT);

app_header('Journal d’audit', $admin);
?>
      <section class="management-section">
        <div class="eyebrow-row">
          <p class="eyebrow"><span class="eyebrow-line"></span> CONFIGURATION · SUIVI ET SÉCURITÉ</p>
          <?php app_page_links([['Configuration', 'settings.php'], ['Tableau de présence', app_matrix_tab_url()], ['Retour à l’agenda', 'index.php']]); ?>
        </div>
        <div class="section-heading">
          <div>
            <h1>Journal d’audit<span class="heading-dot">.</span></h1>
          </div>
        </div>
        <?php app_settings_nav('suivi', 'attendance-log.php', $admin); ?>

        <section class="management-panel">
          <form method="get" action="attendance-log.php" class="log-filters">
            <div>
              <label for="log-event">Date</label>
              <select id="log-event" name="event">
                <option value="">Toutes les dates</option>
                <?php foreach ($eventOptions as $value => $label): ?>
                <option value="<?= app_escape((string) $value) ?>"<?= $filterEvent === (string) $value ? ' selected' : '' ?>><?= app_escape($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label for="log-member">Membre</label>
              <select id="log-member" name="member">
                <option value="">Tous les membres</option>
                <?php foreach ($memberOptions as $value => $label): ?>
                <option value="<?= app_escape((string) $value) ?>"<?= $filterMember === (string) $value ? ' selected' : '' ?>><?= app_escape($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label for="log-source">Source</label>
              <select id="log-source" name="source">
                <option value="">Toutes les sources</option>
                <?php foreach (ATTENDANCE_LOG_SOURCES as $value => $label): ?>
                <option value="<?= app_escape($value) ?>"<?= $filterSource === $value ? ' selected' : '' ?>><?= app_escape($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-actions">
              <button class="secondary-button compact-button" type="submit">Filtrer</button>
              <?php if ($filterQuery !== []): ?><a class="section-note page-switch" href="attendance-log.php">Effacer les filtres</a><?php endif; ?>
              <a class="section-note page-switch" href="attendance-log.php?<?= app_escape(http_build_query($filterQuery + ['format' => 'csv'])) ?>">Exporter en CSV <span aria-hidden="true">↓</span></a>
            </div>
          </form>

          <h2>Entrées <span class="count-badge"><?= $totalEntries ?></span></h2>
          <?php if ($totalEntries > ATTENDANCE_LOG_DISPLAY_LIMIT): ?>
          <p class="muted-copy">Les <?= ATTENDANCE_LOG_DISPLAY_LIMIT ?> entrées les plus récentes sont affichées. Affinez les filtres ou exportez en CSV pour tout consulter.</p>
          <?php endif; ?>
          <?php if ($entries === []): ?>
          <p class="muted-copy">Aucune entrée enregistrée<?= $filterQuery !== [] ? ' pour ces filtres' : '' ?>.</p>
          <?php else: ?>
          <?php app_render_attendance_log_table($entries, true, $existingEventIds); ?>
          <?php endif; ?>
        </section>
      </section>
<?php app_footer(); ?>
