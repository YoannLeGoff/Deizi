<?php
declare(strict_types=1);

// "Paramètres des devis" (Logistique): logo, address and footer printed on the generated quotes,
// default remarks and due date, colours and numbering (data/settings.json, key "quote_issuer"),
// in the "Mise en page" tab; statuses of the quotes to organizers and their workflow in the
// "Flux de travail" tab (?onglet=flux, key "quote_statuses"); formulas of the dates in the
// "Formules" tab (?onglet=formules, key "logistics_formulas").
require __DIR__ . '/lib/bootstrap.php';
$user = app_require_roles(LOGISTICS_ROLES);

$flowUrl = 'logistics-settings.php?onglet=flux';
$formulasUrl = 'logistics-settings.php?onglet=formules';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_formulas') {
    app_verify_csrf();
    // Rows in the order of their position; empty new rows are ignored, ticked rows deleted.
    $rows = [];
    foreach (is_array($_POST['formulas'] ?? null) ? array_values($_POST['formulas']) : [] as $index => $row) {
        if (!is_array($row) || ($row['delete'] ?? '') === '1') {
            continue;
        }
        $key = is_string($row['key'] ?? null) && preg_match('/^[a-z0-9_-]{1,40}$/', $row['key']) === 1 ? $row['key'] : '';
        $label = app_clean_line($row['label'] ?? '', 60);
        if ($label === '') {
            if ($key !== '') {
                app_flash('Chaque formule a un libellé (cochez «' . "\u{00A0}" . 'Supprimer' . "\u{00A0}" . '» pour en retirer une).', 'error');
                app_redirect($formulasUrl);
            }
            continue;
        }
        $position = is_string($row['position'] ?? null) && is_numeric($row['position']) ? (float) $row['position'] : 999.0;
        $rows[] = [$position, $index, ['key' => $key !== '' ? $key : 'formula-' . bin2hex(random_bytes(4)), 'label' => $label]];
    }
    usort($rows, static fn (array $first, array $second): int => [$first[0], $first[1]] <=> [$second[0], $second[1]]);
    $list = array_column($rows, 2);
    $labels = array_map(static fn (string $label): string => function_exists('mb_strtolower') ? mb_strtolower($label) : strtolower($label), array_column($list, 'label'));
    if (count($labels) !== count(array_unique($labels)) || app_logistics_parse_formulas($list) === null) {
        app_flash('Deux formules ne peuvent pas avoir le même libellé (' . LOGISTICS_FORMULA_MAX . ' formules au plus).', 'error');
        app_redirect($formulasUrl);
    }
    update_json_file(app_data_path('settings.json'), static function (array $settings) use ($list): array {
        $settings['logistics_formulas'] = $list;
        return $settings;
    });
    app_flash('Les formules ont été enregistrées.');
    app_redirect($formulasUrl);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['save_statuses', 'reset_statuses'], true)) {
    app_verify_csrf();
    $list = null;
    if ($_POST['action'] === 'save_statuses') {
        // Rows in the order of their position; empty new rows are ignored, ticked rows deleted.
        $rows = [];
        foreach (is_array($_POST['statuses'] ?? null) ? array_values($_POST['statuses']) : [] as $index => $row) {
            if (!is_array($row) || ($row['delete'] ?? '') === '1') {
                continue;
            }
            $key = is_string($row['key'] ?? null) ? $row['key'] : '';
            $label = app_clean_line($row['label'] ?? '', 40);
            if ($key === '' && $label === '') {
                continue;
            }
            $position = is_string($row['position'] ?? null) && is_numeric($row['position']) ? (float) $row['position'] : 999.0;
            $rows[] = [$position, $index, [
                'key' => $key !== '' ? $key : 'custom-' . bin2hex(random_bytes(4)),
                'label' => $label,
                'type' => is_string($row['type'] ?? null) ? $row['type'] : '',
                'default' => ($row['default'] ?? '') === '1',
            ]];
        }
        usort($rows, static fn (array $first, array $second): int => [$first[0], $first[1]] <=> [$second[0], $second[1]]);
        $list = array_column($rows, 2);
        $defaults = array_count_values(array_column(array_filter($list, static fn (array $item): bool => $item['default']), 'type'));
        $missing = array_diff_key(QUOTE_STATUS_TYPES, array_flip(array_column($list, 'type')));
        $error = match (true) {
            array_filter($list, static fn (array $item): bool => $item['label'] === '') !== [] => 'Chaque statut a un libellé.',
            array_filter($list, static fn (array $item): bool => !isset(QUOTE_STATUS_TYPES[$item['type']])) !== [] => 'Choisissez le comportement de chaque statut.',
            max([0, ...array_values($defaults)]) > 1 => 'Un seul statut par défaut pour chaque comportement.',
            $missing !== [] => 'Chaque comportement doit garder au moins un statut (Brouillon, Publié, Refusé et Validé).',
            app_quote_parse_statuses($list) === null => 'La liste des statuts est invalide (' . QUOTE_STATUS_MAX . ' statuts au plus).',
            default => null,
        };
        if ($error !== null) {
            app_flash($error, 'error');
            app_redirect($flowUrl);
        }
    }
    update_json_file(app_data_path('settings.json'), static function (array $settings) use ($list): array {
        if ($list === null) {
            unset($settings['quote_statuses']);
        } else {
            $settings['quote_statuses'] = $list;
        }
        return $settings;
    });
    app_flash($list === null ? 'Les statuts d’origine des devis ont été rétablis.' : 'Les statuts des devis ont été enregistrés.');
    app_redirect($flowUrl);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    app_verify_csrf();
    $prefix = app_clean_line($_POST['prefix'] ?? '', 10);
    $colors = [];
    foreach (['title_color', 'accent_color'] as $key) {
        $value = strtolower(is_string($_POST[$key] ?? null) ? $_POST[$key] : '');
        $colors[$key] = preg_match('/^#[0-9a-f]{6}$/', $value) === 1 ? $value : null;
    }
    $issuer = [
        'name' => app_clean_line($_POST['name'] ?? '', 120),
        'address' => app_clean_text($_POST['address'] ?? '', 400),
        'email' => app_clean_line($_POST['email'] ?? '', 120),
        'phone' => app_clean_line($_POST['phone'] ?? '', 40),
        'conditions' => app_clean_text($_POST['conditions'] ?? '', 2000),
        'due' => app_clean_line($_POST['due'] ?? '', 80),
        'footer' => app_clean_text($_POST['footer'] ?? '', 400),
        'total_label' => app_clean_line($_POST['total_label'] ?? '', 30),
        'prefix' => $prefix,
    ] + $colors;
    if ($issuer['name'] === '' || $issuer['total_label'] === '') {
        app_flash('Le nom de l’association et le libellé du total sont obligatoires.', 'error');
        app_redirect('logistics-settings.php');
    }
    if (in_array(null, $colors, true)) {
        app_flash('Choisissez des couleurs valides.', 'error');
        app_redirect('logistics-settings.php');
    }
    if ($prefix !== '' && preg_match('/^[A-Za-z0-9]+$/', $prefix) !== 1) {
        app_flash('Le préfixe de numérotation ne peut contenir que des lettres et des chiffres.', 'error');
        app_redirect('logistics-settings.php');
    }
    try {
        $uploadedLogo = app_store_uploaded_image($_FILES['logo'] ?? null, 'logo');
    } catch (InvalidArgumentException $exception) {
        app_flash($exception->getMessage(), 'error');
        app_redirect('logistics-settings.php');
    }
    $removeLogo = ($_POST['logo_reset'] ?? '') === '1';
    $obsoleteLogo = null;
    update_json_file(app_data_path('settings.json'), static function (array $settings) use ($issuer, $uploadedLogo, $removeLogo, &$obsoleteLogo): array {
        $previous = is_array($settings['quote_issuer'] ?? null) && is_string($settings['quote_issuer']['logo'] ?? null) ? $settings['quote_issuer']['logo'] : null;
        $issuer['logo'] = $uploadedLogo ?? ($removeLogo ? null : $previous);
        if ($previous !== null && $previous !== $issuer['logo']) {
            $obsoleteLogo = $previous;
        }
        $settings['quote_issuer'] = $issuer;
        return $settings;
    });
    // A logo still shown by a published quote is kept until that link is withdrawn.
    if ($obsoleteLogo !== null && !in_array($obsoleteLogo, app_published_quote_logos(), true)) {
        app_delete_upload($obsoleteLogo);
    }
    app_flash('Les paramètres des devis ont été enregistrés.');
    app_redirect('logistics-settings.php');
}

