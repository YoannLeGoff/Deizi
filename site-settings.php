<?php
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';
$admin = app_require_roles(['admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    app_verify_csrf();
    $title = app_clean_line($_POST['title'] ?? '', 80);
    $nickname = app_clean_line($_POST['nickname'] ?? '', 40);
    $association = app_clean_line($_POST['association'] ?? '', 80);
    $tagline = app_clean_line($_POST['tagline'] ?? '', 120);
    $aboutText = app_clean_text($_POST['about_text'] ?? '', 1000);
    if ($title === '') {
        app_flash(__('titre_site_obligatoire_phrase'), 'error');
        app_redirect('site-settings.php');
    }
    $uploaded = [];
    try {
        foreach (array_keys(SITE_LOGOS) as $key) {
            $uploaded[$key] = app_store_uploaded_image($_FILES[$key] ?? null, 'logo');
        }
    } catch (InvalidArgumentException $exception) {
        array_map('app_delete_upload', $uploaded);
        app_flash($exception->getMessage(), 'error');
        app_redirect('site-settings.php');
    }
    $reset = [];
    foreach (array_keys(SITE_LOGOS) as $key) {
        $reset[$key] = ($_POST[$key . '_reset'] ?? '') === '1';
    }

    $obsolete = [];
    update_json_file(app_data_path('settings.json'), static function (array $settings) use ($title, $nickname, $association, $tagline, $aboutText, $uploaded, $reset, &$obsolete): array {
        $site = is_array($settings['site'] ?? null) ? $settings['site'] : [];
        // The default language is chosen in Configuration > Langues: kept as it is.
        $saved = ['title' => $title, 'nickname' => $nickname, 'association' => $association, 'tagline' => $tagline, 'about_text' => $aboutText] + array_intersect_key($site, ['language' => true]);
        foreach (array_keys(SITE_LOGOS) as $key) {
            $previous = is_string($site[$key] ?? null) ? $site[$key] : null;
            $saved[$key] = $uploaded[$key] ?? ($reset[$key] ? null : $previous);
            if ($previous !== null && $previous !== $saved[$key]) {
                $obsolete[] = $previous;
            }
        }
        $settings['site'] = $saved;
        return $settings;
    });
    array_map('app_delete_upload', $obsolete);
    app_flash(__('lidentite_site_ete_enregistree_phrase'));
    app_redirect('site-settings.php');
}

$identity = app_site_identity();

app_header('Identité du site', $admin);
?>
      <section class="management-section">
        <div class="eyebrow-row">
          <p class="eyebrow"><span class="eyebrow-line"></span> <?= app_escape(__('configuration_site_majuscules')) ?></p>
          <?php app_page_links([['Configuration', 'settings.php']]); ?>
        </div>
        <div class="section-heading">
          <div>
            <h1><?= app_escape(__('identite_site')) ?><span class="heading-dot">.</span></h1>
          </div>
        </div>
        <?php app_settings_nav('organisation', 'site-settings.php', $admin); ?>

        <form method="post" action="site-settings.php" enctype="multipart/form-data" class="form-stack">
          <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">

          <section class="management-panel" aria-labelledby="site-text-title">
            <h2 class="detail-heading" id="site-text-title"><?= app_escape(__('titre_pseudo_association_phrase_daccroche')) ?></h2>
            <p class="muted-copy"><?= app_escape(__('affiches_haut_chaque_page_cote_logo')) ?></p>
            <div class="form-grid site-text-grid">
              <div class="form-stack">
                <label for="site-title"><?= app_escape(__('titre_site')) ?></label>
                <input id="site-title" name="title" maxlength="80" required value="<?= app_escape($identity['title']) ?>">
              </div>
              <div class="form-stack">
                <label for="site-nickname"><?= app_escape(__('pseudo')) ?></label>
                <input id="site-nickname" name="nickname" maxlength="40" value="<?= app_escape($identity['nickname']) ?>">
                <p class="field-help"><?= app_escape(__('nom_court_lapplication_exemple_deizi_affiche')) ?></p>
              </div>
              <div class="form-stack">
                <label for="site-association"><?= app_escape(__('nom_lassociation')) ?></label>
                <input id="site-association" name="association" maxlength="80" placeholder="Kerlenn Pondi" value="<?= app_escape($identity['association']) ?>">
                <p class="field-help"><?= app_escape(__('affiche_dans_titre_lagenda_complet_tous')) ?></p>
              </div>
              <div class="form-stack">
                <label for="site-tagline"><?= app_escape(__('phrase_daccroche')) ?></label>
                <input id="site-tagline" name="tagline" maxlength="120" value="<?= app_escape($identity['tagline']) ?>">
                <p class="field-help"><?= app_escape(__('laissez_vide_nafficher_titre_phrase')) ?></p>
              </div>
            </div>
          </section>

          <?php $about = app_site_about(); ?>
          <section class="management-panel" aria-labelledby="site-about-title">
            <h2 class="detail-heading" id="site-about-title"><?= app_escape(__('presentation_lapplication')) ?></h2>
            <p class="muted-copy"><?= app_escape(__('tete_page_propos_mises_jour_dessus')) ?></p>
            <div class="form-stack">
              <label for="site-about-text"><?= app_escape(__('texte_presentation')) ?></label>
              <textarea id="site-about-text" name="about_text" rows="4" maxlength="1000"><?= app_escape($about['text']) ?></textarea>
              <p class="field-help"><?= app_escape(__('texte_simple_1_000_caracteres_plus')) ?></p>
            </div>
          </section>

          <?php // Logos and icon: folded, their state summed up on the line that opens them. ?>
          <details class="form-accordion site-logos-accordion">
            <summary>
              <span class="form-accordion-title"><?= app_escape(__('logos_icone_lapplication')) ?></span>
              <span class="form-accordion-summary"><?= app_escape(__('logo_den_tete_logo_logo_connexion', ['logo' => $identity['logo'] !== null ? __('personnalise') : __('dorigine'), 'login' => $identity['login_logo'] !== null ? __('oui') : __('aucun'), 'icon' => $identity['app_icon'] !== null ? __('personnalisee') : __('faite_partir_logo')])) ?></span>
            </summary>
            <div class="form-accordion-body">
          <?php foreach (SITE_LOGOS as $key => $label): ?>
          <?php $logoUrl = app_site_logo_url($key); ?>
          <section class="site-logo-block" aria-labelledby="site-<?= $key ?>-title">
            <h3 class="detail-heading" id="site-<?= $key ?>-title"><?= app_escape(app_label($label)) ?></h3>
            <?php if ($key === 'logo'): ?>
            <p class="muted-copy"><?= app_escape(__('affiche_petit_gauche_titre_haut_chaque')) ?></p>
            <?php elseif ($key === 'app_icon'): ?>
            <p class="muted-copy"><?= app_escape(__('icone_lapplication_installee_lecran_daccueil_telephones')) ?></p>
            <?php else: ?>
            <p class="muted-copy"><?= app_escape(__('affiche_grand_haut_cadre_connexion_160')) ?></p>
            <?php endif; ?>
            <div class="qr-settings-layout">
              <figure class="qr-preview site-logo-preview">
                <?php if ($key === 'app_icon'): ?>
                <img class="app-icon-preview" src="icon.php?size=192&amp;v=<?= app_escape(app_icon_version()) ?>" alt="<?= app_escape(__('icone_actuelle_lapplication')) ?>">
                <?php elseif ($logoUrl !== null): ?>
                <img src="<?= app_escape($logoUrl) ?>" alt="<?= app_escape(__('logo_actuel_2', ['logo' => app_label($label)])) ?>">
                <?php elseif ($key === 'logo'): ?>
                <span class="brand-mark" aria-hidden="true">♫</span>
                <?php else: ?>
                <span class="site-logo-empty" aria-hidden="true">—</span>
                <?php endif; ?>
                <figcaption><?= app_escape($key === 'app_icon' ? ($logoUrl !== null ? __('icone_personnalisee') : __('faite_partir_logo_len_tete')) : ($logoUrl !== null ? __('logo_personnalise') : ($key === 'logo' ? __('logo_dorigine') : __('aucun_logo')))) ?></figcaption>
              </figure>
              <div class="form-stack">
                <label for="site-<?= $key ?>"><?= app_escape($key === 'app_icon' ? __('nouvelle_icone_png_jpeg_carre_2') : __('nouveau_logo_png_jpeg_gif_webp')) ?></label>
                <input id="site-<?= $key ?>" name="<?= $key ?>" type="file" accept="image/png,image/jpeg,image/gif,image/webp">
                <?php if ($logoUrl !== null): ?>
                <label class="active-toggle"><input type="checkbox" name="<?= $key ?>_reset" value="1"> <?= app_escape($key === 'logo' ? __('revenir_logo_dorigine') : ($key === 'app_icon' ? __('revenir_licone_faite_partir_logo') : __('retirer_ce_logo'))) ?></label>
                <?php endif; ?>
              </div>
            </div>
          </section>
          <?php endforeach; ?>
            </div>
          </details>

          <div class="form-actions">
            <button class="primary-button" type="submit"><?= app_escape(__('enregistrer')) ?></button>
            <a class="secondary-button" href="settings.php"><?= app_escape(__('annuler')) ?></a>
          </div>
        </form>
      </section>
<?php app_footer(); ?>
