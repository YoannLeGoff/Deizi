<?php
declare(strict_types=1);

// "Langues": the translations of the interface, reserved for administrators. They are corrected
// on screen, or in bulk by exporting a language to a spreadsheet (CSV for Excel or LibreOffice)
// that a translator fills in without an account, then importing it back. Corrections are kept in
// data/translations-<code>.json; the export as lang/<code>.php shares them with the project.
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/i18n-admin.php';
$admin = app_require_roles(['admin']);

$editable = array_diff_key(LANGUAGES, [DEFAULT_LANGUAGE => true]);
$language = is_string($_REQUEST['langue'] ?? null) && isset($editable[$_REQUEST['langue']]) ? $_REQUEST['langue'] : array_key_first($editable);
$filter = in_array($_GET['filtre'] ?? '', ['manquantes', 'modifiees'], true) ? $_GET['filtre'] : 'toutes';
$search = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
$keys = array_keys(app_i18n_keys());
$byId = [];
foreach ($keys as $french) {
    $byId[app_translation_id($french)] = $french;
}
$pageUrl = static fn (array $query = []): string => 'languages-settings.php?' . http_build_query(['langue' => $language] + $query);

// Exports: the spreadsheet to translate, or the complete language file of the project.
if (in_array($_GET['export'] ?? '', ['csv', 'php'], true)) {
    $translations = app_translations($language);
    if ($_GET['export'] === 'php') {
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $language . '.php"');
        header('Cache-Control: no-store');
        echo app_translation_php($language);
        exit;
    }
    $missingOnly = ($_GET['manquantes'] ?? '') === '1';
    $rows = [];
    foreach ($keys as $french) {
        $translation = $translations[$french] ?? '';
        if (!$missingOnly || $translation === '') {
            $rows[] = [app_translation_id($french), $french, $translation];
        }
    }
    app_send_csv('deizi-' . $language . ($missingOnly ? '-a-traduire' : '') . '.csv', ['Id', 'Français', LANGUAGES[$language]], $rows);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    app_verify_csrf();
    $action = $_POST['action'] ?? '';
    $returnUrl = $pageUrl(array_filter(['filtre' => $_POST['filtre'] ?? null, 'q' => $_POST['q'] ?? null], static fn (mixed $value): bool => is_string($value) && $value !== '' && $value !== 'toutes'));
    $changes = [];
    $unknown = 0;
    if ($action === 'save') {
        // One field per text shown (the filtered ones): ids of the form.
        foreach (is_array($_POST['t'] ?? null) ? $_POST['t'] : [] as $id => $translation) {
            if (is_string($id) && isset($byId[$id]) && is_string($translation)) {
                $changes[$byId[$id]] = $translation;
            }
        }
    } elseif ($action === 'import') {
        $file = $_FILES['file'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name']) || ($file['size'] ?? 0) > 2 * 1024 * 1024) {
            app_flash(__('Choisissez un fichier CSV de 2 Mo au plus.'), 'error');
            app_redirect($returnUrl);
        }
        $byFrench = array_flip($keys);
        foreach (app_read_translation_csv((string) file_get_contents((string) $file['tmp_name'])) as $cells) {
            [$id, $french, $translation] = array_pad($cells, 3, '');
            $id = trim($id);
            if (strcasecmp($id, 'Id') === 0) {
                continue;
            }
            // The export protects cells starting with = + - @ for Excel with a "'": removed here.
            $unprotect = static fn (string $text): string => preg_match("/^'[=+\\-@]/", $text) === 1 ? substr($text, 1) : $text;
            $french = $unprotect($french);
            $key = $byId[$id] ?? (isset($byFrench[$french]) ? $french : null);
            if ($key === null) {
                $unknown++;
                continue;
            }
            $changes[$key] = $unprotect($translation);
        }
    } else {
        http_response_code(400);
        exit(__('Action inconnue.'));
    }
    [$saved, $unchanged, $refused] = app_save_translations($language, $changes);
    $message = __n('{n} traduction enregistrée', '{n} traductions enregistrées', $saved) . ', ' . __n('{n} inchangée', '{n} inchangées', $unchanged);
    if ($unknown > 0) {
        $message .= ', ' . __n('{n} ligne ignorée (texte qui n’existe plus)', '{n} lignes ignorées (textes qui n’existent plus)', $unknown);
    }
    if ($refused !== []) {
        $details = implode(' ; ', array_map(
            static fn (string $french, string $reason): string => '« ' . app_truncate($french, 60) . ' » ' . $reason,
            array_slice(array_keys($refused), 0, 10),
            array_slice(array_values($refused), 0, 10)
        )) . (count($refused) > 10 ? ' ; …' : '');
        $message .= '. ' . __n('{n} refusée : {details}', '{n} refusées : {details}', count($refused), ['details' => $details]);
    }
    app_flash($message . '.', $refused === [] ? 'success' : 'error');
    app_redirect($returnUrl);
}

