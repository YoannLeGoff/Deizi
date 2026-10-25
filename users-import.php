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
    // An optional header row ("Login ; Prénom ; NOM ; …") is skipped.
    if ($rows !== [] && preg_match('/^(login|identifiant)$/i', $rows[0]['cells'][0] ?? '') === 1) {
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
            !app_is_valid_username($login) => 'Login invalide (' . USERNAME_RULE . ').',
            isset($existing[app_username_key($login)]) => 'Ce login existe déjà.',
            isset($seen[app_username_key($login)]) => 'Login en double dans le fichier.',
            $leafInput === '' => 'Sous-section principale manquante.',
            $leaf === null => 'Sous-section « ' . $leafInput . ' » introuvable.',
            $password !== '' && (strlen($password) < 12 || strlen($password) > 200) => 'Mot de passe trop court (12 caractères minimum).',
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
        app_flash('Les mots de passe ne sont plus disponibles : ils ne sont téléchargeables qu’une fois, juste après l’import.', 'error');
        app_redirect('users-import.php');
    }
    app_send_csv(
        'comptes-importes-' . date('Y-m-d') . '.csv',
        ['Login', 'Prénom', 'NOM', 'Sous-section principale', 'Mot de passe temporaire'],
        array_map(static fn (array $account): array => [$account['login'], $account['first_name'], $account['last_name'], $account['leaf_label'], $account['password'] ?? 'Fourni dans le fichier importé'], $created)
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
            app_flash('Choisissez un fichier CSV à importer.', 'error');
            app_redirect('users-import.php');
        }
        if (($file['size'] ?? 0) > IMPORT_MAX_BYTES) {
            app_flash('Le fichier est trop volumineux (512 Ko maximum).', 'error');
            app_redirect('users-import.php');
        }
        $rows = importReadRows((string) file_get_contents($file['tmp_name']));
        if ($rows === [] || count($rows) > IMPORT_MAX_ROWS) {
            app_flash($rows === [] ? 'Le fichier ne contient aucune ligne de compte.' : 'Le fichier contient trop de lignes (' . IMPORT_MAX_ROWS . ' au maximum).', 'error');
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
            app_flash('Aucun import en attente : chargez à nouveau le fichier.', 'error');
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
        exit('Action inconnue.');
    }
}

$validCount = count(array_filter($checked, static fn (array $row): bool => $row['error'] === null));

app_header('Import de comptes', $admin);
?>
      <section class="management-section">
        <div class="eyebrow-row">
          <p class="eyebrow"><span class="eyebrow-line"></span> CONFIGURATION · MEMBRES</p>
          <?php app_page_links([['Tous les comptes', 'users.php'], ['Configuration', 'settings.php']]); ?>
        </div>
        <div class="section-heading">
          <div>
            <h1>Import de comptes<span class="heading-dot">.</span></h1>
          </div>
        </div>
        <?php app_settings_nav('membres', 'users-import.php', $admin); ?>

        <?php if ($step === 'upload'): ?>
        <section class="management-panel" aria-labelledby="import-title">
          <h2 class="detail-heading" id="import-title">Fichier à importer</h2>
          <p class="muted-copy">Un fichier CSV avec une ligne par compte et ces colonnes, dans cet ordre : <strong>Login ; Prénom ; NOM ; Sous-section principale ; Mot de passe</strong>. La dernière colonne est facultative. La ligne d’en-tête est facultative ; le séparateur peut être « ; » ou « , » (export Excel accepté). La sous-section s’écrit avec son nom ou son code (par exemple « Cornemuses » ou « Corn »).</p>
          <pre class="import-sample">Login;Prénom;NOM;Sous-section principale;Mot de passe
