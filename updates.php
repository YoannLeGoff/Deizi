<?php
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/changelog.php';
$user = app_require_login();

$weekdays = ['', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];
$months = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
$days = CHANGELOG;
$nickname = app_site_nickname();
krsort($days);

app_header('Mises à jour', $user);
?>
      <section class="management-section">
        <div class="eyebrow-row">
          <p class="eyebrow"><span class="eyebrow-line"></span> À PROPOS</p>
          <?php // Managers, organizers and administrators also reach this page from the Configuration. ?>
          <?php app_page_links([['Retour à l’agenda', 'index.php'], in_array($user['role'], EVENT_MANAGER_ROLES, true) ? ['Configuration', 'settings.php'] : null]); ?>
        </div>
        <div class="section-heading">
          <div>
            <h1><?= app_escape($nickname) ?><span class="heading-dot">.</span></h1>
          </div>
          <div class="page-actions">
            <a class="primary-button compact-button" href="onboarding.php" target="_blank" rel="noopener">Voir la présentation</a>
            <a class="secondary-button compact-button" href="onboarding.php?f=Deizi-presentation.pdf" target="_blank" rel="noopener">PDF</a>
          </div>
        </div>

        <?php // General presentation of the application, above the list of updates. ?>
        <section class="management-panel app-intro" aria-label="Présentation de <?= app_escape($nickname) ?>">
          <p class="app-intro-presentation">Pour découvrir l’application pas à pas, selon votre rôle : <a class="page-switch" href="onboarding.php" target="_blank" rel="noopener">la présentation de <?= app_escape($nickname) ?></a> (diaporama, également en <a class="page-switch" href="onboarding.php?f=Deizi-presentation.pdf" target="_blank" rel="noopener">PDF</a>).</p>
          <?php // Presentation set in Configuration > Identité du site. ?>
          <?php $about = app_site_about(); ?>
          <?php if ($about['title'] !== '' || $about['text'] !== ''): ?>
          <p class="app-intro-lead"><?php if ($about['title'] !== ''): ?><strong><?= app_escape($about['title']) ?></strong> <?php endif; ?><?= nl2br(app_escape($about['text']), false) ?></p>
          <?php endif; ?>
          <ul class="app-intro-features">
            <li><strong>L’agenda</strong><span>Les prochaines dates de vos sections, avec horaire, lieu et statut. L’Agenda complet garde aussi les dates passées, et le lien iCal les affiche dans l’agenda de votre téléphone.</span></li>
            <li><strong>Votre présence</strong><span>Présent, Absent ou Je ne sais pas, en un clic depuis l’agenda ou la page de la date ; tant que vous n’avez pas répondu, aucun choix n’est sélectionné. Vous pouvez changer d’avis tant que la date n’est pas terminée, annulée ou refusée.</span></li>
            <li><strong>Les sondages</strong><span>Une question pratique sur une date (bus, repas, hébergement…) à laquelle chacun répond en un clic.</span></li>
            <li><strong>La feuille de route</strong><span>Sur le modèle de la feuille papier : tout le déroulé d’une sortie confirmée, à consulter sur téléphone.</span></li>
            <li><strong>Les vues avancées</strong><span>La liste et le planning annuel des dates de toutes les sections, pour avoir une vue d’ensemble de la saison.</span></li>
            <li><strong>Les annonces</strong><span>Un message de votre manager ou des organisateurs (texte, photo, lien) en haut de la page d’accueil, et en notification si vous les avez activées. <a href="#annonces">En savoir plus</a></span></li>
            <li><strong>Les nouveautés signalées</strong><span>Une étiquette « Nouveau » sur les dates créées, une pastille rouge sur celles modifiées depuis votre dernière visite, la cloche qui les compte en haut de page et, si vous les activez, des <a href="#notifications">notifications</a> sur votre téléphone.</span></li>
          </ul>
          <h3 class="app-intro-subtitle">Qui fait quoi ?</h3>
          <ul class="app-intro-roles">
            <li><strong>Artistes</strong><span>consultent les dates de leurs sections, répondent aux présences et aux sondages.</span></li>
            <li><strong>Managers</strong><span>créent et modifient les dates de leurs sous-sections, leur publient des annonces et saisissent les présences de leurs membres.</span></li>
            <li><strong>Organisateurs</strong><span>font de même pour toutes les sections, gèrent sondages et feuilles de route, et créent des comptes.</span></li>
            <li><strong>Logisticiens</strong><span>ont les mêmes droits que les organisateurs et suivent en plus la gestion administrative des dates : cachets, devis envoyés aux organisateurs et devis de transport.</span></li>
            <li><strong>Administrateurs</strong><span>gèrent en plus les comptes et la configuration de l’application.</span></li>
          </ul>
          <?php if (in_array($user['role'], EVENT_MANAGER_ROLES, true)): ?>
          <p class="app-intro-note">Le détail des droits de chaque rôle se trouve dans la <a href="rights.php">matrice des droits</a>.</p>
          <?php endif; ?>
        </section>

        <?php // Announcements: how members see them, and the shortcut of those who publish them. ?>
        <section class="management-panel feature-panel" id="annonces" aria-labelledby="announcements-title">
          <h2 class="detail-heading heading-with-icon" id="announcements-title"><?= app_megaphone_icon('heading-icon') ?><span>Les annonces</span></h2>
          <p class="feature-panel-lead">Un changement de salle, du matériel à apporter, un rappel avant un stage : une annonce s’affiche en haut de la page d’accueil des membres concernés, au-dessus des prochaines dates.</p>
          <?php $canPublish = app_can_publish_announcements($user); ?>
          <div class="feature-panel-columns<?= $canPublish ? '' : ' is-single' ?>">
            <div>
              <h3>Pour tous les membres</h3>
              <ul>
                <li>La dernière annonce de vos sections s’affiche en grand, avec son titre, sa photo, son texte et, s’il y en a un, son lien.</li>
                <li>Les autres sont rangées sous « Autres annonces ».</li>
                <li><strong>Masquer</strong> la range avec les autres : elles restent consultables par le lien « Annonces » à côté de « Agenda complet », jusqu’à la fin de leur affichage.</li>
                <li>Avec les <a href="#notifications">notifications</a> activées, une nouvelle annonce arrive aussi sur votre téléphone.</li>
              </ul>
            </div>
            <?php if ($canPublish): ?>
            <div>
              <h3>Pour les managers et les organisateurs</h3>
              <ul>
                <li>Les managers publient pour les sous-sections de leur périmètre, les organisateurs, logisticiens et administrateurs pour toutes les sections ou tout le monde.</li>
                <li>Titre, texte, lien et photo, période d’affichage, et notification envoyée à la publication ou le premier jour.</li>
                <li>L’onglet « Mes annonces » permet de modifier ou de supprimer vos annonces.</li>
              </ul>
              <div class="feature-panel-actions">
                <a class="primary-button compact-button" href="announcements.php?nouvelle=1">Publier une annonce</a>
                <a class="secondary-button compact-button" href="announcements.php">Mes annonces</a>
              </div>
            </div>
            <?php endif; ?>
          </div>
        </section>

        <section class="management-panel install-panel" id="installer" aria-labelledby="install-title">
          <h2 class="detail-heading" id="install-title">Installer <?= app_escape($nickname) ?> sur votre téléphone ou votre ordinateur</h2>
          <p class="muted-copy">Ajoutez l’application à l’écran d’accueil de votre téléphone, ou à la barre des tâches et au menu Démarrer de votre ordinateur : elle s’ouvre d’un geste, dans sa propre fenêtre, comme une application.</p>
          <p class="install-done" data-install-installed hidden>✓ Vous utilisez déjà l’application installée.</p>
          <button class="primary-button" type="button" data-install-button hidden>Installer l’application</button>
          <div class="install-steps" data-install-help>
            <div>
              <h3>iPhone et iPad (Safari)</h3>
              <ol><li>Ouvrez le site dans <strong>Safari</strong>.</li><li>Touchez le bouton <strong>Partager</strong> (carré avec une flèche vers le haut).</li><li>Choisissez <strong>Sur l’écran d’accueil</strong>, puis <strong>Ajouter</strong>.</li></ol>
            </div>
            <div>
              <h3>Android (Chrome)</h3>
              <ol><li>Touchez le bouton <strong>Installer l’application</strong> ci-dessus s’il apparaît,</li><li>ou ouvrez le menu <strong>⋮</strong> de Chrome,</li><li>puis <strong>Installer l’application</strong> (ou <strong>Ajouter à l’écran d’accueil</strong>).</li></ol>
            </div>
            <div>
              <h3>Ordinateur Windows ou Mac (Chrome)</h3>
              <ol><li>Cliquez sur le bouton <strong>Installer l’application</strong> ci-dessus s’il apparaît, ou sur l’icône d’installation à droite de la barre d’adresse,</li><li>ou ouvrez le menu <strong>⋮</strong> de Chrome, puis <strong>Caster, enregistrer et partager</strong> › <strong>Installer la page en tant qu’application</strong>.</li></ol>
            </div>
            <div>
              <h3>Ordinateur Windows (Edge)</h3>
              <ol><li>Ouvrez le menu <strong>⋯</strong> d’Edge,</li><li>puis <strong>Applications</strong> › <strong>Installer ce site en tant qu’application</strong>.</li></ol>
            </div>
          </div>
          <p class="app-intro-note" data-install-help><strong>Vous utilisez Firefox ?</strong> Il ne permet pas d’installer un site comme une application : ouvrez le site une fois dans <strong>Chrome</strong> ou <strong>Edge</strong> pour l’installer (étapes ci-dessus). Ensuite, l’application s’ouvre depuis le menu Démarrer ou la barre des tâches, quel que soit votre navigateur habituel.</p>
        </section>

        <section class="management-panel install-panel push-panel" id="notifications" aria-labelledby="notifications-title" data-push-panel data-push-key="<?= app_escape(app_push_vapid()['public']) ?>" data-csrf="<?= app_escape(app_csrf_token()) ?>">
          <h2 class="detail-heading" id="notifications-title">Notifications</h2>
          <p class="muted-copy">Recevez une notification sur cet appareil quand une <strong>nouvelle date</strong> concerne vos sections, ou quand une date que vous avez <strong>déjà consultée</strong> est modifiée. Touchez la notification pour ouvrir la date.</p>
          <p class="muted-copy">Sans notifications, la <strong>cloche</strong> en haut de page compte ces dates et les liste, et l’icône de l’application installée affiche leur nombre.</p>
          <p class="push-state" data-push-state role="status"></p>
          <button class="primary-button" type="button" data-push-enable hidden>Activer les notifications sur cet appareil</button>
          <button class="secondary-button" type="button" data-push-disable hidden>Désactiver les notifications sur cet appareil</button>
          <p class="app-intro-note">Sur iPhone et iPad, les notifications ne fonctionnent que dans l’application installée (iOS 16.4 ou plus récent) : installez-la d’abord, puis activez-les depuis cette page dans l’application.</p>
        </section>

        <h2 class="app-intro-history">Dernières mises à jour</h2>
        <div class="changelog">
          <?php foreach ($days as $day => $entries): ?>
          <?php $date = app_parse_date($day); ?>
          <section class="management-panel changelog-day" aria-labelledby="changelog-<?= app_escape($day) ?>">
            <h2 class="detail-heading" id="changelog-<?= app_escape($day) ?>">
              <?= $date !== null ? app_escape($weekdays[(int) $date->format('N')] . ' ' . $date->format('j') . ' ' . $months[(int) $date->format('n')] . ' ' . $date->format('Y')) : app_escape($day) ?>
              <span class="count-badge" title="Nouveautés ce jour-là"><?= count(array_filter($entries, static fn (array $entry): bool => $entry !== MINOR_UPDATE)) ?></span>
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
