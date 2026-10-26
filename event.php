<?php
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';
$user = app_require_login();
$isAdmin = $user['role'] === 'admin';
$canSeeHistory = app_can_view_history($user);
$isManager = in_array($user['role'], EVENT_MANAGER_ROLES, true);

$eventId = $_GET['id'] ?? '';
$event = null;
if (is_string($eventId) && $eventId !== '') {
    foreach (read_json_file(app_data_path('events.json')) as $candidate) {
        if (is_array($candidate) && ($candidate['id'] ?? null) === $eventId) {
            $event = $candidate;
            break;
        }
    }
}
$date = $event !== null ? app_parse_date($event['date'] ?? null) : null;

$userLeaves = app_expand_sections(app_user_sections($user));
$eventLeaves = [];
if ($event !== null) {
    $rawSections = isset($event['sections']) && is_array($event['sections']) ? array_filter($event['sections'], 'is_string') : [];
    $eventLeaves = array_values(array_intersect(app_expand_sections($rawSections), app_section_leaves()));
}
// Same visibility rule as the agenda: an artist only sees the dates and sections that concern them,
// and a reserved date is hidden from the managers of other sub-sections.
$visibleLeaves = $isManager ? $eventLeaves : array_values(array_intersect($eventLeaves, $userLeaves));
if ($event === null || $date === null || !is_string($event['title'] ?? null) || $visibleLeaves === [] || !app_user_can_see_event($user, $event)) {
    http_response_code(404);
    app_header(__('date_introuvable'), $user);
    ?>
      <section class="management-section">
        <p class="management-panel muted-copy"><?= app_escape(__('cette_date_nexiste_concerne_vos_sections')) ?> <a class="page-switch" href="index.php"><?= app_escape(__('retour_lagenda')) ?></a></p>
      </section>
<?php
    app_footer();
    exit;
}

$status = is_string($event['status'] ?? null) && isset(EVENT_STATUSES[$event['status']]) ? $event['status'] : 'pending';
// Artists see the roadmap once the date is confirmed; managers and admins prepare it beforehand.
// A date marked "roadmap not available" has no roadmap tab at all.
$showRoadmapTab = app_roadmap_available($event) && ($isManager || $status === 'confirmed');
// Polls hidden from members (by a manager, or on a finished, cancelled or refused date) stay visible to managers.
$polls = app_visible_polls($event, $user);
$isConcerned = app_user_concerned_by_event($user, $event);
// Changes since the member last opened this date, then the date counts as seen (not while an
// administrator is logged in as the member).
$changeNotice = app_event_change_notice($event, $user, app_seen_state($user));
if (app_impersonator() === null) {
    app_mark_event_seen($user, (string) $event['id']);
}
// Managers only edit the dates whose sub-sections are all in their management scope.
$canEditEvent = app_user_can_edit_event($user, $event);
// Attendance entry for this date only, with the same access rules as the Présences page:
// managers and administrators, for the members of the sub-sections they manage.
$matrixEvent = $isManager ? app_matrix_event($event, app_user_managed_leaves($user)) : null;
$requestedTab = is_string($_GET['tab'] ?? null) ? $_GET['tab'] : '';
$requestedPollId = str_starts_with($requestedTab, 'sondage-') ? substr($requestedTab, 8) : '';
$tab = match (true) {
    $requestedTab === 'feuille-de-route' && $showRoadmapTab => 'feuille-de-route',
    $requestedTab === 'historique' && $canSeeHistory => 'historique',
    $requestedTab === 'presences' && $matrixEvent !== null => 'presences',
    $requestedPollId !== '' && isset($polls[$requestedPollId]) => 'poll',
    $requestedTab === 'nouveau-sondage' && $isManager => 'new-poll',
    default => 'details',
};
$activePoll = $tab === 'poll' ? $polls[$requestedPollId] : null;
$roadmap = app_event_roadmap($event);
$endDate = app_parse_date($event['end_date'] ?? null);
if ($endDate !== null && $endDate < $date) {
    $endDate = null;
}
$allDay = ($event['all_day'] ?? false) === true;
$timeRange = app_event_time_range($event);
$location = is_string($event['location'] ?? null) ? $event['location'] : '';
$url = is_string($event['url'] ?? null) ? $event['url'] : '';
$validUrl = filter_var($url, FILTER_VALIDATE_URL) !== false && in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true);
$attendance = isset($event['attendance']) && is_array($event['attendance']) ? $event['attendance'] : [];
$sectionLabels = app_section_labels();

$formatLongDate = static fn (DateTimeImmutable $value): string => app_long_date($value, true, true, false);

function eventResponse(array $attendance, string $userId): string
{
    return app_attendance_response($attendance, $userId);
}

$canRespond = array_intersect($visibleLeaves, $userLeaves) !== [];
$myResponse = eventResponse($attendance, $user['id']);

$responsesBySection = [];
// Total row: each member once, even when they belong to several sub-sections of the date.
$responseTotals = ['present' => [], 'absent' => [], 'undecided' => [], ATTENDANCE_NO_RESPONSE => []];
$concernedMembers = [];
// "Réponses par sous-section": every sub-section of the date, for every member who can open it.
$members = array_filter(read_json_file(app_data_path('users.json')), 'app_is_active_user');
usort($members, static fn (array $first, array $second): int => strcasecmp($first['username'], $second['username']));
if ($isManager) {
    $concernedMembers = array_values(array_filter($members, static fn (array $member): bool => app_user_concerned_by_event($member, $event)));
}
foreach ($eventLeaves as $leaf) {
    $responsesBySection[$leaf] = ['present' => [], 'absent' => [], 'undecided' => [], ATTENDANCE_NO_RESPONSE => []];
    // The responsable of the sub-section first.
    foreach (app_sort_section_members($members, $leaf) as $member) {
        if (in_array($leaf, app_expand_sections(app_user_sections($member)), true)) {
            $memberResponse = eventResponse($attendance, $member['id']);
            $responsesBySection[$leaf][$memberResponse][] = $member['username'];
            $responseTotals[$memberResponse][$member['id']] = true;
        }
    }
}

