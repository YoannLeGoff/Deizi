<?php
declare(strict_types=1);

// "Mon compte": every member changes their own login and password (current password required).
// A change of login goes to the history of entries; a change of password logs the other devices
// kept logged in ("Rester connecté") out, this one staying logged in.
require __DIR__ . '/lib/bootstrap.php';
$user = app_require_login();
// An administrator acting as a member does not change the member's password.
$impersonating = app_impersonator() !== null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'username') {
    app_verify_csrf();
    $current = $_POST['current_password'] ?? '';
    $input = $_POST['username'] ?? '';
    $userId = (string) $user['id'];
    $newUsername = is_string($input) ? app_normalize_username($input) : '';
    $oldUsername = (string) $user['username'];
    $error = null;
    if ($impersonating) {
        $error = 'Vous agissez en tant que ce membre : son identifiant ne peut pas être changé ainsi.';
    } elseif (!is_string($current) || !app_is_valid_username($newUsername)) {
        $error = 'Identifiant invalide (' . USERNAME_RULE . ').';
    } elseif ($newUsername === $oldUsername) {
        $error = 'C’est déjà votre identifiant.';
    }
    if ($error === null) {
        update_json_file(app_data_path('users.json'), static function (array $users) use ($userId, $current, $newUsername, $oldUsername, &$error): array {
            $index = null;
            foreach ($users as $position => $account) {
                if (!is_array($account) || !is_string($account['username'] ?? null)) {
                    continue;
                }
                if (($account['id'] ?? null) === $userId) {
                    $index = $position;
                } elseif (app_username_key($account['username']) === app_username_key($newUsername)) {
                    // Logins are unique regardless of case and accents.
                    $error = 'Cet identifiant est déjà utilisé par un autre compte.';
                    return $users;
                }
            }
            if ($index === null) {
                $error = 'Ce compte n’existe plus.';
                return $users;
            }
            if (!is_string($users[$index]['password_hash'] ?? null) || !password_verify($current, $users[$index]['password_hash'])) {
                $error = 'Le mot de passe actuel est incorrect.';
                return $users;
            }
            $users[$index]['username'] = $newUsername;
            // Traced in the history of entries, under the same lock as the change.
            app_attendance_log_append([app_account_log_entry($users[$index], 'rename', $oldUsername, $newUsername, $users[$index])]);
            return $users;
        });
    }
    if ($error !== null) {
        usleep(800000);
        app_flash($error, 'error');
        app_redirect('account.php');
    }
    app_flash('Votre identifiant est maintenant « ' . $newUsername . ' ». Utilisez-le pour vous connecter.');
    app_redirect('account.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    app_verify_csrf();
    $current = $_POST['current_password'] ?? '';
    $new = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    $userId = (string) $user['id'];
    $error = null;
    if ($impersonating) {
        $error = 'Vous agissez en tant que ce membre' . "\u{00A0}" . ': son mot de passe ne peut pas être changé ainsi.';
    } elseif (!is_string($current) || !is_string($new) || !is_string($confirm)) {
        $error = 'Demande invalide.';
    } elseif (strlen($new) < 12 || strlen($new) > 200) {
        $error = 'Le nouveau mot de passe doit compter 12 caractères au moins.';
    } elseif ($new !== $confirm) {
        $error = 'Les deux saisies du nouveau mot de passe ne sont pas identiques.';
    } elseif ($new === $current) {
        $error = 'Le nouveau mot de passe doit être différent de l’actuel.';
    }
    if ($error === null) {
        update_json_file(app_data_path('users.json'), static function (array $users) use ($userId, $current, $new, &$error): array {
            foreach ($users as &$account) {
                if (!is_array($account) || ($account['id'] ?? null) !== $userId) {
                    continue;
                }
                if (!is_string($account['password_hash'] ?? null) || !password_verify($current, $account['password_hash'])) {
                    $error = 'Le mot de passe actuel est incorrect.';
                    return $users;
                }
                $account['password_hash'] = password_hash($new, PASSWORD_DEFAULT);
                return $users;
            }
            $error = 'Ce compte n’existe plus.';
            return $users;
        });
    }
    if ($error !== null) {
        // Slows down repeated guesses of the current password.
        usleep(800000);
        app_flash($error, 'error');
        app_redirect('account.php');
    }
    // Every "Rester connecté" of the account ends; this device keeps it if it had one.
    $remembered = is_string($_COOKIE[REMEMBER_COOKIE] ?? null);
    app_remember_forget_user($userId);
    if ($remembered) {
        app_remember_issue($userId);
    }
    session_regenerate_id(true);
    app_flash('Votre mot de passe a été changé. Vos autres appareils devront se reconnecter.');
    app_redirect('account.php');
}

app_header('Mon compte', $user);
?>
      <section class="management-section">
        <div class="eyebrow-row">
          <p class="eyebrow"><span class="eyebrow-line"></span> MON COMPTE</p>
          <?php app_page_links([['Retour à l’agenda', 'index.php']]); ?>
        </div>
        <div class="section-heading">
          <div>
            <h1>Mon compte<span class="heading-dot">.</span></h1>
          </div>
        </div>

        <section class="management-panel account-self" aria-labelledby="account-identity-title">
          <h2 class="detail-heading" id="account-identity-title"><?= app_escape((string) $user['username']) ?></h2>
          <p class="muted-copy"><?= app_escape(app_user_role_label($user)) ?> · <?= app_escape(app_describe_sections(app_user_sections($user))) ?></p>
          <p class="muted-copy">Votre rôle et vos sections sont gérés par un organisateur ou un administrateur.</p>
        </section>

        <?php app_photo_panel($user, $user, 'account.php', 'Ma photo'); ?>

        <section class="management-panel account-self" aria-labelledby="account-username-title">
          <h2 class="detail-heading" id="account-username-title">Changer mon identifiant</h2>
          <?php if ($impersonating): ?>
          <p class="muted-copy">Vous agissez en tant que ce membre<?= "\u{00A0}" ?>: son identifiant ne peut pas être changé ainsi.</p>
          <?php else: ?>
          <form method="post" action="account.php" class="form-stack account-password-form">
            <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
            <input type="hidden" name="action" value="username">
            <label for="new-username">Nouvel identifiant</label>
            <input id="new-username" name="username" required minlength="3" maxlength="32" autocomplete="username" value="<?= app_escape((string) $user['username']) ?>">
            <p class="field-help"><?= app_escape(ucfirst(USERNAME_RULE)) ?>. Les majuscules et les accents ne comptent pas à la connexion.</p>
            <label for="username-password">Mot de passe actuel</label>
            <div class="password-field">
              <input id="username-password" name="current_password" type="password" required maxlength="200" autocomplete="current-password">
              <button class="password-toggle" type="button" data-password-toggle aria-controls="username-password" aria-label="Afficher le mot de passe" aria-pressed="false">Afficher</button>
            </div>
            <p class="field-help">Le changement est noté dans le journal d’audit, avec l’ancien et le nouvel identifiant.</p>
            <div class="form-actions">
              <button class="primary-button" type="submit">Changer mon identifiant</button>
            </div>
          </form>
          <?php endif; ?>
        </section>

        <section class="management-panel account-self" aria-labelledby="account-password-title">
          <h2 class="detail-heading" id="account-password-title">Changer mon mot de passe</h2>
          <?php if ($impersonating): ?>
          <p class="muted-copy">Vous agissez en tant que ce membre<?= "\u{00A0}" ?>: son mot de passe se change depuis sa fiche dans Comptes.</p>
          <?php else: ?>
          <form method="post" action="account.php" class="form-stack account-password-form">
            <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
            <input type="hidden" name="action" value="password">
            <input type="text" name="username" value="<?= app_escape((string) $user['username']) ?>" autocomplete="username" hidden>
            <label for="current-password">Mot de passe actuel</label>
            <div class="password-field">
              <input id="current-password" name="current_password" type="password" required maxlength="200" autocomplete="current-password">
              <button class="password-toggle" type="button" data-password-toggle aria-controls="current-password" aria-label="Afficher le mot de passe" aria-pressed="false">Afficher</button>
            </div>
            <label for="new-password">Nouveau mot de passe (12 caractères minimum)</label>
            <div class="password-field">
              <input id="new-password" name="new_password" type="password" required minlength="12" maxlength="200" autocomplete="new-password">
              <button class="password-toggle" type="button" data-password-toggle aria-controls="new-password" aria-label="Afficher le mot de passe" aria-pressed="false">Afficher</button>
            </div>
            <label for="confirm-password">Nouveau mot de passe, une seconde fois</label>
            <div class="password-field">
              <input id="confirm-password" name="confirm_password" type="password" required minlength="12" maxlength="200" autocomplete="new-password">
              <button class="password-toggle" type="button" data-password-toggle aria-controls="confirm-password" aria-label="Afficher le mot de passe" aria-pressed="false">Afficher</button>
            </div>
            <p class="field-help">Après le changement, vos autres appareils (téléphone, ordinateur) devront se reconnecter avec le nouveau mot de passe.</p>
            <div class="form-actions">
              <button class="primary-button" type="submit">Changer mon mot de passe</button>
            </div>
          </form>
          <?php endif; ?>
        </section>
      </section>
<?php app_footer(); ?>
