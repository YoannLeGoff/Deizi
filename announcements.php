<?php
declare(strict_types=1);

// Announcements: list and form (managers within their scope, organizers, logisticians and
// administrators); saved by announcement-action.php.
require __DIR__ . '/lib/bootstrap.php';
$user = app_require_roles(ANNOUNCEMENT_ROLES);
if (!app_can_publish_announcements($user)) {
    app_flash('Votre compte n’a pas de périmètre de management' . "\u{00A0}" . ': vous ne pouvez pas publier d’annonce.', 'error');
    app_redirect('index.php');
}
$isScopedManager = $user['role'] === 'manager';
$managedLeaves = $isScopedManager ? app_user_managed_leaves($user) : [];
$isAdmin = $user['role'] === 'admin';
$allAnnouncements = app_announcements();
$editable = array_values(array_filter($allAnnouncements, static fn (array $announcement): bool => app_can_edit_announcement($user, $announcement)));
// "Mes annonces": the announcements published by the member (administrators see every
// announcement in the "Administrateur" tab).
$announcements = array_values(array_filter($allAnnouncements, static fn (array $announcement): bool => app_is_announcement_author($announcement, $user)));

$editId = $_GET['modifier'] ?? '';
$editing = null;
foreach ($editable as $announcement) {
    if ($announcement['id'] === $editId) {
        $editing = $announcement;
    }
}
$tab = match (true) {
    $editing !== null => 'edit',
    ($_GET['nouvelle'] ?? '') !== '' => 'add',
    $isAdmin && ($_GET['onglet'] ?? '') === 'administrateur' => 'admin',
    default => 'list',
};

$users = [];
foreach (read_json_file(app_data_path('users.json')) as $account) {
    if (is_array($account) && is_string($account['id'] ?? null)) {
        $users[$account['id']] = $account;
    }
}
$today = app_today();
// "Administrateur" tab: every announcement, with the number of active members it is meant for.
$activeMembers = array_values(array_filter($users, static fn (array $account): bool => app_is_active_user($account)));
$reach = static fn (array $announcement): int => count(array_filter($activeMembers, static fn (array $account): bool => app_announcement_targets($announcement, $account)));
$stateLabels = ['active' => 'En cours', 'scheduled' => 'Programmée', 'ended' => 'Terminée'];
$stateOrder = ['active' => 0, 'scheduled' => 1, 'ended' => 2];
$adminRows = $allAnnouncements;
usort($adminRows, static fn (array $first, array $second): int => [$stateOrder[app_announcement_state($first, $today)], $second['starts']] <=> [$stateOrder[app_announcement_state($second, $today)], $first['starts']]);
$groups = ['active' => 'En cours', 'scheduled' => 'Programmées', 'ended' => 'Terminées'];
$byState = array_fill_keys(array_keys($groups), []);
foreach ($announcements as $announcement) {
    $byState[app_announcement_state($announcement, $today)][] = $announcement;
}

function announcementPeriod(array $announcement): string
{
    $starts = app_parse_date($announcement['starts']);
    $ends = $announcement['ends'] !== null ? app_parse_date($announcement['ends']) : null;
    return 'Du ' . $starts->format('d/m/Y') . ($ends !== null ? ' au ' . $ends->format('d/m/Y') : ', sans date de fin');
}

$form = $editing ?? [
    'id' => '', 'title' => '', 'body' => '', 'link_url' => '', 'link_label' => '', 'image' => null,
    // By default, the whole scope of a manager, everybody for the other roles (summed up above the
    // folded options).
    'everyone' => !$isScopedManager, 'sections' => $isScopedManager ? $managedLeaves : [], 'starts' => $today,
    'ends' => (new DateTimeImmutable($today))->modify('+' . ANNOUNCEMENT_DEFAULT_DAYS . ' days')->format('Y-m-d'),
    'push' => true, 'push_sent_at' => null,
];

