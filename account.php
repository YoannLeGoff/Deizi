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
        $error = __('vous_agissez_tant_ce_membre_identifiant');
    } elseif (!is_string($current) || !app_is_valid_username($newUsername)) {
        $error = __('identifiant_invalide_rule_phrase', ['rule' => app_label(USERNAME_RULE)]);
    } elseif ($newUsername === $oldUsername) {
        $error = __('cest_deja_votre_identifiant_phrase');
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
                    $error = __('cet_identifiant_deja_utilise_autre_compte');
                    return $users;
                }
            }
            if ($index === null) {
                $error = __('ce_compte_nexiste_plus_phrase');
                return $users;
            }
            if (!is_string($users[$index]['password_hash'] ?? null) || !password_verify($current, $users[$index]['password_hash'])) {
                $error = __('mot_passe_actuel_incorrect_phrase');
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

// Language of the interface for this member ("" = the default language of the site).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'language') {
    app_verify_csrf();
    $language = app_is_language($_POST['language'] ?? null) ? $_POST['language'] : '';
    $userId = (string) $user['id'];
    update_json_file(app_data_path('users.json'), static function (array $users) use ($userId, $language): array {
        foreach ($users as &$account) {
            if (is_array($account) && ($account['id'] ?? null) === $userId) {
                if ($language === '') {
                    unset($account['language']);
                } else {
                    $account['language'] = $language;
                }
            }
        }
        unset($account);
        return $users;
    });
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
        $error = __('vous_agissez_tant_ce_membre_mot');
    } elseif (!is_string($current) || !is_string($new) || !is_string($confirm)) {
        $error = __('demande_invalide_phrase');
    } elseif (strlen($new) < 12 || strlen($new) > 200) {
        $error = __('nouveau_mot_passe_doit_compter_12');
    } elseif ($new !== $confirm) {
        $error = __('deux_saisies_nouveau_mot_passe_sont');
    } elseif ($new === $current) {
        $error = __('nouveau_mot_passe_doit_etre_different');
    }
    if ($error === null) {
        update_json_file(app_data_path('users.json'), static function (array $users) use ($userId, $current, $new, &$error): array {
            foreach ($users as &$account) {
                if (!is_array($account) || ($account['id'] ?? null) !== $userId) {
                    continue;
                }
                if (!is_string($account['password_hash'] ?? null) || !password_verify($current, $account['password_hash'])) {
                    $error = __('mot_passe_actuel_incorrect_phrase');
                    return $users;
                }
                $account['password_hash'] = password_hash($new, PASSWORD_DEFAULT);
                return $users;
            }
            $error = __('ce_compte_nexiste_plus_phrase');
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
    app_flash(__('votre_mot_passe_ete_change_vos'));
    app_redirect('account.php');
}

app_header('Mon compte', $user);
?>
      <section class="management-section">
        <div class="eyebrow-row">
          <p class="eyebrow"><span class="eyebrow-line"></span> <?= app_escape(mb_strtoupper(__('mon_compte'))) ?></p>
          <?php app_page_links([['Retour à l’agenda', 'index.php']]); ?>
        </div>
        <div class="section-heading">
          <div>
            <h1><?= app_escape(__('mon_compte')) ?><span class="heading-dot">.</span></h1>
          </div>
        </div>

        <section class="management-panel account-self" aria-labelledby="account-identity-title">
          <h2 class="detail-heading" id="account-identity-title"><?= app_escape((string) $user['username']) ?></h2>
          <p class="muted-copy"><?= app_escape(app_user_role_label($user)) ?> · <?= app_escape(app_describe_sections(app_user_sections($user))) ?></p>
          <p class="muted-copy"><?= app_escape(__('votre_role_vos_sections_sont_geres')) ?></p>
        </section>

        <section class="management-panel account-self" aria-labelledby="account-language-title">
          <h2 class="detail-heading" id="account-language-title"><?= app_escape(__('langue')) ?></h2>
          <form method="post" action="account.php" class="form-stack account-password-form">
            <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
            <input type="hidden" name="action" value="language">
            <label for="account-language"><?= app_escape(__('langue_lapplication')) ?></label>
            <select id="account-language" name="language">
              <option value=""><?= app_escape(__('langue_site_language', ['language' => app_languages()[app_site_language()]])) ?></option>
              <?php foreach (app_languages() as $code => $name): ?>
              <option value="<?= $code ?>" lang="<?= $code ?>"<?= ($user['language'] ?? '') === $code ? ' selected' : '' ?>><?= app_escape($name) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="form-actions">
              <button class="primary-button" type="submit"><?= app_escape(__('enregistrer')) ?></button>
            </div>
          </form>
        </section>

        <?php app_photo_panel($user, $user, 'account.php', __('ma_photo')); ?>

        <section class="management-panel account-self" aria-labelledby="account-username-title">
          <h2 class="detail-heading" id="account-username-title"><?= app_escape(__('changer_mon_identifiant')) ?></h2>
          <?php if ($impersonating): ?>
          <p class="muted-copy"><?= app_escape(__('vous_agissez_tant_ce_membre_identifiant')) ?></p>
          <?php else: ?>
          <form method="post" action="account.php" class="form-stack account-password-form">
            <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
            <input type="hidden" name="action" value="username">
            <label for="new-username"><?= app_escape(__('nouvel_identifiant')) ?></label>
            <input id="new-username" name="username" required minlength="3" maxlength="32" autocomplete="username" value="<?= app_escape((string) $user['username']) ?>">
            <p class="field-help"><?= app_escape(app_ucfirst(app_label(USERNAME_RULE))) ?>. <?= app_escape(__('majuscules_accents_comptent_connexion')) ?></p>
            <label for="username-password"><?= app_escape(__('mot_passe_actuel')) ?></label>
            <div class="password-field">
              <input id="username-password" name="current_password" type="password" required maxlength="200" autocomplete="current-password">
              <button class="password-toggle" type="button" data-password-toggle aria-controls="username-password" aria-label="<?= app_escape(__('afficher_mot_passe')) ?>" aria-pressed="false"><?= app_escape(__('afficher')) ?></button>
            </div>
            <p class="field-help"><?= app_escape(__('changement_note_dans_journal_daudit_avec')) ?></p>
            <div class="form-actions">
              <button class="primary-button" type="submit"><?= app_escape(__('changer_mon_identifiant')) ?></button>
            </div>
          </form>
          <?php endif; ?>
        </section>

        <section class="management-panel account-self" aria-labelledby="account-password-title">
          <h2 class="detail-heading" id="account-password-title"><?= app_escape(__('changer_mon_mot_passe')) ?></h2>
          <?php if ($impersonating): ?>
          <p class="muted-copy"><?= app_escape(__('vous_agissez_tant_ce_membre_mot_passe_change')) ?></p>
          <?php else: ?>
          <form method="post" action="account.php" class="form-stack account-password-form">
            <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
            <input type="hidden" name="action" value="password">
            <input type="text" name="username" value="<?= app_escape((string) $user['username']) ?>" autocomplete="username" hidden>
            <label for="current-password"><?= app_escape(__('mot_passe_actuel')) ?></label>
            <div class="password-field">
              <input id="current-password" name="current_password" type="password" required maxlength="200" autocomplete="current-password">
              <button class="password-toggle" type="button" data-password-toggle aria-controls="current-password" aria-label="<?= app_escape(__('afficher_mot_passe')) ?>" aria-pressed="false"><?= app_escape(__('afficher')) ?></button>
            </div>
            <label for="new-password"><?= app_escape(__('nouveau_mot_passe_12_caracteres_minimum')) ?></label>
            <div class="password-field">
              <input id="new-password" name="new_password" type="password" required minlength="12" maxlength="200" autocomplete="new-password">
              <button class="password-toggle" type="button" data-password-toggle aria-controls="new-password" aria-label="<?= app_escape(__('afficher_mot_passe')) ?>" aria-pressed="false"><?= app_escape(__('afficher')) ?></button>
            </div>
            <label for="confirm-password"><?= app_escape(__('nouveau_mot_passe_seconde_fois')) ?></label>
            <div class="password-field">
              <input id="confirm-password" name="confirm_password" type="password" required minlength="12" maxlength="200" autocomplete="new-password">
              <button class="password-toggle" type="button" data-password-toggle aria-controls="confirm-password" aria-label="<?= app_escape(__('afficher_mot_passe')) ?>" aria-pressed="false"><?= app_escape(__('afficher')) ?></button>
            </div>
            <p class="field-help"><?= app_escape(__('apres_changement_vos_autres_appareils_telephone')) ?></p>
            <div class="form-actions">
              <button class="primary-button" type="submit"><?= app_escape(__('changer_mon_mot_passe')) ?></button>
            </div>
          </form>
          <?php endif; ?>
        </section>
      </section>
<?php app_footer(); ?>
