<?php
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/changelog.php';
$user = app_require_login();

$days = CHANGELOG;
$nickname = app_site_nickname();
// Words in bold inside translated sentences.
$strong = static fn (string $text): string => '<strong>' . app_escape($text) . '</strong>';
krsort($days);

app_header('Mises à jour', $user);
?>
      <section class="management-section">
        <div class="eyebrow-row">
          <p class="eyebrow"><span class="eyebrow-line"></span> <?= app_escape(__('À PROPOS')) ?></p>
          <?php // Managers, organizers and administrators also reach this page from the Configuration. ?>
          <?php app_page_links([['Retour à l’agenda', 'index.php'], in_array($user['role'], EVENT_MANAGER_ROLES, true) ? ['Configuration', 'settings.php'] : null]); ?>
        </div>
        <div class="section-heading">
          <div>
            <h1><?= app_escape($nickname) ?><span class="heading-dot">.</span></h1>
          </div>
          <div class="page-actions">
            <a class="primary-button compact-button" href="onboarding.php" target="_blank" rel="noopener"><?= app_escape(__('Voir la présentation')) ?></a>
            <a class="secondary-button compact-button" href="onboarding.php?f=Deizi-presentation.pdf" target="_blank" rel="noopener"><?= app_escape(__('PDF')) ?></a>
          </div>
        </div>

        <?php // General presentation of the application, above the list of updates. ?>
        <section class="management-panel app-intro" aria-label="<?= app_escape(__('Présentation de {app}', ['app' => $nickname])) ?>">
          <p class="app-intro-presentation"><?= __('Pour découvrir l’application pas à pas, selon votre rôle : {presentation} (diaporama, également en {pdf}).', ['presentation' => '<a class="page-switch" href="onboarding.php" target="_blank" rel="noopener">' . app_escape(__('la présentation de {app}', ['app' => $nickname])) . '</a>', 'pdf' => '<a class="page-switch" href="onboarding.php?f=Deizi-presentation.pdf" target="_blank" rel="noopener">PDF</a>']) ?></p>
          <?php // Presentation set in Configuration > Identité du site. ?>
          <?php $about = app_site_about(); ?>
          <?php if ($about['title'] !== '' || $about['text'] !== ''): ?>
          <p class="app-intro-lead"><?php if ($about['title'] !== ''): ?><strong><?= app_escape($about['title']) ?></strong> <?php endif; ?><?= nl2br(app_escape($about['text']), false) ?></p>
          <?php endif; ?>
          <ul class="app-intro-features">
            <li><strong><?= app_escape(__('L’agenda')) ?></strong><span><?= app_escape(__('Les prochaines dates de vos sections, avec horaire, lieu et statut. L’Agenda complet garde aussi les dates passées, et le lien iCal les affiche dans l’agenda de votre téléphone.')) ?></span></li>
            <li><strong><?= app_escape(__('Votre présence')) ?></strong><span><?= app_escape(__('Présent, Absent ou Je ne sais pas, en un clic depuis l’agenda ou la page de la date ; tant que vous n’avez pas répondu, aucun choix n’est sélectionné. Vous pouvez changer d’avis tant que la date n’est pas terminée, annulée ou refusée.')) ?></span></li>
            <li><strong><?= app_escape(__('Les sondages')) ?></strong><span><?= app_escape(__('Une question pratique sur une date (bus, repas, hébergement…) à laquelle chacun répond en un clic.')) ?></span></li>
            <li><strong><?= app_escape(__('La feuille de route')) ?></strong><span><?= app_escape(__('Sur le modèle de la feuille papier : tout le déroulé d’une sortie confirmée, à consulter sur téléphone.')) ?></span></li>
            <li><strong><?= app_escape(__('Les vues avancées')) ?></strong><span><?= app_escape(__('La liste et le planning annuel des dates de toutes les sections, pour avoir une vue d’ensemble de la saison.')) ?></span></li>
            <li><strong><?= app_escape(__('Les annonces')) ?></strong><span><?= app_escape(__('Un message de votre manager ou des organisateurs (texte, photo, lien) en haut de la page d’accueil, et en notification si vous les avez activées.')) ?> <a href="#annonces"><?= app_escape(__('En savoir plus')) ?></a></span></li>
            <li><strong><?= app_escape(__('Les nouveautés signalées')) ?></strong><span><?= __('Une étiquette « Nouveau » sur les dates créées, une pastille rouge sur celles modifiées depuis votre dernière visite, la cloche qui les compte en haut de page et, si vous les activez, des {notifications} sur votre téléphone.', ['notifications' => '<a href="#notifications">' . app_escape(__('notifications')) . '</a>']) ?></span></li>
          </ul>
          <h3 class="app-intro-subtitle"><?= app_escape(__('Qui fait quoi ?')) ?></h3>
          <ul class="app-intro-roles">
            <li><strong><?= app_escape(__('Artistes')) ?></strong><span><?= app_escape(__('consultent les dates de leurs sections, répondent aux présences et aux sondages.')) ?></span></li>
            <li><strong><?= app_escape(__('Managers')) ?></strong><span><?= app_escape(__('créent et modifient les dates de leurs sous-sections, leur publient des annonces et saisissent les présences de leurs membres.')) ?></span></li>
            <li><strong><?= app_escape(__('Organisateurs')) ?></strong><span><?= app_escape(__('font de même pour toutes les sections, gèrent sondages et feuilles de route, et créent des comptes.')) ?></span></li>
            <li><strong><?= app_escape(__('Logisticiens')) ?></strong><span><?= app_escape(__('ont les mêmes droits que les organisateurs et suivent en plus la gestion administrative des dates : cachets, devis envoyés aux organisateurs et devis de transport.')) ?></span></li>
            <li><strong><?= app_escape(__('Administrateurs')) ?></strong><span><?= app_escape(__('gèrent en plus les comptes et la configuration de l’application.')) ?></span></li>
          </ul>
          <?php if (in_array($user['role'], EVENT_MANAGER_ROLES, true)): ?>
          <p class="app-intro-note"><?= __('Le détail des droits de chaque rôle se trouve dans la {rights}.', ['rights' => '<a href="rights.php">' . app_escape(__('matrice des droits')) . '</a>']) ?></p>
          <?php endif; ?>
        </section>

        <?php // Announcements: how members see them, and the shortcut of those who publish them. ?>
        <section class="management-panel feature-panel" id="annonces" aria-labelledby="announcements-title">
          <h2 class="detail-heading heading-with-icon" id="announcements-title"><?= app_megaphone_icon('heading-icon') ?><span><?= app_escape(__('Les annonces')) ?></span></h2>
          <p class="feature-panel-lead"><?= app_escape(__('Un changement de salle, du matériel à apporter, un rappel avant un stage : une annonce s’affiche en haut de la page d’accueil des membres concernés, au-dessus des prochaines dates.')) ?></p>
          <?php $canPublish = app_can_publish_announcements($user); ?>
          <div class="feature-panel-columns<?= $canPublish ? '' : ' is-single' ?>">
            <div>
              <h3><?= app_escape(__('Pour tous les membres')) ?></h3>
              <ul>
                <li><?= app_escape(__('La dernière annonce de vos sections s’affiche en grand, avec son titre, sa photo, son texte et, s’il y en a un, son lien.')) ?></li>
                <li><?= app_escape(__('Les autres sont rangées sous « Autres annonces ».')) ?></li>
                <li><?= __('{hide} la range avec les autres : elles restent consultables par le lien « Annonces » à côté de « Agenda complet », jusqu’à la fin de leur affichage.', ['hide' => $strong(__('Masquer'))]) ?></li>
                <li><?= __('Avec les {notifications} activées, une nouvelle annonce arrive aussi sur votre téléphone.', ['notifications' => '<a href="#notifications">' . app_escape(__('notifications')) . '</a>']) ?></li>
              </ul>
            </div>
            <?php if ($canPublish): ?>
            <div>
              <h3><?= app_escape(__('Pour les managers et les organisateurs')) ?></h3>
              <ul>
                <li><?= app_escape(__('Les managers publient pour les sous-sections de leur périmètre, les organisateurs, logisticiens et administrateurs pour toutes les sections ou tout le monde.')) ?></li>
                <li><?= app_escape(__('Titre, texte, lien et photo, période d’affichage, et notification envoyée à la publication ou le premier jour.')) ?></li>
                <li><?= app_escape(__('L’onglet « Mes annonces » permet de modifier ou de supprimer vos annonces.')) ?></li>
              </ul>
              <div class="feature-panel-actions">
                <a class="primary-button compact-button" href="announcements.php?nouvelle=1"><?= app_escape(__('Publier une annonce')) ?></a>
                <a class="secondary-button compact-button" href="announcements.php"><?= app_escape(__('Mes annonces')) ?></a>
              </div>
            </div>
            <?php endif; ?>
          </div>
        </section>

        <section class="management-panel install-panel" id="installer" aria-labelledby="install-title">
          <h2 class="detail-heading" id="install-title"><?= app_escape(__('Installer {app} sur votre téléphone ou votre ordinateur', ['app' => $nickname])) ?></h2>
          <p class="muted-copy"><?= app_escape(__('Ajoutez l’application à l’écran d’accueil de votre téléphone, ou à la barre des tâches et au menu Démarrer de votre ordinateur : elle s’ouvre d’un geste, dans sa propre fenêtre, comme une application.')) ?></p>
          <p class="install-done" data-install-installed hidden><?= app_escape(__('✓ Vous utilisez déjà l’application installée.')) ?></p>
          <button class="primary-button" type="button" data-install-button hidden><?= app_escape(__('Installer l’application')) ?></button>
          <div class="install-steps" data-install-help>
            <div>
              <h3><?= app_escape(__('iPhone et iPad (Safari)')) ?></h3>
              <ol><li><?= __('Ouvrez le site dans {browser}.', ['browser' => $strong('Safari')]) ?></li><li><?= __('Touchez le bouton {share} (carré avec une flèche vers le haut).', ['share' => $strong(__('Partager'))]) ?></li><li><?= __('Choisissez {home}, puis {add}.', ['home' => $strong(__('Sur l’écran d’accueil')), 'add' => $strong(__('Ajouter'))]) ?></li></ol>
            </div>
            <div>
              <h3><?= app_escape(__('Android (Chrome)')) ?></h3>
              <ol><li><?= __('Touchez le bouton {install} ci-dessus s’il apparaît,', ['install' => $strong(__('Installer l’application'))]) ?></li><li><?= __('ou ouvrez le menu {menu} de Chrome,', ['menu' => $strong('⋮')]) ?></li><li><?= __('puis {install} (ou {home}).', ['install' => $strong(__('Installer l’application')), 'home' => $strong(__('Ajouter à l’écran d’accueil'))]) ?></li></ol>
            </div>
            <div>
              <h3><?= app_escape(__('Ordinateur Windows ou Mac (Chrome)')) ?></h3>
              <ol><li><?= __('Cliquez sur le bouton {install} ci-dessus s’il apparaît, ou sur l’icône d’installation à droite de la barre d’adresse,', ['install' => $strong(__('Installer l’application'))]) ?></li><li><?= __('ou ouvrez le menu {menu} de Chrome, puis {cast} › {page}.', ['menu' => $strong('⋮'), 'cast' => $strong(__('Caster, enregistrer et partager')), 'page' => $strong(__('Installer la page en tant qu’application'))]) ?></li></ol>
            </div>
            <div>
              <h3><?= app_escape(__('Ordinateur Windows (Edge)')) ?></h3>
              <ol><li><?= __('Ouvrez le menu {menu} d’Edge,', ['menu' => $strong('⋯')]) ?></li><li><?= __('puis {apps} › {install}.', ['apps' => $strong(__('Applications')), 'install' => $strong(__('Installer ce site en tant qu’application'))]) ?></li></ol>
            </div>
          </div>
          <p class="app-intro-note" data-install-help><strong><?= app_escape(__('Vous utilisez Firefox ?')) ?></strong> <?= __('Il ne permet pas d’installer un site comme une application : ouvrez le site une fois dans {chrome} ou {edge} pour l’installer (étapes ci-dessus). Ensuite, l’application s’ouvre depuis le menu Démarrer ou la barre des tâches, quel que soit votre navigateur habituel.', ['chrome' => $strong('Chrome'), 'edge' => $strong('Edge')]) ?></p>
        </section>

        <section class="management-panel install-panel push-panel" id="notifications" aria-labelledby="notifications-title" data-push-panel data-push-key="<?= app_escape(app_push_vapid()['public']) ?>" data-csrf="<?= app_escape(app_csrf_token()) ?>">
          <h2 class="detail-heading" id="notifications-title"><?= app_escape(__('Notifications')) ?></h2>
          <p class="muted-copy"><?= __('Recevez une notification sur cet appareil quand une {new} concerne vos sections, ou quand une date que vous avez {seen} est modifiée. Touchez la notification pour ouvrir la date.', ['new' => $strong(__('nouvelle date')), 'seen' => $strong(__('déjà consultée'))]) ?></p>
          <p class="muted-copy"><?= __('Sans notifications, la {bell} en haut de page compte ces dates et les liste, et l’icône de l’application installée affiche leur nombre.', ['bell' => $strong(__('cloche'))]) ?></p>
          <p class="push-state" data-push-state role="status"></p>
          <button class="primary-button" type="button" data-push-enable hidden><?= app_escape(__('Activer les notifications sur cet appareil')) ?></button>
          <button class="secondary-button" type="button" data-push-disable hidden><?= app_escape(__('Désactiver les notifications sur cet appareil')) ?></button>
          <p class="app-intro-note"><?= app_escape(__('Sur iPhone et iPad, les notifications ne fonctionnent que dans l’application installée (iOS 16.4 ou plus récent) : installez-la d’abord, puis activez-les depuis cette page dans l’application.')) ?></p>
        </section>

        <h2 class="app-intro-history"><?= app_escape(__('Dernières mises à jour')) ?></h2>
        <div class="changelog">
          <?php foreach ($days as $day => $entries): ?>
          <?php $date = app_parse_date($day); ?>
          <section class="management-panel changelog-day" aria-labelledby="changelog-<?= app_escape($day) ?>">
            <h2 class="detail-heading" id="changelog-<?= app_escape($day) ?>">
              <?= $date !== null ? app_escape(app_long_date($date)) : app_escape($day) ?>
              <span class="count-badge" title="<?= app_escape(__('Nouveautés ce jour-là')) ?>"><?= count(array_filter($entries, static fn (array $entry): bool => $entry !== MINOR_UPDATE)) ?></span>
            </h2>
            <ul class="changelog-list">
              <?php foreach ($entries as $entry): ?>
              <?php [$title, $description] = $entry; ?>
              <li<?= $entry === MINOR_UPDATE ? ' class="changelog-minor"' : '' ?>>
                <strong><?= app_escape($title) ?></strong>
                <span><?= app_escape($description) ?></span>
              </li>
              <?php endforeach; ?>
            </ul>
          </section>
          <?php endforeach; ?>
        </div>
      </section>
<?php app_footer(); ?>