app_header('Annonces', $user);
?>
      <section class="management-section">
        <div class="eyebrow-row">
          <p class="eyebrow"><span class="eyebrow-line"></span> <?= $isScopedManager ? 'ESPACE MANAGERS' : 'ANNONCES' ?></p>
          <?php app_page_links([['Retour à l’agenda', 'index.php']]); ?>
        </div>
        <div class="section-heading">
          <div>
            <h1 class="heading-with-icon"><?= app_megaphone_icon('heading-icon') ?><span>Annonces<span class="heading-dot">.</span></span></h1>
          </div>
        </div>
        <p class="accounts-help">Une annonce s’affiche en haut de la page d’accueil des membres des sections choisies<?= $isScopedManager ? ' (dans votre périmètre)' : '' ?>, pendant la période indiquée. Chacun peut la masquer une fois lue. Elle peut aussi être envoyée en notification aux membres qui les ont activées.</p>

        <nav class="detail-tabs" aria-label="Annonces">
          <a class="detail-tab" href="announcements.php"<?= $tab === 'list' ? ' aria-current="page"' : '' ?>>Mes annonces <span class="count-badge"><?= count($byState['active']) + count($byState['scheduled']) ?></span></a>
          <a class="detail-tab" href="announcements.php?nouvelle=1"<?= $tab === 'add' ? ' aria-current="page"' : '' ?>>+ Nouvelle annonce</a>
          <?php if ($isAdmin): ?>
          <a class="detail-tab" href="announcements.php?onglet=administrateur"<?= $tab === 'admin' ? ' aria-current="page"' : '' ?>>Administrateur <span class="count-badge"><?= count($allAnnouncements) ?></span></a>
          <?php endif; ?>
          <?php if ($editing !== null): ?>
          <a class="detail-tab" href="announcements.php?modifier=<?= rawurlencode($editing['id']) ?>" aria-current="page">Modifier<?= "\u{00A0}" ?>: <?= app_escape(app_truncate($editing['title'], 40)) ?></a>
          <?php endif; ?>
        </nav>

        <?php if ($tab === 'list'): ?>
        <section class="management-panel detail-panel has-tabs" aria-label="Liste des annonces">
          <?php if ($announcements === []): ?>
          <p class="muted-copy">Vous n’avez publié aucune annonce. <a href="announcements.php?nouvelle=1">Publier une annonce</a><?php if ($isAdmin && $allAnnouncements !== []): ?> · <a href="announcements.php?onglet=administrateur">Voir toutes les annonces</a><?php endif; ?></p>
          <?php endif; ?>
          <?php foreach ($groups as $state => $groupLabel): ?>
          <?php if ($byState[$state] !== []): ?>
          <h2 class="detail-heading"><?= app_escape($groupLabel) ?> <span class="count-badge"><?= count($byState[$state]) ?></span></h2>
          <ul class="announcement-admin-list">
            <?php foreach ($byState[$state] as $announcement): ?>
            <li class="announcement-admin-item is-<?= $state ?>">
              <?php if ($announcement['image'] !== null): ?>
              <img class="announcement-admin-thumb" src="<?= app_escape(app_announcement_image_url($announcement)) ?>" alt="">
              <?php endif; ?>
              <div class="announcement-admin-main">
                <strong><?= app_escape($announcement['title']) ?></strong>
                <span class="announcement-admin-meta"><?= app_escape(app_announcement_audience($announcement)) ?> · <?= app_escape(announcementPeriod($announcement)) ?></span>
                <span class="announcement-admin-meta">
                  Par <?= app_escape((string) ($users[$announcement['author']]['username'] ?? 'compte supprimé')) ?>
                  <?php if ($announcement['push']): ?> · <?= $announcement['push_sent_at'] !== null ? 'notification envoyée' : 'notification au premier jour' ?><?php endif; ?>
                  <?php if ($announcement['dismissed'] !== []): ?> · masquée par <?= count($announcement['dismissed']) ?> membre<?= count($announcement['dismissed']) > 1 ? 's' : '' ?><?php endif; ?>
                </span>
              </div>
              <div class="announcement-admin-actions">
                <a class="secondary-button compact-button" href="announcements.php?modifier=<?= rawurlencode($announcement['id']) ?>">Modifier</a>
                <form method="post" action="announcement-action.php" onsubmit="return confirm(<?= app_escape(json_encode('Supprimer l’annonce « ' . $announcement['title'] . ' » ?', JSON_UNESCAPED_UNICODE)) ?>);">
                  <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= app_escape($announcement['id']) ?>">
                  <button class="text-button" type="submit">Supprimer</button>
                </form>
              </div>
            </li>
            <?php endforeach; ?>
          </ul>
          <?php endif; ?>
          <?php endforeach; ?>
        </section>
        <?php elseif ($tab === 'admin'): ?>
        <section class="management-panel detail-panel has-tabs" aria-labelledby="announcement-admin-title">
          <h2 class="detail-heading" id="announcement-admin-title">Toutes les annonces</h2>
          <p class="muted-copy">Les annonces de tous les auteurs, terminées comprises. La portée compte les comptes actifs visés ; « Masquée par » liste ceux qui l’ont masquée.</p>
          <?php if ($adminRows === []): ?>
          <p class="muted-copy">Aucune annonce pour le moment.</p>
          <?php else: ?>
          <div class="log-scroll">
            <table class="log-table announcement-admin-table">
              <thead>
                <tr><th scope="col">Annonce</th><th scope="col">État</th><th scope="col">Visible par</th><th scope="col">Auteur</th><th scope="col">Portée</th><th scope="col">Masquée par</th><th scope="col">Notification</th><th scope="col"><span class="sr-only">Actions</span></th></tr>
              </thead>
              <tbody>
                <?php foreach ($adminRows as $announcement): ?>
                <?php $state = app_announcement_state($announcement, $today); ?>
                <tr class="is-<?= $state ?>">
                  <td>
                    <div class="announcement-admin-cell">
                      <?php if ($announcement['image'] !== null): ?><img class="announcement-admin-thumb" src="<?= app_escape(app_announcement_image_url($announcement)) ?>" alt=""><?php endif; ?>
                      <div>
                        <strong><?= app_escape($announcement['title']) ?></strong>
                        <?php if ($announcement['body'] !== ''): ?><span class="announcement-admin-meta announcement-admin-excerpt"><?= app_escape(app_truncate(trim(preg_replace('/\s+/u', ' ', $announcement['body']) ?? ''), 120)) ?></span><?php endif; ?>
                        <?php if ($announcement['link_url'] !== ''): ?><a class="announcement-admin-meta" href="<?= app_escape($announcement['link_url']) ?>" target="_blank" rel="noopener noreferrer"><?= app_escape($announcement['link_label'] !== '' ? $announcement['link_label'] : $announcement['link_url']) ?> ↗</a><?php endif; ?>
                      </div>
                    </div>
                  </td>
                  <td><span class="announcement-state is-<?= $state ?>"><?= $stateLabels[$state] ?></span><span class="announcement-admin-meta"><?= app_escape(announcementPeriod($announcement)) ?></span></td>
                  <td><?= app_escape(app_announcement_audience($announcement)) ?></td>
                  <td><?= app_escape((string) ($users[$announcement['author']]['username'] ?? 'compte supprimé')) ?><span class="announcement-admin-meta"><?= ($created = app_change_time($announcement['created_at'])) !== null ? 'le ' . date('d/m/Y', $created) : '' ?><?= ($updated = app_change_time($announcement['updated_at'])) !== null ? ', modifiée le ' . date('d/m/Y', $updated) : '' ?></span></td>
                  <td><?= $reach($announcement) ?> compte<?= $reach($announcement) > 1 ? 's' : '' ?></td>
                  <td><?= $announcement['dismissed'] === [] ? '—' : app_escape(implode(', ', array_map(static fn (string $id): string => (string) ($users[$id]['username'] ?? '?'), $announcement['dismissed']))) ?></td>
                  <td><?= !$announcement['push'] ? 'Non' : ($announcement['push_sent_at'] !== null ? 'Envoyée le ' . date('d/m/Y', (int) app_change_time($announcement['push_sent_at'])) : 'Au premier jour') ?></td>
                  <td class="announcement-admin-table-actions">
                    <a class="secondary-button compact-button" href="announcements.php?modifier=<?= rawurlencode($announcement['id']) ?>">Modifier</a>
                    <form method="post" action="announcement-action.php" onsubmit="return confirm(<?= app_escape(json_encode('Supprimer l’annonce « ' . $announcement['title'] . ' » ?', JSON_UNESCAPED_UNICODE)) ?>);">
                      <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= app_escape($announcement['id']) ?>">
                      <input type="hidden" name="return" value="administrateur">
                      <button class="text-button" type="submit">Supprimer</button>
                    </form>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php endif; ?>
        </section>
        <?php else: ?>
        <section class="management-panel detail-panel has-tabs" aria-labelledby="announcement-form-title">
          <h2 id="announcement-form-title"><?= $editing === null ? 'Nouvelle annonce' : 'Modifier l’annonce' ?></h2>
          <form method="post" action="announcement-action.php" enctype="multipart/form-data" class="form-stack section-assignment-form" data-announcement-form>
            <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= app_escape($form['id']) ?>">
            <label for="announcement-title">Titre *</label>
            <input id="announcement-title" name="title" required maxlength="<?= ANNOUNCEMENT_TITLE_MAX ?>" value="<?= app_escape($form['title']) ?>" placeholder="Répétition générale exceptionnelle">
            <label for="announcement-image">Photo</label>
            <?php if ($form['image'] !== null): ?>
            <div class="announcement-image-current">
              <img src="<?= app_escape(app_announcement_image_url($form)) ?>" alt="Image actuelle de l’annonce">
              <label class="checkbox-row"><input type="checkbox" name="remove_image" value="1"> Retirer l’image</label>
            </div>
            <?php endif; ?>
            <input id="announcement-image" name="image" type="file" accept="image/png,image/jpeg,image/gif,image/webp">
            <p class="field-help">PNG, JPEG, GIF ou WebP, 2<?= "\u{00A0}" ?>Mo au plus<?= $form['image'] !== null ? '; une nouvelle image remplace l’actuelle' : '' ?>.</p>
            <label class="active-toggle">
              <input type="checkbox" name="push" value="1"<?= $form['push'] ? ' checked' : '' ?><?= $form['push_sent_at'] !== null ? ' disabled' : '' ?>>
              <strong>Envoyer une notification</strong> — <?= $form['push_sent_at'] !== null ? 'déjà envoyée' : 'aux membres concernés qui ont activé les notifications, à la publication ou le premier jour d’affichage' ?>
            </label>
            <?php if ($form['push_sent_at'] !== null): ?><input type="hidden" name="push" value="1"><?php endif; ?>
            <?php // Text, link, display period and audience: folded, summed up on the line that opens them. ?>
            <details class="form-accordion" data-announcement-options>
              <summary>
                <span class="form-accordion-title">Texte, lien, période et public</span>
                <span class="form-accordion-summary" data-announcement-summary><?= app_escape('Visible par : ' . app_announcement_audience($form) . ' · ' . lcfirst(announcementPeriod($form))) ?></span>
              </summary>
              <div class="form-accordion-body">
              <label for="announcement-body">Texte</label>
              <textarea id="announcement-body" name="body" rows="5" maxlength="<?= ANNOUNCEMENT_BODY_MAX ?>" placeholder="Les téléphones et adresses e-mail deviennent cliquables."><?= app_escape($form['body']) ?></textarea>
              <div class="form-grid">
                <div>
                  <label for="announcement-link">Lien</label>
                  <input id="announcement-link" name="link_url" type="url" maxlength="2000" placeholder="https://" value="<?= app_escape($form['link_url']) ?>">
                </div>
                <div>
                  <label for="announcement-link-label">Texte du lien</label>
                  <input id="announcement-link-label" name="link_label" maxlength="<?= ANNOUNCEMENT_LINK_LABEL_MAX ?>" placeholder="En savoir plus" value="<?= app_escape($form['link_label']) ?>">
                </div>
              </div>
              <div class="form-grid">
                <div>
                  <label for="announcement-starts">Affichée à partir du *</label>
                  <input id="announcement-starts" name="starts" type="date" required value="<?= app_escape($form['starts']) ?>">
                </div>
                <div>
                  <label for="announcement-ends">Jusqu’au (inclus)</label>
                  <input id="announcement-ends" name="ends" type="date" value="<?= app_escape((string) $form['ends']) ?>">
                </div>
              </div>
              <fieldset class="section-options">
                <legend><?= $isScopedManager ? 'Qui voit l’annonce (dans votre périmètre)' : 'Qui voit l’annonce' ?></legend>
                <?php if (!$isScopedManager): ?>
                <label class="section-assignment-option section-assignment-parent announcement-everyone">
                  <input type="checkbox" name="everyone" value="1" data-announcement-everyone<?= $form['everyone'] ? ' checked' : '' ?>>
                  Tout le monde
                </label>
                <?php endif; ?>
                <div data-announcement-sections>
                <?php foreach (app_section_tree() as $branch => $definition): ?>
                <?php
                    $branchChildren = $isScopedManager ? array_intersect_key($definition['children'], array_flip($managedLeaves)) : $definition['children'];
                    if ($branchChildren === []) {
                        continue;
                    }
                ?>
                <div class="section-assignment-group">
                  <?php if (count($branchChildren) === count($definition['children'])): ?>
                  <label class="section-assignment-option section-assignment-parent">
                    <input type="checkbox" name="sections[]" value="<?= app_escape($branch) ?>" data-section-parent<?= array_diff(array_keys($definition['children']), $form['sections']) === [] ? ' checked' : '' ?>>
                    <?= app_escape($definition['label']) ?> — toutes les sous-sections
                  </label>
                  <?php else: ?>
                  <span class="section-assignment-option section-assignment-parent"><?= app_escape($definition['label']) ?></span>
                  <?php endif; ?>
                  <div class="section-assignment-children">
                    <?php foreach ($branchChildren as $section => $label): ?>
                    <label class="section-assignment-option"><input type="checkbox" name="sections[]" value="<?= app_escape($section) ?>" data-section-child<?= in_array($section, $form['sections'], true) ? ' checked' : '' ?>> <?= app_escape($label) ?></label>
                    <?php endforeach; ?>
                  </div>
                </div>
                <?php endforeach; ?>
                </div>
              </fieldset>
              </div>
            </details>
            <div class="form-actions">
              <button class="primary-button" type="submit"><?= $editing === null ? 'Publier l’annonce' : 'Enregistrer' ?></button>
              <a class="secondary-button" href="announcements.php">Annuler</a>
            </div>
          </form>
        </section>
        <?php endif; ?>
      </section>
<?php app_footer(); ?>
