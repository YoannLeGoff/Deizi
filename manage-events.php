<?php
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';
$user = app_require_roles(EVENT_MANAGER_ROLES);
// Reserved dates outside a manager's sub-sections are not listed (app_user_can_see_event()).
$events = array_values(array_filter(
    read_json_file(app_data_path('events.json')),
    static fn (mixed $event): bool => is_array($event) && app_user_can_see_event($user, $event)
));
$editId = $_GET['edit'] ?? '';
$editing = null;
if (is_string($editId)) {
    foreach ($events as $event) {
        if (($event['id'] ?? null) === $editId) {
            $editing = $event;
            break;
        }
    }
}
// Managers only edit the dates whose sub-sections are all in their management scope.
if ($editing !== null && !app_user_can_edit_event($user, $editing)) {
    app_flash(__('cette_date_concerne_sous_sections_hors'), 'error');
    app_redirect('event.php?id=' . rawurlencode((string) $editing['id']));
}
$isScopedManager = $user['role'] === 'manager';
$managedLeaves = $isScopedManager ? app_user_managed_leaves($user) : [];
// Tabs: the list of dates by default, the form to add a date, or the form of the date being edited.
$tab = $editing !== null ? 'edit' : match ($_GET['onglet'] ?? '') {
    'ajouter' => 'add',
    'presences' => 'presences',
    default => 'list',
};
$sectionLabels = app_section_labels();
$eventStatus = is_array($editing)
    && isset($editing['status'])
    && is_string($editing['status'])
    && isset(EVENT_STATUSES[$editing['status']])
    ? $editing['status']
    : 'pending';
$eventAllDay = is_array($editing) && ($editing['all_day'] ?? false) === true;
$editingSections = isset($editing['sections']) && is_array($editing['sections']) ? app_expand_sections($editing['sections']) : [];
// A new date starts with no section checked; an edited date shows its own.
$defaultEventSections = $editing === null ? [] : $editingSections;
usort($events, 'app_compare_events');

$settings = app_settings();
$roadmap = app_event_roadmap($editing ?? []);
$roadmapQr = app_roadmap_qr($roadmap, $settings);
// Existing rows plus an empty one to fill in; without contacts, the form starts with
// the default contact of the Configuration (role "Kerlenn Pondi").
$defaultContact = $settings['default_contact'];
$contactRows = $roadmap['contacts'] !== []
    ? [...$roadmap['contacts'], []]
    : [...($defaultContact['label'] !== '' || $defaultContact['details'] !== '' ? [$defaultContact] : []), []];
function roadmapRow(string $kind, string $index, array $values): void
{
    $name = static fn (string $field): string => 'roadmap[' . $kind . '][' . $index . '][' . $field . ']';
    $value = static fn (string $field): string => app_escape((string) ($values[$field] ?? ''));
    ?>
                  <div class="roadmap-row roadmap-row-<?= $kind ?>" data-roadmap-row>
                    <input name="<?= $name('label') ?>" maxlength="80" placeholder="<?= app_escape(__('role_ex_resp_kp')) ?>" aria-label="<?= app_escape(__('role_contact')) ?>" value="<?= $value('label') ?>">
                    <textarea name="<?= $name('details') ?>" rows="<?= max(2, substr_count((string) ($values['details'] ?? ''), "\n") + 1) ?>" placeholder="<?= app_escape(__('nom_telephone_e_mail')) ?>" aria-label="<?= app_escape(__('coordonnees_contact')) ?>"><?= $value('details') ?></textarea>
                    <button class="text-button roadmap-remove" type="button" data-remove-row aria-label="<?= app_escape(__('retirer_cette_ligne')) ?>"><?= app_escape(__('retirer')) ?></button>
                  </div>
<?php
}