$code = app_code_translations($language);
$overrides = app_translation_overrides($language);
$translations = app_translations($language);
$rows = [];
$counts = ['toutes' => count($keys), 'manquantes' => 0, 'modifiees' => count(array_intersect_key($overrides, array_flip($keys)))];
foreach ($keys as $french) {
    $translation = $translations[$french] ?? '';
    $missing = $translation === '';
    $counts['manquantes'] += $missing ? 1 : 0;
    $changed = isset($overrides[$french]);
    if (($filter === 'manquantes' && !$missing) || ($filter === 'modifiees' && !$changed)) {
        continue;
    }
    if ($search !== '' && mb_stripos($french . "\n" . $translation, $search) === false) {
        continue;
    }
    $rows[] = ['french' => $french, 'translation' => $translation, 'missing' => $missing, 'changed' => $changed, 'code' => $code[$french] ?? ''];
}

app_header('Langues', $admin);
?>
      <section class="management-section">
        <div class="eyebrow-row">
          <p class="eyebrow"><span class="eyebrow-line"></span> <?= app_escape(mb_strtoupper(__('Configuration du site'))) ?></p>
          <?php app_page_links([['Configuration', 'settings.php']]); ?>
        </div>
        <div class="section-heading">
          <div>
            <h1><?= app_escape(__('Langues')) ?><span class="heading-dot">.</span></h1>
          </div>
        </div>
        <?php app_settings_nav('organisation', 'languages-settings.php', $admin); ?>

        <p class="accounts-help"><?= app_escape(__('Les libellés de l’interface (menus, boutons, messages) sont traduits ; les contenus de l’association (dates, annonces, sections, statuts) restent tels que saisis. Corrigez une traduction ci-dessous, ou confiez une langue à un traducteur : exportez-la en tableur, faites remplir la dernière colonne, puis importez le fichier.')) ?></p>

        <nav class="detail-tabs" aria-label="<?= app_escape(__('Langue')) ?>">
          <?php foreach ($editable as $code => $name): ?>
          <a class="detail-tab" href="languages-settings.php?langue=<?= $code ?>" lang="<?= $code ?>"<?= $code === $language ? ' aria-current="page"' : '' ?>><?= app_escape($name) ?></a>
          <?php endforeach; ?>
        </nav>

        <section class="management-panel languages-bulk" aria-labelledby="languages-bulk-title">
          <h2 class="detail-heading" id="languages-bulk-title"><?= app_escape(__('Traduire en masse')) ?></h2>
          <div class="languages-bulk-columns">
            <div>
              <h3><?= app_escape(__('1. Exporter')) ?></h3>
              <p class="field-help"><?= app_escape(__('Un fichier CSV pour Excel ou LibreOffice : une ligne par texte, avec son identifiant, le texte français et la traduction actuelle. Le traducteur remplit ou corrige la dernière colonne, sans toucher aux deux premières, et garde les valeurs entre accolades comme {name}.')) ?></p>
              <div class="form-actions">
                <a class="secondary-button compact-button" href="<?= app_escape($pageUrl(['export' => 'csv', 'manquantes' => '1'])) ?>"><?= app_escape(__('Textes à traduire ({n})', ['n' => $counts['manquantes']])) ?></a>
                <a class="secondary-button compact-button" href="<?= app_escape($pageUrl(['export' => 'csv'])) ?>"><?= app_escape(__('Tous les textes ({n})', ['n' => $counts['toutes']])) ?></a>
              </div>
            </div>
            <div>
              <h3><?= app_escape(__('2. Importer')) ?></h3>
              <form method="post" action="languages-settings.php" enctype="multipart/form-data" class="form-stack">
                <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
                <input type="hidden" name="action" value="import">
                <input type="hidden" name="langue" value="<?= app_escape($language) ?>">
                <label for="languages-file"><?= app_escape(__('Fichier rempli (CSV)')) ?></label>
                <input id="languages-file" name="file" type="file" accept=".csv,text/csv" required>
                <p class="field-help"><?= app_escape(__('Les traductions valides sont enregistrées ; une cellule vide garde la traduction d’origine. Les lignes refusées (valeur entre accolades manquante…) sont listées.')) ?></p>
                <div class="form-actions"><button class="primary-button compact-button" type="submit"><?= app_escape(__('Importer')) ?></button></div>
              </form>
            </div>
            <div>
              <h3><?= app_escape(__('3. Partager')) ?></h3>
              <p class="field-help"><?= app_escape(__('Le fichier de langue complet du projet, avec vos corrections, à proposer sur le dépôt du code pour en faire profiter les autres associations.')) ?></p>
              <div class="form-actions">
                <a class="secondary-button compact-button" href="<?= app_escape($pageUrl(['export' => 'php'])) ?>"><?= app_escape(__('Télécharger {file}', ['file' => 'lang/' . $language . '.php'])) ?></a>
              </div>
            </div>
          </div>
        </section>

        <section class="management-panel" aria-labelledby="languages-edit-title">
          <h2 class="detail-heading" id="languages-edit-title"><?= app_escape(__('Corriger à l’écran')) ?></h2>
          <form method="get" action="languages-settings.php" class="languages-filters">
            <input type="hidden" name="langue" value="<?= app_escape($language) ?>">
            <div class="filter-buttons">
              <?php foreach (['toutes' => __('Tous ({n})', ['n' => $counts['toutes']]), 'manquantes' => __('À traduire ({n})', ['n' => $counts['manquantes']]), 'modifiees' => __('Modifiés ici ({n})', ['n' => $counts['modifiees']])] as $value => $label): ?>
              <a class="filter-button<?= $filter === $value ? ' is-active' : '' ?>" href="<?= app_escape($pageUrl(array_filter(['filtre' => $value === 'toutes' ? null : $value, 'q' => $search !== '' ? $search : null]))) ?>"<?= $filter === $value ? ' aria-current="page"' : '' ?>><?= app_escape($label) ?></a>
              <?php endforeach; ?>
            </div>
            <?php if ($filter !== 'toutes'): ?><input type="hidden" name="filtre" value="<?= app_escape($filter) ?>"><?php endif; ?>
            <label class="sr-only" for="languages-search"><?= app_escape(__('Rechercher')) ?></label>
            <input id="languages-search" name="q" type="search" value="<?= app_escape($search) ?>" placeholder="<?= app_escape(__('Rechercher un texte')) ?>">
            <button class="secondary-button compact-button" type="submit"><?= app_escape(__('Rechercher')) ?></button>
          </form>

          <?php if ($rows === []): ?>
          <p class="muted-copy"><?= app_escape(__('Aucun texte pour ce filtre.')) ?></p>
          <?php else: ?>
          <form method="post" action="languages-settings.php" class="languages-form">
            <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="langue" value="<?= app_escape($language) ?>">
            <input type="hidden" name="filtre" value="<?= app_escape($filter) ?>">
            <input type="hidden" name="q" value="<?= app_escape($search) ?>">
            <table class="languages-table">
              <thead><tr><th scope="col"><?= app_escape(__('Français')) ?></th><th scope="col" lang="<?= $language ?>"><?= app_escape(LANGUAGES[$language]) ?></th></tr></thead>
              <tbody>
                <?php foreach ($rows as $row): ?>
                <?php $id = app_translation_id($row['french']); ?>
                <tr class="<?= $row['missing'] ? 'is-missing' : ($row['changed'] ? 'is-changed' : '') ?>">
                  <td class="languages-french">
                    <label for="t-<?= $id ?>"><?= nl2br(app_escape($row['french']), false) ?></label>
                    <?php if ($row['missing']): ?><span class="languages-state"><?= app_escape(__('À traduire')) ?></span><?php elseif ($row['changed']): ?><span class="languages-state" title="<?= app_escape(__('Traduction d’origine : {text}', ['text' => $row['code'] !== '' ? $row['code'] : '—'])) ?>"><?= app_escape(__('Modifié ici')) ?></span><?php endif; ?>
                  </td>
                  <td><textarea id="t-<?= $id ?>" name="t[<?= $id ?>]" rows="<?= max(1, min(6, (int) ceil(mb_strlen($row['french']) / 70) + substr_count($row['french'], "\n"))) ?>" lang="<?= $language ?>" placeholder="<?= app_escape($row['code']) ?>"><?= app_escape($row['translation']) ?></textarea></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
            <p class="field-help"><?= app_escape(__('Videz un champ pour revenir à la traduction d’origine (affichée en grisé). Les valeurs entre accolades comme {name} doivent rester identiques.')) ?></p>
            <div class="form-actions languages-save"><button class="primary-button" type="submit"><?= app_escape(__('Enregistrer les traductions')) ?></button></div>
          </form>
          <?php endif; ?>
        </section>
      </section>
<?php app_footer(); ?>
