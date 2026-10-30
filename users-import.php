<?php
declare(strict_types=1);

// Bulk import of accounts from a CSV file, for administrators only.
// Expected columns: Login ; Prénom ; NOM ; Sous-section principale (name or code) ; Mot de passe
// (optional). Step 1 checks the file and shows a preview; step 2 creates the valid lines as
// artists. A password given in the file is hashed at once and never shown again; without one, a
// random temporary password is generated, shown once (and downloadable as CSV).
require __DIR__ . '/lib/bootstrap.php';
$admin = app_require_roles(['admin']);

const IMPORT_MAX_BYTES = 512 * 1024;
const IMPORT_MAX_ROWS = 500;

// Sub-section from its name or its code, case-insensitive.
function importLeafLookup(): array
{
    $lookup = [];
    $labels = app_section_labels();
    foreach (app_section_leaves() as $leaf) {
        $lookup[app_lowercase(trim($labels[$leaf] ?? ''))] = $leaf;
        $lookup[app_lowercase(app_section_code($leaf))] = $leaf;
    }
    unset($lookup['']);
    return $lookup;
}

// Reads the uploaded file: UTF-8 (BOM allowed) or Windows-1252 (Excel), ";" or "," separated.
function importReadRows(string $content): array
{
    if (str_starts_with($content, "\u{FEFF}")) {
        $content = substr($content, 3);
    }
    if (!mb_check_encoding($content, 'UTF-8')) {
        $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
    }
    $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
    $firstLine = (string) ($lines[0] ?? '');
    $separator = substr_count($firstLine, ';') >= substr_count($firstLine, ',') ? ';' : ',';
    $rows = [];
    foreach ($lines as $index => $line) {
        if (trim($line) === '') {
            continue;
        }
        $rows[] = ['line' => $index + 1, 'cells' => array_map('trim', str_getcsv($line, $separator, '"', ''))];
    }
    // An optional header row ("Login ; Prénom ; NOM ; …", or its translation shown on the page) is skipped.
    if ($rows !== [] && in_array(app_lowercase(trim($rows[0]['cells'][0] ?? '')), ['login', 'identifiant', app_lowercase(__('login'))], true)) {
        array_shift($rows);
    }
    return $rows;
}

// Checks each row; returns the rows with their status ("ok" or an error message).
function importCheckRows(array $rows): array
{
    $leaves = importLeafLookup();
    $labels = app_section_labels();
    $existing = [];
    foreach (read_json_file(app_data_path('users.json')) as $account) {
        if (is_array($account) && is_string($account['username'] ?? null)) {
            $existing[app_username_key($account['username'])] = true;
        }
    }
    $seen = [];
    $checked = [];
    foreach ($rows as $row) {
        [$login, $firstName, $lastName, $leafInput, $password] = array_pad($row['cells'], 5, '');
        $login = app_normalize_username($login);
        $leaf = $leaves[app_lowercase($leafInput)] ?? null;
        $error = match (true) {
            !app_is_valid_username($login) => __('login_invalide_rule_phrase', ['rule' => app_label(USERNAME_RULE)]),
            isset($existing[app_username_key($login)]) => __('ce_login_existe_deja_phrase'),
            isset($seen[app_username_key($login)]) => __('login_double_dans_fichier_phrase'),
            $leafInput === '' => __('sous_section_principale_manquante_phrase'),
            $leaf === null => __('sous_section_section_introuvable_phrase', ['section' => $leafInput]),
            $password !== '' && (strlen($password) < 12 || strlen($password) > 200) => __('mot_passe_trop_court_12_caracteres_phrase'),
            default => null,
        };
        $seen[app_username_key($login)] = true;
        $checked[] = [
            'line' => $row['line'],
            'login' => $login,
            'first_name' => app_clean_line($firstName, 60),
            'last_name' => app_clean_line($lastName, 60),
            'leaf' => $leaf,
            'leaf_label' => $leaf !== null ? $labels[$leaf] : $leafInput,
            // Kept only until the preview is saved: hashed there, never shown.
            'password' => $password,
            'error' => $error,
        ];
    }
    return $checked;
}