// Row of a roadmap document: label, then a link or a file (PDF or image); a stored file is kept
// unless a new one is chosen.
function roadmapDocumentRow(string $index, array $document, array $event): void
{
    $file = $document['file'] ?? null;
    ?>
                  <div class="roadmap-row roadmap-document-row" data-roadmap-row>
                    <input name="roadmap[documents][<?= $index ?>][label]" maxlength="<?= ROADMAP_DOCUMENT_LABEL_MAX ?>" placeholder="<?= app_escape(__('intitule_ex_plan_dacces')) ?>" aria-label="<?= app_escape(__('intitule_document')) ?>" value="<?= app_escape((string) ($document['label'] ?? '')) ?>">
                    <?php if ($file !== null): ?>
                    <span class="roadmap-document-file">
                      <input type="hidden" name="roadmap[documents][<?= $index ?>][file]" value="<?= app_escape($file) ?>">
                      <a href="<?= app_escape(app_roadmap_document_url($event, $document)) ?>" target="_blank" rel="noopener"><?= app_escape(__('fichier_type', ['type' => strtoupper(pathinfo($file, PATHINFO_EXTENSION))])) ?> <span aria-hidden="true">↗</span></a>
                      <label class="roadmap-document-replace"><?= app_escape(__('remplacer_label')) ?> <input type="file" name="roadmap_document_files[<?= $index ?>]" accept="application/pdf,image/png,image/jpeg,image/webp"></label>
                    </span>
                    <?php else: ?>
                    <span class="roadmap-document-source">
                      <input name="roadmap[documents][<?= $index ?>][url]" type="url" maxlength="2000" placeholder="<?= app_escape(__('https_lien')) ?>" aria-label="<?= app_escape(__('lien_document')) ?>" value="<?= app_escape((string) ($document['url'] ?? '')) ?>">
                      <span class="roadmap-document-or"><?= app_escape(__('ou')) ?></span>
                      <input type="file" name="roadmap_document_files[<?= $index ?>]" accept="application/pdf,image/png,image/jpeg,image/webp" aria-label="<?= app_escape(__('fichier_document_pdf_image')) ?>">
                    </span>
                    <?php endif; ?>
                    <button class="text-button roadmap-remove" type="button" data-remove-row aria-label="<?= app_escape(__('retirer_ce_document')) ?>"><?= app_escape(__('retirer')) ?></button>
                  </div>
<?php
}

