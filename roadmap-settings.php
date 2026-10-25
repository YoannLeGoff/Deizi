<?php
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';
$admin = app_require_roles(ACCOUNT_MANAGER_ROLES);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    app_verify_csrf();
    $linkInput = app_clean_line($_POST['qr_link'] ?? '', 2000);
    if ($linkInput !== '' && !app_is_http_url($linkInput)) {
        app_flash('Le lien du QR code doit être une adresse http(s) valide.', 'error');
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
    app_flash('Les réglages de la feuille de route ont été enregistrés.');
    app_redirect('roadmap-settings.php');
}

$settings = app_settings();

app_header('Feuille de route', $admin);
?>
      <section class="management-section">
        <div class="eyebrow-row">
          <p class="eyebrow"><span class="eyebrow-line"></span> CONFIGURATION · DOCUMENTS</p>
          <?php app_page_links([['Configuration', 'settings.php']]); ?>
        </div>
        <div class="section-heading">
          <div>
            <h1>Feuille de route<span class="heading-dot">.</span></h1>
          </div>
        </div>
        <?php app_settings_nav('documents', 'roadmap-settings.php', $admin); ?>

        <form method="post" action="roadmap-settings.php" enctype="multipart/form-data" class="form-stack">
          <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">

          <section class="management-panel" aria-labelledby="roadmap-qr-title">
            <h2 class="detail-heading" id="roadmap-qr-title">QR code par défaut</h2>
            <p class="muted-copy">Affiché dans le cadre « Contacts » de chaque feuille de route. Une date peut le remplacer depuis son formulaire.</p>
            <div class="qr-settings-layout">
              <figure class="qr-preview">
                <?php if ($settings['qr']['image'] !== null): ?>
                <img src="<?= app_escape((string) app_upload_url($settings['qr']['image'])) ?>" alt="QR code actuel" width="120" height="120">
                <figcaption>Image personnalisée</figcaption>
                <?php else: ?>
                <figcaption>Aucune image : la feuille de route n’affiche pas de QR code tant qu’il n’y a ni image ni lien.</figcaption>
                <?php endif; ?>
              </figure>
              <div class="form-stack">
                <label for="qr-caption">Libellé</label>
                <input id="qr-caption" name="qr_caption" maxlength="80" value="<?= app_escape($settings['qr']['caption']) ?>">
                <label for="qr-link">Lien ouvert par le libellé</label>
                <input id="qr-link" name="qr_link" type="url" maxlength="2000" placeholder="https://chat.whatsapp.com/…" value="<?= app_escape($settings['qr']['link']) ?>">
                <label for="qr-image">Nouvelle image du QR code (PNG, JPEG, GIF ou WebP, 2 Mo maximum)</label>
                <input id="qr-image" name="qr_image" type="file" accept="image/png,image/jpeg,image/gif,image/webp">
                <p class="field-help">L’image n’est pas générée à partir du lien : si vous changez de groupe, remplacez aussi l’image par le QR code correspondant.</p>
                <?php if ($settings['qr']['image'] !== null): ?>
                <label class="active-toggle"><input type="checkbox" name="qr_reset" value="1"> Retirer l’image</label>
                <?php endif; ?>
              </div>
            </div>
          </section>

          <section class="management-panel" aria-labelledby="roadmap-contact-title">
            <h2 class="detail-heading" id="roadmap-contact-title">Contact par défaut</h2>
            <p class="muted-copy">Pré-rempli comme premier contact dans le formulaire d’une date qui n’a pas encore de contact. Il reste modifiable date par date. Laissez vide pour ne rien pré-remplir.</p>
            <div class="roadmap-row roadmap-row-contacts">
              <div class="form-stack">
                <label for="default-contact-label">Rôle</label>
                <input id="default-contact-label" name="default_contact[label]" maxlength="80" value="<?= app_escape($settings['default_contact']['label']) ?>">
              </div>
              <div class="form-stack">
                <label for="default-contact-details">Coordonnées</label>
                <textarea id="default-contact-details" name="default_contact[details]" rows="<?= max(2, substr_count($settings['default_contact']['details'], "\n") + 1) ?>" placeholder="Nom, téléphone, e-mail…"><?= app_escape($settings['default_contact']['details']) ?></textarea>
              </div>
            </div>
          </section>

          <section class="management-panel" aria-labelledby="roadmap-repertoires-title">
            <h2 class="detail-heading" id="roadmap-repertoires-title">Répertoires</h2>
            <p class="muted-copy">Morceaux proposés à cocher dans le formulaire d’une date, un par ligne, pour chaque section qui a un répertoire (case « Répertoire » de <a href="sections-settings.php">Configuration › Sections</a>). Une date affiche les répertoires de ses sections : une sortie Bagad + Bagadig propose les deux listes. Les morceaux retirés restent sur les feuilles de route où ils sont déjà cochés.</p>
            <?php if ($settings['repertoires'] === []): ?>
            <p class="muted-copy">Aucune section n’a de répertoire pour le moment.</p>
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
            <button class="primary-button" type="submit">Enregistrer les réglages</button>
            <a class="secondary-button" href="settings.php">Annuler</a>
          </div>
        </form>
      </section>
<?php app_footer(); ?>