function importPassword(): string
{
    // 14 characters without ambiguous ones (0/O, 1/l/I).
    $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $password = '';
    for ($index = 0; $index < 14; $index++) {
        $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $password;
}

// Download of the created accounts and their temporary passwords (once, then forgotten).
if (($_GET['telecharger'] ?? '') === '1') {
    $created = $_SESSION['import_created'] ?? [];
    unset($_SESSION['import_created']);
    if (!is_array($created) || $created === []) {
        app_flash(__('mots_passe_sont_plus_disponibles_ils'), 'error');
        app_redirect('users-import.php');
    }
    app_send_csv(
        'comptes-importes-' . date('Y-m-d') . '.csv',
        [__('login'), __('prenom'), __('nom_2'), __('sous_section_principale'), __('mot_passe_temporaire')],
        array_map(static fn (array $account): array => [$account['login'], $account['first_name'], $account['last_name'], $account['leaf_label'], $account['password'] ?? __('fourni_dans_fichier_importe')], $created)
    );
}

$step = 'upload';
$checked = [];
$created = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    app_verify_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'preview') {
        $file = $_FILES['csv'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            app_flash(__('choisissez_fichier_csv_importer_phrase'), 'error');
            app_redirect('users-import.php');
        }
        if (($file['size'] ?? 0) > IMPORT_MAX_BYTES) {
            app_flash(__('fichier_trop_volumineux_512_ko_maximum_phrase'), 'error');
            app_redirect('users-import.php');
        }
        $rows = importReadRows((string) file_get_contents($file['tmp_name']));
        if ($rows === [] || count($rows) > IMPORT_MAX_ROWS) {
            app_flash($rows === [] ? __('fichier_contient_aucune_ligne_compte_phrase') : __('fichier_contient_trop_lignes_maximum', ['n' => IMPORT_MAX_ROWS]), 'error');
            app_redirect('users-import.php');
        }
        $checked = importCheckRows($rows);
        // The checked rows wait in the session for the confirmation; a password given in the file
        // only as its hash.
        $_SESSION['import_rows'] = array_map(static fn (array $row): array => [
            'line' => $row['line'],
            'cells' => [$row['login'], $row['first_name'], $row['last_name'], $row['leaf_label']],
            'password_hash' => $row['error'] === null && $row['password'] !== '' ? password_hash($row['password'], PASSWORD_DEFAULT) : null,
            // Only the lines accepted in the preview can be created (the password is no longer
            // there to be checked again).
            'accepted' => $row['error'] === null,
        ], $checked);
        $step = 'preview';
    } elseif ($action === 'confirm') {
        $pending = $_SESSION['import_rows'] ?? null;
        unset($_SESSION['import_rows']);
        if (!is_array($pending) || $pending === []) {
            app_flash(__('aucun_import_attente_chargez_nouveau_fichier'), 'error');
            app_redirect('users-import.php');
        }
        // Checked again at confirmation time: an account may have been created in between.
        $pending = array_values(array_filter($pending, static fn (mixed $row): bool => is_array($row) && ($row['accepted'] ?? false) === true));
        $valid = array_values(array_filter(importCheckRows($pending), static fn (array $row): bool => $row['error'] === null));
        $givenHashes = [];
        foreach ($pending as $row) {
            if (is_array($row) && is_string($row['password_hash'] ?? null)) {
                $givenHashes[$row['line']] = $row['password_hash'];
            }
        }
        $newAccounts = [];
        foreach ($valid as $row) {
            // Password of the file, or a temporary one shown once.
            if (isset($givenHashes[$row['line']])) {
                $row['password'] = null;
                $row['password_hash'] = $givenHashes[$row['line']];
            } else {
                $row['password'] = importPassword();
                $row['password_hash'] = password_hash($row['password'], PASSWORD_DEFAULT);
            }
            $newAccounts[] = $row;
        }
        update_json_file(app_data_path('users.json'), static function (array $users) use (&$newAccounts): array {
            $taken = [];
            foreach ($users as $user) {
                if (is_array($user) && is_string($user['username'] ?? null)) {
                    $taken[app_username_key($user['username'])] = true;
                }
            }
            $newAccounts = array_values(array_filter($newAccounts, static fn (array $row): bool => !isset($taken[app_username_key($row['login'])])));
            foreach ($newAccounts as $row) {
                $users[] = [
                    'id' => bin2hex(random_bytes(16)),
                    'username' => $row['login'],
                    'password_hash' => $row['password_hash'],
                    'role' => 'musician',
                    'active' => true,
                    'sections' => [$row['leaf']],
                    'default_view' => $row['leaf'],
                ] + array_filter(['first_name' => $row['first_name'], 'last_name' => $row['last_name']], static fn (string $value): bool => $value !== '');
            }
            return $users;
        });
        $created = $newAccounts;
        $_SESSION['import_created'] = array_map(static fn (array $row): array => array_intersect_key($row, array_flip(['login', 'first_name', 'last_name', 'leaf_label', 'password'])), $created);
        $step = 'done';
    } else {
        http_response_code(400);
        exit(__('action_inconnue_phrase'));
    }
}

