<?php
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';
$user = app_require_login();

$nickname = app_site_nickname();
// Words in bold inside translated sentences.
$strong = static fn (string $text): string => '<strong>' . app_escape($text) . '</strong>';

app_header('Mises à jour', $user);
?>
      <section class="management-section">
        <div class="eyebrow-row">
          <p class="eyebrow"><span class="eyebrow-line"></span> <?= app_escape(__('propos_majuscules')) ?></p>
          <?php // Managers, organizers and administrators also reach this page from the Configuration. ?>
          <?php app_page_links([['Retour à l’agenda', 'index.php'], in_array($user['role'], EVENT_MANAGER_ROLES, true) ? ['Configuration', 'settings.php'] : null]); ?>
        </div>
        <div class="section-heading">
          <div>
            <h1><?= app_escape($nickname) ?><span class="heading-dot">.</span></h1>
          </div>
          <div class="page-actions">
            <a class="primary-button compact-button" href="onboarding.php" target="_blank" rel="noopener"><?= app_escape(__('voir_presentation')) ?></a>
            <a class="secondary-button compact-button" href="onboarding.php?f=pdf" target="_blank" rel="noopener"><?= app_escape(__('pdf')) ?></a>
          </div>
        </div>

        <?php // General presentation of the application, above the list of updates. ?>
        <section class="management-panel app-intro" aria-label="<?= app_escape(__('presentation_app', ['app' => $nickname])) ?>">
          <p class="app-intro-presentation"><?= __('decouvrir_lapplication_selon_votre_role_presentation', ['presentation' => '<a class="page-switch" href="onboarding.php" target="_blank" rel="noopener">' . app_escape(__('presentation_app_minuscule', ['app' => $nickname])) . '</a>', 'pdf' => '<a class="page-switch" href="onboarding.php?f=pdf" target="_blank" rel="noopener">PDF</a>']) ?></p>
          <?php // Presentation set in Configuration > Identité du site. ?>
          <?php $about = app_site_about(); ?>
          <?php if ($about['title'] !== '' || $about['text'] !== ''): ?>
          <p class="app-intro-lead"><?php if ($about['title'] !== ''): ?><strong><?= app_escape($about['title']) ?></strong> <?php endif; ?><?= nl2br(app_escape($about['text']), false) ?></p>
          <?php endif; ?>
          <ul class="app-intro-features">
            <li><strong><?= app_escape(__('lagenda')) ?></strong><span><?= app_escape(__('prochaines_dates_vos_sections_avec_horaire')) ?></span></li>
            <li><strong><?= app_escape(__('votre_presence')) ?></strong><span><?= app_escape(__('present_absent_je_sais_clic_depuis')) ?></span></li>
            <li><strong><?= app_escape(__('sondages')) ?></strong><span><?= app_escape(__('question_pratique_date_bus_repas_hebergement')) ?></span></li>
            <li><strong><?= app_escape(__('la_feuille_de_route')) ?></strong><span><?= app_escape(__('modele_feuille_papier_tout_deroule_dune')) ?></span></li>
            <li><strong><?= app_escape(__('vues_avancees')) ?></strong><span><?= app_escape(__('liste_planning_annuel_dates_toutes_sections')) ?></span></li>
            <li><strong><?= app_escape(__('les_annonces')) ?></strong><span><?= app_escape(__('message_votre_manager_organisateurs_texte_photo')) ?> <a href="#annonces"><?= app_escape(__('savoir_plus')) ?></a></span></li>
            <li><strong><?= app_escape(__('nouveautes_signalees')) ?></strong><span><?= __('etiquette_nouveau_dates_creees_pastille_rouge', ['notifications' => '<a href="#notifications">' . app_escape(__('notifications_minuscule')) . '</a>']) ?></span></li>
          </ul>
          <h3 class="app-intro-subtitle"><?= app_escape(__('fait_quoi')) ?></h3>
          <ul class="app-intro-roles">
            <li><strong><?= app_escape(__('artistes')) ?></strong><span><?= app_escape(__('consultent_dates_leurs_sections_repondent_presences')) ?></span></li>
            <li><strong><?= app_escape(__('managers')) ?></strong><span><?= app_escape(__('creent_modifient_dates_leurs_sous_sections')) ?></span></li>
            <li><strong><?= app_escape(__('organisateurs')) ?></strong><span><?= app_escape(__('font_meme_toutes_sections_gerent_sondages')) ?></span></li>
            <li><strong><?= app_escape(__('logisticiens')) ?></strong><span><?= app_escape(__('ont_memes_droits_organisateurs_suivent_plus')) ?></span></li>
            <li><strong><?= app_escape(__('administrateurs')) ?></strong><span><?= app_escape(__('gerent_plus_comptes_configuration_lapplication')) ?></span></li>
          </ul>
          <?php if (in_array($user['role'], EVENT_MANAGER_ROLES, true)): ?>
          <p class="app-intro-note"><?= __('detail_droits_chaque_role_trouve_dans', ['rights' => '<a href="rights.php">' . app_escape(__('matrice_droits_minuscule')) . '</a>']) ?></p>
          <?php endif; ?>
        </section>

        <?php // Announcements: how members see them, and the shortcut of those who publish them. ?>
        <section class="management-panel feature-panel" id="annonces" aria-labelledby="announcements-title">
          <h2 class="detail-heading heading-with-icon" id="announcements-title"><?= app_megaphone_icon('heading-icon') ?><span><?= app_escape(__('les_annonces')) ?></span></h2>
          <p class="feature-panel-lead"><?= app_escape(__('changement_salle_materiel_apporter_rappel_avant')) ?></p>
          <?php $canPublish = app_can_publish_announcements($user); ?>
          <div class="feature-panel-columns<?= $canPublish ? '' : ' is-single' ?>">
            <div>
              <h3><?= app_escape(__('tous_membres')) ?></h3>
              <ul>
                <li><?= app_escape(__('derniere_annonce_vos_sections_saffiche_grand')) ?></li>
                <li><?= app_escape(__('autres_sont_rangees_sous_autres_annonces')) ?></li>
                <li><?= __('hide_range_avec_autres_elles_restent', ['hide' => $strong(__('masquer'))]) ?></li>
                <li><?= __('avec_notifications_activees_nouvelle_annonce_arrive', ['notifications' => '<a href="#notifications">' . app_escape(__('notifications_minuscule')) . '</a>']) ?></li>
              </ul>
            </div>
            <?php if ($canPublish): ?>
            <div>
              <h3><?= app_escape(__('managers_organisateurs')) ?></h3>
              <ul>
                <li><?= app_escape(__('managers_publient_sous_sections_leur_perimetre')) ?></li>
                <li><?= app_escape(__('titre_texte_lien_photo_periode_daffichage')) ?></li>
                <li><?= app_escape(__('longlet_mes_annonces_permet_modifier_supprimer')) ?></li>
              </ul>
              <div class="feature-panel-actions">
                <a class="primary-button compact-button" href="announcements.php?nouvelle=1"><?= app_escape(__('publier_annonce')) ?></a>
                <a class="secondary-button compact-button" href="announcements.php"><?= app_escape(__('mes_annonces')) ?></a>
              </div>
            </div>
            <?php endif; ?>
          </div>
        </section>

        <section class="management-panel install-panel" id="installer" aria-labelledby="install-title">
          <h2 class="detail-heading" id="install-title"><?= app_escape(__('installer_app_votre_telephone_votre_ordinateur', ['app' => $nickname])) ?></h2>
          <p class="muted-copy"><?= app_escape(__('ajoutez_lapplication_lecran_daccueil_votre_telephone')) ?></p>
          <p class="install-done" data-install-installed hidden><?= app_escape(__('vous_utilisez_deja_lapplication_installee_phrase')) ?></p>
          <button class="primary-button" type="button" data-install-button hidden><?= app_escape(__('installer_lapplication')) ?></button>
          <div class="install-steps" data-install-help>
            <div>
              <h3><?= app_escape(__('iphone_ipad_safari')) ?></h3>
              <ol><li><?= __('ouvrez_site_dans_browser_phrase', ['browser' => $strong('Safari')]) ?></li><li><?= __('touchez_bouton_share_carre_avec_fleche', ['share' => $strong(__('partager'))]) ?></li><li><?= __('choisissez_home_puis_add_phrase', ['home' => $strong(__('lecran_daccueil')), 'add' => $strong(__('ajouter'))]) ?></li></ol>
            </div>
            <div>
              <h3><?= app_escape(__('android_chrome')) ?></h3>
              <ol><li><?= __('touchez_bouton_install_ci_dessus_sil', ['install' => $strong(__('installer_lapplication'))]) ?></li><li><?= __('ouvrez_menu_menu_chrome', ['menu' => $strong('⋮')]) ?></li><li><?= __('puis_install_home_phrase', ['install' => $strong(__('installer_lapplication')), 'home' => $strong(__('ajouter_lecran_daccueil'))]) ?></li></ol>
            </div>
            <div>
              <h3><?= app_escape(__('ordinateur_windows_mac_chrome')) ?></h3>
              <ol><li><?= __('cliquez_bouton_install_ci_dessus_sil', ['install' => $strong(__('installer_lapplication'))]) ?></li><li><?= __('ouvrez_menu_menu_chrome_puis_cast', ['menu' => $strong('⋮'), 'cast' => $strong(__('caster_enregistrer_partager')), 'page' => $strong(__('installer_page_tant_quapplication'))]) ?></li></ol>
            </div>
            <div>
              <h3><?= app_escape(__('ordinateur_windows_edge')) ?></h3>
              <ol><li><?= __('ouvrez_menu_menu_dedge', ['menu' => $strong('⋯')]) ?></li><li><?= __('puis_apps_install_phrase', ['apps' => $strong(__('applications')), 'install' => $strong(__('installer_ce_site_tant_quapplication'))]) ?></li></ol>
            </div>
          </div>
          <p class="app-intro-note" data-install-help><strong><?= app_escape(__('vous_utilisez_firefox')) ?></strong> <?= __('permet_dinstaller_site_comme_application_ouvrez', ['chrome' => $strong('Chrome'), 'edge' => $strong('Edge')]) ?></p>
        </section>

        <section class="management-panel install-panel push-panel" id="notifications" aria-labelledby="notifications-title" data-push-panel data-push-key="<?= app_escape(app_push_vapid()['public']) ?>" data-csrf="<?= app_escape(app_csrf_token()) ?>">
          <h2 class="detail-heading" id="notifications-title"><?= app_escape(__('notifications')) ?></h2>
          <p class="muted-copy"><?= __('recevez_notification_cet_appareil_quand_new', ['new' => $strong(__('nouvelle_date')), 'seen' => $strong(__('deja_consultee'))]) ?></p>
          <p class="muted-copy"><?= __('sans_notifications_bell_haut_page_compte', ['bell' => $strong(__('cloche'))]) ?></p>
          <p class="push-state" data-push-state role="status"></p>
          <button class="primary-button" type="button" data-push-enable hidden><?= app_escape(__('activer_notifications_cet_appareil')) ?></button>
          <button class="secondary-button" type="button" data-push-disable hidden><?= app_escape(__('desactiver_notifications_cet_appareil')) ?></button>
          <p class="app-intro-note"><?= app_escape(__('iphone_ipad_notifications_fonctionnent_dans_lapplication')) ?></p>
        </section>

        <?php // What is new: the release notes of each version, kept with the source code (releases/, GitHub). ?>
        <section class="management-panel install-panel" id="nouveautes" aria-labelledby="releases-title">
          <h2 class="detail-heading" id="releases-title"><?= app_escape(__('nouveautes')) ?></h2>
          <p class="muted-copy"><?= app_escape(__('version_date_mise_jour', ['version' => DEIZI_VERSION, 'date' => app_long_date(app_files_updated_at(), false)])) ?></p>
          <p class="muted-copy"><?= app_escape(__('notes_version_decrivent_nouveautes')) ?></p>
          <p><a class="secondary-button compact-button" href="<?= app_escape(app_releases_url()) ?>" target="_blank" rel="noopener"><?= app_escape(__('voir_nouveautes')) ?> <span aria-hidden="true">↗</span></a></p>
        </section>
      </section>
<?php app_footer(); ?>