$logEntries = [];
if ($canSeeHistory) {
    $logEntries = array_reverse(array_values(array_filter(
        app_attendance_log_read(true),
        static fn (array $entry): bool => $entry['event_id'] === $event['id']
    )));
}
$tabUrl = static fn (string $name): string => 'event.php?id=' . rawurlencode($event['id']) . ($name === 'details' ? '' : '&tab=' . $name);

app_header($event['title'], $user);
?>
      <section class="management-section">
        <div class="eyebrow-row">
          <p class="eyebrow"><span class="eyebrow-line"></span> <?= app_escape(__('detail_date_majuscules')) ?></p>
          <?php app_page_links([['Retour à l’agenda', 'index.php#event-' . rawurlencode($event['id'])], $isManager ? [__('tableau_presence'), app_matrix_tab_url()] : null]); ?>
        </div>
        <div class="section-heading">
          <div>
            <h1><?= app_escape($event['title']) ?><span class="heading-dot">.</span><?= $changeNotice !== null && $changeNotice['type'] === 'changed' ? app_change_badge($changeNotice) : '' ?></h1>
            <?php if ($changeNotice !== null): ?>
            <p class="change-notice"><?= $changeNotice['type'] === 'new' ? app_change_badge($changeNotice) . ' ' . app_escape(__('nouvelle_date_depuis_votre_derniere_visite_phrase')) : app_escape(__('modifie_depuis_votre_derniere_consultation_fields_phrase', ['fields' => implode(', ', array_map('app_label', $changeNotice['fields']))])) ?></p>
            <?php endif; ?>
          </div>
          <div class="page-actions">
            <?php if ($canEditEvent): ?>
            <a class="secondary-button compact-button" href="manage-events.php?edit=<?= rawurlencode($event['id']) ?>"><?= app_escape(__('modifier')) ?></a>
            <?php endif; ?>
          </div>
        </div>

        <nav class="detail-tabs" aria-label="<?= app_escape(__('sections_page')) ?>">
          <a class="detail-tab" href="<?= app_escape($tabUrl('details')) ?>"<?= $tab === 'details' ? ' aria-current="page"' : '' ?>><?= app_escape(__('details')) ?></a>
          <?php if ($matrixEvent !== null): ?>
          <a class="detail-tab" href="<?= app_escape($tabUrl('presences')) ?>"<?= $tab === 'presences' ? ' aria-current="page"' : '' ?>><?= app_escape(__('presences')) ?></a>
          <?php endif; ?>
          <?php if ($showRoadmapTab): ?>
          <a class="detail-tab" href="<?= app_escape($tabUrl('feuille-de-route')) ?>"<?= $tab === 'feuille-de-route' ? ' aria-current="page"' : '' ?>><?= app_escape(__('feuille_route')) ?></a>
          <?php endif; ?>
          <?php foreach ($polls as $poll): ?>
          <?php
              $isCurrent = $activePoll !== null && $activePoll['id'] === $poll['id'];
              $tabLabel = app_truncate($poll['question'], 32) . (strlen($poll['question']) > strlen(app_truncate($poll['question'], 32)) ? '…' : '');
              $isPending = $isConcerned && !$poll['closed'] && app_poll_hidden_reason($event, $poll) === null && !isset($poll['responses'][$user['id']]);
          ?>
          <a class="detail-tab poll-tab" href="<?= app_escape($tabUrl('sondage-' . $poll['id'])) ?>" title="<?= app_escape(__('sondage_question', ['question' => $poll['question']])) ?>"<?= $isCurrent ? ' aria-current="page"' : '' ?>>
            <?= app_escape($tabLabel) ?>
            <?php if ($isManager): ?><span class="count-badge" title="<?= app_escape(__('reponses_recues')) ?>"><?= count($poll['responses']) ?></span><?php endif; ?>
            <?php if (($hiddenReason = app_poll_hidden_reason($event, $poll)) !== null): ?><span class="poll-hidden-mark" title="<?= app_escape(__('masque_membres_reason', ['reason' => $hiddenReason])) ?>"><?= app_escape(__('masque')) ?></span><?php endif; ?>
            <?php if ($isPending): ?><span class="poll-pending-dot" title="<?= app_escape(__('sondage_remplir')) ?>"><span class="sr-only"><?= app_escape(__('remplir_parentheses')) ?></span></span><?php endif; ?>
            <?php if ($poll['closed']): ?><span class="poll-closed-badge"><?= app_escape(__('clos')) ?></span><?php endif; ?>
          </a>
          <?php endforeach; ?>
          <?php if ($isManager): ?>
          <a class="detail-tab poll-new-tab" href="<?= app_escape($tabUrl('nouveau-sondage')) ?>"<?= $tab === 'new-poll' ? ' aria-current="page"' : '' ?>><?= app_escape(__('plus_sondage')) ?></a>
          <?php endif; ?>
          <?php if ($canSeeHistory): ?>
          <a class="detail-tab" href="<?= app_escape($tabUrl('historique')) ?>"<?= $tab === 'historique' ? ' aria-current="page"' : '' ?>><?= app_escape(__('journal_daudit')) ?> <span class="count-badge"><?= count($logEntries) ?></span></a>
          <?php endif; ?>
        </nav>

        <?php if ($tab === 'feuille-de-route'): ?>
        <section class="management-panel detail-panel has-tabs roadmap-panel" aria-label="<?= app_escape(__('feuille_route')) ?>">
          <div class="roadmap-actions">
            <?php if (!app_roadmap_is_empty($roadmap)): ?>
            <button class="primary-button compact-button" type="button" data-print><?= app_escape(__('imprimer_enregistrer_pdf')) ?></button>
            <?php endif; ?>
            <?php if ($canEditEvent): ?>
            <a class="secondary-button compact-button" href="manage-events.php?edit=<?= rawurlencode($event['id']) ?>#roadmap"><?= app_escape(__('modifier_feuille_route')) ?></a>
            <?php endif; ?>
          </div>
          <?php $shareToken = app_roadmap_share_token($event); ?>
          <?php if ($shareToken !== null || $isManager): ?>
          <div class="roadmap-share">
            <span class="roadmap-form-label"><?= app_escape(__('lien_public')) ?></span>
            <?php if ($shareToken !== null): ?>
            <div class="roadmap-share-link">
              <label class="sr-only" for="roadmap-share-url"><?= app_escape(__('lien_public_feuille_route')) ?></label>
              <input id="roadmap-share-url" type="url" readonly value="<?= app_escape(app_roadmap_share_url($shareToken)) ?>">
              <button class="secondary-button compact-button" type="button" data-copy="roadmap-share-url"><?= app_escape(__('copier_lien')) ?></button>
            </div>
            <p class="field-help"><?= app_escape(__('toute_personne_ce_lien_peut_consulter')) ?></p>
            <?php if ($isManager): ?>
            <div class="roadmap-share-tools">
              <form method="post" action="roadmap-share-action.php" onsubmit="return confirm(<?= app_escape(json_encode(__('creer_nouveau_lien_lancien_cessera_fonctionner'), JSON_UNESCAPED_UNICODE)) ?>);">
                <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
                <input type="hidden" name="event_id" value="<?= app_escape($event['id']) ?>">
                <input type="hidden" name="action" value="renew">
                <button class="text-button" type="submit"><?= app_escape(__('renouveler_lien')) ?></button>
              </form>
              <form method="post" action="roadmap-share-action.php" onsubmit="return confirm(<?= app_escape(json_encode(__('desactiver_lien_public_cessera_fonctionner'), JSON_UNESCAPED_UNICODE)) ?>);">
                <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
                <input type="hidden" name="event_id" value="<?= app_escape($event['id']) ?>">
                <input type="hidden" name="action" value="revoke">
                <button class="text-button" type="submit"><?= app_escape(__('desactiver_lien')) ?></button>
              </form>
            </div>
            <?php endif; ?>
            <?php else: ?>
            <form method="post" action="roadmap-share-action.php" class="roadmap-share-tools">
              <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
              <input type="hidden" name="event_id" value="<?= app_escape($event['id']) ?>">
              <input type="hidden" name="action" value="create">
              <span class="field-help"><?= app_escape(__('aucun_lien_public_linstant_phrase')) ?></span>
              <button class="secondary-button compact-button" type="submit"><?= app_escape(__('creer_lien_public')) ?></button>
            </form>
            <?php endif; ?>
          </div>
          <?php endif; ?>
          <?php if (app_roadmap_is_empty($roadmap)): ?>
          <p class="muted-copy"><?= app_escape(__('feuille_route_cette_date_na_encore')) ?></p>
          <?php else: ?>
          <?php app_render_roadmap($event, $roadmap, app_settings()); ?>
          <?php endif; ?>
        </section>
        <?php elseif ($tab === 'poll'): ?>
        <?php $myAnswer = $activePoll['responses'][$user['id']] ?? null; ?>
        <section class="management-panel detail-panel has-tabs poll-panel" aria-label="<?= app_escape(__('sondage')) ?>">
          <div class="detail-panel-heading">
            <h2 class="detail-heading"><?= app_escape($activePoll['question']) ?></h2>
            <span class="status-badge <?= $activePoll['closed'] ? 'status-badge-cancelled' : 'status-badge-pending' ?>"><?= $activePoll['closed'] ? __('clos') : __('ouvert') ?></span>
          </div>
          <?php $activeHiddenReason = app_poll_hidden_reason($event, $activePoll); ?>
          <?php if ($activeHiddenReason !== null): ?>
          <p class="poll-hidden-notice"><strong><?= app_escape(__('sondage_masque_membres')) ?></strong> <?= app_escape(__('reason_seuls_managers_organisateurs_administrateurs_voient', ['reason' => $activeHiddenReason])) ?><?= $activePoll['hidden'] && $activeHiddenReason === __('masque_temporairement') ? ' ' . __('utilisez_reafficher_sondage_rendre_nouveau_visible') : '' ?></p>
          <?php endif; ?>
          <?php if ($activePoll['description'] !== ''): ?>
          <p class="poll-description"><?= nl2br(app_linkify($activePoll['description']), false) ?></p>
          <?php endif; ?>

          <?php if ($isManager): ?>
          <details class="poll-manage">
            <summary><?= app_escape(__('modifier_sondage')) ?></summary>
            <form class="form-stack" method="post" action="poll-action.php">
              <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
              <input type="hidden" name="event_id" value="<?= app_escape($event['id']) ?>">
              <input type="hidden" name="poll_id" value="<?= app_escape($activePoll['id']) ?>">
              <input type="hidden" name="action" value="update">
              <label for="poll-question"><?= app_escape(__('question')) ?></label>
              <input id="poll-question" name="question" maxlength="<?= POLL_QUESTION_MAX_LENGTH ?>" required value="<?= app_escape($activePoll['question']) ?>">
              <label for="poll-description"><?= app_escape(__('commentaire_facultatif')) ?></label>
              <textarea id="poll-description" name="description" rows="2" maxlength="<?= POLL_DESCRIPTION_MAX_LENGTH ?>" placeholder="<?= app_escape(__('precisions_question_reponses')) ?>"><?= app_escape($activePoll['description']) ?></textarea>
              <label for="poll-options"><?= app_escape(__('reponses_possibles')) ?></label>
              <p class="field-help"><?= app_escape(__('reponse_ligne_2_max_reponses_deja', ['max' => POLL_MAX_OPTIONS])) ?></p>
              <textarea id="poll-options" name="options" rows="<?= max(3, count($activePoll['options']) + 1) ?>" required><?= app_escape(implode("\n", $activePoll['options'])) ?></textarea>
              <fieldset class="section-options poll-user-info">
                <legend><?= app_escape(__('informations_membres_afficher_dans_resultats')) ?></legend>
                <div class="checkbox-row">
                  <?php foreach (app_translated(POLL_USER_INFO) as $flag => $label): ?>
                  <label><input type="checkbox" name="user_info[]" value="<?= $flag ?>"<?= in_array($flag, $activePoll['user_info'], true) ? ' checked' : '' ?>> <?= app_escape($label) ?></label>
                  <?php endforeach; ?>
                </div>
              </fieldset>
              <?php // Closing and deleting are separate forms; their buttons sit on the same line as "Enregistrer". ?>
              <div class="form-actions poll-manage-actions">
                <button class="primary-button compact-button" type="submit"><?= app_escape(__('enregistrer')) ?></button>
                <button class="secondary-button compact-button" type="submit" form="poll-toggle-form"><?= $activePoll['closed'] ? __('rouvrir_sondage') : __('clore_sondage') ?></button>
                <button class="secondary-button compact-button" type="submit" form="poll-hide-form"><?= $activePoll['hidden'] ? __('reafficher_sondage') : __('masquer_sondage') ?></button>
                <button class="text-button poll-delete-button" type="submit" form="poll-delete-form"><?= app_escape(__('supprimer_sondage')) ?></button>
              </div>
            </form>
            <form id="poll-toggle-form" method="post" action="poll-action.php">
              <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
              <input type="hidden" name="event_id" value="<?= app_escape($event['id']) ?>">
              <input type="hidden" name="poll_id" value="<?= app_escape($activePoll['id']) ?>">
              <input type="hidden" name="action" value="toggle">
            </form>
            <form id="poll-hide-form" method="post" action="poll-action.php">
              <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
              <input type="hidden" name="event_id" value="<?= app_escape($event['id']) ?>">
              <input type="hidden" name="poll_id" value="<?= app_escape($activePoll['id']) ?>">
              <input type="hidden" name="action" value="hide">
            </form>
            <form id="poll-delete-form" method="post" action="poll-action.php" onsubmit="return confirm(<?= app_escape(json_encode(__('supprimer_ce_sondage_toutes_reponses'), JSON_UNESCAPED_UNICODE)) ?>);">
              <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
              <input type="hidden" name="event_id" value="<?= app_escape($event['id']) ?>">
              <input type="hidden" name="poll_id" value="<?= app_escape($activePoll['id']) ?>">
              <input type="hidden" name="action" value="delete">
            </form>
          </details>
          <?php endif; ?>

          <?php if ($isConcerned && !$activePoll['closed'] && $activeHiddenReason === null): ?>
          <form class="attendance-form poll-form" method="post" action="poll-action.php">
            <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
            <input type="hidden" name="event_id" value="<?= app_escape($event['id']) ?>">
            <input type="hidden" name="poll_id" value="<?= app_escape($activePoll['id']) ?>">
            <input type="hidden" name="action" value="respond">
            <span class="attendance-label"><?= app_escape(__('ma_reponse')) ?></span>
            <div class="attendance-options" role="group" aria-label="<?= app_escape(__('votre_reponse_question', ['question' => $activePoll['question']])) ?>">
              <?php foreach ($activePoll['options'] as $optionId => $label): ?>
              <button class="attendance-option poll-option<?= $myAnswer === $optionId ? ' is-selected' : '' ?>" type="submit" name="option" value="<?= app_escape($optionId) ?>" aria-pressed="<?= $myAnswer === $optionId ? 'true' : 'false' ?>"><?= app_escape($label) ?></button>
              <?php endforeach; ?>
            </div>
          </form>
          <?php elseif ($isConcerned): ?>
          <p class="muted-copy"><?= app_escape(__('ce_sondage_clos_phrase')) ?> <?= $myAnswer !== null ? __('votre_reponse_answer_phrase', ['answer' => '<strong>' . app_escape($activePoll['options'][$myAnswer]) . '</strong>']) : app_escape(__('vous_ny_avez_repondu_phrase')) ?></p>
          <?php endif; ?>

          <?php if ($isManager): ?>
          <?php
              $answeredIds = array_keys($activePoll['responses']);
              $membersByOption = array_fill_keys(array_keys($activePoll['options']), []);
              $withoutAnswer = [];
              foreach ($concernedMembers as $member) {
                  $answer = $activePoll['responses'][$member['id']] ?? null;
                  if ($answer === null) {
                      $withoutAnswer[] = $member;
                  } else {
                      $membersByOption[$answer][] = $member;
                  }
              }
              // Account information chosen for this poll: badges on names and counts per answer.
              $userInfo = $activePoll['user_info'];
              $flagBadges = ['vegetarian' => __('vege'), 'minor' => __('mineur')];
              $enteredBy = $activePoll['entered_by'];
              $renderMembers = static function (array $members) use ($userInfo, $flagBadges, $enteredBy): string {
                  if ($members === []) {
                      return '<span class="log-sub">—</span>';
                  }
                  return implode(', ', array_map(static function (array $member) use ($userInfo, $flagBadges, $enteredBy): string {
                      $html = app_escape($member['username']);
                      foreach ($userInfo as $flag) {
                          if (($member[$flag] ?? false) === true) {
                              $html .= ' <span class="account-flag account-flag-' . $flag . '">' . $flagBadges[$flag] . '</span>';
                          }
                      }
                      if (isset($enteredBy[$member['id']])) {
                          $html .= ' <span class="poll-entered-by">' . app_escape(__('saisi_name_parentheses', ['name' => $enteredBy[$member['id']]])) . '</span>';
                      }
                      return $html;
                  }, $members));
              };
              $proxyMembers = app_poll_proxy_members($user, $event, $concernedMembers);
              $infoCounts = static function (array $members) use ($userInfo): string {
                  $parts = [];
                  foreach ($userInfo as $flag) {
                      $count = count(array_filter($members, static fn (array $member): bool => ($member[$flag] ?? false) === true));
                      $parts[] = $count . ' ' . strtolower(ACCOUNT_FLAGS[$flag]) . ($count > 1 ? 's' : '');
                  }
                  return $parts === [] || $members === [] ? '' : '<span class="log-sub">dont ' . app_escape(implode(', ', $parts)) . '</span>';
              };
          ?>
          <div class="detail-responses">
            <div class="detail-panel-heading">
              <h2 class="detail-heading"><?= app_escape(__('resultats')) ?> <span class="poll-result-total"><?= app_escape(__n('n_reponse', 'reponses', count($answeredIds))) ?> · <?= app_escape(__n('membre_concerne', 'membres_concernes', count($concernedMembers))) ?></span></h2>
              <div class="poll-export-links">
                <a class="section-note page-switch" href="poll-export.php?<?= app_escape(http_build_query(['event' => $event['id'], 'poll' => $activePoll['id']])) ?>"><?= app_escape(__('exporter_csv')) ?> <span aria-hidden="true">↓</span></a>
                <?php if (count($polls) > 1): ?>
                <a class="section-note page-switch" href="poll-export.php?<?= app_escape(http_build_query(['event' => $event['id']])) ?>"><?= app_escape(__('tous_sondages_csv')) ?> <span aria-hidden="true">↓</span></a>
                <?php endif; ?>
              </div>
            </div>
            <div class="log-scroll">
              <table class="log-table poll-results">
                <thead><tr><th scope="col"><?= app_escape(__('reponse')) ?></th><th scope="col"><?= app_escape(__('nombre')) ?></th><th scope="col"><?= app_escape(__('membres')) ?></th></tr></thead>
                <tbody>
                  <?php foreach ($activePoll['options'] as $optionId => $label): ?>
                  <tr>
                    <th scope="row"><?= app_escape($label) ?></th>
                    <td><span class="log-response log-response-present"><?= count($membersByOption[$optionId]) ?></span><?= $infoCounts($membersByOption[$optionId]) ?></td>
                    <td><?= $renderMembers($membersByOption[$optionId]) ?></td>
                  </tr>
                  <?php endforeach; ?>
                  <tr class="poll-results-pending">
                    <th scope="row"><?= app_escape(__('sans_reponse')) ?></th>
                    <td><span class="log-response"><?= count($withoutAnswer) ?></span><?= $infoCounts($withoutAnswer) ?></td>
                    <td><?= $renderMembers($withoutAnswer) ?></td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <?php if ($proxyMembers !== []): ?>
          <form class="poll-proxy" method="post" action="poll-action.php">
            <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
            <input type="hidden" name="event_id" value="<?= app_escape($event['id']) ?>">
            <input type="hidden" name="poll_id" value="<?= app_escape($activePoll['id']) ?>">
            <input type="hidden" name="action" value="respond_for">
            <span class="roadmap-form-label"><?= app_escape(__('saisir_reponse_membre')) ?></span>
            <div class="poll-proxy-fields">
              <label class="sr-only" for="poll-proxy-member"><?= app_escape(__('membre')) ?></label>
              <select id="poll-proxy-member" name="member_id" required>
                <option value=""><?= app_escape(__('choisir_membre')) ?></option>
                <?php foreach ($proxyMembers as $member): ?>
                <?php $current = $activePoll['responses'][$member['id']] ?? null; ?>
                <option value="<?= app_escape($member['id']) ?>"><?= app_escape($member['username']) ?> — <?= $current !== null ? app_escape($activePoll['options'][$current]) : __('sans_reponse_minuscule') ?></option>
                <?php endforeach; ?>
              </select>
              <label class="sr-only" for="poll-proxy-option"><?= app_escape(__('reponse')) ?></label>
              <select id="poll-proxy-option" name="option">
                <?php foreach ($activePoll['options'] as $optionId => $label): ?>
                <option value="<?= app_escape($optionId) ?>"><?= app_escape($label) ?></option>
                <?php endforeach; ?>
                <option value=""><?= app_escape(__('effacer_reponse_parentheses')) ?></option>
              </select>
              <button class="secondary-button compact-button" type="submit"><?= app_escape(__('enregistrer')) ?></button>
            </div>
            <p class="field-help"><?= in_array($user['role'], ALL_SECTIONS_ROLES, true) ? __('tous_membres_concernes_date_phrase') : __('membres_concernes_date_dans_votre_perimetre') ?> <?= app_escape($activePoll['closed'] ? __('reponse_apparait_comme_saisie_name_jusqua_ce_membre', ['name' => $user['username']]) : __('reponse_apparait_comme_saisie_name_jusqua', ['name' => $user['username']])) ?></p>
          </form>
          <?php endif; ?>


          <?php if (app_can_view_history($user)): ?>
          <?php // Organizers and administrators: one row per member who answered, one column per answer. ?>
          <?php
              $respondents = array_values(array_filter(
                  $concernedMembers,
                  static fn (array $member): bool => isset($activePoll['responses'][$member['id']])
              ));
          ?>
          <div class="detail-responses poll-detail">
            <div class="detail-panel-heading poll-detail-heading">
              <h2 class="detail-heading"><?= app_escape(__('tableau_detaille')) ?></h2>
              <?php if ($respondents !== []): ?>
              <button class="secondary-button compact-button" type="button" data-print-poll-detail><?= app_escape(__('imprimer')) ?></button>
              <?php endif; ?>
            </div>
            <?php // Printed alone (portrait): the date and the question head the table. ?>
            <p class="poll-detail-print-title"><strong><?= app_escape($event['title']) ?></strong><?= $date !== null ? ' — ' . app_escape(ucfirst($formatLongDate($date))) : '' ?><br><?= app_escape($activePoll['question']) ?></p>
            <?php if ($respondents === []): ?>
            <p class="muted-copy"><?= app_escape(__('personne_na_encore_repondu_ce_sondage_phrase')) ?></p>
            <?php else: ?>
            <?php // Filter on the login, first name or last name (menu.js; ignores case and accents). ?>
            <div class="poll-detail-filter" data-poll-filter hidden>
              <label for="poll-detail-search"><?= app_escape(__('filtrer')) ?></label>
              <input id="poll-detail-search" type="search" placeholder="<?= app_escape(__('identifiant_prenom_nom')) ?>" autocomplete="off" aria-describedby="poll-detail-count">
              <span class="field-help" id="poll-detail-count" aria-live="polite" data-poll-filter-count></span>
            </div>
            <div class="log-scroll">
              <table class="log-table poll-detail-table" data-poll-filter-table>
                <thead>
                  <tr>
                    <th scope="col"><?= app_escape(__('identifiant')) ?></th>
                    <th scope="col"><?= app_escape(__('prenom')) ?></th>
                    <th scope="col"><?= app_escape(__('nom')) ?></th>
                    <?php // Account information checked in "Informations des membres à afficher dans les résultats", then the answers. ?>
                    <?php foreach ($userInfo as $flag): ?>
                    <th scope="col" class="poll-detail-option poll-detail-info"><?= app_escape(app_label(POLL_USER_INFO[$flag])) ?></th>
                    <?php endforeach; ?>
                    <?php foreach ($activePoll['options'] as $label): ?>
                    <th scope="col" class="poll-detail-option"><?= app_escape($label) ?></th>
                    <?php endforeach; ?>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($respondents as $member): ?>
                  <?php $answer = $activePoll['responses'][$member['id']]; ?>
                  <tr data-search="<?= app_escape(implode(' ', [$member['username'], (string) ($member['first_name'] ?? ''), (string) ($member['last_name'] ?? '')])) ?>">
                    <th scope="row"><?= app_escape($member['username']) ?><?php if (isset($enteredBy[$member['id']])): ?> <span class="poll-entered-by"><?= app_escape(__('saisi_name_parentheses', ['name' => $enteredBy[$member['id']]])) ?></span><?php endif; ?></th>
                    <td><?= app_escape((string) ($member['first_name'] ?? '')) ?></td>
                    <td><?= app_escape((string) ($member['last_name'] ?? '')) ?></td>
                    <?php foreach ($userInfo as $flag): ?>
                    <td class="poll-detail-option poll-detail-info"><?= ($member[$flag] ?? false) === true ? '<strong>Oui</strong>' : '<span class="log-sub">Non</span>' ?></td>
                    <?php endforeach; ?>
                    <?php foreach (array_keys($activePoll['options']) as $optionId): ?>
                    <td class="poll-detail-option"><?= $answer === $optionId ? '<span class="poll-detail-check" aria-label="Oui">✓</span>' : '' ?></td>
                    <?php endforeach; ?>
                  </tr>
                  <?php endforeach; ?>
                  <tr data-poll-filter-empty hidden>
                    <td colspan="<?= 3 + count($userInfo) + count($activePoll['options']) ?>" class="muted-copy"><?= app_escape(__('aucun_membre_correspond_ce_filtre_phrase')) ?></td>
                  </tr>
                </tbody>
                <tfoot>
                  <tr>
                    <th scope="row" colspan="3"><?= app_escape(__n('total_reponse_total_membres_concernes', 'total_reponses_total_membres_concernes', count($respondents), ['total' => count($concernedMembers)])) ?></th>
                    <?php foreach ($userInfo as $flag): ?>
                    <td class="poll-detail-option"><?= count(array_filter($respondents, static fn (array $member): bool => ($member[$flag] ?? false) === true)) ?></td>
                    <?php endforeach; ?>
                    <?php foreach (array_keys($activePoll['options']) as $optionId): ?>
                    <td class="poll-detail-option"><?= count($membersByOption[$optionId]) ?></td>
                    <?php endforeach; ?>
                  </tr>
                </tfoot>
              </table>
            </div>
            <?php endif; ?>
          </div>
          <?php endif; ?>
          <?php endif; ?>
        </section>
        <?php elseif ($tab === 'new-poll'): ?>
        <section class="management-panel detail-panel has-tabs" aria-labelledby="new-poll-title">
          <h2 class="detail-heading" id="new-poll-title"><?= app_escape(__('nouveau_sondage')) ?></h2>
          <p class="muted-copy"><?= app_escape(__('membres_concernes_date_repondent_comme_leur')) ?></p>
          <form class="form-stack" method="post" action="poll-action.php">
            <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
            <input type="hidden" name="event_id" value="<?= app_escape($event['id']) ?>">
            <input type="hidden" name="poll_id" value="">
            <input type="hidden" name="action" value="create">
            <label for="new-poll-question"><?= app_escape(__('question')) ?></label>
            <input id="new-poll-question" name="question" maxlength="<?= POLL_QUESTION_MAX_LENGTH ?>" required placeholder="<?= app_escape(__('prendrez_vous_bus')) ?>">
            <label for="new-poll-description"><?= app_escape(__('commentaire_facultatif')) ?></label>
            <p class="field-help"><?= app_escape(__('affiche_sous_question_expliciter_question_reponses')) ?></p>
            <textarea id="new-poll-description" name="description" rows="2" maxlength="<?= POLL_DESCRIPTION_MAX_LENGTH ?>" placeholder="<?= app_escape(__('depart_bus_18h_devant_local_retour')) ?>"></textarea>
            <label for="new-poll-options"><?= app_escape(__('reponses_possibles')) ?></label>
            <p class="field-help"><?= app_escape(__('reponse_ligne_2_max_exemple_bus', ['max' => POLL_MAX_OPTIONS])) ?></p>
            <textarea id="new-poll-options" name="options" rows="5" required><?= app_escape(__('aller_retour_aller_seulement_retour_seulement')) ?></textarea>
            <fieldset class="section-options poll-user-info">
              <legend><?= app_escape(__('informations_membres_afficher_dans_resultats')) ?></legend>
              <p class="field-help"><?= app_escape(__('ajoute_ces_informations_noms_decomptes_resultats')) ?></p>
              <div class="checkbox-row">
                <?php foreach (app_translated(POLL_USER_INFO) as $flag => $label): ?>
                <label><input type="checkbox" name="user_info[]" value="<?= $flag ?>"> <?= app_escape($label) ?></label>
                <?php endforeach; ?>
              </div>
            </fieldset>
            <div class="form-actions"><button class="primary-button" type="submit"><?= app_escape(__('creer_sondage')) ?></button></div>
          </form>
        </section>
        <?php elseif ($tab === 'presences'): ?>
        <section class="management-panel detail-panel has-tabs" aria-label="<?= app_escape(__('presences')) ?>">
          <div class="detail-panel-heading">
            <h2 class="detail-heading"><?= app_escape(__('presences')) ?></h2>
            <a class="section-note page-switch" href="<?= app_escape(app_matrix_tab_url()) ?>"><?= app_escape(__('toutes_dates')) ?> <span aria-hidden="true">↗</span></a>
          </div>
          <?php if ($user['role'] === 'manager'): ?>
          <p class="matrix-scope"><?= app_escape(__('votre_perimetre_label')) ?> <strong><?= app_escape(app_describe_sections(app_user_managed_sections($user))) ?></strong></p>
          <?php endif; ?>
          <div class="matrix-toolbar">
            <?php app_matrix_legend($canSeeHistory); ?>
          </div>
          <?php app_matrix_render($user, [$matrixEvent], app_matrix_members($user), 'attendance-matrix.php', ['return_event' => $event['id']], true); ?>
        </section>
        <?php elseif ($tab === 'details'): ?>
        <section class="management-panel detail-panel has-tabs" aria-label="<?= app_escape(__('details_date')) ?>">
          <?php $comment = app_event_comment($event); ?>
          <?php $showAdminNote = app_can_see_admin_note($user); ?>
          <div class="event-detail-layout<?= $comment !== '' || $showAdminNote ? ' has-comment' : '' ?>">
          <dl class="event-detail-list">
            <dt><?= app_escape(__('date')) ?></dt>
            <dd><?= app_escape(ucfirst($formatLongDate($date))) ?><?= $endDate !== null ? ' — ' . app_escape(__('jusquau_date_minuscule', ['date' => $formatLongDate($endDate)])) : '' ?></dd>
            <dt><?= app_escape(__('horaire')) ?></dt>
            <dd><?php if ($allDay): ?><?= app_escape(__('journee_entiere')) ?><?php elseif ($timeRange !== ''): ?><?= app_escape($timeRange) ?><?php else: ?><span class="muted-copy"><?= app_escape(__('non_precise')) ?></span><?php endif; ?></dd>
            <dt><?= app_escape(__('statut')) ?></dt>
            <dd><span class="status-badge status-badge-<?= app_escape($status) ?>"><?= app_escape(app_statuses()[$status]) ?></span></dd>
            <?php if ($location !== ''): ?>
            <dt><?= app_escape(__('lieu')) ?></dt>
            <dd><?= app_escape($location) ?></dd>
            <?php endif; ?>
            <?php if ($validUrl): ?>
            <dt><?= app_escape(__('lien')) ?></dt>
            <dd><a class="page-switch" href="<?= app_escape($url) ?>" target="_blank" rel="noopener noreferrer"><?= app_escape(__('savoir_plus')) ?> <span aria-hidden="true">↗</span></a></dd>
            <?php endif; ?>
            <dt><?= app_escape(__('sections')) ?></dt>
            <dd class="event-sections">
              <?php foreach (app_section_display_items($eventLeaves) as $section): ?>
              <span class="section-tag <?= app_escape(app_section_css_class($section)) ?>"><?= app_escape($sectionLabels[$section]) ?></span>
              <?php endforeach; ?>
              <?php if (app_event_is_reserved($event)): ?><span class="reserved-tag" title="<?= app_escape(__('visible_uniquement_membres_sous_sections_concernees')) ?>"><?= app_escape(__('reservee')) ?></span><?php endif; ?>
            </dd>
          </dl>
          <?php if ($comment !== '' || $showAdminNote): ?>
          <div class="event-detail-side">
            <?php if ($comment !== ''): ?>
            <aside class="event-comment" aria-labelledby="event-comment-title">
              <h2 class="event-comment-title" id="event-comment-title"><?= app_escape(__('commentaire')) ?></h2>
              <p><?= nl2br(app_linkify($comment), false) ?></p>
            </aside>
            <?php endif; ?>
            <?php // Admin space (note and documents annexes), under the comment. ?>
            <?php if ($showAdminNote): ?>
            <?php $adminNote = app_event_admin_note($event); ?>
            <div class="admin-note">
              <p class="admin-note-title"><?= app_escape(__('note_admin')) ?> <span class="admin-only-badge" title="<?= app_escape(__('visible_organisateurs_logisticiens_administrateurs_uniquemen')) ?>"><?= app_escape(__('interne')) ?></span><?php if (app_can_manage_logistics($user)): ?> <a class="page-switch" href="logistics.php?event=<?= rawurlencode($event['id']) ?>"><?= app_escape(__('logistique')) ?> <span aria-hidden="true">↗</span></a><?php endif; ?></p>
              <?php if ($adminNote === ''): ?>
              <p class="muted-copy"><?= app_escape(__('aucune_note_phrase')) ?> <a class="page-switch" href="manage-events.php?edit=<?= rawurlencode($event['id']) ?>#event-admin-note"><?= app_escape(__('ajouter_note')) ?></a></p>
              <?php else: ?>
              <p class="admin-note-text"><?= nl2br(app_linkify($adminNote), false) ?></p>
              <?php endif; ?>
              <?php // Documents annexes of the date (contract…), entered in its Logistique page. ?>
              <?php $eventDocuments = app_logistics_documents(read_json_file(app_data_path('logistics.json'))[$event['id']]['documents'] ?? null); ?>
              <?php if ($eventDocuments !== []): ?>
              <div class="admin-note-documents">
                <p class="admin-note-subtitle"><?= app_escape(__('documents_annexes')) ?><?php if (app_can_manage_logistics($user)): ?> <a class="page-switch" href="<?= app_escape(app_logistics_tab_url($event['id'], 'documents')) ?>"><?= app_escape(__('gerer')) ?></a><?php endif; ?></p>
                <ul>
                  <?php foreach ($eventDocuments as $document): ?>
                  <li><a href="<?= app_escape($document['url']) ?>" target="_blank" rel="noopener noreferrer"><?= app_escape($document['label']) ?> <span aria-hidden="true">↗</span></a> <span class="log-sub"><?= app_escape((string) parse_url($document['url'], PHP_URL_HOST)) ?></span></li>
                  <?php endforeach; ?>
                </ul>
              </div>
              <?php elseif (app_can_manage_logistics($user)): ?>
              <p class="admin-note-documents muted-copy"><?= app_escape(__('aucun_document_annexe_phrase')) ?> <a class="page-switch" href="<?= app_escape(app_logistics_tab_url($event['id'], 'documents')) ?>"><?= app_escape(__('ajouter_document')) ?></a></p>
              <?php endif; ?>
          </div>
          <?php endif; ?>
          </div>

          </div>
          <?php endif; ?>

          <?php if ($canRespond): ?>
          <?php $attendanceLocked = app_event_attendance_locked($event); ?>
          <form class="attendance-form detail-attendance<?= $attendanceLocked ? ' is-locked' : '' ?>" method="post" action="attendance-action.php">
            <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
            <input type="hidden" name="event_id" value="<?= app_escape($event['id']) ?>">
            <input type="hidden" name="return_page" value="event">
            <input type="hidden" name="section" value="">
            <span class="attendance-label"><?= app_escape(__('ma_reponse')) ?></span>
            <div class="attendance-options" role="group" aria-label="<?= app_escape(__('votre_reponse_title', ['title' => $event['title']])) ?>">
              <?php foreach (app_attendance_responses() as $response => $label): ?>
              <button class="attendance-option attendance-<?= app_escape($response) ?><?= $myResponse === $response ? ' is-selected' : '' ?>" type="submit" name="response" value="<?= app_escape($response) ?>" aria-pressed="<?= $myResponse === $response ? 'true' : 'false' ?>"<?= $attendanceLocked ? ' disabled title="Date terminée, annulée ou refusée : la présence ne peut plus être indiquée"' : '' ?>><?= app_escape($label) ?></button>
              <?php endforeach; ?>
            </div>
          </form>
          <?php endif; ?>

          <?php if ($responsesBySection !== []): ?>
          <div class="detail-responses">
            <h2 class="detail-heading"><?= app_escape(__('reponses_sous_section')) ?></h2>
            <div class="log-scroll">
              <table class="log-table">
                <thead>
                  <tr>
                    <th scope="col"><?= app_escape(__('sous_section')) ?></th>
                    <?php // Each answer with its total in brackets: every member once, whatever their sub-sections. ?>
                    <?php foreach (app_attendance_responses() + [ATTENDANCE_NO_RESPONSE => app_no_response_label()] as $response => $label): ?>
                    <th scope="col"><?= app_escape($label) ?> <span class="response-total">(<?= count($responseTotals[$response]) ?>)</span></th>
                    <?php endforeach; ?>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($responsesBySection as $leaf => $responses): ?>
                  <tr>
                    <th scope="row"><?= app_escape($sectionLabels[$leaf]) ?></th>
                    <?php foreach ($responses as $response => $names): ?>
                    <td>
                      <span class="log-response log-response-<?= app_escape($response) ?>"><?= count($names) ?></span>
                      <span class="log-sub"><?= $names === [] ? '—' : app_escape(implode(', ', $names)) ?></span>
                    </td>
                    <?php endforeach; ?>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <?php if (count($responsesBySection) > 1): ?>
            <p class="field-help"><?= app_escape(__('nombre_entre_parentheses_compte_chaque_membre')) ?></p>
            <?php endif; ?>
          </div>
          <?php endif; ?>
        </section>
        <?php else: ?>
        <section class="management-panel detail-panel has-tabs" aria-label="<?= app_escape(__('journal_daudit_date')) ?>">
          <div class="detail-panel-heading">
            <h2 class="detail-heading"><?= app_escape(__('journal_daudit_date')) ?></h2>
            <?php if ($logEntries !== []): ?>
            <a class="section-note page-switch" href="attendance-log.php?<?= app_escape(http_build_query(['event' => $event['id'], 'format' => 'csv'])) ?>"><?= app_escape(__('exporter_csv')) ?> <span aria-hidden="true">↓</span></a>
            <?php endif; ?>
          </div>
          <?php if ($logEntries === []): ?>
          <p class="muted-copy"><?= app_escape(__('aucune_saisie_enregistree_cette_date_reponses')) ?></p>
          <?php else: ?>
          <?php app_render_attendance_log_table($logEntries, false); ?>
          <?php endif; ?>
        </section>
        <?php endif; ?>
      </section>
<?php app_footer(); ?>
