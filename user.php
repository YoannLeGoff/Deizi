<?php
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';
// Administrators manage every account. Organizers create accounts and edit artists and managers
// (role among these two, sections, scope, flags); they do not deactivate accounts, change passwords,
// renew iCal links or log in as another member. Managers only reach the photo and the login of
// the artists and managers of their scope (from the Trombinoscope). Changes of login, first name,
// last name, title and photo go to the audit journal, shown to administrators in a tab.
$actor = app_require_roles(EVENT_MANAGER_ROLES);
$actorIsAdmin = $actor['role'] === 'admin';
$profileOnly = !in_array($actor['role'], ACCOUNT_MANAGER_ROLES, true);
$usersPath = app_data_path('users.json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    app_verify_csrf();
    $action = $_POST['action'] ?? '';
    $postedId = $_POST['id'] ?? '';

    // Change of login: administrators, organizers and logisticians (accounts they edit), managers (their scope).
    if ($action === 'rename') {
        $input = $_POST['username'] ?? '';
        $newUsername = is_string($input) ? app_normalize_username($input) : '';
        $returnUrl = 'user.php?id=' . rawurlencode(is_string($postedId) ? $postedId : '') . '#identifiant';
        $error = null;
        if (app_impersonator() !== null) {
            $error = 'L’identifiant ne se change pas en agissant en tant qu’un membre.';
        } elseif (!is_string($postedId) || $postedId === '' || !app_is_valid_username($newUsername)) {
            $error = 'Identifiant invalide (' . USERNAME_RULE . ').';
        } else {
            update_json_file($usersPath, static function (array $users) use ($actor, $postedId, $newUsername, &$error): array {
                $index = null;
                foreach ($users as $position => $account) {
                    if (!is_array($account) || !is_string($account['username'] ?? null)) {
                        continue;
                    }
                    if (($account['id'] ?? null) === $postedId) {
                        $index = $position;
                    } elseif (app_username_key($account['username']) === app_username_key($newUsername)) {
                        $error = 'Cet identifiant est déjà utilisé par un autre compte.';
                        return $users;
                    }
                }
                if ($index === null) {
                    $error = 'Ce compte n’existe plus.';
                } elseif (!app_can_rename_account($actor, $users[$index])) {
                    $error = 'Vous ne pouvez pas modifier l’identifiant de ce compte.';
                } elseif ($users[$index]['username'] === $newUsername) {
                    $error = 'C’est déjà l’identifiant de ce compte.';
                } else {
                    $oldUsername = (string) $users[$index]['username'];
                    $users[$index]['username'] = $newUsername;
                    // Traced in the audit journal, under the same lock as the change.
                    app_attendance_log_append([app_account_log_entry($users[$index], 'rename', $oldUsername, $newUsername, $actor)]);
                }
                return $users;
            });
        }
        app_flash($error ?? 'L’identifiant est maintenant «' . "\u{00A0}" . $newUsername . "\u{00A0}" . '». Transmettez-le à son titulaire.', $error === null ? 'success' : 'error');
        app_redirect($returnUrl);
    }
    if ($profileOnly) {
        http_response_code(403);
        exit('Vous ne pouvez pas modifier ce compte.');
    }
    $returnUrl = $action === 'save' && is_string($postedId) && $postedId !== ''
        ? 'user.php?id=' . rawurlencode($postedId)
        : 'user.php';
    $roles = app_assignable_roles($actor);
    $selectedSections = app_normalize_sections($_POST['sections'] ?? []);
    // Default agenda view: a branch or every branch, independent of the account's sections.
    $defaultView = app_normalize_default_view($_POST['default_view'] ?? '');
    $managedSections = app_normalize_managed_sections($_POST['managed_sections'] ?? []);
    $flags = [];
    foreach (array_keys(ACCOUNT_FLAGS) as $flag) {
        $flags[$flag] = ($_POST[$flag] ?? '') === '1';
    }
    // Optional: first name, last name and gender (removed from the account when left empty).
    $identity = app_account_identity_input($_POST);
    // Title shown instead of the role: administrators only (null: left as it is).
    $title = $actorIsAdmin ? app_clean_line($_POST['title'] ?? '', USER_TITLE_MAX_LENGTH) : null;
    // "Responsable de section": only among the sub-sections of the account.
    $sectionLeads = array_values(array_intersect(array_filter((array) ($_POST['section_leads'] ?? []), 'is_string'), app_expand_sections($selectedSections)));
    if (($_POST['role'] ?? '') === 'manager' && $managedSections === []) {
        app_flash('Précisez le périmètre de management : au moins une branche entière ou une sous-section.', 'error');
        app_redirect($returnUrl);
    }

    if ($action === 'create') {
        $usernameInput = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? '';
        if (!is_string($usernameInput) || !is_string($password) || !is_string($role)) {
            http_response_code(400);
            exit('Données de compte invalides.');
        }
        $username = app_normalize_username($usernameInput);
        if (!app_is_valid_username($username)) {
            app_flash('Identifiant invalide (' . USERNAME_RULE . ').', 'error');
            app_redirect('user.php');
        }
        if (strlen($password) < 12 || strlen($password) > 200 || !in_array($role, $roles, true) || $selectedSections === [] || $defaultView === '') {
            app_flash('Identifiant ou rôle invalide. Sélectionnez au moins une section, une vue par défaut et un mot de passe d’au moins 12 caractères.', 'error');
            app_redirect('user.php');
        }

        $newId = bin2hex(random_bytes(16));
        $created = false;
        update_json_file($usersPath, static function (array $users) use ($newId, $username, $password, $role, $selectedSections, $defaultView, $managedSections, $flags, $identity, $title, $sectionLeads, &$created): array {
            foreach ($users as $user) {
                if (is_array($user) && isset($user['username']) && app_username_key((string) $user['username']) === app_username_key($username)) {
                    return $users;
                }
            }
            $newUser = [
                'id' => $newId,
                'username' => $username,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'role' => $role,
                'active' => true,
                'sections' => $selectedSections,
                'default_view' => $defaultView,
            ] + array_filter($identity, static fn (string $value): bool => $value !== '') + $flags;
            if ($role === 'manager') {
                $newUser['managed_sections'] = $managedSections;
            }
            if ($title !== null && $title !== '') {
                $newUser['title'] = $title;
            }
            if ($sectionLeads !== []) {
                $newUser['section_leads'] = $sectionLeads;
                $users = app_release_section_leads($users, $sectionLeads, $newId);
            }
            $users[] = $newUser;
            $created = true;
            return $users;
        });
        if (!$created) {
            app_flash('Cet identifiant existe déjà.', 'error');
            app_redirect('user.php');
        }
        app_flash('Le compte a été créé. Transmettez l’identifiant et le mot de passe à son titulaire.');
        app_redirect('user.php?id=' . rawurlencode($newId));
    }

    if ($action === 'save') {
        $userId = $postedId;
        $role = $_POST['role'] ?? '';
        $active = ($_POST['active'] ?? '') === '1';
        $password = $_POST['password'] ?? '';
        if (!$actorIsAdmin && $password !== '') {
            app_flash('Seul un administrateur peut changer le mot de passe d’un compte.', 'error');
            app_redirect($returnUrl);
        }
        if (!is_string($userId) || !is_string($role) || !is_string($password) || $userId === '' || !in_array($role, $roles, true) || $selectedSections === [] || $defaultView === '' || ($password !== '' && (strlen($password) < 12 || strlen($password) > 200))) {
            app_flash('Vérifiez le rôle et le mot de passe, sélectionnez au moins une section et une vue par défaut.', 'error');
            app_redirect($returnUrl);
        }

        $saved = false;
        $error = '';
        $before = null;
        update_json_file($usersPath, static function (array $users) use ($actor, $actorIsAdmin, $userId, $role, $active, $password, $selectedSections, $defaultView, $managedSections, $flags, $identity, $title, $sectionLeads, &$saved, &$error, &$before): array {
            $targetIndex = null;
            foreach ($users as $index => $user) {
                if (is_array($user) && ($user['id'] ?? null) === $userId) {
                    $targetIndex = $index;
                    break;
                }
            }
            if ($targetIndex === null) {
                $error = 'Ce compte n’existe plus.';
                return $users;
            }

            $target = $users[$targetIndex];
            if (!app_can_edit_account($actor, $target)) {
                $error = 'Seul un administrateur peut modifier ce compte.';
                return $users;
            }
            if (!$actorIsAdmin) {
                // Organizers cannot deactivate (or reactivate) an account.
                $active = ($target['active'] ?? false) === true;
            }
            if ($userId === $actor['id'] && (!$active || $role !== 'admin')) {
                $error = 'Vous ne pouvez pas désactiver ou rétrograder votre propre compte.';
                return $users;
            }
            $users[$targetIndex]['role'] = $role;
            $users[$targetIndex]['active'] = $active;
            $users[$targetIndex]['sections'] = $selectedSections;
            $users[$targetIndex]['default_view'] = $defaultView;
            unset($users[$targetIndex]['primary_section']);
            foreach ($flags as $flag => $value) {
                $users[$targetIndex][$flag] = $value;
            }
            foreach ($identity as $field => $value) {
                if ($value === '') {
                    unset($users[$targetIndex][$field]);
                } else {
                    $users[$targetIndex][$field] = $value;
                }
            }
            if ($sectionLeads === []) {
                unset($users[$targetIndex]['section_leads']);
            } else {
                $users[$targetIndex]['section_leads'] = $sectionLeads;
                $users = app_release_section_leads($users, $sectionLeads, $userId);
            }
            if ($title === '') {
                unset($users[$targetIndex]['title']);
            } elseif ($title !== null) {
                $users[$targetIndex]['title'] = $title;
            }
            if ($role === 'manager') {
                $users[$targetIndex]['managed_sections'] = $managedSections;
            } else {
                unset($users[$targetIndex]['managed_sections']);
            }
            if ($password !== '') {
                $users[$targetIndex]['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
                unset($users[$targetIndex]['calendar_token']);
            }

            $activeAdmins = array_filter($users, static fn (mixed $user): bool => is_array($user) && ($user['role'] ?? null) === 'admin' && ($user['active'] ?? false) === true);
            if ($activeAdmins === []) {
                $users[$targetIndex] = $target;
                $error = 'Il doit rester au moins un compte administrateur actif.';
                return $users;
            }
            $saved = true;
            $before = $target;
            // First name, last name and title changes go to the audit journal.
            $logEntries = [];
            foreach (['first_name', 'last_name', 'title'] as $field) {
                $old = is_string($target[$field] ?? null) ? $target[$field] : '';
                $new = is_string($users[$targetIndex][$field] ?? null) ? $users[$targetIndex][$field] : '';
                if ($old !== $new) {
                    $logEntries[] = app_account_log_entry($users[$targetIndex], $field, $old, $new, $actor);
                }
            }
            if ($logEntries !== []) {
                app_attendance_log_append($logEntries);
            }
            return $users;
        });

        // A new password or a deactivated account ends the "Rester connecté" of every device.
        if ($saved && ($password !== '' || ($actorIsAdmin && !$active))) {
            app_remember_forget_user($userId);
        }
        // A deactivated account no longer receives push notifications on any device.
        if ($saved && $actorIsAdmin && !$active) {
            app_push_unsubscribe(null, $userId);
        }
        app_flash($saved ? 'Le compte a été mis à jour.' : $error, $saved ? 'success' : 'error');
        app_redirect($error === 'Ce compte n’existe plus.' ? 'users.php' : $returnUrl);
    }

    http_response_code(400);
    exit('Action inconnue.');
}

// One responsable per sub-section: $leaves are taken away from every account but $exceptId.
function app_release_section_leads(array $users, array $leaves, string $exceptId): array
{
    foreach ($users as &$user) {
        if (is_array($user) && ($user['id'] ?? null) !== $exceptId && is_array($user['section_leads'] ?? null)) {
            $user['section_leads'] = array_values(array_diff($user['section_leads'], $leaves));
            if ($user['section_leads'] === []) {
                unset($user['section_leads']);
            }
        }
    }
    unset($user);
    return $users;
}

$requestedId = $_GET['id'] ?? '';
$account = null;
if (is_string($requestedId) && $requestedId !== '') {
    foreach (app_read_users_with_migration() as $storedUser) {
        if (is_array($storedUser) && ($storedUser['id'] ?? null) === $requestedId) {
            $account = $storedUser;
            break;
        }
    }
    if ($account === null) {
        http_response_code(404);
        app_header('Compte introuvable', $actor);
        ?>
      <section class="management-section">
        <p class="management-panel muted-copy">Ce compte n’existe pas. <a class="page-switch" href="users.php">Retour aux comptes</a></p>
      </section>
<?php
        app_footer();
        exit;
    }
}

$isNew = $account === null;
// Managers: only an existing account of their scope.
if ($profileOnly && ($isNew || !app_can_rename_account($actor, $account))) {
    http_response_code(403);
    app_header('Accès refusé', $actor);
    ?>
      <section class="management-section">
        <p class="management-panel muted-copy">Vous ne pouvez modifier que les membres de votre périmètre. <a class="page-switch" href="trombinoscope.php">Retour au trombinoscope</a></p>
      </section>
<?php
    app_footer();
    exit;
}
// Administrators: the account's sheet, or its audit journal (entries about the member or made by them).
$tab = !$isNew && $actorIsAdmin && ($_GET['onglet'] ?? '') === 'journal' ? 'journal' : 'fiche';
$canEdit = $isNew || app_can_edit_account($actor, $account);
$assignableRoles = app_assignable_roles($actor);
$accountId = $isNew ? 'new' : (string) $account['id'];
$accountRole = $isNew ? 'musician' : (string) ($account['role'] ?? 'musician');
$accountSections = $isNew ? [] : app_user_sections($account);
$accountLeaves = app_expand_sections($accountSections);
$defaultView = $isNew ? 'Toutes' : app_user_default_view($account);
// For a non-manager, pre-fill the scope with their own sections in case the role is switched to manager.
$managedSections = $isNew ? [] : app_user_managed_sections(['role' => 'manager'] + $account);
$managedLeaves = app_expand_sections($managedSections);
$sectionLabels = app_section_labels();

app_header($isNew ? 'Nouveau compte' : (string) $account['username'], $actor);
?>
      <section class="management-section">
        <div class="eyebrow-row">
          <p class="eyebrow"><span class="eyebrow-line"></span> <?= $profileOnly ? 'LES MEMBRES · PHOTO ET IDENTIFIANT' : 'CONFIGURATION · COMPTES' ?></p>
          <?php app_page_links($profileOnly ? [['Trombinoscope', 'trombinoscope.php']] : [['Tous les comptes', 'users.php'], ['Configuration', 'settings.php']]); ?>
        </div>
        <div class="section-heading">
          <div>
            <h1><?= $isNew ? 'Nouveau compte' : app_escape((string) $account['username']) ?><span class="heading-dot">.</span></h1>
          </div>
        </div>

        <?php if (!$isNew && $actorIsAdmin): ?>
        <?php
            $accountEntries = array_reverse(array_values(array_filter(app_attendance_log_read(true), static fn (array $entry): bool => ($entry['member_id'] ?? null) === $accountId || ($entry['by_id'] ?? null) === $accountId)));
        ?>
        <nav class="detail-tabs" aria-label="Compte">
          <a class="detail-tab" href="user.php?id=<?= rawurlencode($accountId) ?>"<?= $tab === 'fiche' ? ' aria-current="page"' : '' ?>>Fiche</a>
          <a class="detail-tab" href="user.php?id=<?= rawurlencode($accountId) ?>&amp;onglet=journal"<?= $tab === 'journal' ? ' aria-current="page"' : '' ?>>Journal d’audit <span class="count-badge"><?= count($accountEntries) ?></span></a>
        </nav>
        <?php endif; ?>

        <?php if ($tab === 'journal'): ?>
        <?php
            $existingEventIds = [];
            foreach (read_json_file(app_data_path('events.json')) as $event) {
                if (is_array($event) && is_string($event['id'] ?? null)) {
                    $existingEventIds[$event['id']] = true;
                }
            }
        ?>
        <section class="management-panel">
          <p class="accounts-help">Ce qui concerne ce membre (connexions, réponses, changements d’identifiant, de nom, de titre ou de photo) et ce qu’il a saisi lui-même, du plus récent au plus ancien. <a class="page-switch" href="attendance-log.php?member=<?= rawurlencode($accountId) ?>">Ouvrir dans le journal d’audit</a></p>
          <?php if ($accountEntries === []): ?>
          <p class="muted-copy">Aucune entrée pour ce membre.</p>
          <?php else: ?>
          <?php if (count($accountEntries) > 300): ?><p class="muted-copy">Les 300 entrées les plus récentes sont affichées.</p><?php endif; ?>
          <?php app_render_attendance_log_table(array_slice($accountEntries, 0, 300), true, $existingEventIds); ?>
          <?php endif; ?>
        </section>
      </section>
<?php
    app_footer();
    exit;
endif; ?>

        <?php if (!$isNew && !$profileOnly): ?>
        <section class="management-panel" aria-label="Résumé du compte">
          <dl class="event-detail-list">
            <?php $fullName = trim((string) ($account['first_name'] ?? '') . ' ' . (string) ($account['last_name'] ?? '')); ?>
            <?php if ($fullName !== ''): ?>
            <dt>Nom</dt>
            <dd><?= app_escape($fullName) ?></dd>
            <?php endif; ?>
            <?php if (isset(ACCOUNT_GENDERS[$account['gender'] ?? ''])): ?>
            <dt>Sexe</dt>
            <dd><?= app_escape(ACCOUNT_GENDERS[$account['gender']]) ?></dd>
            <?php endif; ?>
            <?php // Always shown to administrators, who set it (link to the field of the form below). ?>
            <?php if (app_user_title($account) !== '' || $actorIsAdmin): ?>
            <dt>Titre</dt>
            <dd><?= app_user_title($account) !== '' ? app_escape(app_user_title($account)) : '<span class="muted-copy">Aucun</span>' ?><?php if ($actorIsAdmin && $canEdit): ?> <a class="page-switch" href="#account-title"><?= app_user_title($account) !== '' ? 'Modifier' : 'Ajouter un titre' ?></a><?php endif; ?></dd>
            <?php endif; ?>
            <dt>Rôle</dt>
            <dd><?= app_escape(USER_ROLES[$accountRole] ?? $accountRole) ?></dd>
            <dt>Statut</dt>
            <dd><?= ($account['active'] ?? false) === true ? 'Actif' : '<span class="account-inactive">Désactivé</span>' ?></dd>
            <dt>Membre de</dt>
            <dd class="event-sections">
              <?php foreach (app_section_display_items($accountSections) as $section): ?>
              <span class="section-tag <?= app_escape(app_section_css_class($section)) ?>"><?= app_escape($sectionLabels[$section]) ?></span>
              <?php endforeach; ?>
              <?php if ($accountSections === []): ?><span class="muted-copy">Aucune section</span><?php endif; ?>
            </dd>
            <?php if (app_user_section_leads($account) !== []): ?>
            <dt>Responsable de</dt>
            <dd><?= app_escape(implode(', ', array_map(static fn (string $leaf): string => $sectionLabels[$leaf] ?? $leaf, app_user_section_leads($account)))) ?></dd>
            <?php endif; ?>
            <dt>Vue par défaut</dt>
            <dd><?= $defaultView === 'Toutes' ? 'Toutes les sections' : app_escape($sectionLabels[$defaultView]) ?></dd>
            <?php if ($accountRole === 'manager'): ?>
            <dt>Gère</dt>
            <dd><?= app_escape(app_describe_sections(app_user_managed_sections($account))) ?></dd>
            <?php endif; ?>
            <?php foreach (ACCOUNT_FLAGS as $flag => $label): ?>
            <dt><?= app_escape($label) ?></dt>
            <dd><?= ($account[$flag] ?? false) === true ? 'Oui' : 'Non' ?></dd>
            <?php endforeach; ?>
            <dt>Lien iCal</dt>
            <dd>
              <?php if (app_is_calendar_token($account['calendar_token'] ?? null) && $actorIsAdmin): ?>
              <form class="inline-form" method="post" action="calendar-token-action.php" onsubmit="return confirm('Renouveler le lien iCal de ce compte ? L’ancien lien cessera de fonctionner.');">
                <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
                <input type="hidden" name="user_id" value="<?= app_escape($accountId) ?>">
                <span>Actif</span>
                <button class="secondary-button compact-button" type="submit">Renouveler le lien</button>
              </form>
              <?php elseif (app_is_calendar_token($account['calendar_token'] ?? null)): ?>
              <span>Actif</span>
              <?php else: ?>
              <span class="muted-copy">Pas encore créé (il le sera à la prochaine visite de l’agenda).</span>
              <?php endif; ?>
            </dd>
          </dl>
          <?php if ($actorIsAdmin && app_can_be_impersonated($account)): ?>
          <form class="account-impersonate-form" method="post" action="impersonate-action.php">
            <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
            <input type="hidden" name="action" value="start">
            <input type="hidden" name="user_id" value="<?= app_escape($accountId) ?>">
            <button class="secondary-button compact-button" type="submit">Se connecter en tant que <?= app_escape((string) $account['username']) ?></button>
            <span class="field-help">Ses saisies seront enregistrées au nom « <?= app_escape($account['username'] . '-i-' . $actor['username']) ?> ».</span>
          </form>
          <?php endif; ?>
        </section>
        <?php endif; ?>

        <?php // Photo of the account (Trombinoscope): administrators, and managers for their scope. ?>
        <?php if (!$isNew && ($actorIsAdmin || app_can_edit_photo($actor, $account))): ?>
        <?php app_photo_panel($account, $actor, 'user.php?id=' . rawurlencode($accountId), 'Photo'); ?>
        <?php endif; ?>

        <?php if (!$isNew && app_can_rename_account($actor, $account)): ?>
        <section class="management-panel account-self" id="identifiant" aria-labelledby="rename-title">
          <h2 class="detail-heading" id="rename-title">Identifiant</h2>
          <?php if (app_impersonator() !== null): ?>
          <p class="muted-copy">L’identifiant ne se change pas en agissant en tant qu’un membre.</p>
          <?php else: ?>
          <form method="post" action="user.php" class="form-stack">
            <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
            <input type="hidden" name="action" value="rename">
            <input type="hidden" name="id" value="<?= app_escape($accountId) ?>">
            <label for="rename-username">Nouvel identifiant</label>
            <input id="rename-username" name="username" required minlength="3" maxlength="32" pattern="[\p{L}\p{M}\p{N}_.\-]+" title="<?= app_escape(ucfirst(USERNAME_RULE)) ?>" autocomplete="off" value="<?= app_escape((string) $account['username']) ?>">
            <p class="field-help"><?= app_escape(ucfirst(USERNAME_RULE)) ?>. Le membre se connectera avec ce nouvel identifiant (son mot de passe ne change pas) ; pensez à le prévenir. Le changement est noté dans le journal d’audit.</p>
            <div class="form-actions"><button class="secondary-button compact-button" type="submit">Changer l’identifiant</button></div>
          </form>
          <?php endif; ?>
        </section>
        <?php endif; ?>

        <?php if ($profileOnly): ?>
        <?php // Managers: photo and login only. ?>
        <?php elseif (!$canEdit): ?>
        <p class="management-panel muted-copy">Seul un administrateur peut modifier ce compte.</p>
        <?php else: ?>
        <section class="management-panel" aria-labelledby="account-form-title">
          <h2 class="detail-heading" id="account-form-title"><?= $isNew ? 'Créer le compte' : 'Modifier le compte' ?></h2>
          <form method="post" action="user.php" class="form-stack section-assignment-form">
            <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
            <input type="hidden" name="action" value="<?= $isNew ? 'create' : 'save' ?>">
            <?php if (!$isNew): ?><input type="hidden" name="id" value="<?= app_escape($accountId) ?>"><?php endif; ?>
            <div class="form-grid align-top">
              <div>
                <label for="account-username">Identifiant</label>
                <?php if ($isNew): ?>
                <input id="account-username" name="username" required minlength="3" maxlength="32" pattern="[\p{L}\p{M}\p{N}_.\-]+" title="<?= app_escape(ucfirst(USERNAME_RULE)) ?>" autocomplete="off">
                <?php else: ?>
                <input id="account-username" value="<?= app_escape((string) $account['username']) ?>" readonly>
                <?php endif; ?>
              </div>
              <div>
                <label for="account-role">Rôle</label>
                <select id="account-role" name="role" required>
                  <?php foreach (array_intersect_key(USER_ROLES, array_flip($assignableRoles)) as $role => $label): ?>
                  <option value="<?= app_escape($role) ?>"<?= $accountRole === $role ? ' selected' : '' ?>><?= app_escape($label) ?></option>
                  <?php endforeach; ?>
                </select>
                <p class="field-help">Manager : dates et présences de son périmètre. Organisateur : dates et présences de toutes les sections, sans accès à la Configuration. Logisticien : comme l’organisateur, avec en plus la page Logistique (cachets, devis). Administrateur : tout, y compris la Configuration.</p>
              </div>
            </div>
            <?php if ($actorIsAdmin): ?>
            <div>
              <label for="account-title">Titre <span class="field-optional">(facultatif)</span></label>
              <input id="account-title" name="title" maxlength="<?= USER_TITLE_MAX_LENGTH ?>" autocomplete="off" placeholder="Ex. : Penn-soner, Président, Trésorière" value="<?= app_escape($isNew ? '' : app_user_title($account)) ?>">
              <p class="field-help">Fonction du membre dans l’association. Elle s’affiche à la place du rôle dans l’application (en-tête, Mon compte, comptes ; pas dans le tableau de présence) et sous son nom dans le trombinoscope. Seuls les administrateurs la modifient ; elle ne change pas les droits.</p>
            </div>
            <?php endif; ?>
            <div class="form-grid account-identity">
              <div>
                <label for="account-first-name">Prénom <span class="field-optional">(facultatif)</span></label>
                <input id="account-first-name" name="first_name" maxlength="60" autocomplete="off" value="<?= app_escape($isNew ? '' : (string) ($account['first_name'] ?? '')) ?>">
              </div>
              <div>
                <label for="account-last-name">Nom <span class="field-optional">(facultatif)</span></label>
                <input id="account-last-name" name="last_name" maxlength="60" autocomplete="off" value="<?= app_escape($isNew ? '' : (string) ($account['last_name'] ?? '')) ?>">
              </div>
              <div>
                <label for="account-gender">Sexe <span class="field-optional">(facultatif)</span></label>
                <select id="account-gender" name="gender">
                  <option value="">Non renseigné</option>
                  <?php foreach (ACCOUNT_GENDERS as $gender => $genderLabel): ?>
                  <option value="<?= $gender ?>"<?= !$isNew && ($account['gender'] ?? '') === $gender ? ' selected' : '' ?>><?= app_escape($genderLabel) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <?php if (!$isNew && $actorIsAdmin): ?>
            <label class="active-toggle">
              <input type="checkbox" name="active" value="1"<?= ($account['active'] ?? false) === true ? ' checked' : '' ?>>
              Compte actif
            </label>
            <?php endif; ?>
            <div class="checkbox-row account-flags" role="group" aria-label="Informations du membre">
              <?php foreach (ACCOUNT_FLAGS as $flag => $label): ?>
              <label><input type="checkbox" name="<?= $flag ?>" value="1"<?= !$isNew && ($account[$flag] ?? false) === true ? ' checked' : '' ?>> <?= app_escape($label) ?></label>
              <?php endforeach; ?>
            </div>
            <?php if ($isNew || $actorIsAdmin): ?>
            <label for="account-password"><?= $isNew ? 'Mot de passe temporaire (12 caractères minimum)' : 'Nouveau mot de passe (laisser vide pour ne pas le changer)' ?></label>
            <div class="password-field">
              <input id="account-password" name="password" type="password" minlength="12" maxlength="200" autocomplete="new-password" data-password-input<?= $isNew ? ' required' : '' ?>>
              <button class="password-toggle" type="button" data-password-toggle aria-controls="account-password" aria-label="Afficher le mot de passe" aria-pressed="false">Afficher</button>
            </div>
            <?php endif; ?>
            <fieldset class="section-options" aria-required="true">
              <legend>Sections associées (au moins une)</legend>
              <?php foreach (app_section_tree() as $branch => $definition): ?>
              <div class="section-assignment-group">
                <?php $branchIsSelected = array_diff(array_keys($definition['children']), $accountLeaves) === []; ?>
                <label class="section-assignment-option section-assignment-parent"><input type="checkbox" name="sections[]" value="<?= app_escape($branch) ?>" data-section-option data-section-parent<?= $branchIsSelected ? ' checked' : '' ?>><?= app_escape($definition['label']) ?> — toute la branche</label>
                <div class="section-assignment-children">
                  <?php foreach ($definition['children'] as $section => $label): ?>
                  <label class="section-assignment-option"><input type="checkbox" name="sections[]" value="<?= app_escape($section) ?>" data-section-option data-section-child<?= in_array($section, $accountLeaves, true) ? ' checked' : '' ?>><?= app_escape($label) ?></label>
                  <?php endforeach; ?>
                </div>
              </div>
              <?php endforeach; ?>
            </fieldset>
            <?php
                // Current responsable of each sub-section (another account), named next to its box.
                $currentLeads = [];
                foreach (app_read_users_with_migration() as $other) {
                    if (is_array($other) && ($other['id'] ?? null) !== $accountId) {
                        foreach (app_user_section_leads($other) as $leaf) {
                            $currentLeads[$leaf] = (string) $other['username'];
                        }
                    }
                }
                $accountLeads = $isNew ? [] : app_user_section_leads($account);
            ?>
            <fieldset class="section-options section-leads">
              <legend>Responsable de section</legend>
              <p class="field-help">Le responsable est affiché en premier dans les listes de sa sous-section (comptes, trombinoscope, tableau de présence, réponses d’une date), indépendamment du rôle de manager. Une sous-section n’a qu’un responsable : cocher une case remplace le responsable actuel, indiqué entre parenthèses. Seules les sous-sections associées au compte sont retenues.</p>
              <?php foreach (app_section_tree() as $branch => $definition): ?>
              <div class="section-assignment-group">
                <strong><?= app_escape($definition['label']) ?></strong>
                <div class="section-assignment-children">
                  <?php foreach ($definition['children'] as $section => $label): ?>
                  <label class="section-assignment-option"><input type="checkbox" name="section_leads[]" value="<?= app_escape($section) ?>"<?= in_array($section, $accountLeads, true) ? ' checked' : '' ?>><?= app_escape($label) ?><?php if (isset($currentLeads[$section])): ?> <span class="field-optional">(<?= app_escape($currentLeads[$section]) ?>)</span><?php endif; ?></label>
                  <?php endforeach; ?>
                </div>
              </div>
              <?php endforeach; ?>
            </fieldset>
            <label for="account-default-view">Vue par défaut</label>
            <select id="account-default-view" name="default_view" aria-describedby="account-default-view-help" required>
              <option value="<?= DEFAULT_VIEW_ALL ?>"<?= $defaultView === 'Toutes' ? ' selected' : '' ?>>Toutes les sections</option>
              <?php // Each branch (whole branch), followed by its sub-sections, indented. ?>
              <?php foreach (app_section_tree() as $branch => $definition): ?>
              <option value="<?= app_escape($branch) ?>"<?= $defaultView === $branch ? ' selected' : '' ?>><?= app_escape($definition['label']) ?> — toute la branche</option>
              <?php foreach ($definition['children'] as $section => $label): ?>
              <option value="<?= app_escape($section) ?>"<?= $defaultView === $section ? ' selected' : '' ?>>&nbsp;&nbsp;&nbsp;&nbsp;<?= app_escape($label) ?></option>
              <?php endforeach; ?>
              <?php endforeach; ?>
            </select>
            <p class="field-help" id="account-default-view-help">Filtre appliqué à l’agenda à l’ouverture, indépendamment des sections associées.</p>
            <fieldset class="section-options managed-scope" data-managed-scope>
              <legend>Périmètre de management (managers uniquement)</legend>
              <p class="field-help">Cochez une branche entière (section mère) pour gérer toutes ses sous-sections, y compris celles ajoutées plus tard, ou seulement les sous-sections concernées.</p>
              <?php foreach (app_section_tree() as $branch => $definition): ?>
              <div class="section-assignment-group">
                <label class="section-assignment-option section-assignment-parent"><input type="checkbox" name="managed_sections[]" value="<?= app_escape($branch) ?>" data-section-parent<?= in_array($branch, $managedSections, true) ? ' checked' : '' ?>><?= app_escape($definition['label']) ?> — toute la branche</label>
                <div class="section-assignment-children">
                  <?php foreach ($definition['children'] as $section => $label): ?>
                  <label class="section-assignment-option"><input type="checkbox" name="managed_sections[]" value="<?= app_escape($section) ?>" data-section-child<?= in_array($section, $managedLeaves, true) ? ' checked' : '' ?>><?= app_escape($label) ?></label>
                  <?php endforeach; ?>
                </div>
              </div>
              <?php endforeach; ?>
            </fieldset>
            <div class="form-actions">
              <button class="primary-button" type="submit"><?= $isNew ? 'Créer le compte' : 'Enregistrer les modifications' ?></button>
              <a class="secondary-button" href="users.php">Annuler</a>
            </div>
          </form>
        </section>
        <?php endif; ?>
      </section>
<?php app_footer(); ?>
