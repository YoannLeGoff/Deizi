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
                app_flash(__('chaque_formule_libelle_cochez_supprimer_retirer'), 'error');
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
        app_flash(__('deux_formules_peuvent_avoir_meme_libelle', ['n' => LOGISTICS_FORMULA_MAX]), 'error');
        app_redirect($formulasUrl);
    }
    update_json_file(app_data_path('settings.json'), static function (array $settings) use ($list): array {
        $settings['logistics_formulas'] = $list;
        return $settings;
    });
    app_flash(__('formules_ont_ete_enregistrees_phrase'));
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
            array_filter($list, static fn (array $item): bool => $item['label'] === '') !== [] => __('chaque_statut_libelle_phrase'),
            array_filter($list, static fn (array $item): bool => !isset(QUOTE_STATUS_TYPES[$item['type']])) !== [] => __('choisissez_comportement_chaque_statut_phrase'),
            max([0, ...array_values($defaults)]) > 1 => __('seul_statut_defaut_chaque_comportement_phrase'),
            $missing !== [] => __('chaque_comportement_doit_garder_moins_statut'),
            app_quote_parse_statuses($list) === null => __('liste_statuts_invalide_statuts_plus', ['n' => QUOTE_STATUS_MAX]),
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
    app_flash($list === null ? __('statuts_dorigine_devis_ont_ete_retablis_phrase') : __('statuts_devis_ont_ete_enregistres_phrase'));
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
        app_flash(__('nom_lassociation_libelle_total_sont_obligatoires'), 'error');
        app_redirect('logistics-settings.php');
    }
    if (in_array(null, $colors, true)) {
        app_flash(__('choisissez_couleurs_valides_phrase'), 'error');
        app_redirect('logistics-settings.php');
    }
    if ($prefix !== '' && preg_match('/^[A-Za-z0-9]+$/', $prefix) !== 1) {
        app_flash(__('prefixe_numerotation_peut_contenir_lettres_chiffres'), 'error');
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
    app_flash(__('parametres_devis_ont_ete_enregistres_phrase'));
    app_redirect('logistics-settings.php');
}

$issuer = app_quote_issuer();
$year = (new DateTimeImmutable('now', new DateTimeZone('Europe/Paris')))->format('Y');
$tab = in_array($_GET['onglet'] ?? '', ['flux', 'formules'], true) ? $_GET['onglet'] : 'mise-en-page';

