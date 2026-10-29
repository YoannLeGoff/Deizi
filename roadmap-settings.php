<?php
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';
$admin = app_require_roles(ACCOUNT_MANAGER_ROLES);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    app_verify_csrf();
    $linkInput = app_clean_line($_POST['qr_link'] ?? '', 2000);
    if ($linkInput !== '' && !app_is_http_url($linkInput)) {
        app_flash(__('lien_qr_code_doit_etre_adresse'), 'error');
        app_redirect('roadmap-settings.php');
    }
    try {
        $uploaded = app_store_uploaded_image($_FILES['qr_image'] ?? null);
    } catch (InvalidArgumentException $exception) {
        app_flash($exception->getMessage(), 'error');
        app_redirect('roadmap-settings.php');
    }
    $resetImage = ($_POST['qr_reset'] ?? '') === '1';
    $caption = app_clean_line($_POST['qr_caption'] ?? '', 80);
    $repertoires = [];
    foreach (app_repertoire_sections() as $key => $label) {
        $repertoires[$key] = app_clean_lines($_POST['repertoires'][$key] ?? '', REPERTOIRE_MAX_PIECES, 120);
    }
    $defaultContact = [
        'label' => app_clean_line($_POST['default_contact']['label'] ?? '', 80),
        'details' => app_clean_text($_POST['default_contact']['details'] ?? '', 600),
    ];

    $previousImage = app_settings()['qr']['image'];
    update_json_file(app_data_path('settings.json'), static function (array $settings) use ($caption, $linkInput, $uploaded, $resetImage, $previousImage, $repertoires, $defaultContact): array {
        $settings['qr'] = [
            'caption' => $caption !== '' ? $caption : app_default_settings()['qr']['caption'],
            'link' => $linkInput,
            'image' => $uploaded ?? ($resetImage ? null : $previousImage),
        ];
        $settings['default_contact'] = $defaultContact;
        // The lists of sections whose repertoire is turned off are kept for when it is turned on again.
        $settings['repertoires'] = array_merge(is_array($settings['repertoires'] ?? null) ? $settings['repertoires'] : [], $repertoires);
        return $settings;
    });
    if ($previousImage !== null && ($uploaded !== null || $resetImage)) {
        app_delete_upload($previousImage);
    }
    app_flash(__('reglages_feuille_route_ont_ete_enregistres'));
    app_redirect('roadmap-settings.php');
}

$settings = app_settings();

