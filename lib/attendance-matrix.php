<?php
declare(strict_types=1);

// Attendance matrix shared by the Présences page (all dates) and the Présences tab of a date.
// Managers enter the answers of the members of the sub-sections they manage; administrators manage all of them.

// Active members of the sub-sections managed by $manager, sorted by name.
function app_matrix_members(array $manager): array
{
    $managedLeaves = app_user_managed_leaves($manager);
    $members = [];
    foreach (read_json_file(app_data_path('users.json')) as $account) {
        if (!app_is_active_user($account)) {
            continue;
        }
        $leaves = array_values(array_intersect(app_expand_sections(app_user_sections($account)), $managedLeaves));
        if ($leaves !== []) {
            $members[$account['id']] = [
                'id' => $account['id'],
                'username' => $account['username'],
                'role' => $account['role'],
                'sections' => $account['sections'] ?? [],
                'section_leads' => $account['section_leads'] ?? [],
                'leaves' => $leaves,
            ];
        }
    }
    uasort($members, static fn (array $first, array $second): int => strcasecmp($first['username'], $second['username']));
    return $members;
}

// Sub-sections of a date within the manager's scope.
function app_matrix_event_leaves(array $event, array $managedLeaves): array
{
    $sections = isset($event['sections']) && is_array($event['sections'])
        ? array_filter($event['sections'], 'is_string')
        : [];
    return array_values(array_intersect(app_expand_sections($sections), $managedLeaves));
}

// Column of the matrix for a date, or null when the date is invalid or outside the manager's scope.
function app_matrix_event(mixed $event, array $managedLeaves): ?array
{
    if (
        !is_array($event)
        || !is_string($event['id'] ?? null)
        || !is_string($event['title'] ?? null)
        || !is_string($event['date'] ?? null)
    ) {
        return null;
    }
    $timezone = new DateTimeZone('Europe/Paris');
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $event['date'], $timezone);
    if ($date === false || $date->format('Y-m-d') !== $event['date']) {
        return null;
    }
    $endDate = is_string($event['end_date'] ?? null)
        ? DateTimeImmutable::createFromFormat('!Y-m-d', $event['end_date'], $timezone)
        : false;
    if ($endDate === false || $endDate->format('Y-m-d') !== $event['end_date'] || $endDate < $date) {
        $endDate = null;
    }
    $leaves = app_matrix_event_leaves($event, $managedLeaves);
    if ($leaves === []) {
        return null;
    }
    return [
        'id' => $event['id'],
        'title' => $event['title'],
        'date' => $date,
        'sort' => app_event_sort_key($event),
        'end_date' => $endDate,
        'status' => is_string($event['status'] ?? null) && isset(EVENT_STATUSES[$event['status']]) ? $event['status'] : 'pending',
        'leaves' => $leaves,
        'attendance' => isset($event['attendance']) && is_array($event['attendance']) ? $event['attendance'] : [],
    ];
}

// The attendance table is a tab of "Gérer les dates"; attendance-matrix.php receives its saves.
function app_matrix_tab_url(bool $showPast = false): string
{
    return 'manage-events.php?onglet=presences' . ($showPast ? '&periode=toutes' : '');
}

// Statuses left out of the attendance table (prospective and turned-down requests).
const MATRIX_HIDDEN_STATUSES = ['lead', 'refused'];

// Columns of the attendance table: dates within the manager's scope, upcoming only unless $showPast,
// without leads and refused dates.
function app_matrix_events(array $manager, bool $showPast): array
{
    $managedLeaves = app_user_managed_leaves($manager);
    $today = new DateTimeImmutable('today', new DateTimeZone('Europe/Paris'));
    $events = [];
    foreach (read_json_file(app_data_path('events.json')) as $event) {
        $item = app_matrix_event($event, $managedLeaves);
        if ($item !== null && !in_array($item['status'], MATRIX_HIDDEN_STATUSES, true) && ($showPast || ($item['end_date'] ?? $item['date']) >= $today)) {
            $events[] = $item;
        }
    }
    usort($events, static fn (array $first, array $second): int => $first['sort'] <=> $second['sort']);
    return $events;
}

