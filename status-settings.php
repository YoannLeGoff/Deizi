<?php
declare(strict_types=1);

// "Statuts": label and colour of the status buttons (badges) of the dates, reserved for
// administrators (labels are content of the association, not translated), and
// list of the logistics statuses (code, label, status of the date it sets, default one) with
// their workflow.
// The text colour, the accents of the agenda cards, legend dots and attendance columns and the
// planning cells are derived from it (app_status_palette()).
require __DIR__ . '/lib/bootstrap.php';
$admin = app_require_roles(['admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    app_verify_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'save_logistics' || $action === 'reset_logistics') {
        $list = null;
        if ($action === 'save_logistics') {
            // Rows in the order of their position; empty new rows are ignored, ticked rows deleted.
            $rows = [];
            foreach (is_array($_POST['logistics'] ?? null) ? array_values($_POST['logistics']) : [] as $index => $row) {
                if (!is_array($row) || ($row['delete'] ?? '') === '1') {
                    continue;
                }
                $key = is_string($row['key'] ?? null) ? $row['key'] : '';
                $code = app_clean_line($row['code'] ?? '', 6);
                $label = app_clean_line($row['label'] ?? '', 60);
                if ($key === '' && $code === '' && $label === '') {
                    continue;
                }
                $position = is_string($row['position'] ?? null) && is_numeric($row['position']) ? (float) $row['position'] : 999.0;
                $rows[] = [$position, $index, [
                    'key' => $key !== '' ? $key : 'custom-' . bin2hex(random_bytes(4)),
                    'code' => $code,
                    'label' => $label,
                    'status' => is_string($row['status'] ?? null) ? $row['status'] : '',
                    'default' => ($row['default'] ?? '') === '1',
                ]];
            }
            usort($rows, static fn (array $first, array $second): int => [$first[0], $first[1]] <=> [$second[0], $second[1]]);
            $list = array_column($rows, 2);
            $codes = array_map('strtolower', array_column($list, 'code'));
            $defaults = array_count_values(array_column(array_filter($list, static fn (array $item): bool => $item['default']), 'status'));
            $error = match (true) {
                count($list) > LOGISTICS_STATUS_MAX => __('liste_compte_plus_statuts_logistiques_phrase', ['n' => LOGISTICS_STATUS_MAX]),
                array_filter($list, static fn (array $item): bool => preg_match('/^[A-Za-z0-9]{1,6}$/', $item['code']) !== 1) !== [] => __('chaque_statut_logistique_code_1_6'),
                array_filter($list, static fn (array $item): bool => $item['label'] === '') !== [] => __('chaque_statut_logistique_libelle_phrase'),
                array_filter($list, static fn (array $item): bool => !isset(EVENT_STATUSES[$item['status']])) !== [] => __('choisissez_statut_date_chaque_statut_logistique'),
                count($codes) !== count(array_unique($codes)) => __('deux_statuts_logistiques_peuvent_avoir_meme'),
                max([0, ...array_values($defaults)]) > 1 => __('seul_statut_logistique_defaut_chaque_statut'),
                default => null,
            };
            $missing = array_diff_key(EVENT_STATUSES, array_flip(array_column($list, 'status')));
            if ($error === null && $missing !== []) {
                $error = __('chaque_statut_date_doit_avoir_moins', ['statuses' => implode(', ', array_intersect_key(app_statuses(), $missing))]);
            }
            if ($error !== null || app_logistics_parse_statuses($list) === null) {
                app_flash($error ?? __('liste_statuts_logistiques_invalide_phrase'), 'error');
                app_redirect('status-settings.php#logistics-statuses-title');
            }
        }
        update_json_file(app_data_path('settings.json'), static function (array $settings) use ($list): array {
            if ($list === null) {
                unset($settings['logistics_statuses']);
            } else {
                $settings['logistics_statuses'] = $list;
            }
            return $settings;
        });
        app_flash($list === null ? __('liste_dorigine_statuts_logistiques_ete_retablie') : __('statuts_logistiques_ont_ete_enregistres_phrase'));
        app_redirect('status-settings.php#logistics-statuses-title');
    }
    $posted = is_array($_POST['colors'] ?? null) ? $_POST['colors'] : [];
    if ($action === 'save') {
        // Labels: an empty field gives back the original label.
        $labels = [];
        foreach (EVENT_STATUSES as $status => $defaultLabel) {
            $label = app_clean_line($_POST['labels'][$status] ?? '', STATUS_LABEL_MAX_LENGTH);
            if ($label !== '' && $label !== $defaultLabel) {
                $labels[$status] = $label;
            }
        }
        $effective = array_map(static fn (string $label): string => app_lowercase($label), $labels + EVENT_STATUSES);
        if (count($effective) !== count(array_unique($effective))) {
            app_flash(__('deux_statuts_peuvent_avoir_meme_libelle'), 'error');
            app_redirect('status-settings.php');
        }
        update_json_file(app_data_path('settings.json'), static function (array $settings) use ($labels): array {
            if ($labels === []) {
                unset($settings['status_labels']);
            } else {
                $settings['status_labels'] = $labels;
            }
            return $settings;
        });
        $colors = [];
        foreach (STATUS_DEFAULT_PALETTES as $status => ['bg' => $default]) {
            $color = app_normalize_hex_color($posted[$status] ?? null);
            if ($color === null) {
                app_flash(__('couleur_invalide_statut_status_phrase', ['status' => app_statuses()[$status]]), 'error');
                app_redirect('status-settings.php');
            }
            if ($color !== $default) {
                $colors[$status] = $color;
            }
        }
        $message = __('libelles_couleurs_statuts_ont_ete_enregistres');
    } elseif ($action === 'reset') {
        $colors = [];
        $message = __('couleurs_dorigine_statuts_ont_ete_retablies_phrase');
    } else {
        http_response_code(400);
        exit(__('action_inconnue_phrase'));
    }
    update_json_file(app_data_path('settings.json'), static function (array $settings) use ($colors): array {
        if ($colors === []) {
            unset($settings['status_colors']);
        } else {
            $settings['status_colors'] = $colors;
        }
        return $settings;
    });
    app_flash($message);
    app_redirect('status-settings.php');
}

