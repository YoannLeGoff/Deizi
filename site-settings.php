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
    $language = app_is_language($_POST['language'] ?? null) ? $_POST['language'] : DEFAULT_LANGUAGE;
    if ($title === '') {
        app_flash('Le titre du site est obligatoire.', 'error');
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
    update_json_file(app_data_path('settings.json'), static function (array $settings) use ($title, $nickname, $association, $tagline, $aboutText, $language, $uploaded, $reset, &$obsolete): array {
        $site = is_array($settings['site'] ?? null) ? $settings['site'] : [];
        $saved = ['title' => $title, 'nickname' => $nickname, 'association' => $association, 'tagline' => $tagline, 'about_text' => $aboutText, 'language' => $language];
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
    app_flash('L’identité du site a été enregistrée.');
    app_redirect('site-settings.php');
}

$identity = app_site_identity();

app_header('Identité du site', $admin);
?>
      <section class="management-section">
        <div class="eyebrow-row">
          <p class="eyebrow"><span class="eyebrow-line"></span> CONFIGURATION DU SITE</p>
          <?php app_page_links([['Configuration', 'settings.php']]); ?>
        </div>
        <div class="section-heading">
          <div>
            <h1>Identité du site<span class="heading-dot">.</span></h1>
          </div>
        </div>
        <?php app_settings_nav('organisation', 'site-settings.php', $admin); ?>

        <form method="post" action="site-settings.php" enctype="multipart/form-data" class="form-stack">
          <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">

          <section class="management-panel" aria-labelledby="site-text-title">
            <h2 class="detail-heading" id="site-text-title">Titre, pseudo, association et phrase d’accroche</h2>
            <p class="muted-copy">Affichés en haut de chaque page, à côté du logo. Le titre sert aussi de nom à l’onglet du navigateur, au calendrier iCal et au planning imprimé.</p>
            <div class="form-grid site-text-grid">
              <div class="form-stack">
                <label for="site-title">Titre du site</label>
                <input id="site-title" name="title" maxlength="80" required value="<?= app_escape($identity['title']) ?>">
              </div>
              <div class="form-stack">
                <label for="site-nickname">Pseudo</label>
                <input id="site-nickname" name="nickname" maxlength="40" value="<?= app_escape($identity['nickname']) ?>">
                <p class="field-help">Nom court de l’application (par exemple « Deizi »), affiché en titre de la page « Mises à jour ». Vide, le titre du site est utilisé.</p>
              </div>
              <div class="form-stack">
                <label for="site-association">Nom de l’association</label>
                <input id="site-association" name="association" maxlength="80" placeholder="Kerlenn Pondi" value="<?= app_escape($identity['association']) ?>">
                <p class="field-help">Affiché dans le titre de l’Agenda complet (« Tous les événements Kerlenn Pondi »). Vide, le titre reste « Tous les événements ».</p>
              </div>
              <div class="form-stack">
                <label for="site-tagline">Phrase d’accroche</label>
                <input id="site-tagline" name="tagline" maxlength="120" value="<?= app_escape($identity['tagline']) ?>">
                <p class="field-help">Laissez vide pour n’afficher que le titre.</p>
              </div>
            </div>
          </section>

          <section class="management-panel" aria-labelledby="site-language-title">
            <h2 class="detail-heading" id="site-language-title"><?= app_escape(__('Langue')) ?></h2>
            <div class="form-stack">
              <label for="site-language"><?= app_escape(__('Langue par défaut du site')) ?></label>
              <select id="site-language" name="language">
                <?php foreach (app_languages() as $code => $name): ?>
                <option value="<?= $code ?>" lang="<?= $code ?>"<?= app_site_language() === $code ? ' selected' : '' ?>><?= app_escape($name) ?></option>
                <?php endforeach; ?>
              </select>
              <p class="field-help"><?= app_escape(__('Langue des membres qui n’en ont pas choisi une dans « Mon compte ». Avant la connexion, la page s’affiche dans la langue du navigateur quand elle est disponible.')) ?></p>
            </div>
          </section>

          <?php $about = app_site_about(); ?>
          <section class="management-panel" aria-labelledby="site-about-title">
            <h2 class="detail-heading" id="site-about-title">Présentation de l’application</h2>
            <p class="muted-copy">En tête de la page « À propos » (Mises à jour), au-dessus de la liste des fonctionnalités, précédé en gras de la phrase d’accroche.</p>
            <div class="form-stack">
              <label for="site-about-text">Texte de la présentation</label>
              <textarea id="site-about-text" name="about_text" rows="4" maxlength="1000"><?= app_escape($about['text']) ?></textarea>
              <p class="field-help">Texte simple (1 000 caractères au plus) ; les retours à la ligne sont conservés.</p>
            </div>
          </section>

          <?php // Logos and icon: folded, their state summed up on the line that opens them. ?>
          <details class="form-accordion site-logos-accordion">
            <summary>
              <span class="form-accordion-title">Logos et icône de l’application</span>
              <span class="form-accordion-summary">Logo d’en-tête : <?= $identity['logo'] !== null ? 'personnalisé' : 'd’origine' ?> · logo de connexion : <?= $identity['login_logo'] !== null ? 'oui' : 'aucun' ?> · icône : <?= $identity['app_icon'] !== null ? 'personnalisée' : 'faite à partir du logo' ?></span>
            </summary>
            <div class="form-accordion-body">
          <?php foreach (SITE_LOGOS as $key => $label): ?>
          <?php $logoUrl = app_site_logo_url($key); ?>
          <section class="site-logo-block" aria-labelledby="site-<?= $key ?>-title">
            <h3 class="detail-heading" id="site-<?= $key ?>-title"><?= app_escape($label) ?></h3>
            <?php if ($key === 'logo'): ?>
            <p class="muted-copy">Affiché en petit à gauche du titre, en haut de chaque page. Une image carrée ou au fond transparent rend le mieux.</p>
            <?php elseif ($key === 'app_icon'): ?>
            <p class="muted-copy">Icône de l’application installée sur l’écran d’accueil des téléphones (page Mises à jour, « Installer l’application »). Image carrée, idéalement 512 × 512 px en PNG ou JPEG, dont le motif principal reste au centre (les téléphones peuvent arrondir ou découper les bords). Sans icône, celle-ci est faite à partir du logo de l’en-tête. Les téléphones déjà équipés peuvent garder l’ancienne icône jusqu’à une nouvelle installation.</p>
            <?php else: ?>
            <p class="muted-copy">Affiché en grand en haut du cadre de connexion (160 px de haut au plus). Sans logo, le cadre ne montre que le formulaire.</p>
            <?php endif; ?>
            <div class="qr-settings-layout">
              <figure class="qr-preview site-logo-preview">
                <?php if ($key === 'app_icon'): ?>
                <img class="app-icon-preview" src="icon.php?size=192&amp;v=<?= app_escape(app_icon_version()) ?>" alt="Icône actuelle de l’application">
                <?php elseif ($logoUrl !== null): ?>
                <img src="<?= app_escape($logoUrl) ?>" alt="<?= app_escape($label) ?> actuel">
                <?php elseif ($key === 'logo'): ?>
                <span class="brand-mark" aria-hidden="true">♫</span>
                <?php else: ?>
                <span class="site-logo-empty" aria-hidden="true">—</span>
                <?php endif; ?>
                <figcaption><?= $key === 'app_icon' ? ($logoUrl !== null ? 'Icône personnalisée' : 'Faite à partir du logo de l’en-tête') : ($logoUrl !== null ? 'Logo personnalisé' : ($key === 'logo' ? 'Logo d’origine' : 'Aucun logo')) ?></figcaption>
              </figure>
              <div class="form-stack">
                <label for="site-<?= $key ?>"><?= $key === 'app_icon' ? 'Nouvelle icône (PNG ou JPEG carré, 2 Mo maximum)' : 'Nouveau logo (PNG, JPEG, GIF ou WebP, 2 Mo maximum)' ?></label>
                <input id="site-<?= $key ?>" name="<?= $key ?>" type="file" accept="image/png,image/jpeg,image/gif,image/webp">
                <?php if ($logoUrl !== null): ?>
                <label class="active-toggle"><input type="checkbox" name="<?= $key ?>_reset" value="1"> <?= $key === 'logo' ? 'Revenir au logo d’origine' : ($key === 'app_icon' ? 'Revenir à l’icône faite à partir du logo' : 'Retirer ce logo') ?></label>
                <?php endif; ?>
              </div>
            </div>
          </section>
          <?php endforeach; ?>
            </div>
          </details>

          <div class="form-actions">
            <button class="primary-button" type="submit">Enregistrer</button>
            <a class="secondary-button" href="settings.php">Annuler</a>
          </div>
        </form>
      </section>
<?php app_footer(); ?>
