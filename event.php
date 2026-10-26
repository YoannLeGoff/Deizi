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
    app_header(__('Date introuvable'), $user);
    ?>
      <section class="management-section">
        <p class="management-panel muted-copy"><?= app_escape(__('Cette date n’existe pas ou ne concerne pas vos sections.')) ?> <a class="page-switch" href="index.php"><?= app_escape(__('Retour à l’agenda')) ?></a></p>
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
          <p class="eyebrow"><span class="eyebrow-line"></span> <?= app_escape(__('DÉTAIL DE LA DATE')) ?></p>
          <?php app_page_links([['Retour à l’agenda', 'index.php#event-' . rawurlencode($event['id'])], $isManager ? [__('Tableau de présence'), app_matrix_tab_url()] : null]); ?>
        </div>
        <div class="section-heading">
          <div>
            <h1><?= app_escape($event['title']) ?><span class="heading-dot">.</span><?= $changeNotice !== null && $changeNotice['type'] === 'changed' ? app_change_badge($changeNotice) : '' ?></h1>
            <?php if ($changeNotice !== null): ?>
            <p class="change-notice"><?= $changeNotice['type'] === 'new' ? app_change_badge($changeNotice) . ' ' . app_escape(__('Nouvelle date depuis votre dernière visite.')) : app_escape(__('Modifié depuis votre dernière consultation : {fields}.', ['fields' => implode(', ', array_map('__', $changeNotice['fields']))])) ?></p>
            <?php endif; ?>
          </div>
          <div class="page-actions">
            <?php if ($canEditEvent): ?>
            <a class="secondary-button compact-button" href="manage-events.php?edit=<?= rawurlencode($event['id']) ?>"><?= app_escape(__('Modifier')) ?></a>
            <?php endif; ?>
          </div>
        </div>

        <nav class="detail-tabs" aria-label="<?= app_escape(__('Sections de la page')) ?>">
          <a class="detail-tab" href="<?= app_escape($tabUrl('details')) ?>"<?= $tab === 'details' ? ' aria-current="page"' : '' ?>><?= app_escape(__('Détails')) ?></a>
          <?php if ($matrixEvent !== null): ?>
          <a class="detail-tab" href="<?= app_escape($tabUrl('presences')) ?>"<?= $tab === 'presences' ? ' aria-current="page"' : '' ?>><?= app_escape(__('Présences')) ?></a>
          <?php endif; ?>
          <?php if ($showRoadmapTab): ?>
          <a class="detail-tab" href="<?= app_escape($tabUrl('feuille-de-route')) ?>"<?= $tab === 'feuille-de-route' ? ' aria-current="page"' : '' ?>><?= app_escape(__('Feuille de route')) ?></a>
          <?php endif; ?>
          <?php foreach ($polls as $poll): ?>
          <?php
              $isCurrent = $activePoll !== null && $activePoll['id'] === $poll['id'];
              $tabLabel = app_truncate($poll['question'], 32) . (strlen($poll['question']) > strlen(app_truncate($poll['question'], 32)) ? '…' : '');
              $isPending = $isConcerned && !$poll['closed'] && app_poll_hidden_reason($event, $poll) === null && !isset($poll['responses'][$user['id']]);
          ?>
          <a class="detail-tab poll-tab" href="<?= app_escape($tabUrl('sondage-' . $poll['id'])) ?>" title="<?= app_escape(__('Sondage : {question}', ['question' => $poll['question']])) ?>"<?= $isCurrent ? ' aria-current="page"' : '' ?>>
            <?= app_escape($tabLabel) ?>
            <?php if ($isManager): ?><span class="count-badge" title="<?= app_escape(__('Réponses reçues')) ?>"><?= count($poll['responses']) ?></span><?php endif; ?>
            <?php if (($hiddenReason = app_poll_hidden_reason($event, $poll)) !== null): ?><span class="poll-hidden-mark" title="<?= app_escape(__('Masqué aux membres : {reason}', ['reason' => $hiddenReason])) ?>"><?= app_escape(__('Masqué')) ?></span><?php endif; ?>
            <?php if ($isPending): ?><span class="poll-pending-dot" title="<?= app_escape(__('Sondage à remplir')) ?>"><span class="sr-only"><?= app_escape(__('(à remplir)')) ?></span></span><?php endif; ?>
            <?php if ($poll['closed']): ?><span class="poll-closed-badge"><?= app_escape(__('Clos')) ?></span><?php endif; ?>
          </a>
          <?php endforeach; ?>
          <?php if ($isManager): ?>
          <a class="detail-tab poll-new-tab" href="<?= app_escape($tabUrl('nouveau-sondage')) ?>"<?= $tab === 'new-poll' ? ' aria-current="page"' : '' ?>><?= app_escape(__('+ Sondage')) ?></a>
          <?php endif; ?>
          <?php if ($canSeeHistory): ?>
          <a class="detail-tab" href="<?= app_escape($tabUrl('historique')) ?>"<?= $tab === 'historique' ? ' aria-current="page"' : '' ?>><?= app_escape(__('Journal d’audit')) ?> <span class="count-badge"><?= count($logEntries) ?></span></a>
          <?php endif; ?>
        </nav>

        <?php if ($tab === 'feuille-de-route'): ?>
        <section class="management-panel detail-panel has-tabs roadmap-panel" aria-label="<?= app_escape(__('Feuille de route')) ?>">
          <div class="roadmap-actions">
            <?php if (!app_roadmap_is_empty($roadmap)): ?>
            <button class="primary-button compact-button" type="button" data-print><?= app_escape(__('Imprimer / enregistrer en PDF')) ?></button>
            <?php endif; ?>
            <?php if ($canEditEvent): ?>
            <a class="secondary-button compact-button" href="manage-events.php?edit=<?= rawurlencode($event['id']) ?>#roadmap"><?= app_escape(__('Modifier la feuille de route')) ?></a>
            <?php endif; ?>
          </div>
          <?php $shareToken = app_roadmap_share_token($event); ?>
          <?php if ($shareToken !== null || $isManager): ?>
          <div class="roadmap-share">
            <span class="roadmap-form-label"><?= app_escape(__('Lien public')) ?></span>
            <?php if ($shareToken !== null): ?>
            <div class="roadmap-share-link">
              <label class="sr-only" for="roadmap-share-url"><?= app_escape(__('Lien public de la feuille de route')) ?></label>
              <input id="roadmap-share-url" type="url" readonly value="<?= app_escape(app_roadmap_share_url($shareToken)) ?>">
              <button class="secondary-button compact-button" type="button" data-copy="roadmap-share-url"><?= app_escape(__('Copier le lien')) ?></button>
            </div>
            <p class="field-help"><?= app_escape(__('Toute personne qui a ce lien peut consulter et imprimer la feuille de route sans compte, contacts compris.')) ?></p>
            <?php if ($isManager): ?>
            <div class="roadmap-share-tools">
              <form method="post" action="roadmap-share-action.php" onsubmit="return confirm(<?= app_escape(json_encode(__('Créer un nouveau lien ? L’ancien cessera de fonctionner.'), JSON_UNESCAPED_UNICODE)) ?>);">
                <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
                <input type="hidden" name="event_id" value="<?= app_escape($event['id']) ?>">
                <input type="hidden" name="action" value="renew">
                <button class="text-button" type="submit"><?= app_escape(__('Renouveler le lien')) ?></button>
              </form>
              <form method="post" action="roadmap-share-action.php" onsubmit="return confirm(<?= app_escape(json_encode(__('Désactiver le lien public ? Il cessera de fonctionner.'), JSON_UNESCAPED_UNICODE)) ?>);">
                <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
                <input type="hidden" name="event_id" value="<?= app_escape($event['id']) ?>">
                <input type="hidden" name="action" value="revoke">
                <button class="text-button" type="submit"><?= app_escape(__('Désactiver le lien')) ?></button>
              </form>
            </div>
            <?php endif; ?>
            <?php else: ?>
            <form method="post" action="roadmap-share-action.php" class="roadmap-share-tools">
              <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
              <input type="hidden" name="event_id" value="<?= app_escape($event['id']) ?>">
              <input type="hidden" name="action" value="create">
              <span class="field-help"><?= app_escape(__('Aucun lien public pour l’instant.')) ?></span>
              <button class="secondary-button compact-button" type="submit"><?= app_escape(__('Créer un lien public')) ?></button>
            </form>
            <?php endif; ?>
          </div>
          <?php endif; ?>
          <?php if (app_roadmap_is_empty($roadmap)): ?>
          <p class="muted-copy"><?= app_escape(__('La feuille de route de cette date n’a pas encore été renseignée.')) ?></p>
          <?php else: ?>
          <?php app_render_roadmap($event, $roadmap, app_settings()); ?>
          <?php endif; ?>
        </section>
        <?php elseif ($tab === 'poll'): ?>
        <?php $myAnswer = $activePoll['responses'][$user['id']] ?? null; ?>
        <section class="management-panel detail-panel has-tabs poll-panel" aria-label="<?= app_escape(__('Sondage')) ?>">
          <div class="detail-panel-heading">
            <h2 class="detail-heading"><?= app_escape($activePoll['question']) ?></h2>
            <span class="status-badge <?= $activePoll['closed'] ? 'status-badge-cancelled' : 'status-badge-pending' ?>"><?= $activePoll['closed'] ? __('Clos') : __('Ouvert') ?></span>
          </div>
          <?php $activeHiddenReason = app_poll_hidden_reason($event, $activePoll); ?>
          <?php if ($activeHiddenReason !== null): ?>
          <p class="poll-hidden-notice"><strong><?= app_escape(__('Sondage masqué aux membres')) ?></strong> <?= app_escape(__('({reason}) : seuls les managers, organisateurs et administrateurs le voient ; les membres ne peuvent pas y répondre, mais la saisie pour un membre reste possible.', ['reason' => $activeHiddenReason])) ?><?= $activePoll['hidden'] && $activeHiddenReason === __('masqué temporairement') ? ' ' . __('Utilisez « Réafficher le sondage » pour le rendre de nouveau visible.') : '' ?></p>
          <?php endif; ?>
          <?php if ($activePoll['description'] !== ''): ?>
          <p class="poll-description"><?= nl2br(app_linkify($activePoll['description']), false) ?></p>
          <?php endif; ?>

          <?php if ($isManager): ?>
          <details class="poll-manage">
            <summary><?= app_escape(__('Modifier le sondage')) ?></summary>
            <form class="form-stack" method="post" action="poll-action.php">
              <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
              <input type="hidden" name="event_id" value="<?= app_escape($event['id']) ?>">
              <input type="hidden" name="poll_id" value="<?= app_escape($activePoll['id']) ?>">
              <input type="hidden" name="action" value="update">
              <label for="poll-question"><?= app_escape(__('Question')) ?></label>
              <input id="poll-question" name="question" maxlength="<?= POLL_QUESTION_MAX_LENGTH ?>" required value="<?= app_escape($activePoll['question']) ?>">
              <label for="poll-description"><?= app_escape(__('Commentaire (facultatif)')) ?></label>
              <textarea id="poll-description" name="description" rows="2" maxlength="<?= POLL_DESCRIPTION_MAX_LENGTH ?>" placeholder="<?= app_escape(__('Précisions sur la question ou les réponses')) ?>"><?= app_escape($activePoll['description']) ?></textarea>
              <label for="poll-options"><?= app_escape(__('Réponses possibles')) ?></label>
              <p class="field-help"><?= app_escape(__('Une réponse par ligne (2 à {max}). Les réponses déjà données à une option conservée sont gardées ; celles d’une option retirée sont effacées.', ['max' => POLL_MAX_OPTIONS])) ?></p>
              <textarea id="poll-options" name="options" rows="<?= max(3, count($activePoll['options']) + 1) ?>" required><?= app_escape(implode("\n", $activePoll['options'])) ?></textarea>
              <fieldset class="section-options poll-user-info">
                <legend><?= app_escape(__('Informations des membres à afficher dans les résultats')) ?></legend>
                <div class="checkbox-row">
                  <?php foreach (app_translated(POLL_USER_INFO) as $flag => $label): ?>
                  <label><input type="checkbox" name="user_info[]" value="<?= $flag ?>"<?= in_array($flag, $activePoll['user_info'], true) ? ' checked' : '' ?>> <?= app_escape($label) ?></label>
                  <?php endforeach; ?>
                </div>
              </fieldset>
              <?php // Closing and deleting are separate forms; their buttons sit on the same line as "Enregistrer". ?>
              <div class="form-actions poll-manage-actions">
                <button class="primary-button compact-button" type="submit"><?= app_escape(__('Enregistrer')) ?></button>
                <button class="secondary-button compact-button" type="submit" form="poll-toggle-form"><?= $activePoll['closed'] ? __('Rouvrir le sondage') : __('Clore le sondage') ?></button>
                <button class="secondary-button compact-button" type="submit" form="poll-hide-form"><?= $activePoll['hidden'] ? __('Réafficher le sondage') : __('Masquer le sondage') ?></button>
                <button class="text-button poll-delete-button" type="submit" form="poll-delete-form"><?= app_escape(__('Supprimer le sondage')) ?></button>
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
            <form id="poll-delete-form" method="post" action="poll-action.php" onsubmit="return confirm(<?= app_escape(json_encode(__('Supprimer ce sondage et toutes ses réponses ?'), JSON_UNESCAPED_UNICODE)) ?>);">
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
            <span class="attendance-label"><?= app_escape(__('Ma réponse')) ?></span>
            <div class="attendance-options" role="group" aria-label="<?= app_escape(__('Votre réponse : {question}', ['question' => $activePoll['question']])) ?>">
              <?php foreach ($activePoll['options'] as $optionId => $label): ?>
              <button class="attendance-option poll-option<?= $myAnswer === $optionId ? ' is-selected' : '' ?>" type="submit" name="option" value="<?= app_escape($optionId) ?>" aria-pressed="<?= $myAnswer === $optionId ? 'true' : 'false' ?>"><?= app_escape($label) ?></button>
              <?php endforeach; ?>
            </div>
          </form>
          <?php elseif ($isConcerned): ?>
          <p class="muted-copy"><?= app_escape(__('Ce sondage est clos.')) ?> <?= $myAnswer !== null ? __('Votre réponse : {answer}.', ['answer' => '<strong>' . app_escape($activePoll['options'][$myAnswer]) . '</strong>']) : app_escape(__('Vous n’y avez pas répondu.')) ?></p>
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
              $flagBadges = ['vegetarian' => __('Végé'), 'minor' => __('Mineur')];
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
                          $html .= ' <span class="poll-entered-by">' . app_escape(__('(saisi par {name})', ['name' => $enteredBy[$member['id']]])) . '</span>';
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
              <h2 class="detail-heading"><?= app_escape(__('Résultats')) ?> <span class="poll-result-total"><?= app_escape(__n('{n} réponse', '{n} réponses', count($answeredIds))) ?> · <?= app_escape(__n('{n} membre concerné', '{n} membres concernés', count($concernedMembers))) ?></span></h2>
              <div class="poll-export-links">
                <a class="section-note page-switch" href="poll-export.php?<?= app_escape(http_build_query(['event' => $event['id'], 'poll' => $activePoll['id']])) ?>"><?= app_escape(__('Exporter en CSV')) ?> <span aria-hidden="true">↓</span></a>
                <?php if (count($polls) > 1): ?>
                <a class="section-note page-switch" href="poll-export.php?<?= app_escape(http_build_query(['event' => $event['id']])) ?>"><?= app_escape(__('Tous les sondages (CSV)')) ?> <span aria-hidden="true">↓</span></a>
                <?php endif; ?>
              </div>
            </div>
            <div class="log-scroll">
              <table class="log-table poll-results">
                <thead><tr><th scope="col"><?= app_escape(__('Réponse')) ?></th><th scope="col"><?= app_escape(__('Nombre')) ?></th><th scope="col"><?= app_escape(__('Membres')) ?></th></tr></thead>
                <tbody>
                  <?php foreach ($activePoll['options'] as $optionId => $label): ?>
                  <tr>
                    <th scope="row"><?= app_escape($label) ?></th>
                    <td><span class="log-response log-response-present"><?= count($membersByOption[$optionId]) ?></span><?= $infoCounts($membersByOption[$optionId]) ?></td>
                    <td><?= $renderMembers($membersByOption[$optionId]) ?></td>
                  </tr>
                  <?php endforeach; ?>
                  <tr class="poll-results-pending">
                    <th scope="row"><?= app_escape(__('Sans réponse')) ?></th>
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
            <span class="roadmap-form-label"><?= app_escape(__('Saisir une réponse pour un membre')) ?></span>
            <div class="poll-proxy-fields">
              <label class="sr-only" for="poll-proxy-member"><?= app_escape(__('Membre')) ?></label>
              <select id="poll-proxy-member" name="member_id" required>
                <option value=""><?= app_escape(__('Choisir un membre…')) ?></option>
                <?php foreach ($proxyMembers as $member): ?>
                <?php $current = $activePoll['responses'][$member['id']] ?? null; ?>
                <option value="<?= app_escape($member['id']) ?>"><?= app_escape($member['username']) ?> — <?= $current !== null ? app_escape($activePoll['options'][$current]) : __('sans réponse') ?></option>
                <?php endforeach; ?>
              </select>
              <label class="sr-only" for="poll-proxy-option"><?= app_escape(__('Réponse')) ?></label>
              <select id="poll-proxy-option" name="option">
                <?php foreach ($activePoll['options'] as $optionId => $label): ?>
                <option value="<?= app_escape($optionId) ?>"><?= app_escape($label) ?></option>
                <?php endforeach; ?>
                <option value=""><?= app_escape(__('(Effacer la réponse)')) ?></option>
              </select>
              <button class="secondary-button compact-button" type="submit"><?= app_escape(__('Enregistrer')) ?></button>
            </div>
            <p class="field-help"><?= in_array($user['role'], ALL_SECTIONS_ROLES, true) ? __('Tous les membres concernés par la date.') : __('Membres concernés par la date dans votre périmètre de management.') ?> <?= app_escape($activePoll['closed'] ? __('La réponse apparaît comme « saisie par {name} » jusqu’à ce que le membre réponde lui-même ; la saisie reste possible sur un sondage clos.', ['name' => $user['username']]) : __('La réponse apparaît comme « saisie par {name} » jusqu’à ce que le membre réponde lui-même.', ['name' => $user['username']])) ?></p>
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
              <h2 class="detail-heading"><?= app_escape(__('Tableau détaillé')) ?></h2>
              <?php if ($respondents !== []): ?>
              <button class="secondary-button compact-button" type="button" data-print-poll-detail><?= app_escape(__('Imprimer')) ?></button>
              <?php endif; ?>
            </div>
            <?php // Printed alone (portrait): the date and the question head the table. ?>
            <p class="poll-detail-print-title"><strong><?= app_escape($event['title']) ?></strong><?= $date !== null ? ' — ' . app_escape(ucfirst($formatLongDate($date))) : '' ?><br><?= app_escape($activePoll['question']) ?></p>
            <?php if ($respondents === []): ?>
            <p class="muted-copy"><?= app_escape(__('Personne n’a encore répondu à ce sondage.')) ?></p>
            <?php else: ?>
            <?php // Filter on the login, first name or last name (menu.js; ignores case and accents). ?>
            <div class="poll-detail-filter" data-poll-filter hidden>
              <label for="poll-detail-search"><?= app_escape(__('Filtrer')) ?></label>
              <input id="poll-detail-search" type="search" placeholder="<?= app_escape(__('Identifiant, prénom ou nom')) ?>" autocomplete="off" aria-describedby="poll-detail-count">
              <span class="field-help" id="poll-detail-count" aria-live="polite" data-poll-filter-count></span>
            </div>
            <div class="log-scroll">
              <table class="log-table poll-detail-table" data-poll-filter-table>
                <thead>
                  <tr>
                    <th scope="col"><?= app_escape(__('Identifiant')) ?></th>
                    <th scope="col"><?= app_escape(__('Prénom')) ?></th>
                    <th scope="col"><?= app_escape(__('Nom')) ?></th>
                    <?php // Account information checked in "Informations des membres à afficher dans les résultats", then the answers. ?>
                    <?php foreach ($userInfo as $flag): ?>
                    <th scope="col" class="poll-detail-option poll-detail-info"><?= app_escape(__(POLL_USER_INFO[$flag])) ?></th>
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
                    <th scope="row"><?= app_escape($member['username']) ?><?php if (isset($enteredBy[$member['id']])): ?> <span class="poll-entered-by"><?= app_escape(__('(saisi par {name})', ['name' => $enteredBy[$member['id']]])) ?></span><?php endif; ?></th>
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
                    <td colspan="<?= 3 + count($userInfo) + count($activePoll['options']) ?>" class="muted-copy"><?= app_escape(__('Aucun membre ne correspond à ce filtre.')) ?></td>
                  </tr>
                </tbody>
                <tfoot>
                  <tr>
                    <th scope="row" colspan="3"><?= app_escape(__n('Total ({n} réponse sur {total} membres concernés)', 'Total ({n} réponses sur {total} membres concernés)', count($respondents), ['total' => count($concernedMembers)])) ?></th>
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
          <h2 class="detail-heading" id="new-poll-title"><?= app_escape(__('Nouveau sondage')) ?></h2>
          <p class="muted-copy"><?= app_escape(__('Les membres concernés par la date y répondent comme à leur présence, depuis l’onglet du sondage ; l’agenda leur signale les sondages à remplir.')) ?></p>
          <form class="form-stack" method="post" action="poll-action.php">
            <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
            <input type="hidden" name="event_id" value="<?= app_escape($event['id']) ?>">
            <input type="hidden" name="poll_id" value="">
            <input type="hidden" name="action" value="create">
            <label for="new-poll-question"><?= app_escape(__('Question')) ?></label>
            <input id="new-poll-question" name="question" maxlength="<?= POLL_QUESTION_MAX_LENGTH ?>" required placeholder="<?= app_escape(__('Prendrez-vous le bus ?')) ?>">
            <label for="new-poll-description"><?= app_escape(__('Commentaire (facultatif)')) ?></label>
            <p class="field-help"><?= app_escape(__('Affiché sous la question pour expliciter la question ou les réponses.')) ?></p>
            <textarea id="new-poll-description" name="description" rows="2" maxlength="<?= POLL_DESCRIPTION_MAX_LENGTH ?>" placeholder="<?= app_escape(__('Départ du bus à 18h devant le local, retour vers 0h30. Répondez avant le 1er juillet.')) ?>"></textarea>
            <label for="new-poll-options"><?= app_escape(__('Réponses possibles')) ?></label>
            <p class="field-help"><?= app_escape(__('Une réponse par ligne (2 à {max}). Exemple pour le bus : « Aller et retour », « Aller seulement », « Retour seulement », « Pas de bus ».', ['max' => POLL_MAX_OPTIONS])) ?></p>
            <textarea id="new-poll-options" name="options" rows="5" required><?= app_escape(__('Aller et retour
Aller seulement
Retour seulement
Pas de bus')) ?></textarea>
            <fieldset class="section-options poll-user-info">
              <legend><?= app_escape(__('Informations des membres à afficher dans les résultats')) ?></legend>
              <p class="field-help"><?= app_escape(__('Ajoute ces informations aux noms et aux décomptes des résultats, ainsi qu’à l’export CSV, par exemple pour prévoir les repas ou l’encadrement des mineurs.')) ?></p>
              <div class="checkbox-row">
                <?php foreach (app_translated(POLL_USER_INFO) as $flag => $label): ?>
                <label><input type="checkbox" name="user_info[]" value="<?= $flag ?>"> <?= app_escape($label) ?></label>
                <?php endforeach; ?>
              </div>
            </fieldset>
            <div class="form-actions"><button class="primary-button" type="submit"><?= app_escape(__('Créer le sondage')) ?></button></div>
          </form>
        </section>
        <?php elseif ($tab === 'presences'): ?>
        <section class="management-panel detail-panel has-tabs" aria-label="<?= app_escape(__('Présences')) ?>">
          <div class="detail-panel-heading">
            <h2 class="detail-heading"><?= app_escape(__('Présences')) ?></h2>
            <a class="section-note page-switch" href="<?= app_escape(app_matrix_tab_url()) ?>"><?= app_escape(__('Toutes les dates')) ?> <span aria-hidden="true">↗</span></a>
          </div>
          <?php if ($user['role'] === 'manager'): ?>
          <p class="matrix-scope"><?= app_escape(__('Votre périmètre :')) ?> <strong><?= app_escape(app_describe_sections(app_user_managed_sections($user))) ?></strong></p>
          <?php endif; ?>
          <div class="matrix-toolbar">
            <?php app_matrix_legend($canSeeHistory); ?>
          </div>
          <?php app_matrix_render($user, [$matrixEvent], app_matrix_members($user), 'attendance-matrix.php', ['return_event' => $event['id']], true); ?>
        </section>
        <?php elseif ($tab === 'details'): ?>
        <section class="management-panel detail-panel has-tabs" aria-label="<?= app_escape(__('Détails de la date')) ?>">
          <?php $comment = app_event_comment($event); ?>
          <?php $showAdminNote = app_can_see_admin_note($user); ?>
          <div class="event-detail-layout<?= $comment !== '' || $showAdminNote ? ' has-comment' : '' ?>">
          <dl class="event-detail-list">
            <dt><?= app_escape(__('Date')) ?></dt>
            <dd><?= app_escape(ucfirst($formatLongDate($date))) ?><?= $endDate !== null ? ' — ' . app_escape(__('jusqu’au {date}', ['date' => $formatLongDate($endDate)])) : '' ?></dd>
            <dt><?= app_escape(__('Horaire')) ?></dt>
            <dd><?php if ($allDay): ?><?= app_escape(__('Journée entière')) ?><?php elseif ($timeRange !== ''): ?><?= app_escape($timeRange) ?><?php else: ?><span class="muted-copy"><?= app_escape(__('Non précisé')) ?></span><?php endif; ?></dd>
            <dt><?= app_escape(__('Statut')) ?></dt>
            <dd><span class="status-badge status-badge-<?= app_escape($status) ?>"><?= app_escape(app_statuses()[$status]) ?></span></dd>
            <?php if ($location !== ''): ?>
            <dt><?= app_escape(__('Lieu')) ?></dt>
            <dd><?= app_escape($location) ?></dd>
            <?php endif; ?>
            <?php if ($validUrl): ?>
            <dt><?= app_escape(__('Lien')) ?></dt>
            <dd><a class="page-switch" href="<?= app_escape($url) ?>" target="_blank" rel="noopener noreferrer"><?= app_escape(__('En savoir plus')) ?> <span aria-hidden="true">↗</span></a></dd>
            <?php endif; ?>
            <dt><?= app_escape(__('Sections')) ?></dt>
            <dd class="event-sections">
              <?php foreach (app_section_display_items($eventLeaves) as $section): ?>
              <span class="section-tag <?= app_escape(app_section_css_class($section)) ?>"><?= app_escape($sectionLabels[$section]) ?></span>
              <?php endforeach; ?>
              <?php if (app_event_is_reserved($event)): ?><span class="reserved-tag" title="<?= app_escape(__('Visible uniquement par les membres des sous-sections concernées, leurs managers, les organisateurs et les administrateurs')) ?>"><?= app_escape(__('Réservée')) ?></span><?php endif; ?>
            </dd>
          </dl>
          <?php if ($comment !== '' || $showAdminNote): ?>
          <div class="event-detail-side">
            <?php if ($comment !== ''): ?>
            <aside class="event-comment" aria-labelledby="event-comment-title">
              <h2 class="event-comment-title" id="event-comment-title"><?= app_escape(__('Commentaire')) ?></h2>
              <p><?= nl2br(app_linkify($comment), false) ?></p>
            </aside>
            <?php endif; ?>
            <?php // Admin space (note and documents annexes), under the comment. ?>
            <?php if ($showAdminNote): ?>
            <?php $adminNote = app_event_admin_note($event); ?>
            <div class="admin-note">
              <p class="admin-note-title"><?= app_escape(__('Note admin')) ?> <span class="admin-only-badge" title="<?= app_escape(__('Visible des organisateurs, logisticiens et administrateurs uniquement')) ?>"><?= app_escape(__('Interne')) ?></span><?php if (app_can_manage_logistics($user)): ?> <a class="page-switch" href="logistics.php?event=<?= rawurlencode($event['id']) ?>"><?= app_escape(__('Logistique')) ?> <span aria-hidden="true">↗</span></a><?php endif; ?></p>
              <?php if ($adminNote === ''): ?>
              <p class="muted-copy"><?= app_escape(__('Aucune note.')) ?> <a class="page-switch" href="manage-events.php?edit=<?= rawurlencode($event['id']) ?>#event-admin-note"><?= app_escape(__('Ajouter une note')) ?></a></p>
              <?php else: ?>
              <p class="admin-note-text"><?= nl2br(app_linkify($adminNote), false) ?></p>
              <?php endif; ?>
              <?php // Documents annexes of the date (contract…), entered in its Logistique page. ?>
              <?php $eventDocuments = app_logistics_documents(read_json_file(app_data_path('logistics.json'))[$event['id']]['documents'] ?? null); ?>
              <?php if ($eventDocuments !== []): ?>
              <div class="admin-note-documents">
                <p class="admin-note-subtitle"><?= app_escape(__('Documents annexes')) ?><?php if (app_can_manage_logistics($user)): ?> <a class="page-switch" href="<?= app_escape(app_logistics_tab_url($event['id'], 'documents')) ?>"><?= app_escape(__('Gérer')) ?></a><?php endif; ?></p>
                <ul>
                  <?php foreach ($eventDocuments as $document): ?>
                  <li><a href="<?= app_escape($document['url']) ?>" target="_blank" rel="noopener noreferrer"><?= app_escape($document['label']) ?> <span aria-hidden="true">↗</span></a> <span class="log-sub"><?= app_escape((string) parse_url($document['url'], PHP_URL_HOST)) ?></span></li>
                  <?php endforeach; ?>
                </ul>
              </div>
              <?php elseif (app_can_manage_logistics($user)): ?>
              <p class="admin-note-documents muted-copy"><?= app_escape(__('Aucun document annexe.')) ?> <a class="page-switch" href="<?= app_escape(app_logistics_tab_url($event['id'], 'documents')) ?>"><?= app_escape(__('Ajouter un document')) ?></a></p>
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
            <span class="attendance-label"><?= app_escape(__('Ma réponse')) ?></span>
            <div class="attendance-options" role="group" aria-label="<?= app_escape(__('Votre réponse à {title}', ['title' => $event['title']])) ?>">
              <?php foreach (app_attendance_responses() as $response => $label): ?>
              <button class="attendance-option attendance-<?= app_escape($response) ?><?= $myResponse === $response ? ' is-selected' : '' ?>" type="submit" name="response" value="<?= app_escape($response) ?>" aria-pressed="<?= $myResponse === $response ? 'true' : 'false' ?>"<?= $attendanceLocked ? ' disabled title="Date terminée, annulée ou refusée : la présence ne peut plus être indiquée"' : '' ?>><?= app_escape($label) ?></button>
              <?php endforeach; ?>
            </div>
          </form>
          <?php endif; ?>

          <?php if ($responsesBySection !== []): ?>
          <div class="detail-responses">
            <h2 class="detail-heading"><?= app_escape(__('Réponses par sous-section')) ?></h2>
            <div class="log-scroll">
              <table class="log-table">
                <thead>
                  <tr>
                    <th scope="col"><?= app_escape(__('Sous-section')) ?></th>
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
            <p class="field-help"><?= app_escape(__('Le nombre entre parenthèses compte chaque membre une seule fois, même s’il fait partie de plusieurs sous-sections de la date.')) ?></p>
            <?php endif; ?>
          </div>
          <?php endif; ?>
        </section>
        <?php else: ?>
        <section class="management-panel detail-panel has-tabs" aria-label="<?= app_escape(__('Journal d’audit de la date')) ?>">
          <div class="detail-panel-heading">
            <h2 class="detail-heading"><?= app_escape(__('Journal d’audit de la date')) ?></h2>
            <?php if ($logEntries !== []): ?>
            <a class="section-note page-switch" href="attendance-log.php?<?= app_escape(http_build_query(['event' => $event['id'], 'format' => 'csv'])) ?>"><?= app_escape(__('Exporter en CSV')) ?> <span aria-hidden="true">↓</span></a>
            <?php endif; ?>
          </div>
          <?php if ($logEntries === []): ?>
          <p class="muted-copy"><?= app_escape(__('Aucune saisie enregistrée pour cette date. Les réponses données avant la mise en place du journal n’y figurent pas.')) ?></p>
          <?php else: ?>
          <?php app_render_attendance_log_table($logEntries, false); ?>
          <?php endif; ?>
        </section>
        <?php endif; ?>
      </section>
<?php app_footer(); ?>
