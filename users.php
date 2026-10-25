<?php
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';
$admin = app_require_roles(ACCOUNT_MANAGER_ROLES);

$accounts = array_values(array_filter(
    app_read_users_with_migration(),
    static fn (mixed $account): bool => is_array($account) && is_string($account['id'] ?? null) && is_string($account['username'] ?? null)
));
usort($accounts, static fn (array $first, array $second): int => strcasecmp($first['username'], $second['username']));

$sectionLabels = app_section_labels();
$membersByLeaf = [];
$managersByLeaf = [];
$branchManagers = [];
$withoutSection = [];
$roleCounts = array_fill_keys(array_keys(USER_ROLES), 0);
$activeCount = 0;
foreach ($accounts as $account) {
    $isActive = ($account['active'] ?? false) === true;
    $activeCount += $isActive ? 1 : 0;
    if (isset($roleCounts[$account['role'] ?? ''])) {
        $roleCounts[$account['role']]++;
    }

    $leaves = app_expand_sections(app_user_sections($account));
    if ($leaves === []) {
        $withoutSection[] = $account;
    }
    // A member of several sub-sections is listed under each of them.
    foreach ($leaves as $leaf) {
        $membersByLeaf[$leaf][] = $account;
    }

    if (($account['role'] ?? '') === 'manager' && $isActive) {
        $managed = app_user_managed_sections($account);
        foreach (app_expand_sections($managed) as $leaf) {
            $managersByLeaf[$leaf][] = $account;
        }
        foreach ($managed as $section) {
            if (isset(app_section_tree()[$section])) {
                $branchManagers[$section][] = $account;
            }
        }
    }
}

// $leaf: sub-section of the list, to mark its responsable.
function renderAccountLink(array $account, string $leaf = ''): void
{
    $role = (string) ($account['role'] ?? '');
    $isActive = ($account['active'] ?? false) === true;
    // Logging in as another member is reserved to administrators.
    $canImpersonate = $GLOBALS['admin']['role'] === 'admin' && app_can_be_impersonated($account);
    ?>
                <li class="account-item<?= $canImpersonate ? ' has-impersonate' : '' ?>">
                  <a class="account-chip<?= $isActive ? '' : ' is-inactive' ?>" href="user.php?id=<?= rawurlencode($account['id']) ?>"><?= app_escape($account['username']) ?><?php if ($role !== 'musician' || app_user_title($account) !== ''): ?> <span class="account-role account-role-<?= app_escape($role) ?>" title="<?= app_escape('Rôle : ' . (USER_ROLES[$role] ?? $role)) ?>"><?= app_escape(app_user_role_label($account)) ?></span><?php endif; ?><?php if ($leaf !== '' && app_is_section_lead($account, $leaf)): ?> <span class="account-role account-lead">Responsable</span><?php endif; ?><?php if (!$isActive): ?> <span class="account-role">Désactivé</span><?php endif; ?></a>
                  <?php if ($canImpersonate): ?>
                  <form method="post" action="impersonate-action.php">
                    <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
                    <input type="hidden" name="action" value="start">
                    <input type="hidden" name="user_id" value="<?= app_escape($account['id']) ?>">
                    <button class="account-impersonate" type="submit" title="Se connecter en tant que <?= app_escape($account['username']) ?>" aria-label="Se connecter en tant que <?= app_escape($account['username']) ?>">
                      <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.6-3.6 3.2-5.5 6.5-5.5 1.6 0 3 .4 4.1 1.3"/><path d="M15 15.5h6.5M18.5 12.5l3 3-3 3"/></svg>
                    </button>
                  </form>
                  <?php endif; ?>
                </li>
<?php
}

function managerLinks(array $managers): string
{
    return implode(', ', array_map(
        static fn (array $manager): string => '<a href="user.php?id=' . rawurlencode($manager['id']) . '">' . app_escape($manager['username']) . '</a>',
        $managers
    ));
}

