<?php
declare(strict_types=1);

// Announcements: list and form (managers within their scope, organizers, logisticians and
// administrators); saved by announcement-action.php.
require __DIR__ . '/lib/bootstrap.php';
$user = app_require_roles(ANNOUNCEMENT_ROLES);
if (!app_can_publish_announcements($user)) {
    app_flash(__('votre_compte_na_perimetre_management_vous'), 'error');
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
$stateLabels = ['active' => __('cours'), 'scheduled' => __('programmee'), 'ended' => __('terminee')];
$stateOrder = ['active' => 0, 'scheduled' => 1, 'ended' => 2];
$adminRows = $allAnnouncements;
usort($adminRows, static fn (array $first, array $second): int => [$stateOrder[app_announcement_state($first, $today)], $second['starts']] <=> [$stateOrder[app_announcement_state($second, $today)], $first['starts']]);
$groups = ['active' => __('cours'), 'scheduled' => __('programmees'), 'ended' => __('terminees')];
$byState = array_fill_keys(array_keys($groups), []);
foreach ($announcements as $announcement) {
    $byState[app_announcement_state($announcement, $today)][] = $announcement;
}

function announcementPeriod(array $announcement): string
{
    $starts = app_parse_date($announcement['starts']);
    $ends = $announcement['ends'] !== null ? app_parse_date($announcement['ends']) : null;
    return $ends !== null
        ? __('first_last_2', ['first' => $starts->format('d/m/Y'), 'last' => $ends->format('d/m/Y')])
        : __('first_sans_date_fin', ['first' => $starts->format('d/m/Y')]);
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
            <h1 class="heading-with-icon"><?= app_megaphone_icon('heading-icon') ?><span><?= app_escape(__('annonces')) ?><span class="heading-dot">.</span></span></h1>
          </div>
        </div>
        <p class="accounts-help"><?= app_escape($isScopedManager ? __('annonce_saffiche_haut_page_daccueil_membres_sections_choisie') : __('annonce_saffiche_haut_page_daccueil_membres')) ?></p>

        <nav class="detail-tabs" aria-label="<?= app_escape(__('annonces')) ?>">
          <a class="detail-tab" href="announcements.php"<?= $tab === 'list' ? ' aria-current="page"' : '' ?>><?= app_escape(__('mes_annonces')) ?> <span class="count-badge"><?= count($byState['active']) + count($byState['scheduled']) ?></span></a>
          <a class="detail-tab" href="announcements.php?nouvelle=1"<?= $tab === 'add' ? ' aria-current="page"' : '' ?>><?= app_escape(__('plus_nouvelle_annonce')) ?></a>
          <?php if ($isAdmin): ?>
          <a class="detail-tab" href="announcements.php?onglet=administrateur"<?= $tab === 'admin' ? ' aria-current="page"' : '' ?>><?= app_escape(__('administrateur')) ?> <span class="count-badge"><?= count($allAnnouncements) ?></span></a>
          <?php endif; ?>
          <?php if ($editing !== null): ?>
          <a class="detail-tab" href="announcements.php?modifier=<?= rawurlencode($editing['id']) ?>" aria-current="page"><?= app_escape(__('modifier_title', ['title' => app_truncate($editing['title'], 40)])) ?></a>
          <?php endif; ?>
        </nav>

        <?php if ($tab === 'list'): ?>
        <section class="management-panel detail-panel has-tabs" aria-label="<?= app_escape(__('liste_annonces')) ?>">
          <?php if ($announcements === []): ?>
          <p class="muted-copy"><?= app_escape(__('vous_navez_publie_aucune_annonce_phrase')) ?> <a href="announcements.php?nouvelle=1"><?= app_escape(__('publier_annonce')) ?></a><?php if ($isAdmin && $allAnnouncements !== []): ?> · <a href="announcements.php?onglet=administrateur"><?= app_escape(__('voir_toutes_annonces')) ?></a><?php endif; ?></p>
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
                  <?= app_escape(__('auteur_name', ['name' => (string) ($users[$announcement['author']]['username'] ?? __('compte_supprime'))])) ?>
                  <?php if ($announcement['push']): ?> · <?= app_escape($announcement['push_sent_at'] !== null ? __('notification_envoyee') : __('notification_premier_jour')) ?><?php endif; ?>
                  <?php if ($announcement['dismissed'] !== []): ?> · <?= app_escape(__n('masquee_membre', 'masquee_membres', count($announcement['dismissed']))) ?><?php endif; ?>
                </span>
              </div>
              <div class="announcement-admin-actions">
                <a class="secondary-button compact-button" href="announcements.php?modifier=<?= rawurlencode($announcement['id']) ?>"><?= app_escape(__('modifier')) ?></a>
                <form method="post" action="announcement-action.php" onsubmit="return confirm(<?= app_escape(json_encode(__('supprimer_lannonce_title', ['title' => $announcement['title']]), JSON_UNESCAPED_UNICODE)) ?>);">
                  <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= app_escape($announcement['id']) ?>">
                  <button class="text-button" type="submit"><?= app_escape(__('supprimer')) ?></button>
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
          <h2 class="detail-heading" id="announcement-admin-title"><?= app_escape(__('toutes_annonces')) ?></h2>
          <p class="muted-copy"><?= app_escape(__('annonces_tous_auteurs_terminees_comprises_portee')) ?></p>
          <?php if ($adminRows === []): ?>
          <p class="muted-copy"><?= app_escape(__('aucune_annonce_moment_phrase')) ?></p>
          <?php else: ?>
          <div class="log-scroll">
            <table class="log-table announcement-admin-table">
              <thead>
                <tr><th scope="col"><?= app_escape(__('annonce')) ?></th><th scope="col"><?= app_escape(__('etat')) ?></th><th scope="col"><?= app_escape(__('visible')) ?></th><th scope="col"><?= app_escape(__('auteur')) ?></th><th scope="col"><?= app_escape(__('portee')) ?></th><th scope="col"><?= app_escape(__('masquee')) ?></th><th scope="col"><?= app_escape(__('notification')) ?></th><th scope="col"><span class="sr-only"><?= app_escape(__('actions')) ?></span></th></tr>
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
                  <td><?= app_escape((string) ($users[$announcement['author']]['username'] ?? __('compte_supprime'))) ?><span class="announcement-admin-meta"><?= ($created = app_change_time($announcement['created_at'])) !== null ? app_escape(__('date_4', ['date' => date('d/m/Y', $created)])) : '' ?><?= ($updated = app_change_time($announcement['updated_at'])) !== null ? app_escape(__('modifiee_date', ['date' => date('d/m/Y', $updated)])) : '' ?></span></td>
                  <td><?= app_escape(__n('compte', 'comptes_2', $reach($announcement))) ?></td>
                  <td><?= $announcement['dismissed'] === [] ? '—' : app_escape(implode(', ', array_map(static fn (string $id): string => (string) ($users[$id]['username'] ?? '?'), $announcement['dismissed']))) ?></td>
                  <td><?= app_escape(!$announcement['push'] ? __('non') : ($announcement['push_sent_at'] !== null ? __('envoyee_date', ['date' => date('d/m/Y', (int) app_change_time($announcement['push_sent_at']))]) : __('premier_jour'))) ?></td>
                  <td class="announcement-admin-table-actions">
                    <a class="secondary-button compact-button" href="announcements.php?modifier=<?= rawurlencode($announcement['id']) ?>"><?= app_escape(__('modifier')) ?></a>
                    <form method="post" action="announcement-action.php" onsubmit="return confirm(<?= app_escape(json_encode(__('supprimer_lannonce_title', ['title' => $announcement['title']]), JSON_UNESCAPED_UNICODE)) ?>);">
                      <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= app_escape($announcement['id']) ?>">
                      <input type="hidden" name="return" value="administrateur">
                      <button class="text-button" type="submit"><?= app_escape(__('supprimer')) ?></button>
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
          <h2 id="announcement-form-title"><?= $editing === null ? __('nouvelle_annonce') : 'Modifier l’annonce' ?></h2>
          <form method="post" action="announcement-action.php" enctype="multipart/form-data" class="form-stack section-assignment-form" data-announcement-form>
            <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= app_escape($form['id']) ?>">
            <label for="announcement-title"><?= app_escape(__('titre_3')) ?></label>
            <input id="announcement-title" name="title" required maxlength="<?= ANNOUNCEMENT_TITLE_MAX ?>" value="<?= app_escape($form['title']) ?>" placeholder="<?= app_escape(__('repetition_generale_exceptionnelle')) ?>">
            <label for="announcement-image"><?= app_escape(__('photo')) ?></label>
            <?php if ($form['image'] !== null): ?>
            <div class="announcement-image-current">
              <img src="<?= app_escape(app_announcement_image_url($form)) ?>" alt="<?= app_escape(__('image_actuelle_lannonce')) ?>">
              <label class="checkbox-row"><input type="checkbox" name="remove_image" value="1"> <?= app_escape(__('retirer_limage')) ?></label>
            </div>
            <?php endif; ?>
            <input id="announcement-image" name="image" type="file" accept="image/png,image/jpeg,image/gif,image/webp">
            <p class="field-help"><?= app_escape($form['image'] !== null ? __('png_jpeg_gif_webp_2_mo_plus_nouvelle') : __('png_jpeg_gif_webp_2_mo')) ?></p>
            <label class="active-toggle">
              <input type="checkbox" name="push" value="1"<?= $form['push'] ? ' checked' : '' ?><?= $form['push_sent_at'] !== null ? ' disabled' : '' ?>>
              <strong><?= app_escape(__('envoyer_notification')) ?></strong> — <?= app_escape($form['push_sent_at'] !== null ? __('deja_envoyee') : __('membres_concernes_ont_active_notifications_publication')) ?>
            </label>
            <?php if ($form['push_sent_at'] !== null): ?><input type="hidden" name="push" value="1"><?php endif; ?>
            <?php // Text, link, display period and audience: folded, summed up on the line that opens them. ?>
            <details class="form-accordion" data-announcement-options>
              <summary>
                <span class="form-accordion-title"><?= app_escape(__('texte_lien_periode_public')) ?></span>
                <span class="form-accordion-summary" data-announcement-summary><?= app_escape(__('js_annonce_visible_par', ['audience' => app_announcement_audience($form)]) . ' · ' . mb_strtolower(mb_substr(announcementPeriod($form), 0, 1)) . mb_substr(announcementPeriod($form), 1)) ?></span>
              </summary>
              <div class="form-accordion-body">
              <label for="announcement-body"><?= app_escape(__('texte')) ?></label>
              <textarea id="announcement-body" name="body" rows="5" maxlength="<?= ANNOUNCEMENT_BODY_MAX ?>" placeholder="<?= app_escape(__('telephones_adresses_e_mail_deviennent_cliquables_phrase')) ?>"><?= app_escape($form['body']) ?></textarea>
              <div class="form-grid">
                <div>
                  <label for="announcement-link"><?= app_escape(__('lien')) ?></label>
                  <input id="announcement-link" name="link_url" type="url" maxlength="2000" placeholder="<?= app_escape(__('https')) ?>" value="<?= app_escape($form['link_url']) ?>">
                </div>
                <div>
                  <label for="announcement-link-label"><?= app_escape(__('texte_lien')) ?></label>
                  <input id="announcement-link-label" name="link_label" maxlength="<?= ANNOUNCEMENT_LINK_LABEL_MAX ?>" placeholder="<?= app_escape(__('savoir_plus')) ?>" value="<?= app_escape($form['link_label']) ?>">
                </div>
              </div>
              <div class="form-grid">
                <div>
                  <label for="announcement-starts"><?= app_escape(__('affichee_partir')) ?></label>
                  <input id="announcement-starts" name="starts" type="date" required value="<?= app_escape($form['starts']) ?>">
                </div>
                <div>
                  <label for="announcement-ends"><?= app_escape(__('jusquau_inclus')) ?></label>
                  <input id="announcement-ends" name="ends" type="date" value="<?= app_escape((string) $form['ends']) ?>">
                </div>
              </div>
              <fieldset class="section-options">
                <legend><?= $isScopedManager ? __('voit_lannonce_dans_votre_perimetre') : __('voit_lannonce') ?></legend>
                <?php if (!$isScopedManager): ?>
                <label class="section-assignment-option section-assignment-parent announcement-everyone">
                  <input type="checkbox" name="everyone" value="1" data-announcement-everyone<?= $form['everyone'] ? ' checked' : '' ?>>
                  <?= app_escape(__('tout_monde')) ?>
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
                    <?= app_escape(__('section_toutes_sous_sections', ['section' => $definition['label']])) ?>
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
              <button class="primary-button" type="submit"><?= $editing === null ? 'Publier l’annonce' : __('enregistrer') ?></button>
              <a class="secondary-button" href="announcements.php"><?= app_escape(__('annuler')) ?></a>
            </div>
          </form>
        </section>
        <?php endif; ?>
      </section>
<?php app_footer(); ?>