app_header('Feuille de route', $admin);
?>
      <section class="management-section">
        <div class="eyebrow-row">
          <p class="eyebrow"><span class="eyebrow-line"></span> <?= app_escape(__('configuration_documents_majuscules')) ?></p>
          <?php app_page_links([['Configuration', 'settings.php']]); ?>
        </div>
        <div class="section-heading">
          <div>
            <h1><?= app_escape(__('feuille_route')) ?><span class="heading-dot">.</span></h1>
          </div>
        </div>
        <?php app_settings_nav('documents', 'roadmap-settings.php', $admin); ?>

        <form method="post" action="roadmap-settings.php" enctype="multipart/form-data" class="form-stack">
          <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">

          <section class="management-panel" aria-labelledby="roadmap-qr-title">
            <h2 class="detail-heading" id="roadmap-qr-title"><?= app_escape(__('qr_code_defaut')) ?></h2>
            <p class="muted-copy"><?= app_escape(__('affiche_dans_cadre_contacts_chaque_feuille')) ?></p>
            <div class="qr-settings-layout">
              <figure class="qr-preview">
                <?php if ($settings['qr']['image'] !== null): ?>
                <img src="<?= app_escape((string) app_upload_url($settings['qr']['image'])) ?>" alt="<?= app_escape(__('qr_code_actuel')) ?>" width="120" height="120">
                <figcaption><?= app_escape(__('image_personnalisee')) ?></figcaption>
                <?php else: ?>
                <figcaption><?= app_escape(__('aucune_image_feuille_route_naffiche_qr')) ?></figcaption>
                <?php endif; ?>
              </figure>
              <div class="form-stack">
                <label for="qr-caption"><?= app_escape(__('libelle')) ?></label>
                <input id="qr-caption" name="qr_caption" maxlength="80" value="<?= app_escape($settings['qr']['caption']) ?>">
                <label for="qr-link"><?= app_escape(__('lien_ouvert_libelle')) ?></label>
                <input id="qr-link" name="qr_link" type="url" maxlength="2000" placeholder="https://chat.whatsapp.com/…" value="<?= app_escape($settings['qr']['link']) ?>">
                <label for="qr-image"><?= app_escape(__('nouvelle_image_qr_code_png_jpeg')) ?></label>
                <input id="qr-image" name="qr_image" type="file" accept="image/png,image/jpeg,image/gif,image/webp">
                <p class="field-help"><?= app_escape(__('limage_nest_generee_partir_lien_si')) ?></p>
                <?php if ($settings['qr']['image'] !== null): ?>
                <label class="active-toggle"><input type="checkbox" name="qr_reset" value="1"> <?= app_escape(__('retirer_limage')) ?></label>
                <?php endif; ?>
              </div>
            </div>
          </section>

          <section class="management-panel" aria-labelledby="roadmap-contact-title">
            <h2 class="detail-heading" id="roadmap-contact-title"><?= app_escape(__('contact_defaut')) ?></h2>
            <p class="muted-copy"><?= app_escape(__('pre_rempli_comme_premier_contact_dans')) ?></p>
            <div class="roadmap-row roadmap-row-contacts">
              <div class="form-stack">
                <label for="default-contact-label"><?= app_escape(__('role')) ?></label>
                <input id="default-contact-label" name="default_contact[label]" maxlength="80" value="<?= app_escape($settings['default_contact']['label']) ?>">
              </div>
              <div class="form-stack">
                <label for="default-contact-details"><?= app_escape(__('coordonnees')) ?></label>
                <textarea id="default-contact-details" name="default_contact[details]" rows="<?= max(2, substr_count($settings['default_contact']['details'], "\n") + 1) ?>" placeholder="<?= app_escape(__('nom_telephone_e_mail')) ?>"><?= app_escape($settings['default_contact']['details']) ?></textarea>
              </div>
            </div>
          </section>

          <section class="management-panel" aria-labelledby="roadmap-repertoires-title">
            <h2 class="detail-heading" id="roadmap-repertoires-title"><?= app_escape(__('repertoires')) ?></h2>
            <p class="muted-copy"><?= app_escape(__('morceaux_proposes_cocher_dans_formulaire_dune')) ?> <a href="sections-settings.php"><?= app_escape(__('configuration_sections')) ?></a><?= app_escape(__('date_affiche_repertoires_sections_sortie_bagad')) ?></p>
            <?php if ($settings['repertoires'] === []): ?>
            <p class="muted-copy"><?= app_escape(__('aucune_section_na_repertoire_moment_phrase')) ?></p>
            <?php endif; ?>
            <div class="form-grid">
              <?php foreach (app_repertoire_sections() as $key => $label): ?>
              <div>
                <label for="repertoire-<?= app_escape($key) ?>"><?= app_escape($label) ?></label>
                <textarea id="repertoire-<?= app_escape($key) ?>" name="repertoires[<?= app_escape($key) ?>]" rows="8"><?= app_escape(implode("\n", $settings['repertoires'][$key])) ?></textarea>
              </div>
              <?php endforeach; ?>
            </div>
          </section>

          <div class="form-actions">
            <button class="primary-button" type="submit"><?= app_escape(__('enregistrer_reglages')) ?></button>
            <a class="secondary-button" href="settings.php"><?= app_escape(__('annuler')) ?></a>
          </div>
        </form>
      </section>
<?php app_footer(); ?>
