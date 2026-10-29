<?php
declare(strict_types=1);

// "Langues": the translations of the interface, reserved for administrators. They are corrected
// on screen, or in bulk by exporting a language to a spreadsheet (CSV for Excel or LibreOffice)
// that a translator fills in without an account, then importing it back. Corrections are kept in
// data/translations-<code>.json; the export as lang/<code>.php shares them with the project.
// New languages ("Français (Québec)") are created from an existing one, whose translations they
// take until they change them. The default language of the application is chosen here too.
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/i18n-admin.php';
$admin = app_require_roles(['admin']);

// French too: its texts can be adapted to the association like any other language.
$editable = app_languages();
$custom = app_custom_languages();
$language = is_string($_REQUEST['langue'] ?? null) && isset($editable[$_REQUEST['langue']]) ? $_REQUEST['langue'] : 'en';
$filter = in_array($_GET['filtre'] ?? '', ['manquantes', 'modifiees'], true) ? $_GET['filtre'] : 'toutes';
$search = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
$keys = array_keys(app_i18n_keys());
$isKey = array_flip($keys);
$pageUrl = static fn (array $query = []): string => 'languages-settings.php?' . http_build_query(['langue' => $language] + $query);

// Exports: the spreadsheet to translate, or the complete language file of the project.
if (in_array($_GET['export'] ?? '', ['csv', 'php'], true)) {
    if ($_GET['export'] === 'php') {
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $language . '.php"');
        header('Cache-Control: no-store');
        echo app_translation_php($language);
        exit;
    }
    $missingOnly = ($_GET['manquantes'] ?? '') === '1';
    $rows = [];
    $overrides = app_translation_overrides($language);
    foreach ($keys as $key) {
        $translation = $overrides[$key] ?? app_inherited_translation($language, $key);
        if (!$missingOnly || $translation === '') {
            $rows[] = [$key, app_french_text($key), $translation];
        }
    }
    app_send_csv('deizi-' . $language . ($missingOnly ? '-a-traduire' : '') . '.csv', ['Id', 'Français', app_languages()[$language]], $rows);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    app_verify_csrf();
    $action = $_POST['action'] ?? '';
    $returnUrl = $pageUrl(array_filter(['filtre' => $_POST['filtre'] ?? null, 'q' => $_POST['q'] ?? null], static fn (mixed $value): bool => is_string($value) && $value !== '' && $value !== 'toutes'));
    if ($action === 'default_language') {
        $default = is_string($_POST['default'] ?? null) && app_is_language($_POST['default']) ? $_POST['default'] : DEFAULT_LANGUAGE;
        update_json_file(app_data_path('settings.json'), static function (array $settings) use ($default): array {
            $settings['site'] = (is_array($settings['site'] ?? null) ? $settings['site'] : []);
            $settings['site']['language'] = $default;
            return $settings;
        });
        app_flash(__('langue_defaut_enregistree', ['language' => app_languages()[$default]]));
        app_redirect($pageUrl());
    }
    if ($action === 'create_language') {
        $name = app_clean_line($_POST['name'] ?? '', LANGUAGE_NAME_MAX_LENGTH);
        $newCode = is_string($_POST['code'] ?? null) ? strtolower(trim($_POST['code'])) : '';
        $base = is_string($_POST['base'] ?? null) && app_is_language($_POST['base']) ? $_POST['base'] : null;
        $error = match (true) {
            $name === '' => __('donnez_nom_langue_phrase'),
            preg_match(LANGUAGE_CODE_PATTERN, $newCode) !== 1 => __('code_secrit_minuscules_2_3_lettres'),
            app_is_language($newCode) => __('cette_langue_existe_deja_phrase'),
            in_array(app_lowercase($name), array_map('app_lowercase', app_languages()), true) => __('langue_porte_deja_ce_nom_phrase'),
            $base === null => __('choisissez_langue_base_phrase'),
            default => null,
        };
        if ($error !== null) {
            app_flash($error, 'error');
            app_redirect('languages-settings.php#languages-new-title');
        }
        update_json_file(app_data_path('settings.json'), static function (array $settings) use ($newCode, $name, $base): array {
            $settings['languages'] = (is_array($settings['languages'] ?? null) ? $settings['languages'] : []) + [$newCode => ['name' => $name, 'base' => $base]];
            return $settings;
        });
        app_flash(__('langue_name_ete_creee_partir_base', ['name' => $name, 'base' => app_languages()[$base]]));
        app_redirect('languages-settings.php?langue=' . rawurlencode($newCode));
    }
    if ($action === 'delete_language') {
        $deleted = is_string($_POST['code'] ?? null) && isset($custom[$_POST['code']]) ? $_POST['code'] : null;
        $dependents = array_keys(array_filter($custom, static fn (array $item): bool => $item['base'] === $deleted));
        if ($deleted === null || $dependents !== []) {
            app_flash($deleted === null ? __('cette_langue_nexiste_plus_phrase') : __('dautres_langues_sont_basees_celle_ci', ['languages' => implode(', ', array_map(static fn (string $code): string => app_languages()[$code], $dependents))]), 'error');
            app_redirect($pageUrl());
        }
        $deletedName = $custom[$deleted]['name'];
        update_json_file(app_data_path('settings.json'), static function (array $settings) use ($deleted): array {
            unset($settings['languages'][$deleted]);
            if (($settings['languages'] ?? null) === []) {
                unset($settings['languages']);
            }
            // The site language goes back to French when it was this one.
            if (($settings['site']['language'] ?? null) === $deleted) {
                unset($settings['site']['language']);
            }
            return $settings;
        });
        // Members who used it go back to the language of the site.
        update_json_file(app_data_path('users.json'), static function (array $users) use ($deleted): array {
            foreach ($users as &$account) {
                if (is_array($account) && ($account['language'] ?? null) === $deleted) {
                    unset($account['language']);
                }
            }
            unset($account);
            return $users;
        });
        foreach ([app_data_path('translations-' . $deleted . '.json'), app_data_path('translations-' . $deleted . '.json') . '.lock'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        app_flash(__('langue_name_ete_supprimee_membres_reviennent', ['name' => $deletedName]));
        app_redirect('languages-settings.php');
    }
    $changes = [];
    $unknown = 0;
    if ($action === 'save') {
        // One field per text shown (the filtered ones): ids of the form.
        foreach (is_array($_POST['t'] ?? null) ? $_POST['t'] : [] as $id => $translation) {
            if (is_string($id) && isset($isKey[$id]) && is_string($translation)) {
                $changes[$id] = $translation;
            }
        }
    } elseif ($action === 'import') {
        $file = $_FILES['file'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name']) || ($file['size'] ?? 0) > 2 * 1024 * 1024) {
            app_flash(__('choisissez_fichier_csv_2_mo_plus'), 'error');
            app_redirect($returnUrl);
        }
        foreach (app_read_translation_csv((string) file_get_contents((string) $file['tmp_name'])) as $cells) {
            [$id, $french, $translation] = array_pad($cells, 3, '');
            $id = trim($id);
            if (strcasecmp($id, 'Id') === 0) {
                continue;
            }
            // The export protects cells starting with = + - @ for Excel with a "'": removed here.
            $unprotect = static fn (string $text): string => preg_match("/^'[=+\\-@]/", $text) === 1 ? substr($text, 1) : $text;
            $french = $unprotect($french);
            // The key of the first column, otherwise the French text of the second one.
            $key = isset($isKey[$id]) ? $id : app_label_key($french);
            $key = $key !== null && isset($isKey[$key]) ? $key : null;
            if ($key === null) {
                $unknown++;
                continue;
            }
            $changes[$key] = $unprotect($translation);
        }
    } else {
        http_response_code(400);
        exit(__('action_inconnue_phrase'));
    }
    [$saved, $unchanged, $refused] = app_save_translations($language, $changes);
    $message = __n('traduction_enregistree', 'traductions_enregistrees', $saved) . ', ' . __n('inchangee', 'inchangees', $unchanged);
    if ($unknown > 0) {
        $message .= ', ' . __n('ligne_ignoree_texte_nexiste_plus', 'lignes_ignorees_textes_nexistent_plus', $unknown);
    }
    if ($refused !== []) {
        $details = implode(' ; ', array_map(
            static fn (string $key, string $reason): string => '« ' . app_truncate(app_french_text($key), 60) . ' » ' . $reason,
            array_slice(array_keys($refused), 0, 10),
            array_slice(array_values($refused), 0, 10)
        )) . (count($refused) > 10 ? ' ; …' : '');
        $message .= '. ' . __n('refusee_details', 'refusees_details', count($refused), ['details' => $details]);
    }
    app_flash($message . '.', $refused === [] ? 'success' : 'error');
    app_redirect($returnUrl);
}

$overrides = app_translation_overrides($language);
$rows = [];
$counts = ['toutes' => count($keys), 'manquantes' => 0, 'modifiees' => count(array_intersect_key($overrides, array_flip($keys)))];
foreach ($keys as $key) {
    $french = app_french_text($key);
    $inherited = app_inherited_translation($language, $key);
    $translation = $overrides[$key] ?? $inherited;
    $missing = $translation === '';
    $counts['manquantes'] += $missing ? 1 : 0;
    $changed = isset($overrides[$key]);
    if (($filter === 'manquantes' && !$missing) || ($filter === 'modifiees' && !$changed)) {
        continue;
    }
    if ($search !== '' && mb_stripos($key . "\n" . $french . "\n" . $translation, $search) === false) {
        continue;
    }
    $rows[] = ['key' => $key, 'french' => $french, 'translation' => $translation, 'missing' => $missing, 'changed' => $changed, 'code' => $inherited];
}

app_header('Langues', $admin);
?>
      <section class="management-section">
        <div class="eyebrow-row">
          <p class="eyebrow"><span class="eyebrow-line"></span> <?= app_escape(mb_strtoupper(__('configuration_site'))) ?></p>
          <?php app_page_links([['Configuration', 'settings.php']]); ?>
        </div>
        <div class="section-heading">
          <div>
            <h1><?= app_escape(__('langues')) ?><span class="heading-dot">.</span></h1>
          </div>
        </div>
        <?php app_settings_nav('organisation', 'languages-settings.php', $admin); ?>

        <p class="accounts-help"><?= app_escape(__('libelles_linterface_menus_boutons_messages_sont')) ?></p>

        <section class="management-panel languages-default" aria-labelledby="languages-default-title">
          <h2 class="detail-heading" id="languages-default-title"><?= app_escape(__('langue_defaut_application')) ?></h2>
          <form class="languages-default-form" method="post" action="languages-settings.php">
            <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
            <input type="hidden" name="action" value="default_language">
            <input type="hidden" name="langue" value="<?= app_escape($language) ?>">
            <div class="form-stack">
              <label for="languages-default"><?= app_escape(__('langue_defaut_site')) ?></label>
              <select id="languages-default" name="default">
                <?php foreach (app_languages() as $code => $name): ?>
                <option value="<?= $code ?>" lang="<?= $code ?>"<?= app_site_language() === $code ? ' selected' : '' ?>><?= app_escape($name) ?></option>
                <?php endforeach; ?>
              </select>
              <p class="field-help"><?= app_escape(__('langue_membres_nen_ont_choisi_dans')) ?></p>
            </div>
            <button class="primary-button compact-button" type="submit"><?= app_escape(__('enregistrer')) ?></button>
          </form>
        </section>

        <nav class="detail-tabs" aria-label="<?= app_escape(__('langue')) ?>">
          <?php foreach ($editable as $code => $name): ?>
          <a class="detail-tab" href="languages-settings.php?langue=<?= $code ?>" lang="<?= $code ?>"<?= $code === $language ? ' aria-current="page"' : '' ?>><?= app_escape($name) ?><?php if (isset($custom[$code])): ?> <span class="count-badge"><?= app_escape($code) ?></span><?php endif; ?></a>
          <?php endforeach; ?>
        </nav>

        <?php if (isset($custom[$language])): ?>
        <section class="management-panel languages-custom" aria-label="<?= app_escape(app_languages()[$language]) ?>">
          <p class="muted-copy"><?= app_escape(__('name_code_basee_base_reprend_toutes', ['name' => $custom[$language]['name'], 'code' => $language, 'base' => app_languages()[$custom[$language]['base']], 'root' => LANGUAGES[app_language_root($language)]])) ?></p>
          <form method="post" action="languages-settings.php" onsubmit="return confirm(<?= app_escape(json_encode(__('supprimer_langue_name_traductions_membres_reviendront', ['name' => $custom[$language]['name']]), JSON_UNESCAPED_UNICODE)) ?>);">
            <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
            <input type="hidden" name="action" value="delete_language">
            <input type="hidden" name="code" value="<?= app_escape($language) ?>">
            <button class="text-button" type="submit"><?= app_escape(__('supprimer_cette_langue')) ?></button>
          </form>
        </section>
        <?php endif; ?>

        <section class="management-panel languages-bulk" aria-labelledby="languages-bulk-title">
          <h2 class="detail-heading" id="languages-bulk-title"><?= app_escape(__('traduire_masse')) ?></h2>
          <div class="languages-bulk-columns">
            <div>
              <h3><?= app_escape(__('1_exporter')) ?></h3>
              <p class="field-help"><?= app_escape(__('fichier_csv_excel_libreoffice_ligne_texte')) ?></p>
              <div class="form-actions">
                <a class="secondary-button compact-button" href="<?= app_escape($pageUrl(['export' => 'csv', 'manquantes' => '1'])) ?>"><?= app_escape(__('textes_traduire', ['n' => $counts['manquantes']])) ?></a>
                <a class="secondary-button compact-button" href="<?= app_escape($pageUrl(['export' => 'csv'])) ?>"><?= app_escape(__('tous_textes', ['n' => $counts['toutes']])) ?></a>
              </div>
            </div>
            <div>
              <h3><?= app_escape(__('2_importer')) ?></h3>
              <form method="post" action="languages-settings.php" enctype="multipart/form-data" class="form-stack">
                <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
                <input type="hidden" name="action" value="import">
                <input type="hidden" name="langue" value="<?= app_escape($language) ?>">
                <label for="languages-file"><?= app_escape(__('fichier_rempli_csv')) ?></label>
                <input id="languages-file" name="file" type="file" accept=".csv,text/csv" required>
                <p class="field-help"><?= app_escape(__('traductions_valides_sont_enregistrees_cellule_vide')) ?></p>
                <div class="form-actions"><button class="primary-button compact-button" type="submit"><?= app_escape(__('importer')) ?></button></div>
              </form>
            </div>
            <div>
              <h3><?= app_escape(__('3_partager')) ?></h3>
              <p class="field-help"><?= app_escape(__('fichier_langue_complet_projet_avec_vos')) ?></p>
              <div class="form-actions">
                <a class="secondary-button compact-button" href="<?= app_escape($pageUrl(['export' => 'php'])) ?>"><?= app_escape(__('telecharger_file', ['file' => 'lang/' . $language . '.php'])) ?></a>
              </div>
            </div>
          </div>
        </section>

        <section class="management-panel" aria-labelledby="languages-edit-title">
          <h2 class="detail-heading" id="languages-edit-title"><?= app_escape(__('corriger_lecran')) ?></h2>
          <form method="get" action="languages-settings.php" class="languages-filters">
            <input type="hidden" name="langue" value="<?= app_escape($language) ?>">
            <div class="filter-buttons">
              <?php foreach (['toutes' => __('tous', ['n' => $counts['toutes']]), 'manquantes' => __('a_traduire_n', ['n' => $counts['manquantes']]), 'modifiees' => __('modifies_ici', ['n' => $counts['modifiees']])] as $value => $label): ?>
              <a class="filter-button<?= $filter === $value ? ' is-active' : '' ?>" href="<?= app_escape($pageUrl(array_filter(['filtre' => $value === 'toutes' ? null : $value, 'q' => $search !== '' ? $search : null]))) ?>"<?= $filter === $value ? ' aria-current="page"' : '' ?>><?= app_escape($label) ?></a>
              <?php endforeach; ?>
            </div>
            <?php if ($filter !== 'toutes'): ?><input type="hidden" name="filtre" value="<?= app_escape($filter) ?>"><?php endif; ?>
            <label class="sr-only" for="languages-search"><?= app_escape(__('rechercher')) ?></label>
            <input id="languages-search" name="q" type="search" value="<?= app_escape($search) ?>" placeholder="<?= app_escape(__('rechercher_texte')) ?>">
            <button class="secondary-button compact-button" type="submit"><?= app_escape(__('rechercher')) ?></button>
          </form>

          <?php if ($rows === []): ?>
          <p class="muted-copy"><?= app_escape(__('aucun_texte_ce_filtre_phrase')) ?></p>
          <?php else: ?>
          <form method="post" action="languages-settings.php" class="languages-form">
            <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="langue" value="<?= app_escape($language) ?>">
            <input type="hidden" name="filtre" value="<?= app_escape($filter) ?>">
            <input type="hidden" name="q" value="<?= app_escape($search) ?>">
            <table class="languages-table">
              <thead><tr><th scope="col"><?= app_escape(__('francais')) ?></th><th scope="col" lang="<?= $language ?>"><?= app_escape(app_languages()[$language]) ?></th></tr></thead>
              <tbody>
                <?php foreach ($rows as $row): ?>
                <?php $id = $row['key']; ?>
                <tr class="<?= $row['missing'] ? 'is-missing' : ($row['changed'] ? 'is-changed' : '') ?>">
                  <td class="languages-french">
                    <label for="t-<?= $id ?>"><?= nl2br(app_escape($row['french']), false) ?></label>
                    <code class="languages-key"><?= app_escape($row['key']) ?></code>
                    <?php if ($row['missing']): ?><span class="languages-state"><?= app_escape(__('traduire')) ?></span><?php elseif ($row['changed']): ?><span class="languages-state" title="<?= app_escape(__('traduction_dorigine_text', ['text' => $row['code'] !== '' ? $row['code'] : '—'])) ?>"><?= app_escape(__('modifie_ici')) ?></span><?php endif; ?>
                  </td>
                  <td><textarea id="t-<?= $id ?>" name="t[<?= $id ?>]" rows="<?= max(1, min(6, (int) ceil(mb_strlen($row['french']) / 70) + substr_count($row['french'], "\n"))) ?>" lang="<?= $language ?>" placeholder="<?= app_escape($row['code']) ?>"><?= app_escape($row['translation']) ?></textarea></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
            <p class="field-help"><?= app_escape(__('videz_champ_revenir_traduction_dorigine_affichee')) ?></p>
            <div class="form-actions languages-save"><button class="primary-button" type="submit"><?= app_escape(__('enregistrer_traductions')) ?></button></div>
          </form>
          <?php endif; ?>
        </section>

        <section class="management-panel" aria-labelledby="languages-new-title">
          <h2 class="detail-heading" id="languages-new-title"><?= app_escape(__('creer_une_langue')) ?></h2>
          <p class="muted-copy"><?= app_escape(__('variante_francais_quebec_anglais_americain_breton')) ?></p>
          <form method="post" action="languages-settings.php" class="form-grid languages-new">
            <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
            <input type="hidden" name="action" value="create_language">
            <div class="form-stack">
              <label for="language-name"><?= app_escape(__('nom_langue')) ?></label>
              <input id="language-name" name="name" required maxlength="<?= LANGUAGE_NAME_MAX_LENGTH ?>" placeholder="<?= app_escape(__('francais_quebec')) ?>">
            </div>
            <div class="form-stack">
              <label for="language-code"><?= app_escape(__('code_langue')) ?></label>
              <input id="language-code" name="code" required maxlength="12" pattern="[a-z]{2,3}(-[a-z0-9]{2,8})?" placeholder="fr-ca">
            </div>
            <div class="form-stack">
              <label for="language-base"><?= app_escape(__('partir')) ?></label>
              <select id="language-base" name="base">
                <?php foreach (app_languages() as $code => $name): ?>
                <option value="<?= $code ?>" lang="<?= $code ?>"><?= app_escape($name) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-actions"><button class="primary-button" type="submit"><?= app_escape(__('creer_langue')) ?></button></div>
          </form>
          <p class="field-help"><?= app_escape(__('code_identifie_langue_navigateurs_visiteur_dont')) ?></p>
        </section>
      </section>
<?php app_footer(); ?>