$validCount = count(array_filter($checked, static fn (array $row): bool => $row['error'] === null));

app_header('Import de comptes', $admin);
?>
      <section class="management-section">
        <div class="eyebrow-row">
          <p class="eyebrow"><span class="eyebrow-line"></span> <?= app_escape(__('configuration_membres_majuscules')) ?></p>
          <?php app_page_links([[__('tous_comptes'), 'users.php'], ['Configuration', 'settings.php']]); ?>
        </div>
        <div class="section-heading">
          <div>
            <h1><?= app_escape(__('import_comptes')) ?><span class="heading-dot">.</span></h1>
          </div>
        </div>
        <?php app_settings_nav('membres', 'users-import.php', $admin); ?>

        <?php if ($step === 'upload'): ?>
        <section class="management-panel" aria-labelledby="import-title">
          <h2 class="detail-heading" id="import-title"><?= app_escape(__('fichier_importer')) ?></h2>
          <p class="muted-copy"><?= app_escape(__('fichier_csv_avec_ligne_compte_ces_label')) ?> <strong><?= app_escape(__('login_prenom_nom_sous_section_principale')) ?></strong><?= app_escape(__('derniere_colonne_facultative_ligne_den_tete')) ?></p>
          <pre class="import-sample"><?= app_escape(__('login_prenom_nom_sous_section_principale_mot_passe')) ?></pre>
          <p class="muted-copy"><?= app_escape(__('comptes_sont_crees_avec_role')) ?> <strong><?= app_escape(__('artistes')) ?></strong><?= app_escape(__('sous_section_comme_unique_section_comme')) ?> <strong><?= app_escape(__('mot_passe_2')) ?></strong> <?= app_escape(__('derniere_colonne_12_caracteres_minimum_espaces')) ?> <strong><?= app_escape(__('mot_passe_temporaire_minuscule')) ?></strong> <?= app_escape(__('genere_affiche_seule_fois_mots_passe')) ?></p>
          <form class="form-stack" method="post" action="users-import.php" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
            <input type="hidden" name="action" value="preview">
            <label for="import-file"><?= app_escape(__('fichier_csv_512_ko_lignes_maximum', ['n' => IMPORT_MAX_ROWS])) ?></label>
            <input id="import-file" name="csv" type="file" accept=".csv,text/csv,text/plain" required>
            <div class="form-actions">
              <button class="primary-button compact-button" type="submit"><?= app_escape(__('verifier_fichier')) ?></button>
              <a class="secondary-button compact-button" href="users.php"><?= app_escape(__('annuler')) ?></a>
            </div>
          </form>
        </section>

        <?php elseif ($step === 'preview'): ?>
        <section class="management-panel" aria-labelledby="preview-title">
          <h2 class="detail-heading" id="preview-title"><?= app_escape(__('verification')) ?> <span class="count-badge"><?= count($checked) ?></span></h2>
          <p class="muted-copy"><?= app_escape(__n('compte_pret_etre_cree', 'comptes_prets_etre_crees', $validCount)) ?><?= $validCount < count($checked) ? app_escape(__n('ligne_ignoree_voir_motif', 'lignes_ignorees_voir_motif', count($checked) - $validCount)) : '' ?>. <?= app_escape(__('rien_nest_encore_enregistre_phrase')) ?></p>
          <div class="log-scroll">
            <table class="log-table import-table">
              <thead><tr><th scope="col"><?= app_escape(__('ligne')) ?></th><th scope="col"><?= app_escape(__('login')) ?></th><th scope="col"><?= app_escape(__('prenom')) ?></th><th scope="col"><?= app_escape(__('nom_2')) ?></th><th scope="col"><?= app_escape(__('sous_section')) ?></th><th scope="col"><?= app_escape(__('mot_passe')) ?></th><th scope="col"><?= app_escape(__('resultat')) ?></th></tr></thead>
              <tbody>
                <?php foreach ($checked as $row): ?>
                <tr class="<?= $row['error'] === null ? 'import-ok' : 'import-error' ?>">
                  <td><?= $row['line'] ?></td>
                  <td><?= app_escape($row['login']) ?></td>
                  <td><?= app_escape($row['first_name']) ?></td>
                  <td><?= app_escape($row['last_name']) ?></td>
                  <td><?= app_escape($row['leaf_label']) ?></td>
                  <td><?= $row['password'] !== '' ? app_escape(__('fourni')) : '<span class="log-sub">' . app_escape(__('genere_creation')) . '</span>' ?></td>
                  <td><?= $row['error'] === null ? '<span class="log-response log-response-present">' . app_escape(__('creer')) . '</span>' : '<span class="log-response log-response-absent">' . app_escape(__('ignoree')) . '</span> <span class="log-sub">' . app_escape($row['error']) . '</span>' ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <form class="form-actions import-actions" method="post" action="users-import.php">
            <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
            <input type="hidden" name="action" value="confirm">
            <?php if ($validCount > 0): ?>
            <button class="primary-button compact-button" type="submit"><?= app_escape(__n('creer_n_compte', 'creer_n_comptes', $validCount)) ?></button>
            <?php endif; ?>
            <a class="secondary-button compact-button" href="users-import.php"><?= app_escape(__('choisir_autre_fichier')) ?></a>
          </form>
        </section>

        <?php else: ?>
        <section class="management-panel" aria-labelledby="done-title">
          <h2 class="detail-heading" id="done-title"><?= app_escape(__('comptes_crees')) ?> <span class="count-badge"><?= count($created) ?></span></h2>
          <?php if ($created === []): ?>
          <p class="muted-copy"><?= app_escape(__('aucun_compte_na_ete_cree_logins')) ?></p>
          <?php else: ?>
          <?php $temporaryCount = count(array_filter($created, static fn (array $row): bool => $row['password'] !== null)); ?>
          <?php if ($temporaryCount > 0): ?>
          <p class="flash-message error import-warning"><?= app_escape(__('notez_telechargez_mots_passe_temporaires_maintenant')) ?></p>
          <?php else: ?>
          <p class="muted-copy"><?= app_escape(__('tous_comptes_ont_mot_passe_indique')) ?></p>
          <?php endif; ?>
          <p><a class="primary-button compact-button" href="users-import.php?telecharger=1"><?= app_escape(__('telecharger_identifiants_csv')) ?></a></p>
          <div class="log-scroll">
            <table class="log-table import-table">
              <thead><tr><th scope="col"><?= app_escape(__('login')) ?></th><th scope="col"><?= app_escape(__('prenom')) ?></th><th scope="col"><?= app_escape(__('nom_2')) ?></th><th scope="col"><?= app_escape(__('sous_section')) ?></th><th scope="col"><?= app_escape(__('mot_passe')) ?></th></tr></thead>
              <tbody>
                <?php foreach ($created as $row): ?>
                <tr>
                  <td><?= app_escape($row['login']) ?></td>
                  <td><?= app_escape($row['first_name']) ?></td>
                  <td><?= app_escape($row['last_name']) ?></td>
                  <td><?= app_escape($row['leaf_label']) ?></td>
                  <td><?= $row['password'] !== null ? '<code>' . app_escape($row['password']) . '</code> <span class="log-sub">temporaire</span>' : '<span class="log-sub">Celui du fichier</span>' ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php endif; ?>
          <p class="form-actions"><a class="secondary-button compact-button" href="users.php"><?= app_escape(__('voir_comptes')) ?></a></p>
        </section>
        <?php endif; ?>
      </section>
<?php app_footer(); ?>
