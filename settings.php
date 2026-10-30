<?php
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';
// Configuration grouped by theme (SETTINGS_GROUPS): each group lists the pages the user may open,
// with a short summary of their current state. Administrators see everything; organizers and
// logisticians the accounts, holidays, roadmap and audit journal; managers the rights matrix.
$admin = app_require_roles(EVENT_MANAGER_ROLES);
$isAdmin = $admin['role'] === 'admin';
$isAccountManager = in_array($admin['role'], ACCOUNT_MANAGER_ROLES, true);

$accounts = array_values(array_filter(
    app_read_users_with_migration(),
    static fn (mixed $account): bool => is_array($account) && is_string($account['id'] ?? null)
));
$activeCount = count(array_filter($accounts, static fn (array $account): bool => ($account['active'] ?? false) === true));
$managerCount = count(array_filter($accounts, static fn (array $account): bool => ($account['role'] ?? '') === 'manager'));
$logCount = count(app_attendance_log_read(true));
$adminHasCalendarLink = app_is_calendar_token($admin['calendar_token'] ?? null);
$settings = app_settings();
$calendarSettings = app_calendar_settings();
$pieceCount = array_sum(array_map('count', $settings['repertoires']));
$identity = app_site_identity();
$season = app_season_of(new DateTimeImmutable('today', new DateTimeZone('Europe/Paris')));
$customStatusCount = count(app_status_custom_colors());
$schoolCount = count($calendarSettings['school_holidays']);
$lastUpdate = app_files_updated_at();

// Current state of each page, under its name.
$seasonYears = $season['first']->format('Y') . ($season['first']->format('Y') === $season['last']->format('Y') ? '' : '-' . $season['last']->format('Y'));
$holidayCount = count(PUBLIC_HOLIDAY_RULES) - count($calendarSettings['disabled_holidays']);
$customHolidayCount = count($calendarSettings['custom_holidays']);
$repertoireCount = count($settings['repertoires']);
$summaries = [
    'season-settings.php' => __('resume_saisons', ['years' => $seasonYears, 'first' => app_long_date($season['first'], false, true, false), 'last' => app_long_date($season['last'], false, true, false)]),
    'school-holidays-settings.php' => __n('resume_vacances_une', 'resume_vacances', $schoolCount),
    'holidays-settings.php' => __('resume_jours_feries', ['n' => $holidayCount, 'total' => count(PUBLIC_HOLIDAY_RULES)])
        . ($customHolidayCount > 0 ? __n('resume_jours_feries_ajoute', 'resume_jours_feries_ajoutes', $customHolidayCount) : '') . '.',
    'site-settings.php' => $identity['logo'] === null
        ? __('resume_identite_logo_origine', ['title' => app_site_title(), 'nickname' => app_site_nickname()])
        : __('resume_identite_logo_personnalise', ['title' => app_site_title(), 'nickname' => app_site_nickname()]),
    'sections-settings.php' => __('resume_sections', ['branches' => count(app_section_tree()), 'leaves' => count(app_section_leaves())]),
    'status-settings.php' => $customStatusCount === 0
        ? __('resume_statuts_origine', ['n' => count(EVENT_STATUSES)])
        : __('resume_statuts_personnalises', ['n' => count(EVENT_STATUSES), 'custom' => $customStatusCount]),
    'languages-settings.php' => __('resume_langues', ['language' => app_languages()[app_site_language()], 'n' => count(app_languages())]),
    'users.php' => $isAdmin
        ? __('resume_comptes_admin', ['n' => count($accounts), 'active' => $activeCount, 'managers' => $managerCount])
        : __('resume_comptes', ['n' => count($accounts), 'active' => $activeCount, 'managers' => $managerCount]),
    'users-import.php' => __('resume_import_comptes'),
    'rights.php' => __('resume_matrice_droits'),
    'roadmap-settings.php' => __n('resume_feuille_route_un', 'resume_feuille_route', $repertoireCount, ['pieces' => __n('n_morceau', 'n_morceaux', $pieceCount)]),
    'logistics-settings.php' => __('resume_parametres_devis'),
    'attendance-log.php' => __n('resume_journal_une', 'resume_journal', $logCount),
    'delete-events.php' => __('resume_suppression_dates'),
    'maintenance-settings.php' => app_maintenance() !== null ? __('resume_maintenance_fermee') : __('resume_maintenance_ouverte'),
];