app_header('Comptes', $admin);
?>
      <section class="management-section">
        <div class="eyebrow-row">
          <p class="eyebrow"><span class="eyebrow-line"></span> CONFIGURATION · MEMBRES</p>
          <?php app_page_links([['Configuration', 'settings.php']]); ?>
        </div>
        <div class="section-heading">
          <div>
            <h1>Comptes<span class="heading-dot">.</span></h1>
          </div>
          <div class="page-actions">
            <?php if ($admin['role'] === 'admin'): ?>
            <a class="secondary-button compact-button" href="users-import.php">Importer des comptes (CSV)</a>
            <?php endif; ?>
            <a class="primary-button compact-button" href="user.php">Créer un compte</a>
          </div>
        </div>
        <?php app_settings_nav('membres', 'users.php', $admin); ?>

        <p class="accounts-help">Cliquez sur un nom pour ouvrir sa fiche.<?php if ($admin['role'] === 'admin'): ?> L’icône <svg class="inline-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.6-3.6 3.2-5.5 6.5-5.5 1.6 0 3 .4 4.1 1.3"/><path d="M15 15.5h6.5M18.5 12.5l3 3-3 3"/></svg> permet de se connecter en tant qu’un artiste, un manager, un organisateur ou un logisticien pour voir et faire ce qu’il voit et fait ; ses saisies sont alors enregistrées au nom « utilisateur-i-admin ».<?php endif; ?></p>
        <?php
            $countLabel = static fn (int $count, string $singular): string => $count . ' ' . $singular . ($count > 1 ? 's' : '');
            $stats = [
                $countLabel($activeCount, 'actif'),
                $countLabel($roleCounts['musician'], 'artiste'),
                $countLabel($roleCounts['manager'], 'manager'),
                $countLabel($roleCounts['organizer'], 'organisateur'),
                $countLabel($roleCounts['logistician'], 'logisticien'),
                $countLabel($roleCounts['admin'], 'administrateur'),
            ];
        ?>
        <p class="accounts-stats">
          <strong><?= count($accounts) ?></strong> compte<?= count($accounts) > 1 ? 's' : '' ?> · <?= app_escape(implode(' · ', $stats)) ?>
        </p>

        <?php foreach (app_section_tree() as $branch => $definition): ?>
        <section class="management-panel accounts-branch" aria-labelledby="branch-<?= app_escape($branch) ?>">
          <div class="accounts-branch-heading">
            <h2 class="detail-heading" id="branch-<?= app_escape($branch) ?>"><?= app_escape($definition['label']) ?></h2>
            <?php if (isset($branchManagers[$branch])): ?>
            <p class="accounts-managers">Managers de toute la branche : <?= managerLinks($branchManagers[$branch]) ?></p>
            <?php endif; ?>
          </div>
          <?php foreach ($definition['children'] as $leaf => $label): ?>
          <?php $members = app_sort_section_members($membersByLeaf[$leaf] ?? [], $leaf); ?>
          <div class="accounts-subsection">
            <div class="accounts-subsection-heading">
              <h3><?= app_escape($label) ?> <span class="count-badge"><?= count($members) ?></span></h3>
              <p class="accounts-managers">
                Managers :
                <?php if (isset($managersByLeaf[$leaf])): ?>
                <?= managerLinks($managersByLeaf[$leaf]) ?>
                <?php else: ?>
                <span class="accounts-none">aucun</span>
                <?php endif; ?>
              </p>
            </div>
            <?php if ($members === []): ?>
            <p class="accounts-none">Aucun membre.</p>
            <?php else: ?>
            <ul class="account-chips">
              <?php foreach ($members as $member): ?>
              <?php renderAccountLink($member, $leaf); ?>
              <?php endforeach; ?>
            </ul>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </section>
        <?php endforeach; ?>

        <?php if ($withoutSection !== []): ?>
        <section class="management-panel accounts-branch" aria-labelledby="branch-none">
          <h2 class="detail-heading" id="branch-none">Sans section</h2>
          <ul class="account-chips">
            <?php foreach ($withoutSection as $account): ?>
            <?php renderAccountLink($account); ?>
            <?php endforeach; ?>
          </ul>
        </section>
        <?php endif; ?>
      </section>
<?php app_footer(); ?>