anna.k;Anna;KERMARREC;Cornemuses;Exemple-de-mot-de-passe
loig;Loïg;LE MOAL;Bombardes;</pre>
          <p class="muted-copy">Les comptes sont créés avec le rôle <strong>Artistes</strong>, la sous-section comme unique section et comme vue par défaut, et le <strong>mot de passe</strong> de la dernière colonne (12 caractères minimum ; les espaces en début et en fin sont ignorés). Si elle est vide, un <strong>mot de passe temporaire</strong> est généré et affiché une seule fois. Les mots de passe du fichier ne sont jamais réaffichés : supprimez le fichier une fois l’import fait. Chacun pourra ensuite changer son mot de passe dans « Mon compte ».</p>
          <form class="form-stack" method="post" action="users-import.php" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
            <input type="hidden" name="action" value="preview">
            <label for="import-file">Fichier CSV (512 Ko et <?= IMPORT_MAX_ROWS ?> lignes au maximum)</label>
            <input id="import-file" name="csv" type="file" accept=".csv,text/csv,text/plain" required>
            <div class="form-actions">
              <button class="primary-button compact-button" type="submit">Vérifier le fichier</button>
              <a class="secondary-button compact-button" href="users.php">Annuler</a>
            </div>
          </form>
        </section>

        <?php elseif ($step === 'preview'): ?>
        <section class="management-panel" aria-labelledby="preview-title">
          <h2 class="detail-heading" id="preview-title">Vérification <span class="count-badge"><?= count($checked) ?></span></h2>
          <p class="muted-copy"><strong><?= $validCount ?></strong> compte<?= $validCount > 1 ? 's' : '' ?> prêt<?= $validCount > 1 ? 's' : '' ?> à être créé<?= $validCount > 1 ? 's' : '' ?><?= $validCount < count($checked) ? ' ; ' . (count($checked) - $validCount) . ' ligne' . (count($checked) - $validCount > 1 ? 's' : '') . ' ignorée' . (count($checked) - $validCount > 1 ? 's' : '') . ' (voir le motif)' : '' ?>. Rien n’est encore enregistré.</p>
          <div class="log-scroll">
            <table class="log-table import-table">
              <thead><tr><th scope="col">Ligne</th><th scope="col">Login</th><th scope="col">Prénom</th><th scope="col">NOM</th><th scope="col">Sous-section</th><th scope="col">Mot de passe</th><th scope="col">Résultat</th></tr></thead>
              <tbody>
                <?php foreach ($checked as $row): ?>
                <tr class="<?= $row['error'] === null ? 'import-ok' : 'import-error' ?>">
                  <td><?= $row['line'] ?></td>
                  <td><?= app_escape($row['login']) ?></td>
                  <td><?= app_escape($row['first_name']) ?></td>
                  <td><?= app_escape($row['last_name']) ?></td>
                  <td><?= app_escape($row['leaf_label']) ?></td>
                  <td><?= $row['password'] !== '' ? 'Fourni' : '<span class="log-sub">Généré à la création</span>' ?></td>
                  <td><?= $row['error'] === null ? '<span class="log-response log-response-present">À créer</span>' : '<span class="log-response log-response-absent">Ignorée</span> <span class="log-sub">' . app_escape($row['error']) . '</span>' ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <form class="form-actions import-actions" method="post" action="users-import.php">
            <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
            <input type="hidden" name="action" value="confirm">
            <?php if ($validCount > 0): ?>
            <button class="primary-button compact-button" type="submit">Créer <?= $validCount ?> compte<?= $validCount > 1 ? 's' : '' ?></button>
            <?php endif; ?>
            <a class="secondary-button compact-button" href="users-import.php">Choisir un autre fichier</a>
          </form>
        </section>

        <?php else: ?>
        <section class="management-panel" aria-labelledby="done-title">
          <h2 class="detail-heading" id="done-title">Comptes créés <span class="count-badge"><?= count($created) ?></span></h2>
          <?php if ($created === []): ?>
          <p class="muted-copy">Aucun compte n’a été créé : les logins ont été pris entre-temps ou les lignes n’étaient plus valides.</p>
          <?php else: ?>
          <?php $temporaryCount = count(array_filter($created, static fn (array $row): bool => $row['password'] !== null)); ?>
          <?php if ($temporaryCount > 0): ?>
          <p class="flash-message error import-warning">Notez ou téléchargez les mots de passe temporaires maintenant : ils ne seront plus affichés. Transmettez à chacun son login et son mot de passe par un canal sûr.</p>
          <?php else: ?>
          <p class="muted-copy">Tous les comptes ont le mot de passe indiqué dans le fichier. Pensez à supprimer ce fichier, qui contient des mots de passe en clair.</p>
          <?php endif; ?>
          <p><a class="primary-button compact-button" href="users-import.php?telecharger=1">Télécharger les identifiants (CSV)</a></p>
          <div class="log-scroll">
            <table class="log-table import-table">
              <thead><tr><th scope="col">Login</th><th scope="col">Prénom</th><th scope="col">NOM</th><th scope="col">Sous-section</th><th scope="col">Mot de passe</th></tr></thead>
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
          <p class="form-actions"><a class="secondary-button compact-button" href="users.php">Voir les comptes</a></p>
        </section>
        <?php endif; ?>
      </section>
<?php app_footer(); ?>