app_header('Configuration', $admin);
?>
      <section class="management-section">
        <div class="eyebrow-row">
          <p class="eyebrow"><span class="eyebrow-line"></span> <?= app_escape(mb_strtoupper(__('administration'))) ?></p>
          <?php app_page_links([['Retour à l’agenda', 'index.php']]); ?>
        </div>
        <div class="section-heading">
          <div>
            <h1><?= app_escape(__('configuration')) ?><span class="heading-dot">.</span></h1>
          </div>
        </div>

        <p class="accounts-help settings-intro"><?= app_escape(__('reglages_regroupes_par_theme')) ?></p>

        <div class="settings-groups" data-masonry>
          <section class="management-panel settings-group" aria-labelledby="settings-group-apropos" data-masonry-first>
            <h2 class="detail-heading" id="settings-group-apropos"><?= app_escape(__('a_propos')) ?></h2>
            <ul class="settings-rows">
              <li class="settings-row">
                <div class="settings-row-text">
                  <a class="settings-row-title" href="updates.php"><?= app_escape(__('mises_a_jour')) ?></a>
                  <span class="settings-row-summary"><?= app_escape(__('resume_mises_a_jour_date', ['date' => app_long_date($lastUpdate, false, true, false)])) ?></span>
                </div>
                <a class="secondary-button compact-button" href="updates.php" aria-label="<?= app_escape(__('ouvrir_page', ['page' => __('mises_a_jour')])) ?>"><?= app_escape(__('ouvrir')) ?></a>
              </li>
            </ul>
          </section>

          <?php foreach (SETTINGS_GROUPS as $groupKey => [$groupName, $groupDescription]): ?>
          <?php $pages = app_settings_group_pages($groupKey, $admin); ?>
          <?php if ($pages !== [] || ($groupKey === 'suivi' && $isAdmin)): ?>
          <section class="management-panel settings-group" aria-labelledby="settings-group-<?= $groupKey ?>"<?= in_array($groupKey, ['membres', 'documents'], true) ? ' data-masonry-first' : '' ?>>
            <h2 class="detail-heading" id="settings-group-<?= $groupKey ?>"><?= app_escape(app_label($groupName)) ?></h2>
            <p class="muted-copy settings-group-lead"><?= app_escape(app_label($groupDescription)) ?></p>
            <ul class="settings-rows">
              <?php foreach ($pages as [$file, $name]): ?>
              <li class="settings-row<?= $file === 'delete-events.php' ? ' is-danger' : '' ?>">
                <div class="settings-row-text">
                  <a class="settings-row-title" href="<?= app_escape($file) ?>"><?= app_escape(app_label($name)) ?></a>
                  <span class="settings-row-summary"><?= app_escape($summaries[$file] ?? '') ?></span>
                </div>
                <?php if ($file === 'users.php'): ?>
                <a class="secondary-button compact-button" href="user.php"><?= app_escape(__('creer_un_compte')) ?></a>
                <?php endif; ?>
                <a class="<?= $file === 'delete-events.php' ? 'danger-button' : 'secondary-button' ?> compact-button" href="<?= app_escape($file) ?>" aria-label="<?= app_escape(__('ouvrir_page', ['page' => app_label($name)])) ?>"><?= app_escape(__('ouvrir')) ?></a>
              </li>
              <?php endforeach; ?>
              <?php if ($groupKey === 'suivi' && $isAdmin): ?>
              <li class="settings-row">
                <div class="settings-row-text">
                  <span class="settings-row-title"><?= app_escape(__('mon_lien_abonnement_ical')) ?></span>
                  <span class="settings-row-summary"><?= app_escape(__('renouvelez_lien_personnel_partage')) ?><?= $adminHasCalendarLink ? '' : ' ' . app_escape(__('lien_cree_prochaine_visite_agenda')) ?></span>
                </div>
                <form method="post" action="calendar-token-action.php" onsubmit="return confirm(<?= app_escape(json_encode(__('confirmer_renouveler_lien_ical'), JSON_UNESCAPED_UNICODE)) ?>);">
                  <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
                  <button class="secondary-button compact-button" type="submit"<?= $adminHasCalendarLink ? '' : ' disabled' ?>><?= app_escape(__('renouveler')) ?></button>
                </form>
              </li>
              <?php endif; ?>
            </ul>
          </section>
          <?php endif; ?>
          <?php endforeach; ?>

        </div>
      </section>
<?php app_footer(); ?>