app_header('Gestion des dates', $user);
?>
      <section class="management-section">
        <div class="eyebrow-row">
          <p class="eyebrow"><span class="eyebrow-line"></span> <?= app_escape(__('espace_managers_majuscules')) ?></p>
          <?php app_page_links([['Retour à l’agenda', 'index.php']]); ?>
        </div>
        <div class="section-heading">
          <div>
            <h1><?= app_escape(__('gerer_dates')) ?><span class="heading-dot">.</span></h1>
          </div>
        </div>

        <nav class="detail-tabs" aria-label="<?= app_escape(__('gestion_dates')) ?>">
          <a class="detail-tab" href="manage-events.php"<?= $tab === 'list' ? ' aria-current="page"' : '' ?>><?= app_escape(__('dates_enregistrees')) ?> <span class="count-badge"><?= count($events) ?></span></a>
          <a class="detail-tab" href="<?= app_escape(app_matrix_tab_url()) ?>"<?= $tab === 'presences' ? ' aria-current="page"' : '' ?>><?= app_escape(__('tableau_presence')) ?></a>
          <a class="detail-tab" href="manage-events.php?onglet=ajouter"<?= $tab === 'add' ? ' aria-current="page"' : '' ?>><?= app_escape(__('plus_ajouter_date')) ?></a>
          <?php if ($editing !== null): ?>
          <a class="detail-tab" href="manage-events.php?edit=<?= rawurlencode((string) $editing['id']) ?>" aria-current="page"><?= app_escape(__('modifier_title', ['title' => app_truncate((string) ($editing['title'] ?? ''), 40)])) ?></a>
          <?php endif; ?>
        </nav>

        <?php if ($tab === 'presences'): ?>
        <section class="management-panel detail-panel has-tabs" aria-label="<?= app_escape(__('tableau_presence')) ?>">
          <?php app_matrix_tab($user, ($_GET['periode'] ?? '') === 'toutes'); ?>
        </section>
        <?php elseif ($tab !== 'list'): ?>
        <section class="management-panel detail-panel has-tabs" aria-labelledby="event-form-title">
          <h2 id="event-form-title"><?= $editing === null ? __('ajouter_date') : __('modifier_date') ?></h2>
          <form method="post" action="event-action.php" enctype="multipart/form-data" class="form-stack section-assignment-form" data-event-form>
            <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= app_escape((string) ($editing['id'] ?? '')) ?>">
            <label for="event-title"><?= app_escape(__('evenement_2')) ?></label>
            <input id="event-title" name="title" required maxlength="200" value="<?= app_escape((string) ($editing['title'] ?? '')) ?>">
            <label for="event-status"><?= app_escape(__('statut_2')) ?></label>
            <select id="event-status" name="status" required>
              <?php foreach (app_statuses() as $status => $label): ?>
              <option value="<?= app_escape($status) ?>"<?= $eventStatus === $status ? ' selected' : '' ?>><?= app_escape($label) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="form-grid">
              <div>
                <label for="event-date"><?= app_escape(__('date_3')) ?></label>
                <input id="event-date" name="date" type="date" required value="<?= app_escape((string) ($editing['date'] ?? '')) ?>">
              </div>
              <div>
                <label for="event-end-date"><?= app_escape(__('date_fin')) ?></label>
                <input id="event-end-date" name="end_date" type="date" min="<?= app_escape((string) ($editing['date'] ?? '')) ?>" value="<?= app_escape((string) ($editing['end_date'] ?? '')) ?>">
              </div>
            </div>
            <label class="checkbox-row event-all-day-option">
              <input type="checkbox" name="all_day" value="1" data-all-day<?= $eventAllDay ? ' checked' : '' ?>>
              <?= app_escape(__('journee_entiere_exemple_voyage')) ?>
            </label>
            <div class="form-grid">
              <div>
                <label for="event-time"><?= app_escape(__('heure_debut')) ?></label>
                <input id="event-time" name="time" placeholder="20h30" maxlength="50" data-event-time value="<?= app_escape((string) ($editing['time'] ?? '')) ?>">
              </div>
              <div>
                <label for="event-end-time"><?= app_escape(__('heure_fin')) ?></label>
                <input id="event-end-time" name="end_time" placeholder="22h40" maxlength="50" data-event-end-time value="<?= app_escape($editing !== null ? app_event_end_time($editing) : '') ?>">
              </div>
            </div>
            <label for="event-location"><?= app_escape(__('lieu')) ?></label>
            <input id="event-location" name="location" maxlength="300" value="<?= app_escape((string) ($editing['location'] ?? '')) ?>">
            <label for="event-url"><?= app_escape(__('lien_dinformation')) ?></label>
            <input id="event-url" name="url" type="url" maxlength="2000" placeholder="<?= app_escape(__('https')) ?>" value="<?= app_escape((string) ($editing['url'] ?? '')) ?>">
            <label for="event-comment"><?= app_escape(__('commentaire')) ?></label>
            <textarea id="event-comment" name="comment" rows="3" maxlength="3000" placeholder="<?= app_escape(__('informations_complementaires_visibles_membres_concernes')) ?>"><?= app_escape(app_event_comment($editing ?? [])) ?></textarea>
            <?php if (app_can_see_admin_note($user)): ?>
            <div class="admin-note-field">
              <label for="event-admin-note"><?= app_escape(__('note_admin')) ?> <span class="admin-only-badge"><?= app_escape(__('visible_organisateurs_logisticiens_administrateurs_uniquemen')) ?></span></label>
              <textarea id="event-admin-note" name="admin_note" rows="3" maxlength="3000" placeholder="<?= app_escape(__('suivi_interne_devis_echanges_avec_lorganisateur')) ?>"><?= app_escape(app_event_admin_note($editing ?? [])) ?></textarea>
            </div>
            <?php endif; ?>
            <?php if (in_array($user['role'], ALL_SECTIONS_ROLES, true)): ?>
            <?php // Checked for a new date; a date created by a manager has no logistics follow-up. ?>
            <label class="active-toggle event-logistics-option">
              <input type="checkbox" name="logistics" value="1"<?= $editing === null || app_event_needs_logistics($editing) ? ' checked' : '' ?>>
              <strong><?= app_escape(__('suivi_logistique')) ?></strong> <?= app_escape(__('date_apparait_dans_page_logistique_contrat')) ?>
            </label>
            <?php endif; ?>
            <label class="active-toggle event-tutti-option">
              <input type="checkbox" name="tutti" value="1"<?= ($editing['tutti'] ?? false) === true ? ' checked' : '' ?>>
              <strong><?= app_escape(__('tutti')) ?></strong> <?= app_escape(__('afficher_tutti_place_sections_dans_vues')) ?>
            </label>
            <?php // Managers cannot change it: their new dates are reserved, their existing dates keep their value. ?>
            <?php $eventReserved = $editing !== null ? app_event_is_reserved($editing) : $isScopedManager; ?>
            <label class="active-toggle event-reserved-option<?= $isScopedManager ? ' is-locked' : '' ?>">
              <input type="checkbox" name="reserved" value="1"<?= $eventReserved ? ' checked' : '' ?><?= $isScopedManager ? ' disabled' : '' ?>>
              <strong><?= app_escape(__('date_reservee')) ?></strong> <?= app_escape(__('visible_uniquement_membres_sous_sections_concernees_2')) ?>
            </label>
            <?php if ($isScopedManager): ?>
            <p class="field-help"><?= $editing === null ? __('dates_creees_manager_sont_toujours_reservees') : __('seuls_organisateurs_administrateurs_peuvent_modifier_ce') ?></p>
            <?php endif; ?>
            <fieldset class="section-options">
              <legend><?= $isScopedManager ? __('sections_concernees_dans_votre_perimetre') : __('sections_concernees') ?></legend>
              <?php foreach (app_section_tree() as $branch => $definition): ?>
              <?php
                  // Managers: only the sub-sections of their scope; the whole-branch box when they manage it all.
                  $branchChildren = $isScopedManager
                      ? array_intersect_key($definition['children'], array_flip($managedLeaves))
                      : $definition['children'];
                  if ($branchChildren === []) {
                      continue;
                  }
              ?>
              <div class="section-assignment-group">
                <?php if (count($branchChildren) === count($definition['children'])): ?>
                <?php $branchIsSelected = array_diff(array_keys($definition['children']), $defaultEventSections) === []; ?>
                <label class="section-assignment-option section-assignment-parent">
                  <input type="checkbox" name="sections[]" value="<?= app_escape($branch) ?>" data-section-parent<?= $branchIsSelected ? ' checked' : '' ?>>
                  <?= app_escape(__('section_toutes_sous_sections', ['section' => $definition['label']])) ?>
                </label>
                <?php else: ?>
                <span class="section-assignment-option section-assignment-parent"><?= app_escape($definition['label']) ?></span>
                <?php endif; ?>
                <div class="section-assignment-children">
                  <?php foreach ($branchChildren as $section => $label): ?>
                  <label class="section-assignment-option"><input type="checkbox" name="sections[]" value="<?= app_escape($section) ?>" data-section-child<?= in_array($section, $defaultEventSections, true) ? ' checked' : '' ?>> <?= app_escape($label) ?></label>
                  <?php endforeach; ?>
                </div>
              </div>
              <?php endforeach; ?>
            </fieldset>
            <?php if ($isScopedManager && $managedLeaves !== []): ?>
            <?php // A manager opens a reserved date to sub-sections outside their scope. ?>
            <?php $guestSections = $editing !== null ? app_event_leaves_outside_scope($user, $editing) : []; ?>
            <?php if ($eventReserved): ?>
            <fieldset class="section-options guest-sections">
              <legend><?= app_escape(__('ouvrir_aussi_sous_sections_hors_votre')) ?> <span class="field-optional"><?= app_escape(__('facultatif_parentheses')) ?></span></legend>
              <p class="field-help"><?= app_escape(__('membres_ces_sous_sections_leurs_managers')) ?></p>
              <?php foreach (app_section_tree() as $definition): ?>
              <?php $outsideChildren = array_diff_key($definition['children'], array_flip($managedLeaves)); ?>
              <?php if ($outsideChildren !== []): ?>
              <div class="section-assignment-group">
                <span class="section-assignment-option section-assignment-parent"><?= app_escape($definition['label']) ?></span>
                <div class="section-assignment-children">
                  <?php foreach ($outsideChildren as $section => $label): ?>
                  <label class="section-assignment-option"><input type="checkbox" name="guest_sections[]" value="<?= app_escape($section) ?>"<?= in_array($section, $guestSections, true) ? ' checked' : '' ?>> <?= app_escape($label) ?></label>
                  <?php endforeach; ?>
                </div>
              </div>
              <?php endif; ?>
              <?php endforeach; ?>
            </fieldset>
            <?php else: ?>
            <p class="field-help"><?= app_escape(__('cette_date_nest_reservee_peut_etre')) ?></p>
            <?php endif; ?>
            <?php endif; ?>

            <?php // "Feuille de route non disponible" is checked by default for a new date; an existing date keeps its value. ?>
            <?php $roadmapDisabled = $editing === null || ($editing['roadmap_disabled'] ?? false) === true; ?>
            <fieldset class="section-options roadmap-fieldset" id="roadmap">
              <legend><?= app_escape(__('feuille_route')) ?></legend>
              <label class="active-toggle roadmap-disabled-option">
                <input type="checkbox" name="roadmap_disabled" value="1" data-roadmap-disabled<?= $roadmapDisabled ? ' checked' : '' ?>>
                <strong><?= app_escape(__('feuille_route_non_disponible')) ?></strong> <?= app_escape(__('masquer_longlet_feuille_route_cette_date')) ?>
              </label>
              <?php // Folded while the roadmap is not available, but can still be opened to prepare it (menu.js). ?>
              <details class="roadmap-form-details" data-roadmap-details<?= $roadmapDisabled ? '' : ' open' ?>>
                <summary><span class="roadmap-form-show"><?= app_escape(__('afficher_formulaire_feuille_route')) ?></span><span class="roadmap-form-hide"><?= app_escape(__('masquer_formulaire_feuille_route')) ?></span></summary>
                <p class="field-help"><?= app_escape(__('ces_informations_composent_feuille_route_consultable')) ?></p>

                <div class="form-grid">
                  <div>
                    <label for="roadmap-location-note"><?= app_escape(__('precision_lieu')) ?></label>
                    <input id="roadmap-location-note" name="roadmap[location_note]" maxlength="300" placeholder="<?= app_escape(__('replis_rdc_cas_dintemperie')) ?>" value="<?= app_escape($roadmap['location_note']) ?>">
                  </div>
                  <div>
                    <label for="roadmap-location-url"><?= app_escape(__('lien_vers_lieu_plan')) ?></label>
                    <input id="roadmap-location-url" name="roadmap[location_url]" type="url" maxlength="2000" placeholder="<?= app_escape(__('https_maps_google_com')) ?>" value="<?= app_escape($roadmap['location_url']) ?>">
                  </div>
                </div>

                <div class="roadmap-form-group">
                  <span class="roadmap-form-label"><?= app_escape(__('contacts')) ?></span>
                  <p class="field-help"><?= app_escape(__('ligne_contact_dans_coordonnees_ligne_terminee')) ?></p>
                  <div class="roadmap-rows" data-roadmap-rows>
                    <?php foreach ($contactRows as $index => $contact): ?>
                    <?php roadmapRow('contacts', (string) $index, $contact); ?>
                    <?php endforeach; ?>
                    <template><?php roadmapRow('contacts', '__INDEX__', []); ?></template>
                    <button class="secondary-button compact-button" type="button" data-add-row><?= app_escape(__('ajouter_contact')) ?></button>
                  </div>
                </div>

                <div class="form-grid roadmap-general-form">
                  <?php foreach (['transport' => [__('transport'), ROADMAP_TRANSPORT], 'meals' => [__('repas'), null], 'outfit' => [__('tenue'), ROADMAP_OUTFITS]] as $field => [$fieldLabel, $options]): ?>
                  <div class="roadmap-form-group">
                    <?php if ($options === null): ?>
                    <label class="roadmap-form-label" for="roadmap-<?= $field ?>"><?= app_escape($fieldLabel) ?></label>
                    <textarea id="roadmap-<?= $field ?>" name="roadmap[<?= $field ?>]" rows="3" maxlength="500" placeholder="<?= app_escape(__('midi_local_kp_soir_non_inclus')) ?>"><?= app_escape($roadmap[$field]) ?></textarea>
                    <?php else: ?>
                    <span class="roadmap-form-label"><?= app_escape($fieldLabel) ?></span>
                    <?php foreach ($options as $value => $label): ?>
                    <label class="section-assignment-option"><input type="checkbox" name="roadmap[<?= $field ?>][]" value="<?= app_escape($value) ?>"<?= in_array($value, $roadmap[$field], true) ? ' checked' : '' ?>> <?= app_escape(app_label($label)) ?></label>
                    <?php endforeach; ?>
                    <?php endif; ?>
                  </div>
                  <?php endforeach; ?>
                </div>

                <?php // One box per repertoire, shown while one of its sections is checked above (menu.js). ?>
                <?php if ($settings['repertoires'] !== []): ?>
                <div class="roadmap-repertoire-form" data-repertoires>
                  <p class="roadmap-form-label"><?= app_escape(__('repertoire')) ?></p>
                  <p class="field-help" data-repertoire-empty><?= app_escape(__('aucune_sections_cochees_na_repertoire_phrase')) ?></p>
                  <div class="form-grid">
                  <?php foreach (app_repertoire_sections() as $key => $label): ?>
                  <?php $pieces = array_values(array_unique(array_merge($settings['repertoires'][$key], $roadmap['repertoire'][$key] ?? []))); ?>
                  <div class="roadmap-form-group" data-repertoire="<?= app_escape(implode(' ', app_repertoire_triggers($key))) ?>">
                    <span class="roadmap-form-label"><?= app_escape($label) ?></span>
                    <?php foreach ($pieces as $piece): ?>
                    <label class="section-assignment-option"><input type="checkbox" name="roadmap[repertoire][<?= $key ?>][]" value="<?= app_escape($piece) ?>"<?= in_array($piece, $roadmap['repertoire'][$key] ?? [], true) ? ' checked' : '' ?>> <?= app_escape($piece) ?></label>
                    <?php endforeach; ?>
                    <label for="roadmap-extra-<?= $key ?>" class="roadmap-extra-label"><?= app_escape(__('autres_morceaux_ligne')) ?></label>
                    <textarea id="roadmap-extra-<?= $key ?>" name="roadmap[repertoire_extra][<?= $key ?>]" rows="2"></textarea>
                  </div>
                  <?php endforeach; ?>
                  </div>
                </div>
                <?php endif; ?>

                <div class="roadmap-form-group roadmap-documents-form">
                  <span class="roadmap-form-label"><?= app_escape(__('documents')) ?></span>
                  <p class="field-help"><?= app_escape(__('feuille_route_lorganisateur_plan_dacces_lien')) ?></p>
                  <div class="roadmap-rows" data-roadmap-rows>
                    <?php foreach ([...$roadmap['documents'], []] as $documentIndex => $document): ?>
                    <?php roadmapDocumentRow((string) $documentIndex, $document, $editing ?? []); ?>
                    <?php endforeach; ?>
                    <template><?php roadmapDocumentRow('__INDEX__', [], $editing ?? []); ?></template>
                    <button class="secondary-button compact-button" type="button" data-add-row><?= app_escape(__('ajouter_document')) ?></button>
                  </div>
                </div>

                <div class="form-grid">
                  <div class="roadmap-form-group">
                    <label class="roadmap-form-label" for="roadmap-schedule"><?= app_escape(__('deroule_journee')) ?></label>
                    <p class="field-help"><?= app_escape(__('note_ligne_phrase')) ?></p>
                    <textarea id="roadmap-schedule" name="roadmap[schedule]" rows="<?= max(6, substr_count($roadmap['schedule'], "\n") + 2) ?>" placeholder="<?= app_escape(__('exemple_deroule_journee')) ?>"><?= app_escape($roadmap['schedule']) ?></textarea>
                  </div>
                  <div class="roadmap-form-group">
                    <label class="roadmap-form-label" for="roadmap-penn-notes"><?= app_escape(__('note_penn')) ?></label>
                    <p class="field-help"><?= app_escape(__('note_ligne_phrase')) ?></p>
                    <textarea id="roadmap-penn-notes" name="roadmap[penn_notes]" rows="<?= max(6, substr_count($roadmap['penn_notes'], "\n") + 2) ?>"><?= app_escape($roadmap['penn_notes']) ?></textarea>
                  </div>
                </div>

                <div class="roadmap-form-group">
                  <label class="roadmap-form-label" for="roadmap-notes"><?= app_escape(__('notes')) ?></label>
                  <p class="field-help"><?= app_escape(__('note_ligne_phrase')) ?></p>
                  <textarea id="roadmap-notes" name="roadmap[notes]" rows="<?= max(4, substr_count($roadmap['notes'], "\n") + 2) ?>" placeholder="<?= app_escape(__('prenez_gourde_avec_vous')) ?>"><?= app_escape($roadmap['notes']) ?></textarea>
                </div>

                <div class="roadmap-form-group qr-settings">
                  <span class="roadmap-form-label"><?= app_escape(__('qr_code_cette_date')) ?></span>
                  <div class="qr-settings-layout">
                    <figure class="qr-preview">
                      <?php if ($roadmapQr['image_url'] !== null): ?>
                      <img src="<?= app_escape($roadmapQr['image_url']) ?>" alt="<?= app_escape(__('qr_code_utilise')) ?>" width="100" height="100">
                      <figcaption><?= $roadmap['qr']['image'] !== null ? __('image_propre_date') : __('image_configuration') ?></figcaption>
                      <?php else: ?>
                      <figcaption><?= app_escape(__('aucune_image')) ?></figcaption>
                      <?php endif; ?>
                    </figure>
                    <div class="form-stack">
                      <p class="field-help"><?= app_escape(__('laissez_vide_utiliser_qr_code_defini')) ?></p>
                      <div class="form-grid">
                        <div>
                          <label for="roadmap-qr-caption"><?= app_escape(__('libelle')) ?></label>
                          <input id="roadmap-qr-caption" name="roadmap[qr][caption]" maxlength="80" placeholder="<?= app_escape($settings['qr']['caption']) ?>" value="<?= app_escape($roadmap['qr']['caption']) ?>">
                        </div>
                        <div>
                          <label for="roadmap-qr-link"><?= app_escape(__('lien')) ?></label>
                          <input id="roadmap-qr-link" name="roadmap[qr][link]" type="url" maxlength="2000" placeholder="<?= app_escape($settings['qr']['link']) ?>" value="<?= app_escape($roadmap['qr']['link']) ?>">
                        </div>
                      </div>
                      <label for="roadmap-qr-image"><?= app_escape(__('image_qr_code_png_jpeg_gif')) ?></label>
                      <input id="roadmap-qr-image" name="qr_image" type="file" accept="image/png,image/jpeg,image/gif,image/webp">
                      <?php if ($roadmap['qr']['image'] !== null): ?>
                      <label class="active-toggle"><input type="checkbox" name="qr_reset" value="1"> <?= app_escape(__('revenir_limage_configuration')) ?></label>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
              </details>
            </fieldset>

            <div class="form-actions">
              <button class="primary-button" type="submit"><?= $editing === null ? __('ajouter_date_2') : __('enregistrer_modifications') ?></button>
              <a class="secondary-button" href="manage-events.php"><?= app_escape(__('annuler')) ?></a>
            </div>
          </form>
        </section>
        <?php else: ?>

        <section class="management-panel detail-panel has-tabs" aria-labelledby="events-list-title">
          <h2 id="events-list-title" class="sr-only"><?= app_escape(__('dates_enregistrees')) ?></h2>
          <?php if ($events === []): ?>
          <p class="muted-copy"><?= app_escape(__('aucune_date_na_ete_ajoutee_phrase')) ?></p>
          <?php else: ?>
          <div class="managed-events">
            <?php foreach ($events as $event): ?>
            <article class="managed-event">
              <div>
                <strong><?php if (is_string($event['id'] ?? null)): ?><a class="event-title-link" href="event.php?id=<?= rawurlencode($event['id']) ?>"><?= app_escape((string) ($event['title'] ?? __('evenement_sans_titre'))) ?></a><?php else: ?><?= app_escape((string) ($event['title'] ?? __('evenement_sans_titre'))) ?><?php endif; ?></strong>
                <span><?= app_escape((string) ($event['date'] ?? '')) ?><?= !empty($event['end_date']) ? ' – ' . app_escape((string) $event['end_date']) : '' ?><?= ($event['all_day'] ?? false) === true ? ' · ' . app_escape(__('journee_entiere')) : (app_event_time_range($event) !== '' ? ' · ' . app_escape(app_event_time_range($event)) : '') ?></span>
                <?php $eventStatus = isset($event['status']) && is_string($event['status']) && isset(EVENT_STATUSES[$event['status']]) ? $event['status'] : 'pending'; ?>
                <span class="status-badge status-badge-<?= app_escape($eventStatus) ?>"><?= app_escape(app_statuses()[$eventStatus]) ?></span>
                <?php $eventSections = isset($event['sections']) && is_array($event['sections']) ? app_section_display_items(array_filter($event['sections'], 'is_string')) : []; ?>
                <?php if ($eventSections !== [] || app_event_is_reserved($event)): ?>
                <span class="managed-event-sections">
                  <?php foreach ($eventSections as $eventSection): ?>
                  <span class="section-tag <?= app_escape(app_section_css_class($eventSection)) ?>"><?= app_escape($sectionLabels[$eventSection]) ?></span>
                  <?php endforeach; ?>
                  <?php if (app_event_is_reserved($event)): ?><span class="reserved-tag" title="<?= app_escape(__('visible_uniquement_membres_sous_sections_concernees')) ?>"><?= app_escape(__('reservee')) ?></span><?php endif; ?>
                </span>
                <?php endif; ?>
              </div>
              <div class="managed-event-actions">
                <?php if (app_user_can_edit_event($user, $event)): ?>
                <a class="secondary-button compact-button" href="manage-events.php?edit=<?= rawurlencode((string) ($event['id'] ?? '')) ?>"><?= app_escape(__('modifier')) ?></a>
                <?php endif; ?>
              </div>
            </article>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </section>
        <?php endif; ?>
      </section>
<?php app_footer(); ?>