$customColors = app_status_custom_colors();
// Logistics statuses as edited (or as installed), with the number of dates having each of them.
$logisticsStored = app_logistics_stored_statuses();
$logisticsDefaults = app_logistics_status_defaults();
$logisticsRows = [];
foreach (app_logistics_statuses() as $key => [$code, $label, $eventStatus]) {
    $logisticsRows[] = ['key' => $key, 'code' => $code, 'label' => $label, 'status' => $eventStatus, 'default' => $logisticsDefaults[$eventStatus] === $key];
}
$logisticsUsage = [];
$logisticsData = app_logistics_read();
foreach (read_json_file(app_data_path('events.json')) as $event) {
    if (is_array($event) && is_string($event['id'] ?? null)) {
        $key = app_event_logistics_status($event, $logisticsData);
        $logisticsUsage[$key] = ($logisticsUsage[$key] ?? 0) + 1;
    }
}

app_header('Statuts', $admin);
?>
      <section class="management-section">
        <div class="eyebrow-row">
          <p class="eyebrow"><span class="eyebrow-line"></span> <?= app_escape(__('configuration_site_majuscules')) ?></p>
          <?php app_page_links([['Configuration', 'settings.php']]); ?>
        </div>
        <div class="section-heading">
          <div>
            <h1><?= app_escape(__('statuts')) ?><span class="heading-dot">.</span></h1>
          </div>
        </div>
        <?php app_settings_nav('organisation', 'status-settings.php', $admin); ?>

        <p class="accounts-help"><?= app_escape(__('choisissez_libelle_couleur_bouton_chaque_statut')) ?></p>

        <details class="management-panel workflow-accordion">
          <summary><span class="detail-heading" id="workflow-title"><?= app_escape(__('flux_travail_statuts_logistiques')) ?></span> <span class="workflow-accordion-hint"><?= app_escape(__('afficher')) ?></span></summary>
          <p class="muted-copy"><?= app_escape(__('logisticiens_suivent_chaque_date_avec_statut')) ?></p>
          <?php app_render_logistics_workflow(); ?>
        </details>

        <section class="management-panel">
          <form method="post" action="status-settings.php" data-status-colors>
            <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
            <div class="log-scroll">
              <table class="status-colors-table">
                <thead>
                  <tr>
                    <th scope="col"><?= app_escape(__('statut')) ?></th>
                    <th scope="col"><?= app_escape(__('libelle')) ?></th>
                    <th scope="col"><?= app_escape(__('couleur_bouton')) ?></th>
                    <th scope="col"><?= app_escape(__('apercu')) ?></th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach (app_statuses() as $status => $label): ?>
                  <?php
                      $default = STATUS_DEFAULT_PALETTES[$status]['bg'];
                      $color = $customColors[$status] ?? $default;
                  ?>
                  <tr data-status-row data-default-palette="<?= app_escape(json_encode(STATUS_DEFAULT_PALETTES[$status], JSON_THROW_ON_ERROR)) ?>">
                    <th scope="row"><code><?= app_escape($status) ?></code></th>
                    <td><input class="status-label-input" name="labels[<?= $status ?>]" maxlength="<?= STATUS_LABEL_MAX_LENGTH ?>" value="<?= app_escape($label) ?>" placeholder="<?= app_escape(EVENT_STATUSES[$status]) ?>" aria-label="<?= app_escape(__('libelle_statut_status', ['status' => $status])) ?>" data-status-label></td>
                    <td>
                      <div class="status-color-field">
                        <input type="color" id="status-color-<?= $status ?>" name="colors[<?= $status ?>]" value="<?= $color ?>" data-default="<?= $default ?>" aria-label="<?= app_escape(__('couleur_bouton_label', ['label' => $label])) ?>">
                        <button class="secondary-button compact-button" type="button" data-status-default<?= $color === $default ? ' hidden' : '' ?>><?= app_escape(__('couleur_dorigine')) ?></button>
                      </div>
                    </td>
                    <td>
                      <?php // Saved colours come from the --status-* variables; menu.js updates the preview while editing. ?>
                      <div class="status-color-preview" style="--preview-bg: var(--status-<?= $status ?>-bg); --preview-fg: var(--status-<?= $status ?>-fg); --preview-accent: var(--status-<?= $status ?>-accent); --preview-cell: var(--status-<?= $status ?>-cell)" data-status-preview>
                        <span class="status-badge status-preview-badge"><?= app_escape($label) ?></span>
                        <span class="status-preview-card"><?= app_escape(__('carte_lagenda')) ?></span>
                        <span class="status-preview-cell"><?= app_escape(__('planning')) ?></span>
                      </div>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <div class="form-actions">
              <button class="primary-button" type="submit" name="action" value="save"><?= app_escape(__('enregistrer_libelles_couleurs')) ?></button>
              <?php if ($customColors !== []): ?>
              <button class="secondary-button" type="submit" name="action" value="reset" onclick="return confirm(<?= app_escape(json_encode(__('retablir_couleurs_dorigine_tous_statuts'), JSON_UNESCAPED_UNICODE)) ?>);"><?= app_escape(__('retablir_toutes_couleurs_dorigine')) ?></button>
              <?php endif; ?>
            </div>
          </form>
        </section>

        <section class="management-panel" aria-labelledby="logistics-statuses-title">
          <h2 class="detail-heading" id="logistics-statuses-title"><?= app_escape(__('statuts_logistiques_correspondance')) ?></h2>
          <p class="muted-copy"><?= app_escape(__('logisticiens_choisissent_chaque_date_ces_statuts')) ?></p>
          <form method="post" action="status-settings.php">
            <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
            <div class="log-scroll">
              <table class="log-table logistics-statuses-table">
                <thead>
                  <tr>
                    <th scope="col"><?= app_escape(__('position')) ?></th>
                    <th scope="col"><?= app_escape(__('code')) ?></th>
                    <th scope="col"><?= app_escape(__('statut_logistique')) ?></th>
                    <th scope="col"><?= app_escape(__('statut_date_artistes')) ?></th>
                    <th scope="col"><?= app_escape(__('defaut')) ?></th>
                    <th scope="col"><?= app_escape(__('dates')) ?></th>
                    <th scope="col"><?= app_escape(__('supprimer')) ?></th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ([...$logisticsRows, ...array_fill(0, 3, null)] as $index => $row): ?>
                  <tr<?= $row === null ? ' class="is-new-row"' : '' ?>>
                    <td>
                      <input type="hidden" name="logistics[<?= $index ?>][key]" value="<?= app_escape($row['key'] ?? '') ?>">
                      <input class="logistics-position" name="logistics[<?= $index ?>][position]" type="number" step="1" min="1" max="99" aria-label="<?= app_escape(__('position')) ?>" value="<?= $row !== null ? ($index + 1) * 10 : '' ?>" placeholder="<?= $row === null ? 'Pos.' : '' ?>">
                    </td>
                    <td><input class="logistics-code-input" name="logistics[<?= $index ?>][code]" maxlength="6" pattern="[A-Za-z0-9]{1,6}" aria-label="<?= app_escape(__('code')) ?>" value="<?= app_escape($row['code'] ?? '') ?>"></td>
                    <td><input name="logistics[<?= $index ?>][label]" maxlength="60" aria-label="<?= app_escape(__('statut_logistique')) ?>" value="<?= app_escape($row['label'] ?? '') ?>"<?= $row === null ? ' placeholder="Nouveau statut"' : '' ?>></td>
                    <td>
                      <select name="logistics[<?= $index ?>][status]" aria-label="<?= app_escape(__('statut_date')) ?>">
                        <?php if ($row === null): ?><option value="">—</option><?php endif; ?>
                        <?php foreach (app_statuses() as $eventStatus => $eventLabel): ?>
                        <option value="<?= $eventStatus ?>"<?= ($row['status'] ?? '') === $eventStatus ? ' selected' : '' ?>><?= app_escape($eventLabel) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </td>
                    <td><label class="active-toggle"><input type="checkbox" name="logistics[<?= $index ?>][default]" value="1"<?= ($row['default'] ?? false) ? ' checked' : '' ?>> <span class="sr-only"><?= app_escape(__('defaut')) ?></span></label></td>
                    <td><?= $row !== null ? ($logisticsUsage[$row['key']] ?? 0) : '' ?></td>
                    <td><?php if ($row !== null): ?><label class="active-toggle"><input type="checkbox" name="logistics[<?= $index ?>][delete]" value="1"> <span class="sr-only"><?= app_escape(__('supprimer')) ?></span></label><?php endif; ?></td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <p class="field-help"><?= app_escape(__('positions_vont_10_10_inserer_statut')) ?></p>
            <div class="form-actions">
              <button class="primary-button" type="submit" name="action" value="save_logistics"><?= app_escape(__('enregistrer_statuts_logistiques')) ?></button>
              <?php if ($logisticsStored !== null): ?>
              <button class="secondary-button" type="submit" name="action" value="reset_logistics" onclick="return confirm(<?= app_escape(json_encode(__('retablir_liste_dorigine_statuts_logistiques_statuts'), JSON_UNESCAPED_UNICODE)) ?>);"><?= app_escape(__('retablir_liste_dorigine')) ?></button>
              <?php endif; ?>
            </div>
          </form>
        </section>
      </section>
<?php app_footer(); ?>