$issuer = app_quote_issuer();
$year = (new DateTimeImmutable('now', new DateTimeZone('Europe/Paris')))->format('Y');
$tab = in_array($_GET['onglet'] ?? '', ['flux', 'formules'], true) ? $_GET['onglet'] : 'mise-en-page';

app_header('Paramètres des devis', $user);
?>
      <section class="management-section">
        <div class="eyebrow-row">
          <p class="eyebrow"><span class="eyebrow-line"></span> LOGISTIQUE</p>
          <?php app_page_links([['Logistique', 'logistics.php'], ['Configuration', 'settings.php']]); ?>
        </div>
        <div class="section-heading">
          <div>
            <h1>Paramètres des devis<span class="heading-dot">.</span></h1>
          </div>
        </div>
        <?php app_settings_nav('documents', 'logistics-settings.php', $user); ?>

        <nav class="detail-tabs" aria-label="Paramètres des devis">
          <a class="detail-tab" href="logistics-settings.php"<?= $tab === 'mise-en-page' ? ' aria-current="page"' : '' ?>>Mise en page</a>
          <a class="detail-tab" href="<?= app_escape($flowUrl) ?>"<?= $tab === 'flux' ? ' aria-current="page"' : '' ?>>Flux de travail</a>
          <a class="detail-tab" href="<?= app_escape($formulasUrl) ?>"<?= $tab === 'formules' ? ' aria-current="page"' : '' ?>>Formules <span class="count-badge"><?= count(app_logistics_formulas()) ?></span></a>
        </nav>

        <?php if ($tab === 'formules'): ?>
        <?php
            // Number of dates of each formula.
            $formulaUsage = [];
            $logisticsData = app_logistics_read();
            foreach (array_keys($logisticsData) as $eventId) {
                $formula = app_event_logistics($logisticsData, (string) $eventId)['formula'];
                if ($formula !== null) {
                    $formulaUsage[$formula] = ($formulaUsage[$formula] ?? 0) + 1;
                }
            }
            $formulaRows = [];
            foreach (app_logistics_formulas() as $key => $label) {
                $formulaRows[] = ['key' => $key, 'label' => $label];
            }
        ?>
        <section class="management-panel detail-panel has-tabs" aria-labelledby="formulas-title">
          <h2 class="detail-heading" id="formulas-title">Formules</h2>
          <p class="muted-copy">La formule décrit ce que le groupe propose pour une date (par exemple « Bagad seul », « Bagad et Cercle », « Cercle seul »). Elle se choisit dans l’onglet Cachet de chaque date de la Logistique, et le Rapport additionne les cachets par formule. Supprimer une formule la retire des dates qui l’utilisaient.</p>
          <form method="post" action="logistics-settings.php">
            <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
            <div class="log-scroll">
              <table class="log-table logistics-statuses-table">
                <thead>
                  <tr>
                    <th scope="col">Position</th>
                    <th scope="col">Formule</th>
                    <th scope="col">Dates</th>
                    <th scope="col">Supprimer</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ([...$formulaRows, ...array_fill(0, 3, null)] as $index => $row): ?>
                  <tr<?= $row === null ? ' class="is-new-row"' : '' ?>>
                    <td>
                      <input type="hidden" name="formulas[<?= $index ?>][key]" value="<?= app_escape($row['key'] ?? '') ?>">
                      <input class="logistics-position" name="formulas[<?= $index ?>][position]" type="number" step="1" min="1" max="999" aria-label="Position" value="<?= $row !== null ? ($index + 1) * 10 : '' ?>" placeholder="<?= $row === null ? 'Pos.' : '' ?>">
                    </td>
                    <td><input name="formulas[<?= $index ?>][label]" maxlength="60" aria-label="Formule" value="<?= app_escape($row['label'] ?? '') ?>"<?= $row === null ? ' placeholder="Nouvelle formule"' : '' ?>></td>
                    <td><?= $row !== null ? ($formulaUsage[$row['key']] ?? 0) : '' ?></td>
                    <td><?php if ($row !== null): ?><label class="active-toggle"><input type="checkbox" name="formulas[<?= $index ?>][delete]" value="1"> <span class="sr-only">Supprimer</span></label><?php endif; ?></td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <p class="field-help">Positions de 10 en 10 : une position intermédiaire (25) insère une formule entre deux autres. Les lignes vides sont ignorées ; enregistrez pour obtenir de nouvelles lignes.</p>
            <div class="form-actions"><button class="primary-button" type="submit" name="action" value="save_formulas">Enregistrer les formules</button></div>
          </form>
        </section>
        <?php elseif ($tab === 'flux'): ?>
        <?php
            // Statuses as edited (or as installed), with the number of quotes to organizers of each.
            $statusRows = [];
            foreach (app_quote_statuses() as $key => [$label, $type]) {
                $statusRows[] = ['key' => $key, 'label' => $label, 'type' => $type, 'default' => app_quote_status_default($type) === $key];
            }
            $usage = [];
            $logisticsData = app_logistics_read();
            foreach (array_keys($logisticsData) as $eventId) {
                foreach (app_logistics_quotes(app_event_logistics($logisticsData, (string) $eventId), 'sent') as $quote) {
                    $usage[$quote['status']] = ($usage[$quote['status']] ?? 0) + 1;
                }
            }
        ?>
        <section class="management-panel detail-panel has-tabs" aria-labelledby="quote-flow-title">
          <h2 class="detail-heading" id="quote-flow-title">Flux de travail des devis organisateurs</h2>
          <p class="muted-copy">Un devis naît en brouillon ; « Publier le lien » le fait passer à un statut publié (lien public, devis figé) et « Retirer le lien » le ramène en brouillon. Le panneau Suivi du devis enregistre ensuite la réponse de l’organisateur : refusé ou validé (compté dans les totaux acceptés).</p>
          <?php app_render_quote_workflow(); ?>
        </section>

        <section class="management-panel" aria-labelledby="quote-statuses-title">
          <h2 class="detail-heading" id="quote-statuses-title">Statuts des devis</h2>
          <p class="muted-copy">Renommez, réordonnez, ajoutez ou supprimez des statuts. Le <strong>comportement</strong> de chaque statut décide de ce qu’il permet ; chaque comportement garde au moins un statut. Le statut « par défaut » d’un comportement est celui donné automatiquement (création, publication, retrait du lien) ; un devis dont le statut est supprimé reprend celui-ci.</p>
          <form method="post" action="logistics-settings.php">
            <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
            <div class="log-scroll">
              <table class="log-table logistics-statuses-table">
                <thead>
                  <tr>
                    <th scope="col">Position</th>
                    <th scope="col">Statut</th>
                    <th scope="col">Comportement</th>
                    <th scope="col">Par défaut</th>
                    <th scope="col">Devis</th>
                    <th scope="col">Supprimer</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ([...$statusRows, ...array_fill(0, 2, null)] as $index => $row): ?>
                  <tr<?= $row === null ? ' class="is-new-row"' : '' ?>>
                    <td>
                      <input type="hidden" name="statuses[<?= $index ?>][key]" value="<?= app_escape($row['key'] ?? '') ?>">
                      <input class="logistics-position" name="statuses[<?= $index ?>][position]" type="number" step="1" min="1" max="99" aria-label="Position" value="<?= $row !== null ? ($index + 1) * 10 : '' ?>" placeholder="<?= $row === null ? 'Pos.' : '' ?>">
                    </td>
                    <td><input name="statuses[<?= $index ?>][label]" maxlength="40" aria-label="Statut" value="<?= app_escape($row['label'] ?? '') ?>"<?= $row === null ? ' placeholder="Nouveau statut"' : '' ?>></td>
                    <td>
                      <select name="statuses[<?= $index ?>][type]" aria-label="Comportement">
                        <?php if ($row === null): ?><option value="">—</option><?php endif; ?>
                        <?php foreach (QUOTE_STATUS_TYPES as $type => $typeLabel): ?>
                        <option value="<?= $type ?>"<?= ($row['type'] ?? '') === $type ? ' selected' : '' ?>><?= app_escape($typeLabel) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </td>
                    <td><label class="active-toggle"><input type="checkbox" name="statuses[<?= $index ?>][default]" value="1"<?= ($row['default'] ?? false) ? ' checked' : '' ?>> <span class="sr-only">Par défaut</span></label></td>
                    <td><?= $row !== null ? ($usage[$row['key']] ?? 0) : '' ?></td>
                    <td><?php if ($row !== null): ?><label class="active-toggle"><input type="checkbox" name="statuses[<?= $index ?>][delete]" value="1"> <span class="sr-only">Supprimer</span></label><?php endif; ?></td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <p class="field-help">Positions de 10 en 10 : une position intermédiaire (25) insère un statut entre deux autres. Les lignes vides sont ignorées.</p>
            <div class="form-actions">
              <button class="primary-button" type="submit" name="action" value="save_statuses">Enregistrer les statuts</button>
              <?php if (app_quote_stored_statuses() !== null): ?>
              <button class="secondary-button" type="submit" name="action" value="reset_statuses" onclick="return confirm('Rétablir les statuts d’origine (Brouillon, Publié, Refusé, Validé) ?');">Rétablir les statuts d’origine</button>
              <?php endif; ?>
            </div>
          </form>
        </section>
        <?php else: ?>
        <form method="post" action="logistics-settings.php" enctype="multipart/form-data" class="form-stack">
          <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">

          <section class="management-panel" aria-labelledby="quote-logo-title">
            <h2 class="detail-heading" id="quote-logo-title">Logo</h2>
            <p class="muted-copy">Imprimé en haut à droite de chaque devis (80 px de haut au plus). Une image au fond transparent rend le mieux.</p>
            <div class="qr-settings-layout">
              <figure class="qr-preview site-logo-preview">
                <?php if ($issuer['logo'] !== null): ?>
                <img src="quote-logo.php?v=<?= app_escape($issuer['logo']) ?>" alt="Logo actuel des devis">
                <?php else: ?>
                <span class="site-logo-empty" aria-hidden="true">—</span>
                <?php endif; ?>
                <figcaption><?= $issuer['logo'] !== null ? 'Logo actuel' : 'Aucun logo' ?></figcaption>
              </figure>
              <div class="form-stack">
                <label for="quote-logo">Nouveau logo (PNG, JPEG, GIF ou WebP, 2 Mo maximum)</label>
                <input id="quote-logo" name="logo" type="file" accept="image/png,image/jpeg,image/gif,image/webp">
                <?php if ($issuer['logo'] !== null): ?>
                <label class="active-toggle"><input type="checkbox" name="logo_reset" value="1"> Retirer ce logo</label>
                <?php endif; ?>
              </div>
            </div>
          </section>

          <section class="management-panel" aria-labelledby="issuer-title">
            <h2 class="detail-heading" id="issuer-title">Association</h2>
            <p class="muted-copy">Imprimée en haut à gauche de chaque devis.</p>
            <div class="form-grid align-top">
              <div class="form-stack">
                <label for="issuer-name">Nom</label>
                <input id="issuer-name" name="name" maxlength="120" required value="<?= app_escape($issuer['name']) ?>">
                <label for="issuer-address">Adresse</label>
                <textarea id="issuer-address" name="address" rows="4" maxlength="400"><?= app_escape($issuer['address']) ?></textarea>
              </div>
              <div class="form-stack">
                <label for="issuer-email">E-mail (facultatif)</label>
                <input id="issuer-email" name="email" type="email" maxlength="120" value="<?= app_escape($issuer['email']) ?>">
                <label for="issuer-phone">Téléphone (facultatif)</label>
                <input id="issuer-phone" name="phone" maxlength="40" value="<?= app_escape($issuer['phone']) ?>">
              </div>
            </div>
          </section>

          <section class="management-panel" aria-labelledby="footer-title">
            <h2 class="detail-heading" id="footer-title">Contenu par défaut et pied de page</h2>
            <div class="form-grid align-top">
              <div class="form-stack">
                <label for="issuer-conditions">Remarques par défaut</label>
                <textarea id="issuer-conditions" name="conditions" rows="7" maxlength="2000"><?= app_escape($issuer['conditions']) ?></textarea>
                <p class="field-help">Une remarque par ligne ; chacune est précédée d’une puce sur le devis. Modifiables sur chaque devis.</p>
              </div>
              <div class="form-stack">
                <label for="issuer-due">Date d’échéance par défaut</label>
                <input id="issuer-due" name="due" maxlength="80" value="<?= app_escape($issuer['due']) ?>">
                <label for="issuer-total-label">Libellé du total</label>
                <input id="issuer-total-label" name="total_label" maxlength="30" required value="<?= app_escape($issuer['total_label']) ?>">
                <label for="issuer-footer">Pied de page</label>
                <textarea id="issuer-footer" name="footer" rows="3" maxlength="400"><?= app_escape($issuer['footer']) ?></textarea>
                <p class="field-help">Centré en bas du devis : statut de l’association, SIRET, mention de TVA…</p>
              </div>
            </div>
          </section>

          <section class="management-panel" aria-labelledby="look-title">
            <h2 class="detail-heading" id="look-title">Couleurs et numérotation</h2>
            <div class="form-grid logistics-quote-grid">
              <div class="form-stack">
                <label for="issuer-title-color">Couleur du titre « Devis »</label>
                <input id="issuer-title-color" name="title_color" type="color" value="<?= app_escape($issuer['title_color']) ?>">
              </div>
              <div class="form-stack">
                <label for="issuer-accent-color">Couleur de la date d’envoi</label>
                <input id="issuer-accent-color" name="accent_color" type="color" value="<?= app_escape($issuer['accent_color']) ?>">
              </div>
              <div class="form-stack">
                <label for="issuer-prefix">Préfixe de numérotation</label>
                <input id="issuer-prefix" name="prefix" maxlength="10" pattern="[A-Za-z0-9]*" value="<?= app_escape($issuer['prefix']) ?>">
                <p class="field-help">Numéros par année : <?= app_escape(($issuer['prefix'] !== '' ? $issuer['prefix'] . '-' : '') . $year . '-001') ?>, -002…</p>
              </div>
            </div>
          </section>

          <div class="form-actions">
            <button class="primary-button" type="submit">Enregistrer</button>
            <a class="secondary-button" href="logistics.php">Annuler</a>
          </div>
        </form>
        <?php endif; ?>
      </section>
<?php app_footer(); ?>