app_header('Paramètres des devis', $user);
?>
      <section class="management-section">
        <div class="eyebrow-row">
          <p class="eyebrow"><span class="eyebrow-line"></span> <?= app_escape(__('logistique_majuscules')) ?></p>
          <?php app_page_links([['Logistique', 'logistics.php'], ['Configuration', 'settings.php']]); ?>
        </div>
        <div class="section-heading">
          <div>
            <h1><?= app_escape(__('parametres_devis')) ?><span class="heading-dot">.</span></h1>
          </div>
        </div>
        <?php app_settings_nav('documents', 'logistics-settings.php', $user); ?>

        <nav class="detail-tabs" aria-label="<?= app_escape(__('parametres_devis')) ?>">
          <a class="detail-tab" href="logistics-settings.php"<?= $tab === 'mise-en-page' ? ' aria-current="page"' : '' ?>><?= app_escape(__('mise_page')) ?></a>
          <a class="detail-tab" href="<?= app_escape($flowUrl) ?>"<?= $tab === 'flux' ? ' aria-current="page"' : '' ?>><?= app_escape(__('flux_travail')) ?></a>
          <a class="detail-tab" href="<?= app_escape($formulasUrl) ?>"<?= $tab === 'formules' ? ' aria-current="page"' : '' ?>><?= app_escape(__('formules')) ?> <span class="count-badge"><?= count(app_logistics_formulas()) ?></span></a>
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
          <h2 class="detail-heading" id="formulas-title"><?= app_escape(__('formules')) ?></h2>
          <p class="muted-copy"><?= app_escape(__('formule_decrit_ce_groupe_propose_date')) ?></p>
          <form method="post" action="logistics-settings.php">
            <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
            <div class="log-scroll">
              <table class="log-table logistics-statuses-table">
                <thead>
                  <tr>
                    <th scope="col"><?= app_escape(__('position')) ?></th>
                    <th scope="col"><?= app_escape(__('formule')) ?></th>
                    <th scope="col"><?= app_escape(__('dates')) ?></th>
                    <th scope="col"><?= app_escape(__('supprimer')) ?></th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ([...$formulaRows, ...array_fill(0, 3, null)] as $index => $row): ?>
                  <tr<?= $row === null ? ' class="is-new-row"' : '' ?>>
                    <td>
                      <input type="hidden" name="formulas[<?= $index ?>][key]" value="<?= app_escape($row['key'] ?? '') ?>">
                      <input class="logistics-position" name="formulas[<?= $index ?>][position]" type="number" step="1" min="1" max="999" aria-label="<?= app_escape(__('position')) ?>" value="<?= $row !== null ? ($index + 1) * 10 : '' ?>" placeholder="<?= $row === null ? 'Pos.' : '' ?>">
                    </td>
                    <td><input name="formulas[<?= $index ?>][label]" maxlength="60" aria-label="<?= app_escape(__('formule')) ?>" value="<?= app_escape($row['label'] ?? '') ?>"<?= $row === null ? ' placeholder="Nouvelle formule"' : '' ?>></td>
                    <td><?= $row !== null ? ($formulaUsage[$row['key']] ?? 0) : '' ?></td>
                    <td><?php if ($row !== null): ?><label class="active-toggle"><input type="checkbox" name="formulas[<?= $index ?>][delete]" value="1"> <span class="sr-only"><?= app_escape(__('supprimer')) ?></span></label><?php endif; ?></td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <p class="field-help"><?= app_escape(__('positions_10_10_position_intermediaire_25_insere_formule')) ?></p>
            <div class="form-actions"><button class="primary-button" type="submit" name="action" value="save_formulas"><?= app_escape(__('enregistrer_formules')) ?></button></div>
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
          <h2 class="detail-heading" id="quote-flow-title"><?= app_escape(__('flux_travail_devis_organisateurs')) ?></h2>
          <p class="muted-copy"><?= app_escape(__('devis_nait_brouillon_publier_lien_fait')) ?></p>
          <?php app_render_quote_workflow(); ?>
        </section>

        <section class="management-panel" aria-labelledby="quote-statuses-title">
          <h2 class="detail-heading" id="quote-statuses-title"><?= app_escape(__('statuts_devis')) ?></h2>
          <p class="muted-copy"><?= app_escape(__('renommez_reordonnez_ajoutez_supprimez_statuts')) ?> <strong><?= app_escape(__('comportement_minuscule')) ?></strong> <?= app_escape(__('chaque_statut_decide_ce_quil_permet')) ?></p>
          <form method="post" action="logistics-settings.php">
            <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
            <div class="log-scroll">
              <table class="log-table logistics-statuses-table">
                <thead>
                  <tr>
                    <th scope="col"><?= app_escape(__('position')) ?></th>
                    <th scope="col"><?= app_escape(__('statut')) ?></th>
                    <th scope="col"><?= app_escape(__('comportement')) ?></th>
                    <th scope="col"><?= app_escape(__('defaut')) ?></th>
                    <th scope="col"><?= app_escape(__('devis')) ?></th>
                    <th scope="col"><?= app_escape(__('supprimer')) ?></th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ([...$statusRows, ...array_fill(0, 2, null)] as $index => $row): ?>
                  <tr<?= $row === null ? ' class="is-new-row"' : '' ?>>
                    <td>
                      <input type="hidden" name="statuses[<?= $index ?>][key]" value="<?= app_escape($row['key'] ?? '') ?>">
                      <input class="logistics-position" name="statuses[<?= $index ?>][position]" type="number" step="1" min="1" max="99" aria-label="<?= app_escape(__('position')) ?>" value="<?= $row !== null ? ($index + 1) * 10 : '' ?>" placeholder="<?= $row === null ? 'Pos.' : '' ?>">
                    </td>
                    <td><input name="statuses[<?= $index ?>][label]" maxlength="40" aria-label="<?= app_escape(__('statut')) ?>" value="<?= app_escape($row['label'] ?? '') ?>"<?= $row === null ? ' placeholder="Nouveau statut"' : '' ?>></td>
                    <td>
                      <select name="statuses[<?= $index ?>][type]" aria-label="<?= app_escape(__('comportement')) ?>">
                        <?php if ($row === null): ?><option value="">—</option><?php endif; ?>
                        <?php foreach (QUOTE_STATUS_TYPES as $type => $typeLabel): ?>
                        <option value="<?= $type ?>"<?= ($row['type'] ?? '') === $type ? ' selected' : '' ?>><?= app_escape(app_label($typeLabel)) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </td>
                    <td><label class="active-toggle"><input type="checkbox" name="statuses[<?= $index ?>][default]" value="1"<?= ($row['default'] ?? false) ? ' checked' : '' ?>> <span class="sr-only"><?= app_escape(__('defaut')) ?></span></label></td>
                    <td><?= $row !== null ? ($usage[$row['key']] ?? 0) : '' ?></td>
                    <td><?php if ($row !== null): ?><label class="active-toggle"><input type="checkbox" name="statuses[<?= $index ?>][delete]" value="1"> <span class="sr-only"><?= app_escape(__('supprimer')) ?></span></label><?php endif; ?></td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <p class="field-help"><?= app_escape(__('positions_10_10_position_intermediaire_25')) ?></p>
            <div class="form-actions">
              <button class="primary-button" type="submit" name="action" value="save_statuses"><?= app_escape(__('enregistrer_statuts')) ?></button>
              <?php if (app_quote_stored_statuses() !== null): ?>
              <button class="secondary-button" type="submit" name="action" value="reset_statuses" onclick="return confirm(<?= app_escape(json_encode(__('retablir_statuts_dorigine_brouillon_publie_refuse'), JSON_UNESCAPED_UNICODE)) ?>);"><?= app_escape(__('retablir_statuts_dorigine')) ?></button>
              <?php endif; ?>
            </div>
          </form>
        </section>
        <?php else: ?>
        <form method="post" action="logistics-settings.php" enctype="multipart/form-data" class="form-stack">
          <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">

          <section class="management-panel" aria-labelledby="quote-logo-title">
            <h2 class="detail-heading" id="quote-logo-title"><?= app_escape(__('logo')) ?></h2>
            <p class="muted-copy"><?= app_escape(__('imprime_haut_droite_chaque_devis_80')) ?></p>
            <div class="qr-settings-layout">
              <figure class="qr-preview site-logo-preview">
                <?php if ($issuer['logo'] !== null): ?>
                <img src="quote-logo.php?v=<?= app_escape($issuer['logo']) ?>" alt="<?= app_escape(__('logo_actuel_devis')) ?>">
                <?php else: ?>
                <span class="site-logo-empty" aria-hidden="true">—</span>
                <?php endif; ?>
                <figcaption><?= app_escape($issuer['logo'] !== null ? __('logo_actuel') : __('aucun_logo')) ?></figcaption>
              </figure>
              <div class="form-stack">
                <label for="quote-logo"><?= app_escape(__('nouveau_logo_png_jpeg_gif_webp')) ?></label>
                <input id="quote-logo" name="logo" type="file" accept="image/png,image/jpeg,image/gif,image/webp">
                <?php if ($issuer['logo'] !== null): ?>
                <label class="active-toggle"><input type="checkbox" name="logo_reset" value="1"> <?= app_escape(__('retirer_ce_logo')) ?></label>
                <?php endif; ?>
              </div>
            </div>
          </section>

          <section class="management-panel" aria-labelledby="issuer-title">
            <h2 class="detail-heading" id="issuer-title"><?= app_escape(__('association')) ?></h2>
            <p class="muted-copy"><?= app_escape(__('imprimee_haut_gauche_chaque_devis_phrase')) ?></p>
            <div class="form-grid align-top">
              <div class="form-stack">
                <label for="issuer-name"><?= app_escape(__('nom')) ?></label>
                <input id="issuer-name" name="name" maxlength="120" required value="<?= app_escape($issuer['name']) ?>">
                <label for="issuer-address"><?= app_escape(__('adresse')) ?></label>
                <textarea id="issuer-address" name="address" rows="4" maxlength="400"><?= app_escape($issuer['address']) ?></textarea>
              </div>
              <div class="form-stack">
                <label for="issuer-email"><?= app_escape(__('e_mail_facultatif')) ?></label>
                <input id="issuer-email" name="email" type="email" maxlength="120" value="<?= app_escape($issuer['email']) ?>">
                <label for="issuer-phone"><?= app_escape(__('telephone_facultatif')) ?></label>
                <input id="issuer-phone" name="phone" maxlength="40" value="<?= app_escape($issuer['phone']) ?>">
              </div>
            </div>
          </section>

          <section class="management-panel" aria-labelledby="footer-title">
            <h2 class="detail-heading" id="footer-title"><?= app_escape(__('contenu_defaut_pied_page')) ?></h2>
            <div class="form-grid align-top">
              <div class="form-stack">
                <label for="issuer-conditions"><?= app_escape(__('remarques_defaut')) ?></label>
                <textarea id="issuer-conditions" name="conditions" rows="7" maxlength="2000"><?= app_escape($issuer['conditions']) ?></textarea>
                <p class="field-help"><?= app_escape(__('remarque_ligne_chacune_precedee_dune_puce')) ?></p>
              </div>
              <div class="form-stack">
                <label for="issuer-due"><?= app_escape(__('date_decheance_defaut')) ?></label>
                <input id="issuer-due" name="due" maxlength="80" value="<?= app_escape($issuer['due']) ?>">
                <label for="issuer-total-label"><?= app_escape(__('libelle_total')) ?></label>
                <input id="issuer-total-label" name="total_label" maxlength="30" required value="<?= app_escape($issuer['total_label']) ?>">
                <label for="issuer-footer"><?= app_escape(__('pied_page')) ?></label>
                <textarea id="issuer-footer" name="footer" rows="3" maxlength="400"><?= app_escape($issuer['footer']) ?></textarea>
                <p class="field-help"><?= app_escape(__('centre_bas_devis_statut_lassociation_siret')) ?></p>
              </div>
            </div>
          </section>

          <section class="management-panel" aria-labelledby="look-title">
            <h2 class="detail-heading" id="look-title"><?= app_escape(__('couleurs_numerotation')) ?></h2>
            <div class="form-grid logistics-quote-grid">
              <div class="form-stack">
                <label for="issuer-title-color"><?= app_escape(__('couleur_titre_devis')) ?></label>
                <input id="issuer-title-color" name="title_color" type="color" value="<?= app_escape($issuer['title_color']) ?>">
              </div>
              <div class="form-stack">
                <label for="issuer-accent-color"><?= app_escape(__('couleur_date_denvoi')) ?></label>
                <input id="issuer-accent-color" name="accent_color" type="color" value="<?= app_escape($issuer['accent_color']) ?>">
              </div>
              <div class="form-stack">
                <label for="issuer-prefix"><?= app_escape(__('prefixe_numerotation')) ?></label>
                <input id="issuer-prefix" name="prefix" maxlength="10" pattern="[A-Za-z0-9]*" value="<?= app_escape($issuer['prefix']) ?>">
                <p class="field-help"><?= app_escape(__('numeros_annee_example_002', ['example' => ($issuer['prefix'] !== '' ? $issuer['prefix'] . '-' : '') . $year . '-001'])) ?></p>
              </div>
            </div>
          </section>

          <div class="form-actions">
            <button class="primary-button" type="submit"><?= app_escape(__('enregistrer')) ?></button>
            <a class="secondary-button" href="logistics.php"><?= app_escape(__('annuler')) ?></a>
          </div>
        </form>
        <?php endif; ?>
      </section>
<?php app_footer(); ?>
