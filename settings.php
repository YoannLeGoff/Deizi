<?php
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/changelog.php';
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
$lastUpdate = app_parse_date(array_key_first(CHANGELOG));

// Current state of each page, under its name.
$summaries = [
    'season-settings.php' => $season['label'] . ' en cours : ' . app_season_range($season) . '. Les dates sont regroupées par saison dans la Logistique.',
    'school-holidays-settings.php' => $schoolCount . ' période' . ($schoolCount > 1 ? 's' : '') . ' enregistrée' . ($schoolCount > 1 ? 's' : '') . ', repérées dans le planning annuel.',
    'holidays-settings.php' => (count(PUBLIC_HOLIDAY_RULES) - count($calendarSettings['disabled_holidays'])) . ' jours fériés légaux actifs sur ' . count(PUBLIC_HOLIDAY_RULES) . ($calendarSettings['custom_holidays'] !== [] ? ', ' . count($calendarSettings['custom_holidays']) . ' jour' . (count($calendarSettings['custom_holidays']) > 1 ? 's' : '') . ' ajouté' . (count($calendarSettings['custom_holidays']) > 1 ? 's' : '') : '') . '.',
    'site-settings.php' => 'Titre « ' . app_site_title() . ' », pseudo « ' . app_site_nickname() . ' », logo d’en-tête ' . ($identity['logo'] === null ? 'd’origine' : 'personnalisé') . ', icône de l’application et logo de connexion.',
    'sections-settings.php' => count(app_section_tree()) . ' branches et ' . count(app_section_leaves()) . ' sous-sections, leurs codes, couleurs et répertoires.',
    'status-settings.php' => count(EVENT_STATUSES) . ' statuts de date, ' . ($customStatusCount === 0 ? 'aux couleurs d’origine' : $customStatusCount . ' avec une couleur personnalisée') . ' ; statuts logistiques et leur flux.',
    'users.php' => count($accounts) . ' comptes, dont ' . $activeCount . ' actifs et ' . $managerCount . ' managers. Rôles, sections, périmètres' . ($isAdmin ? ' et mots de passe' : '') . '.',
    'users-import.php' => 'Créer des comptes en masse depuis un fichier CSV, avec ou sans mot de passe.',
    'rights.php' => 'Ce que peuvent faire les artistes, managers, organisateurs, logisticiens et administrateurs.',
    'roadmap-settings.php' => 'QR code et contact par défaut, ' . count($settings['repertoires']) . ' répertoire' . (count($settings['repertoires']) > 1 ? 's' : '') . ' (' . $pieceCount . ' morceau' . ($pieceCount > 1 ? 'x' : '') . ').',
    'logistics-settings.php' => 'Mise en page des devis (logo, adresse, pied de page), flux de travail des devis et formules.',
    'attendance-log.php' => $logCount . ' entrée' . ($logCount > 1 ? 's' : '') . ' : connexions, réponses de présence, dates créées, modifiées ou supprimées, changements d’identifiant.',
    'delete-events.php' => 'Supprimer définitivement une date, avec ses réponses, sondages et feuille de route.',
];

app_header('Configuration', $admin);
?>
      <section class="management-section">
        <div class="eyebrow-row">
          <p class="eyebrow"><span class="eyebrow-line"></span> ADMINISTRATION</p>
          <?php app_page_links([['Retour à l’agenda', 'index.php']]); ?>
        </div>
        <div class="section-heading">
          <div>
            <h1>Configuration<span class="heading-dot">.</span></h1>
          </div>
        </div>

        <p class="accounts-help settings-intro">Les réglages sont regroupés par thème. Dans chaque thème, des onglets permettent de passer d’une page à l’autre sans revenir ici.</p>

        <div class="settings-groups" data-masonry>
          <section class="management-panel settings-group" aria-labelledby="settings-group-apropos" data-masonry-first>
            <h2 class="detail-heading" id="settings-group-apropos">À propos</h2>
            <ul class="settings-rows">
              <li class="settings-row">
                <div class="settings-row-text">
                  <a class="settings-row-title" href="updates.php">Mises à jour</a>
                  <span class="settings-row-summary">Les nouveautés de l’application, la présentation et l’installation sur téléphone<?= $lastUpdate !== null ? ' ; dernière mise à jour le ' . $lastUpdate->format('d/m/Y') : '' ?>.</span>
                </div>
                <a class="secondary-button compact-button" href="updates.php" aria-label="Ouvrir Mises à jour">Ouvrir</a>
              </li>
            </ul>
          </section>

          <?php foreach (SETTINGS_GROUPS as $groupKey => [$groupName, $groupDescription]): ?>
          <?php $pages = app_settings_group_pages($groupKey, $admin); ?>
          <?php if ($pages !== [] || ($groupKey === 'suivi' && $isAdmin)): ?>
          <section class="management-panel settings-group" aria-labelledby="settings-group-<?= $groupKey ?>"<?= in_array($groupKey, ['membres', 'documents'], true) ? ' data-masonry-first' : '' ?>>
            <h2 class="detail-heading" id="settings-group-<?= $groupKey ?>"><?= app_escape($groupName) ?></h2>
            <p class="muted-copy settings-group-lead"><?= app_escape($groupDescription) ?></p>
            <ul class="settings-rows">
              <?php foreach ($pages as [$file, $name]): ?>
              <li class="settings-row<?= $file === 'delete-events.php' ? ' is-danger' : '' ?>">
                <div class="settings-row-text">
                  <a class="settings-row-title" href="<?= app_escape($file) ?>"><?= app_escape($name) ?></a>
                  <span class="settings-row-summary"><?= app_escape($summaries[$file] ?? '') ?></span>
                </div>
                <?php if ($file === 'users.php'): ?>
                <a class="secondary-button compact-button" href="user.php">Créer un compte</a>
                <?php endif; ?>
                <a class="<?= $file === 'delete-events.php' ? 'danger-button' : 'secondary-button' ?> compact-button" href="<?= app_escape($file) ?>" aria-label="Ouvrir <?= app_escape($name) ?>">Ouvrir</a>
              </li>
              <?php endforeach; ?>
              <?php if ($groupKey === 'suivi' && $isAdmin): ?>
              <li class="settings-row">
                <div class="settings-row-text">
                  <span class="settings-row-title">Mon lien d’abonnement iCal</span>
                  <span class="settings-row-summary">Renouvelez votre lien personnel si vous pensez qu’il a été partagé : l’ancien cesse aussitôt de fonctionner. Le lien d’un autre membre se renouvelle depuis sa fiche.<?= $adminHasCalendarLink ? '' : ' Votre lien sera créé à votre prochaine visite de l’agenda.' ?></span>
                </div>
                <form method="post" action="calendar-token-action.php" onsubmit="return confirm('Renouveler votre lien d’abonnement iCal ? L’ancien lien cessera de fonctionner.');">
                  <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
                  <button class="secondary-button compact-button" type="submit"<?= $adminHasCalendarLink ? '' : ' disabled' ?>>Renouveler</button>
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
