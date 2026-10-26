<?php
declare(strict_types=1);

// Trombinoscope: the photos of the active members, by branch and sub-section (a member of several
// sub-sections appears in each); sections unchecked under "Trombinoscope" in Configuration >
// Sections are left out (a hidden branch hides its sub-sections). Every logged-in member sees it; artists change their own photo
// in "Mon compte", administrators every photo.
require __DIR__ . '/lib/bootstrap.php';
$user = app_require_login();
$isAdmin = $user['role'] === 'admin';

$members = array_values(array_filter(read_json_file(app_data_path('users.json')), 'app_is_active_user'));
usort($members, static fn (array $first, array $second): int => strcasecmp(app_member_display_name($first), app_member_display_name($second)));
$tree = app_section_tree();
$groups = [];
$shownMembers = [];
foreach ($tree as $branch => $definition) {
    foreach ($definition['children'] as $child => $childLabel) {
        if (!app_section_shown($branch, 'trombinoscope') || !app_section_shown($child, 'trombinoscope')) {
            continue;
        }
        $childMembers = app_sort_section_members(array_filter($members, static fn (array $member): bool => in_array($child, app_expand_sections(app_user_sections($member)), true)), $child);
        if ($childMembers !== []) {
            $groups[$branch][$child] = $childMembers;
            foreach ($childMembers as $member) {
                $shownMembers[$member['id']] = $member;
            }
        }
    }
}
$members = array_values($shownMembers);
$withPhoto = count(array_filter($members, static fn (array $member): bool => app_user_photo($member) !== null));

app_header('Trombinoscope', $user);
?>
      <section class="management-section">
        <div class="eyebrow-row">
          <p class="eyebrow"><span class="eyebrow-line"></span> <?= app_escape(__('LES MEMBRES')) ?></p>
          <?php app_page_links([['Retour à l’agenda', 'index.php']]); ?>
        </div>
        <div class="section-heading">
          <div>
            <h1><?= app_escape(__('Trombinoscope')) ?><span class="heading-dot">.</span></h1>
          </div>
          <?php if (app_can_edit_photo($user, $user)): ?>
          <div class="page-actions">
            <a class="secondary-button compact-button" href="account.php#photo"><?= app_user_photo($user) !== null ? __('Changer ma photo') : __('Ajouter ma photo') ?></a>
          </div>
          <?php endif; ?>
        </div>
        <p class="accounts-help"><?= app_escape(__('{n} membres, dont {photos} avec une photo. Les photos ne sont visibles que des membres connectés.', ['n' => count($members), 'photos' => $withPhoto])) ?><?= app_can_edit_photo($user, $user) ? ' ' . app_escape(__('Ajoutez ou changez la vôtre dans « Mon compte ».')) : '' ?></p>

        <?php if ($groups !== []): ?>
        <nav class="trombi-nav" aria-label="<?= app_escape(__('Branches')) ?>">
          <?php foreach (array_keys($groups) as $branch): ?>
          <a href="#branche-<?= app_escape($branch) ?>"><?= app_escape($tree[$branch]['label']) ?></a>
          <?php endforeach; ?>
        </nav>
        <?php endif; ?>

        <?php foreach ($groups as $branch => $children): ?>
        <section class="management-panel trombi-branch" id="branche-<?= app_escape($branch) ?>" aria-labelledby="trombi-<?= app_escape($branch) ?>">
          <h2 class="detail-heading" id="trombi-<?= app_escape($branch) ?>"><?= app_escape($tree[$branch]['label']) ?></h2>
          <?php foreach ($children as $child => $childMembers): ?>
          <h3 class="trombi-subsection"><?= app_escape($tree[$branch]['children'][$child]) ?> <span class="count-badge"><?= count($childMembers) ?></span></h3>
          <ul class="trombi-grid">
            <?php foreach ($childMembers as $member): ?>
            <li class="trombi-card">
              <?= app_photo_zoom($member, 'trombi-photo', 104) ?>
              <span class="trombi-name"><?= app_escape(app_member_display_name($member)) ?></span>
              <?php if (app_user_title($member) !== ''): ?><span class="trombi-role"><?= app_escape(app_user_title($member)) ?></span><?php endif; ?>
              <?php if ($isAdmin || app_can_edit_photo($user, $member)): ?><a class="trombi-edit" href="<?= (string) $member['id'] === (string) $user['id'] && !$isAdmin ? 'account.php#photo' : 'user.php?id=' . rawurlencode((string) $member['id']) . '#photo' ?>"><?= app_escape(__('Modifier')) ?></a><?php endif; ?>
            </li>
            <?php endforeach; ?>
          </ul>
          <?php endforeach; ?>
        </section>
        <?php endforeach; ?>
      </section>
<?php app_footer(); ?>