// Content of the "Tableau de présence" tab: scope, legend, past dates switch and the table.
function app_matrix_tab(array $manager, bool $showPast): void
{
    if (app_user_managed_leaves($manager) === []) {
        ?>
          <p class="muted-copy">Aucun périmètre de management ne vous est attribué. Demandez à un administrateur de préciser les sections que vous gérez.</p>
<?php
        return;
    }
    $events = app_matrix_events($manager, $showPast);
    ?>
          <?php if ($manager['role'] === 'manager'): ?>
          <p class="matrix-scope">Votre périmètre : <strong><?= app_escape(app_describe_sections(app_user_managed_sections($manager))) ?></strong></p>
          <?php endif; ?>
          <div class="matrix-toolbar">
            <?php app_matrix_legend(app_can_view_history($manager)); ?>
            <a class="section-note page-switch" href="<?= app_escape(app_matrix_tab_url(!$showPast)) ?>"><?= $showPast ? 'Dates à venir uniquement' : 'Afficher aussi les dates passées' ?></a>
          </div>
          <?php if ($events === []): ?>
          <p class="muted-copy"><?= $showPast ? 'Aucune date ne concerne vos sections.' : 'Aucune date à venir ne concerne vos sections.' ?></p>
          <?php else: ?>
          <?php app_matrix_render($manager, $events, app_matrix_members($manager), 'attendance-matrix.php', $showPast ? ['periode' => 'toutes'] : []); ?>
          <?php endif; ?>
<?php
}

// Answer shown in a cell; '' while the member has not answered (empty cell).
function app_matrix_response(array $event, string $userId): string
{
    $response = app_attendance_response($event['attendance'] ?? null, $userId);
    return $response === ATTENDANCE_NO_RESPONSE ? '' : $response;
}

// Tooltip of a cell: who entered the answer and when (administrators only), and whether
// it was entered by someone else than the member (shown with a blue dot).
function app_matrix_audit(?array $lastEntry, string $memberId, bool $hasAnswer, bool $showAudit): array
{
    if (!$showAudit) {
        return ['text' => $hasAnswer ? 'réponse enregistrée' : 'aucune réponse', 'proxy' => false];
    }
    $isProxy = $lastEntry !== null && ($lastEntry['by_id'] !== $memberId || isset($lastEntry['impersonator_id']));
    if ($lastEntry !== null) {
        $text = ($isProxy ? 'Saisi par ' . (string) ($lastEntry['by'] ?? '?') : 'Réponse du membre') . ' le ' . app_format_log_time($lastEntry['at']);
    } else {
        $text = $hasAnswer ? 'Réponse antérieure au journal d’audit' : 'Aucune réponse';
    }
    return ['text' => $text, 'proxy' => $isProxy];
}

