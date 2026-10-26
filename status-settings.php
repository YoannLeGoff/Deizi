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
                count($list) > LOGISTICS_STATUS_MAX => 'La liste compte au plus ' . LOGISTICS_STATUS_MAX . ' statuts logistiques.',
                array_filter($list, static fn (array $item): bool => preg_match('/^[A-Za-z0-9]{1,6}$/', $item['code']) !== 1) !== [] => 'Chaque statut logistique a un code de 1 à 6 lettres ou chiffres.',
                array_filter($list, static fn (array $item): bool => $item['label'] === '') !== [] => 'Chaque statut logistique a un libellé.',
                array_filter($list, static fn (array $item): bool => !isset(EVENT_STATUSES[$item['status']])) !== [] => 'Choisissez le statut de la date de chaque statut logistique.',
                count($codes) !== count(array_unique($codes)) => 'Deux statuts logistiques ne peuvent pas avoir le même code.',
                max([0, ...array_values($defaults)]) > 1 => 'Un seul statut logistique par défaut pour chaque statut de la date.',
                default => null,
            };
            $missing = array_diff_key(EVENT_STATUSES, array_flip(array_column($list, 'status')));
            if ($error === null && $missing !== []) {
                $error = 'Chaque statut de la date doit avoir au moins un statut logistique (manquant' . "\u{00A0}" . ': ' . implode(', ', array_intersect_key(app_statuses(), $missing)) . ').';
            }
            if ($error !== null || app_logistics_parse_statuses($list) === null) {
                app_flash($error ?? 'La liste des statuts logistiques est invalide.', 'error');
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
        app_flash($list === null ? 'La liste d’origine des statuts logistiques a été rétablie.' : 'Les statuts logistiques ont été enregistrés.');
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
            app_flash(__('Deux statuts ne peuvent pas avoir le même libellé.'), 'error');
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
                app_flash('Couleur invalide pour le statut « ' . app_statuses()[$status] . ' ».', 'error');
                app_redirect('status-settings.php');
            }
            if ($color !== $default) {
                $colors[$status] = $color;
            }
        }
        $message = 'Les libellés et les couleurs des statuts ont été enregistrés.';
    } elseif ($action === 'reset') {
        $colors = [];
        $message = 'Les couleurs d’origine des statuts ont été rétablies.';
    } else {
        http_response_code(400);
        exit('Action inconnue.');
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
          <p class="eyebrow"><span class="eyebrow-line"></span> CONFIGURATION DU SITE</p>
          <?php app_page_links([['Configuration', 'settings.php']]); ?>
        </div>
        <div class="section-heading">
          <div>
            <h1>Statuts<span class="heading-dot">.</span></h1>
          </div>
        </div>
        <?php app_settings_nav('organisation', 'status-settings.php', $admin); ?>

        <p class="accounts-help">Choisissez le libellé et la couleur du bouton de chaque statut, affiché sur l’agenda, le détail des dates et les vues avancées. Les libellés s’affichent tels quels dans toutes les langues ; un libellé vide reprend celui d’origine. La couleur du texte, le repère des cartes de l’agenda, les pastilles de légende et les cases du planning annuel s’en déduisent automatiquement.</p>

        <details class="management-panel workflow-accordion">
          <summary><span class="detail-heading" id="workflow-title">Flux de travail des statuts logistiques</span> <span class="workflow-accordion-hint">Afficher</span></summary>
          <p class="muted-copy">Les logisticiens suivent chaque date avec un statut logistique (page Logistique), qui fixe le statut de la date vu par les artistes, managers et organisateurs. Le parcours habituel va de gauche à droite et de haut en bas ; les trois statuts du bas sortent du parcours.</p>
          <?php app_render_logistics_workflow(); ?>
        </details>

        <section class="management-panel">
          <form method="post" action="status-settings.php" data-status-colors>
            <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
            <div class="log-scroll">
              <table class="status-colors-table">
                <thead>
                  <tr>
                    <th scope="col">Statut</th>
                    <th scope="col">Libellé</th>
                    <th scope="col">Couleur du bouton</th>
                    <th scope="col">Aperçu</th>
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
                    <td><input class="status-label-input" name="labels[<?= $status ?>]" maxlength="<?= STATUS_LABEL_MAX_LENGTH ?>" value="<?= app_escape($label) ?>" placeholder="<?= app_escape(EVENT_STATUSES[$status]) ?>" aria-label="Libellé du statut <?= app_escape($status) ?>" data-status-label></td>
                    <td>
                      <div class="status-color-field">
                        <input type="color" id="status-color-<?= $status ?>" name="colors[<?= $status ?>]" value="<?= $color ?>" data-default="<?= $default ?>" aria-label="Couleur du bouton « <?= app_escape($label) ?> »">
                        <button class="secondary-button compact-button" type="button" data-status-default<?= $color === $default ? ' hidden' : '' ?>>Couleur d’origine</button>
                      </div>
                    </td>
                    <td>
                      <?php // Saved colours come from the --status-* variables; menu.js updates the preview while editing. ?>
                      <div class="status-color-preview" style="--preview-bg: var(--status-<?= $status ?>-bg); --preview-fg: var(--status-<?= $status ?>-fg); --preview-accent: var(--status-<?= $status ?>-accent); --preview-cell: var(--status-<?= $status ?>-cell)" data-status-preview>
                        <span class="status-badge status-preview-badge"><?= app_escape($label) ?></span>
                        <span class="status-preview-card">Carte de l’agenda</span>
                        <span class="status-preview-cell">Planning</span>
                      </div>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <div class="form-actions">
              <button class="primary-button" type="submit" name="action" value="save">Enregistrer les libellés et les couleurs</button>
              <?php if ($customColors !== []): ?>
              <button class="secondary-button" type="submit" name="action" value="reset" onclick="return confirm('Rétablir les couleurs d’origine de tous les statuts ?');">Rétablir toutes les couleurs d’origine</button>
              <?php endif; ?>
            </div>
          </form>
        </section>

        <section class="management-panel" aria-labelledby="logistics-statuses-title">
          <h2 class="detail-heading" id="logistics-statuses-title">Statuts logistiques et correspondance</h2>
          <p class="muted-copy">Les logisticiens choisissent pour chaque date un de ces statuts (page Logistique) ; la colonne « Statut de la date » indique le statut alors vu par les artistes, managers et organisateurs. Le statut « par défaut » est donné aux dates dont le statut est changé hors de la Logistique. L’ordre (position) est celui des listes et du flux de travail. Une date dont le statut logistique est supprimé reprend le statut par défaut.</p>
          <form method="post" action="status-settings.php">
            <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
            <div class="log-scroll">
              <table class="log-table logistics-statuses-table">
                <thead>
                  <tr>
                    <th scope="col">Position</th>
                    <th scope="col">Code</th>
                    <th scope="col">Statut logistique</th>
                    <th scope="col">Statut de la date (artistes)</th>
                    <th scope="col">Par défaut</th>
                    <th scope="col">Dates</th>
                    <th scope="col">Supprimer</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ([...$logisticsRows, ...array_fill(0, 3, null)] as $index => $row): ?>
                  <tr<?= $row === null ? ' class="is-new-row"' : '' ?>>
                    <td>
                      <input type="hidden" name="logistics[<?= $index ?>][key]" value="<?= app_escape($row['key'] ?? '') ?>">
                      <input class="logistics-position" name="logistics[<?= $index ?>][position]" type="number" step="1" min="1" max="99" aria-label="Position" value="<?= $row !== null ? ($index + 1) * 10 : '' ?>" placeholder="<?= $row === null ? 'Pos.' : '' ?>">
                    </td>
                    <td><input class="logistics-code-input" name="logistics[<?= $index ?>][code]" maxlength="6" pattern="[A-Za-z0-9]{1,6}" aria-label="Code" value="<?= app_escape($row['code'] ?? '') ?>"></td>
                    <td><input name="logistics[<?= $index ?>][label]" maxlength="60" aria-label="Statut logistique" value="<?= app_escape($row['label'] ?? '') ?>"<?= $row === null ? ' placeholder="Nouveau statut"' : '' ?>></td>
                    <td>
                      <select name="logistics[<?= $index ?>][status]" aria-label="Statut de la date">
                        <?php if ($row === null): ?><option value="">—</option><?php endif; ?>
                        <?php foreach (app_statuses() as $eventStatus => $eventLabel): ?>
                        <option value="<?= $eventStatus ?>"<?= ($row['status'] ?? '') === $eventStatus ? ' selected' : '' ?>><?= app_escape($eventLabel) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </td>
                    <td><label class="active-toggle"><input type="checkbox" name="logistics[<?= $index ?>][default]" value="1"<?= ($row['default'] ?? false) ? ' checked' : '' ?>> <span class="sr-only">Par défaut</span></label></td>
                    <td><?= $row !== null ? ($logisticsUsage[$row['key']] ?? 0) : '' ?></td>
                    <td><?php if ($row !== null): ?><label class="active-toggle"><input type="checkbox" name="logistics[<?= $index ?>][delete]" value="1"> <span class="sr-only">Supprimer</span></label><?php endif; ?></td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <p class="field-help">Les positions vont de 10 en 10 : pour insérer un statut entre deux autres, donnez-lui une position intermédiaire (par exemple 25). Les lignes vides sont ignorées ; enregistrez pour obtenir de nouvelles lignes vides.</p>
            <div class="form-actions">
              <button class="primary-button" type="submit" name="action" value="save_logistics">Enregistrer les statuts logistiques</button>
              <?php if ($logisticsStored !== null): ?>
              <button class="secondary-button" type="submit" name="action" value="reset_logistics" onclick="return confirm('Rétablir la liste d’origine des statuts logistiques ? Les statuts ajoutés seront supprimés.');">Rétablir la liste d’origine</button>
              <?php endif; ?>
            </div>
          </form>
        </section>
      </section>
<?php app_footer(); ?>
