<?php
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

if (app_current_user() !== null) {
    app_redirect('index.php');
}

$error = __('');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    app_verify_csrf();
    $usernameInput = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    if (!is_string($usernameInput) || !is_string($password)) {
        http_response_code(400);
        exit(__('identifiant_mot_passe_invalide_phrase'));
    }
    $username = app_normalize_username($usernameInput);
    // Account named in the form, for the audit journal (only existing accounts are recorded).
    $namedAccount = null;
    foreach (read_json_file(app_data_path('users.json')) as $candidate) {
        if (is_array($candidate) && is_string($candidate['username'] ?? null) && is_string($candidate['id'] ?? null)
            && hash_equals(app_username_key($candidate['username']), app_username_key($username))) {
            $namedAccount = $candidate;
        }
    }
    if (app_login_is_throttled($username)) {
        http_response_code(429);
        $error = __('trop_tentatives_connexion_reessayez_dans_15');
        if ($namedAccount !== null) {
            app_login_log_record($namedAccount, 'throttled');
        }
    }
    $users = $error === '' ? read_json_file(app_data_path('users.json')) : [];
    foreach ($users as $user) {
        if (
            is_array($user)
            && ($user['active'] ?? false) === true
            && isset($user['username'], $user['password_hash'], $user['id'])
            && is_string($user['username'])
            && is_string($user['password_hash'])
            && is_string($user['id'])
            && hash_equals(app_username_key($user['username']), app_username_key($username))
            && password_verify($password, $user['password_hash'])
        ) {
            app_login_record_attempt($username, true);
            app_login_log_record($user, 'login');
            app_adopt_site_language($user);
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            unset($_SESSION['csrf_token']);
            if (($_POST['remember'] ?? '') === '1') {
                app_remember_issue($user['id']);
            }
            // Back to the page asked for before logging in (a date opened from the calendar…).
            $target = app_safe_return_path($_SESSION['after_login'] ?? null) ?? 'index.php';
            unset($_SESSION['after_login']);
            app_redirect($target);
        }
    }

    if ($error === '') {
        app_login_record_attempt($username, false);
        if ($namedAccount !== null) {
            app_login_log_record($namedAccount, 'failed');
        }
        $error = __('identifiant_mot_passe_incorrect_phrase');
    }
}

app_header('Connexion');
?>
      <section class="login-panel" aria-labelledby="login-title">
        <?php if (($loginLogo = app_site_logo_url('login_logo')) !== null): ?>
        <img class="login-logo" src="<?= app_escape($loginLogo) ?>" alt="<?= app_escape(app_site_title()) ?>">
        <?php endif; ?>
        <p class="eyebrow"><span class="eyebrow-line"></span> <?= app_escape(mb_strtoupper(__('acces_membres'))) ?></p>
        <h1 id="login-title"><?= app_escape(__('connexion')) ?></h1>
        <p class="login-intro"><?= app_escape(__('connectez_vous_acceder_lapplication_phrase')) ?></p>
        <?php if ($error !== ''): ?>
        <p class="form-error" role="alert"><?= app_escape($error) ?></p>
        <?php endif; ?>
        <form method="post" action="login.php" class="form-stack">
          <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
          <label for="username"><?= app_escape(__('identifiant')) ?></label>
          <input id="username" name="username" type="text" autocomplete="username" required maxlength="32" autofocus>
          <label for="password"><?= app_escape(__('mot_passe')) ?></label>
          <input id="password" name="password" type="password" autocomplete="current-password" required>
          <?php // Ticked by menu.js in the installed application, where closing it ends the session. ?>
          <label class="active-toggle login-remember"><input type="checkbox" name="remember" value="1" data-remember> <?= app_escape(__('rester_connecte_cet_appareil')) ?></label>
          <p class="field-help login-remember-help"><?= app_escape(__('rester_connecte_90_jours_aide')) ?></p>
          <button class="primary-button" type="submit"><?= app_escape(__('connecter')) ?></button>
        </form>
      </section>
<?php app_footer(); ?>