// Saves the submitted matrix (POST), then redirects to $redirectUrl.
// With ajax=1 (automatic saving of one cell), answers in JSON instead of redirecting. Only cells of members
// and dates within the manager's scope are accepted; every change goes to the audit trail.
function app_matrix_save(array $manager, string $redirectUrl): never
{
    $isAjax = ($_POST['ajax'] ?? '') === '1';
    if ($isAjax && !hash_equals(app_csrf_token(), is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '')) {
        app_matrix_json(403, ['ok' => false, 'message' => 'La vérification de sécurité a échoué. Rechargez la page.']);
    }
    app_verify_csrf();
    $maxInputVars = (int) ini_get('max_input_vars');
    if ($maxInputVars > 0 && count($_POST, COUNT_RECURSIVE) >= $maxInputVars) {
        app_flash('Trop de cases envoyées en une fois : aucune modification n’a été enregistrée. Activez JavaScript ou affichez moins de dates.', 'error');
        app_redirect($redirectUrl);
    }
    $submitted = $_POST['attendance'] ?? [];
    $originalsInput = $_POST['originals'] ?? '';
    if (!is_array($submitted) || !is_string($originalsInput)) {
        http_response_code(400);
        exit('Données de présence invalides.');
    }
    try {
        $originals = json_decode($originalsInput, true, 8, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        $originals = [];
    }
    $originals = is_array($originals) ? $originals : [];

    $members = app_matrix_members($manager);
    $managedLeaves = app_user_managed_leaves($manager);
    $changed = 0;
    $rejected = 0;
    $savedEntries = [];
    $impersonator = app_impersonator();
    update_json_file(app_data_path('events.json'), static function (array $events) use ($submitted, $originals, $members, $managedLeaves, $manager, $impersonator, &$changed, &$rejected, &$savedEntries): array {
        $logEntries = [];
        foreach ($events as &$event) {
            $eventId = is_array($event) ? ($event['id'] ?? null) : null;
            if (!is_string($eventId) || !isset($submitted[$eventId]) || !is_array($submitted[$eventId])) {
                continue;
            }
            $eventLeaves = app_matrix_event_leaves($event, $managedLeaves);
            $eventOriginals = isset($originals[$eventId]) && is_array($originals[$eventId]) ? $originals[$eventId] : [];
            $attendance = isset($event['attendance']) && is_array($event['attendance']) ? $event['attendance'] : [];
            foreach ($submitted[$eventId] as $userId => $response) {
                $userId = (string) $userId;
                // An empty cell ("Sans réponse") withdraws the member's answer.
                if (
                    !is_string($response)
                    || app_event_attendance_locked($event)
                    || ($response !== '' && !isset(ATTENDANCE_RESPONSES[$response]))
                    || !isset($members[$userId])
                    || array_intersect($eventLeaves, $members[$userId]['leaves']) === []
                ) {
                    $rejected++;
                    continue;
                }
                $previous = $attendance[$userId] ?? null;
                if ($response === '') {
                    // Nothing to withdraw (no answer yet, or the page showed no answer).
                    if ($previous === null || ($eventOriginals[$userId] ?? null) === '') {
                        continue;
                    }
                    $logEntries[] = app_attendance_log_entry($event, $members[$userId], $previous, ATTENDANCE_NO_RESPONSE, $manager, 'matrix', $impersonator);
                    unset($attendance[$userId]);
                    $changed++;
                    continue;
                }
                if ($response === ($eventOriginals[$userId] ?? null) || $response === $previous) {
                    continue;
                }
                $logEntries[] = app_attendance_log_entry($event, $members[$userId], $previous, $response, $manager, 'matrix', $impersonator);
                $attendance[$userId] = $response;
                $changed++;
            }
            if ($attendance !== []) {
                $event['attendance'] = $attendance;
            } else {
                unset($event['attendance']);
            }
        }
        unset($event);
        app_attendance_log_append($logEntries);
        $savedEntries = $logEntries;
        return $events;
    });

    if ($isAjax) {
        if ($rejected > 0) {
            app_matrix_json(422, ['ok' => false, 'message' => 'Réponse refusée : ce membre ou cette date est hors de vos sections, ou la date est terminée, annulée ou refusée.']);
        }
        $entry = end($savedEntries);
        $audit = $entry !== false
            ? app_matrix_audit($entry, $entry['member_id'], $entry['response'] !== ATTENDANCE_NO_RESPONSE, app_can_view_history($manager))
            : null;
        app_matrix_json(200, ['ok' => true, 'changed' => $changed, 'audit' => $audit]);
    }

    if ($rejected > 0) {
        app_flash($changed . ' réponse(s) enregistrée(s) ; ' . $rejected . ' case(s) ignorée(s) car hors de vos sections, sur une date terminée, annulée ou refusée, ou invalides.', 'error');
    } else {
        app_flash($changed === 0 ? 'Aucune modification à enregistrer.' : $changed . ' réponse' . ($changed > 1 ? 's enregistrées.' : ' enregistrée.'));
    }
    app_redirect($redirectUrl);
}

function app_matrix_json(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

// Legend of the matrix (states, proxy marker for administrators, keyboard hint).
function app_matrix_legend(bool $showAudit): void
{
    ?>
          <ul class="matrix-legend" aria-label="Légende">
            <li><span class="matrix-legend-icon" data-value="present" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M5 12.5l4.2 4.2L19 7"/></svg></span> Présent</li>
            <li><span class="matrix-legend-icon" data-value="absent" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M6.5 6.5l11 11M17.5 6.5l-11 11"/></svg></span> Absent</li>
            <li><span class="matrix-legend-icon" data-value="undecided" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M9.2 9.2a2.9 2.9 0 1 1 4.3 2.5c-.9.5-1.5 1.2-1.5 2.3v.5"/><path d="M12 18h.01"/></svg></span> Je ne sais pas</li>
            <li><span class="matrix-legend-icon" data-value="" aria-hidden="true"><svg viewBox="0 0 24 24"></svg></span> Sans réponse</li>
            <?php if ($showAudit): ?>
            <li><span class="matrix-legend-proxy" aria-hidden="true"></span> Saisi par un tiers</li>
            <?php endif; ?>
            <li class="matrix-legend-hint">Cliquez sur une case pour changer l’état · touches P, A, N, et S pour « Sans réponse » · flèches pour se déplacer</li>
          </ul>
<?php
}

// Matrix form: members grouped by branch and sub-section in rows, $events in columns.
// $hiddenFields are posted with the form (for instance the date to come back to).
// With $onlyEventLeaves, sub-sections not concerned by any of the dates are left out.
function app_matrix_render(array $manager, array $events, array $members, string $formAction, array $hiddenFields = [], bool $onlyEventLeaves = false): void
{
    $managedLeaves = app_user_managed_leaves($manager);
    $eventLeaves = array_merge(...array_map(static fn (array $event): array => $event['leaves'], $events));
    $originals = [];
    foreach ($events as $event) {
        foreach ($members as $member) {
            if (array_intersect($event['leaves'], $member['leaves']) !== []) {
                $originals[$event['id']][$member['id']] = app_matrix_response($event, $member['id']);
            }
        }
    }

    // The audit trail (who entered what, and when) is reserved for administrators and organizers.
    $showAudit = app_can_view_history($manager);
    $lastEntries = [];
    foreach ($showAudit ? app_attendance_log_read() : [] as $entry) {
        $lastEntries[$entry['event_id']][$entry['member_id']] = $entry;
    }

    $sectionLabels = app_section_labels();
    $branches = [];
    foreach (app_section_tree() as $branch => $definition) {
        $children = array_values(array_intersect(array_keys($definition['children']), $managedLeaves));
        if ($onlyEventLeaves) {
            $children = array_values(array_intersect($children, $eventLeaves));
        }
        if ($children !== []) {
            $branches[$branch] = $children;
        }
    }
    $weekdayNames = ['', 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.', 'dim.'];
    $monthNames = ['', 'janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
    $isSingle = count($events) === 1;
    ?>
        <form method="post" action="<?= app_escape($formAction) ?>" class="matrix-form" data-attendance-matrix>
          <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
          <?php foreach ($hiddenFields as $name => $value): ?>
          <input type="hidden" name="<?= app_escape($name) ?>" value="<?= app_escape($value) ?>">
          <?php endforeach; ?>
          <input type="hidden" name="originals" value="<?= app_escape(json_encode($originals, JSON_THROW_ON_ERROR)) ?>">
          <div class="matrix-scroll<?= $isSingle ? ' is-single' : '' ?>">
            <table class="attendance-matrix">
              <caption class="sr-only">Présences des membres par date</caption>
              <thead>
                <tr>
                  <th scope="col" class="matrix-corner">Membre</th>
                  <?php foreach ($events as $event): ?>
                  <?php
                      $fullDate = $event['date']->format('d/m/Y') . ($event['end_date'] !== null ? ' au ' . $event['end_date']->format('d/m/Y') : '');
                  ?>
                  <th scope="col" class="matrix-date status-<?= app_escape($event['status']) ?>" title="<?= app_escape($event['title'] . ' — ' . $fullDate . ' — ' . app_statuses()[$event['status']]) ?>">
                    <span class="matrix-date-weekday"><?= app_escape($weekdayNames[(int) $event['date']->format('N')]) ?></span>
                    <span class="matrix-date-day"><?= app_escape($event['date']->format('j')) ?> <?= app_escape($monthNames[(int) $event['date']->format('n')]) ?></span>
                    <a class="matrix-date-title" href="event.php?id=<?= rawurlencode($event['id']) ?>"><?= app_escape($event['title']) ?></a>
                  </th>
                  <?php endforeach; ?>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($branches as $branch => $children): ?>
                <tr class="matrix-branch-row">
                  <th scope="rowgroup" colspan="<?= count($events) + 1 ?>"><span><?= app_escape($sectionLabels[$branch]) ?></span></th>
                </tr>
                <?php foreach ($children as $child): ?>
                <?php $childMembers = app_sort_section_members(array_filter($members, static fn (array $member): bool => in_array($child, $member['leaves'], true)), $child); ?>
                <tr class="matrix-subsection-row">
                  <th scope="rowgroup"><?= app_escape($sectionLabels[$child]) ?> <span class="count-badge"><?= count($childMembers) ?></span></th>
                  <?php foreach ($events as $event): ?>
                  <?php if (in_array($child, $event['leaves'], true) && $childMembers !== []): ?>
                  <?php $presentCount = count(array_filter($childMembers, static fn (array $member): bool => app_matrix_response($event, $member['id']) === 'present')); ?>
                  <td class="matrix-count" data-count-event="<?= app_escape($event['id']) ?>" data-count-subsection="<?= app_escape($child) ?>" title="Présents / membres concernés"><?= $presentCount ?>/<?= count($childMembers) ?></td>
                  <?php else: ?>
                  <td class="matrix-count"></td>
                  <?php endif; ?>
                  <?php endforeach; ?>
                </tr>
                <?php if ($childMembers === []): ?>
                <tr class="matrix-empty-row">
                  <td colspan="<?= count($events) + 1 ?>">Aucun membre actif dans cette sous-section.</td>
                </tr>
                <?php endif; ?>
                <?php foreach ($childMembers as $member): ?>
                <tr>
                  <th scope="row" class="matrix-name">
                    <?= app_escape($member['username']) ?>
                    <?php if ($member['role'] !== 'musician'): ?><span class="matrix-role"><?= app_escape(app_roles()[$member['role']]) ?></span><?php endif; ?>
                  </th>
                  <?php foreach ($events as $event): ?>
                  <?php if (in_array($child, $event['leaves'], true)): ?>
                  <?php
                      $response = app_matrix_response($event, $member['id']);
                      $fieldId = 'cell-' . $child . '-' . $event['id'] . '-' . $member['id'];
                      $cellLabel = $member['username'] . ', ' . $event['title'] . ' du ' . $event['date']->format('d/m/Y');
                      $cellAudit = app_matrix_audit($lastEntries[$event['id']][$member['id']] ?? null, $member['id'], isset($event['attendance'][$member['id']]), $showAudit);
                      $audit = $cellAudit['text'];
                      $isProxy = $cellAudit['proxy'];
                  ?>
                  <?php // Finished, cancelled or refused date: the answer is shown but can no longer be changed. ?>
                  <?php $isLocked = in_array($event['status'], ATTENDANCE_LOCKED_STATUSES, true); ?>
                  <td class="matrix-cell<?= $isProxy ? ' is-proxy' : '' ?><?= $isLocked ? ' is-locked' : '' ?>" title="<?= app_escape(($response === '' ? app_no_response_label() : app_attendance_responses()[$response]) . ' — ' . $audit) ?>">
                    <label class="sr-only" for="<?= app_escape($fieldId) ?>"><?= app_escape($cellLabel) ?></label>
                    <select class="matrix-select"<?= $isLocked ? ' disabled' : '' ?> id="<?= app_escape($fieldId) ?>" name="attendance[<?= app_escape($event['id']) ?>][<?= app_escape($member['id']) ?>]" data-original="<?= app_escape($response) ?>" data-event="<?= app_escape($event['id']) ?>" data-member="<?= app_escape($member['id']) ?>" data-subsection="<?= app_escape($child) ?>" data-label="<?= app_escape($cellLabel) ?>" data-audit="<?= app_escape($audit) ?>">
                      <option value=""<?= $response === '' ? ' selected' : '' ?>><?= app_escape(app_no_response_label()) ?></option>
                      <?php foreach (app_attendance_responses() as $value => $label): ?>
                      <option value="<?= app_escape($value) ?>"<?= $response === $value ? ' selected' : '' ?>><?= app_escape($label) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </td>
                  <?php else: ?>
                  <td class="matrix-na"><span aria-hidden="true">·</span><span class="sr-only">Non concerné</span></td>
                  <?php endif; ?>
                  <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
                <?php endforeach; ?>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <div class="matrix-savebar" data-savebar>
            <p class="matrix-savebar-status" data-savebar-status role="status">Modifiez les cases puis enregistrez.</p>
            <div class="form-actions" data-manual-save>
              <button class="secondary-button compact-button" type="reset">Annuler les modifications</button>
              <button class="primary-button compact-button" type="submit" data-save-button>Enregistrer</button>
            </div>
          </div>
        </form>
        <script src="<?= app_escape(app_asset_url('attendance-matrix.js')) ?>" defer></script>
<?php
}
