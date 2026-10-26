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
    app_flash('Cette date concerne des sous-sections hors de votre périmètre de management : vous ne pouvez pas la modifier.', 'error');
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
                    <input name="<?= $name('label') ?>" maxlength="80" placeholder="Rôle (ex. Resp. KP)" aria-label="Rôle du contact" value="<?= $value('label') ?>">
                    <textarea name="<?= $name('details') ?>" rows="<?= max(2, substr_count((string) ($values['details'] ?? ''), "\n") + 1) ?>" placeholder="Nom, téléphone, e-mail…" aria-label="Coordonnées du contact"><?= $value('details') ?></textarea>
                    <button class="text-button roadmap-remove" type="button" data-remove-row aria-label="Retirer cette ligne">Retirer</button>
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
                    <input name="roadmap[documents][<?= $index ?>][label]" maxlength="<?= ROADMAP_DOCUMENT_LABEL_MAX ?>" placeholder="Intitulé (ex. Plan d’accès)" aria-label="Intitulé du document" value="<?= app_escape((string) ($document['label'] ?? '')) ?>">
                    <?php if ($file !== null): ?>
                    <span class="roadmap-document-file">
                      <input type="hidden" name="roadmap[documents][<?= $index ?>][file]" value="<?= app_escape($file) ?>">
                      <a href="<?= app_escape(app_roadmap_document_url($event, $document)) ?>" target="_blank" rel="noopener">Fichier <?= app_escape(strtoupper(pathinfo($file, PATHINFO_EXTENSION))) ?> <span aria-hidden="true">↗</span></a>
                      <label class="roadmap-document-replace">Remplacer<?= "\u{00A0}" ?>: <input type="file" name="roadmap_document_files[<?= $index ?>]" accept="application/pdf,image/png,image/jpeg,image/webp"></label>
                    </span>
                    <?php else: ?>
                    <span class="roadmap-document-source">
                      <input name="roadmap[documents][<?= $index ?>][url]" type="url" maxlength="2000" placeholder="https://… (lien)" aria-label="Lien du document" value="<?= app_escape((string) ($document['url'] ?? '')) ?>">
                      <span class="roadmap-document-or">ou</span>
                      <input type="file" name="roadmap_document_files[<?= $index ?>]" accept="application/pdf,image/png,image/jpeg,image/webp" aria-label="Fichier du document (PDF ou image)">
                    </span>
                    <?php endif; ?>
                    <button class="text-button roadmap-remove" type="button" data-remove-row aria-label="Retirer ce document">Retirer</button>
                  </div>
<?php
}

app_header('Gestion des dates', $user);
?>
      <section class="management-section">
        <div class="eyebrow-row">
          <p class="eyebrow"><span class="eyebrow-line"></span> ESPACE MANAGERS</p>
          <?php app_page_links([['Retour à l’agenda', 'index.php']]); ?>
        </div>
        <div class="section-heading">
          <div>
            <h1>Gérer les dates<span class="heading-dot">.</span></h1>
          </div>
        </div>

        <nav class="detail-tabs" aria-label="Gestion des dates">
          <a class="detail-tab" href="manage-events.php"<?= $tab === 'list' ? ' aria-current="page"' : '' ?>>Dates enregistrées <span class="count-badge"><?= count($events) ?></span></a>
          <a class="detail-tab" href="<?= app_escape(app_matrix_tab_url()) ?>"<?= $tab === 'presences' ? ' aria-current="page"' : '' ?>>Tableau de présence</a>
          <a class="detail-tab" href="manage-events.php?onglet=ajouter"<?= $tab === 'add' ? ' aria-current="page"' : '' ?>>+ Ajouter une date</a>
          <?php if ($editing !== null): ?>
          <a class="detail-tab" href="manage-events.php?edit=<?= rawurlencode((string) $editing['id']) ?>" aria-current="page">Modifier : <?= app_escape(app_truncate((string) ($editing['title'] ?? ''), 40)) ?></a>
          <?php endif; ?>
        </nav>

        <?php if ($tab === 'presences'): ?>
        <section class="management-panel detail-panel has-tabs" aria-label="Tableau de présence">
          <?php app_matrix_tab($user, ($_GET['periode'] ?? '') === 'toutes'); ?>
        </section>
        <?php elseif ($tab !== 'list'): ?>
        <section class="management-panel detail-panel has-tabs" aria-labelledby="event-form-title">
          <h2 id="event-form-title"><?= $editing === null ? 'Ajouter une date' : 'Modifier une date' ?></h2>
          <form method="post" action="event-action.php" enctype="multipart/form-data" class="form-stack section-assignment-form" data-event-form>
            <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= app_escape((string) ($editing['id'] ?? '')) ?>">
            <label for="event-title">Événement *</label>
            <input id="event-title" name="title" required maxlength="200" value="<?= app_escape((string) ($editing['title'] ?? '')) ?>">
            <label for="event-status">Statut *</label>
            <select id="event-status" name="status" required>
              <?php foreach (app_statuses() as $status => $label): ?>
              <option value="<?= app_escape($status) ?>"<?= $eventStatus === $status ? ' selected' : '' ?>><?= app_escape($label) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="form-grid">
              <div>
                <label for="event-date">Date *</label>
                <input id="event-date" name="date" type="date" required value="<?= app_escape((string) ($editing['date'] ?? '')) ?>">
              </div>
              <div>
                <label for="event-end-date">Date de fin</label>
                <input id="event-end-date" name="end_date" type="date" min="<?= app_escape((string) ($editing['date'] ?? '')) ?>" value="<?= app_escape((string) ($editing['end_date'] ?? '')) ?>">
              </div>
            </div>
            <label class="checkbox-row event-all-day-option">
              <input type="checkbox" name="all_day" value="1" data-all-day<?= $eventAllDay ? ' checked' : '' ?>>
              Journée entière (par exemple pour un voyage)
            </label>
            <div class="form-grid">
              <div>
                <label for="event-time">Heure de début</label>
                <input id="event-time" name="time" placeholder="20h30" maxlength="50" data-event-time value="<?= app_escape((string) ($editing['time'] ?? '')) ?>">
              </div>
              <div>
                <label for="event-end-time">Heure de fin</label>
                <input id="event-end-time" name="end_time" placeholder="22h40" maxlength="50" data-event-end-time value="<?= app_escape($editing !== null ? app_event_end_time($editing) : '') ?>">
              </div>
            </div>
            <label for="event-location">Lieu</label>
            <input id="event-location" name="location" maxlength="300" value="<?= app_escape((string) ($editing['location'] ?? '')) ?>">
            <label for="event-url">Lien d’information</label>
            <input id="event-url" name="url" type="url" maxlength="2000" placeholder="https://" value="<?= app_escape((string) ($editing['url'] ?? '')) ?>">
            <label for="event-comment">Commentaire</label>
            <textarea id="event-comment" name="comment" rows="3" maxlength="3000" placeholder="Informations complémentaires visibles par les membres concernés"><?= app_escape(app_event_comment($editing ?? [])) ?></textarea>
            <?php if (app_can_see_admin_note($user)): ?>
            <div class="admin-note-field">
              <label for="event-admin-note">Note admin <span class="admin-only-badge">Visible des organisateurs, logisticiens et administrateurs uniquement</span></label>
              <textarea id="event-admin-note" name="admin_note" rows="3" maxlength="3000" placeholder="Suivi interne : devis, échanges avec l’organisateur…"><?= app_escape(app_event_admin_note($editing ?? [])) ?></textarea>
            </div>
            <?php endif; ?>
            <?php if (in_array($user['role'], ALL_SECTIONS_ROLES, true)): ?>
            <?php // Checked for a new date; a date created by a manager has no logistics follow-up. ?>
            <label class="active-toggle event-logistics-option">
              <input type="checkbox" name="logistics" value="1"<?= $editing === null || app_event_needs_logistics($editing) ? ' checked' : '' ?>>
              <strong>Suivi logistique</strong> — la date apparaît dans la page Logistique (contrat, cachet, devis)
            </label>
            <?php endif; ?>
            <label class="active-toggle event-tutti-option">
              <input type="checkbox" name="tutti" value="1"<?= ($editing['tutti'] ?? false) === true ? ' checked' : '' ?>>
              <strong>Tutti</strong> — afficher « Tutti » à la place des sections dans les vues avancées
            </label>
            <?php // Managers cannot change it: their new dates are reserved, their existing dates keep their value. ?>
            <?php $eventReserved = $editing !== null ? app_event_is_reserved($editing) : $isScopedManager; ?>
            <label class="active-toggle event-reserved-option<?= $isScopedManager ? ' is-locked' : '' ?>">
              <input type="checkbox" name="reserved" value="1"<?= $eventReserved ? ' checked' : '' ?><?= $isScopedManager ? ' disabled' : '' ?>>
              <strong>Date réservée</strong> — visible uniquement par les membres des sous-sections concernées, leurs managers, les organisateurs et les administrateurs
            </label>
            <?php if ($isScopedManager): ?>
            <p class="field-help"><?= $editing === null ? 'Les dates créées par un manager sont toujours réservées aux sous-sections de son périmètre.' : 'Seuls les organisateurs et les administrateurs peuvent modifier ce choix.' ?></p>
            <?php endif; ?>
            <fieldset class="section-options">
              <legend><?= $isScopedManager ? 'Sections concernées (dans votre périmètre)' : 'Sections concernées' ?></legend>
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
                  <?= app_escape($definition['label']) ?> — toutes les sous-sections
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
              <legend>Ouvrir aussi à des sous-sections hors de votre périmètre <span class="field-optional">(facultatif)</span></legend>
              <p class="field-help">Les membres de ces sous-sections et leurs managers verront aussi cette date réservée.</p>
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
            <p class="field-help">Cette date n’est pas réservée : elle ne peut pas être ouverte à des sous-sections hors de votre périmètre.</p>
            <?php endif; ?>
            <?php endif; ?>

            <?php // "Feuille de route non disponible" is checked by default for a new date; an existing date keeps its value. ?>
            <?php $roadmapDisabled = $editing === null || ($editing['roadmap_disabled'] ?? false) === true; ?>
            <fieldset class="section-options roadmap-fieldset" id="roadmap">
              <legend>Feuille de route</legend>
              <label class="active-toggle roadmap-disabled-option">
                <input type="checkbox" name="roadmap_disabled" value="1" data-roadmap-disabled<?= $roadmapDisabled ? ' checked' : '' ?>>
                <strong>Feuille de route non disponible</strong> — masquer l’onglet « Feuille de route » de cette date pour tous les utilisateurs et désactiver son lien public
              </label>
              <?php // Folded while the roadmap is not available, but can still be opened to prepare it (menu.js). ?>
              <details class="roadmap-form-details" data-roadmap-details<?= $roadmapDisabled ? '' : ' open' ?>>
                <summary><span class="roadmap-form-show">Afficher le formulaire de la feuille de route</span><span class="roadmap-form-hide">Masquer le formulaire de la feuille de route</span></summary>
                <p class="field-help">Ces informations composent la feuille de route, consultable et imprimable par les membres concernés depuis la page de la date. Les rubriques vides ne sont pas affichées. Le type de sortie est déduit des sections concernées ci-dessus.</p>

                <div class="form-grid">
                  <div>
                    <label for="roadmap-location-note">Précision sur le lieu</label>
                    <input id="roadmap-location-note" name="roadmap[location_note]" maxlength="300" placeholder="replis au RDC en cas d’intempérie" value="<?= app_escape($roadmap['location_note']) ?>">
                  </div>
                  <div>
                    <label for="roadmap-location-url">Lien vers le lieu (plan)</label>
                    <input id="roadmap-location-url" name="roadmap[location_url]" type="url" maxlength="2000" placeholder="https://maps.google.com/…" value="<?= app_escape($roadmap['location_url']) ?>">
                  </div>
                </div>

                <div class="roadmap-form-group">
                  <span class="roadmap-form-label">Contacts</span>
                  <p class="field-help">Une ligne par contact. Dans les coordonnées, une ligne terminée par « : » s’affiche en gras ; téléphones et e-mails deviennent cliquables.</p>
                  <div class="roadmap-rows" data-roadmap-rows>
                    <?php foreach ($contactRows as $index => $contact): ?>
                    <?php roadmapRow('contacts', (string) $index, $contact); ?>
                    <?php endforeach; ?>
                    <template><?php roadmapRow('contacts', '__INDEX__', []); ?></template>
                    <button class="secondary-button compact-button" type="button" data-add-row>Ajouter un contact</button>
                  </div>
                </div>

                <div class="form-grid roadmap-general-form">
                  <?php foreach (['transport' => ['Transport', ROADMAP_TRANSPORT], 'meals' => ['Repas', null], 'outfit' => ['Tenue', ROADMAP_OUTFITS]] as $field => [$fieldLabel, $options]): ?>
                  <div class="roadmap-form-group">
                    <?php if ($options === null): ?>
                    <label class="roadmap-form-label" for="roadmap-<?= $field ?>"><?= app_escape($fieldLabel) ?></label>
                    <textarea id="roadmap-<?= $field ?>" name="roadmap[<?= $field ?>]" rows="3" maxlength="500" placeholder="Midi au local KP, soir non inclus"><?= app_escape($roadmap[$field]) ?></textarea>
                    <?php else: ?>
                    <span class="roadmap-form-label"><?= app_escape($fieldLabel) ?></span>
                    <?php foreach ($options as $value => $label): ?>
                    <label class="section-assignment-option"><input type="checkbox" name="roadmap[<?= $field ?>][]" value="<?= app_escape($value) ?>"<?= in_array($value, $roadmap[$field], true) ? ' checked' : '' ?>> <?= app_escape($label) ?></label>
                    <?php endforeach; ?>
                    <?php endif; ?>
                  </div>
                  <?php endforeach; ?>
                </div>

                <?php // One box per repertoire, shown while one of its sections is checked above (menu.js). ?>
                <?php if ($settings['repertoires'] !== []): ?>
                <div class="roadmap-repertoire-form" data-repertoires>
                  <p class="roadmap-form-label">Répertoire</p>
                  <p class="field-help" data-repertoire-empty>Aucune des sections cochées n’a de répertoire.</p>
                  <div class="form-grid">
                  <?php foreach (app_repertoire_sections() as $key => $label): ?>
                  <?php $pieces = array_values(array_unique(array_merge($settings['repertoires'][$key], $roadmap['repertoire'][$key] ?? []))); ?>
                  <div class="roadmap-form-group" data-repertoire="<?= app_escape(implode(' ', app_repertoire_triggers($key))) ?>">
                    <span class="roadmap-form-label"><?= app_escape($label) ?></span>
                    <?php foreach ($pieces as $piece): ?>
                    <label class="section-assignment-option"><input type="checkbox" name="roadmap[repertoire][<?= $key ?>][]" value="<?= app_escape($piece) ?>"<?= in_array($piece, $roadmap['repertoire'][$key] ?? [], true) ? ' checked' : '' ?>> <?= app_escape($piece) ?></label>
                    <?php endforeach; ?>
                    <label for="roadmap-extra-<?= $key ?>" class="roadmap-extra-label">Autres morceaux (un par ligne)</label>
                    <textarea id="roadmap-extra-<?= $key ?>" name="roadmap[repertoire_extra][<?= $key ?>]" rows="2"></textarea>
                  </div>
                  <?php endforeach; ?>
                  </div>
                </div>
                <?php endif; ?>

                <div class="roadmap-form-group roadmap-documents-form">
                  <span class="roadmap-form-label">Documents</span>
                  <p class="field-help">Feuille de route de l’organisateur, plan d’accès… : un lien, ou un fichier PDF ou image de 10 Mo au plus. Les documents figurent sur la feuille de route, y compris son lien public.</p>
                  <div class="roadmap-rows" data-roadmap-rows>
                    <?php foreach ([...$roadmap['documents'], []] as $documentIndex => $document): ?>
                    <?php roadmapDocumentRow((string) $documentIndex, $document, $editing ?? []); ?>
                    <?php endforeach; ?>
                    <template><?php roadmapDocumentRow('__INDEX__', [], $editing ?? []); ?></template>
                    <button class="secondary-button compact-button" type="button" data-add-row>Ajouter un document</button>
                  </div>
                </div>

                <div class="form-grid">
                  <div class="roadmap-form-group">
                    <label class="roadmap-form-label" for="roadmap-schedule">Déroulé de la journée</label>
                    <p class="field-help">Une note par ligne.</p>
                    <textarea id="roadmap-schedule" name="roadmap[schedule]" rows="<?= max(6, substr_count($roadmap['schedule'], "\n") + 2) ?>" placeholder="20h00 : RDV mise en place des percussions&#10;20h15 : Balance bande son"><?= app_escape($roadmap['schedule']) ?></textarea>
                  </div>
                  <div class="roadmap-form-group">
                    <label class="roadmap-form-label" for="roadmap-penn-notes">Note des Penn</label>
                    <p class="field-help">Une note par ligne.</p>
                    <textarea id="roadmap-penn-notes" name="roadmap[penn_notes]" rows="<?= max(6, substr_count($roadmap['penn_notes'], "\n") + 2) ?>"><?= app_escape($roadmap['penn_notes']) ?></textarea>
                  </div>
                </div>

                <div class="roadmap-form-group">
                  <label class="roadmap-form-label" for="roadmap-notes">Notes</label>
                  <p class="field-help">Une note par ligne.</p>
                  <textarea id="roadmap-notes" name="roadmap[notes]" rows="<?= max(4, substr_count($roadmap['notes'], "\n") + 2) ?>" placeholder="Prenez une gourde avec vous…"><?= app_escape($roadmap['notes']) ?></textarea>
                </div>

                <div class="roadmap-form-group qr-settings">
                  <span class="roadmap-form-label">QR code de cette date</span>
                  <div class="qr-settings-layout">
                    <figure class="qr-preview">
                      <?php if ($roadmapQr['image_url'] !== null): ?>
                      <img src="<?= app_escape($roadmapQr['image_url']) ?>" alt="QR code utilisé" width="100" height="100">
                      <figcaption><?= $roadmap['qr']['image'] !== null ? 'Image propre à la date' : 'Image de la Configuration' ?></figcaption>
                      <?php else: ?>
                      <figcaption>Aucune image</figcaption>
                      <?php endif; ?>
                    </figure>
                    <div class="form-stack">
                      <p class="field-help">Laissez vide pour utiliser le QR code défini dans la Configuration.</p>
                      <div class="form-grid">
                        <div>
                          <label for="roadmap-qr-caption">Libellé</label>
                          <input id="roadmap-qr-caption" name="roadmap[qr][caption]" maxlength="80" placeholder="<?= app_escape($settings['qr']['caption']) ?>" value="<?= app_escape($roadmap['qr']['caption']) ?>">
                        </div>
                        <div>
                          <label for="roadmap-qr-link">Lien</label>
                          <input id="roadmap-qr-link" name="roadmap[qr][link]" type="url" maxlength="2000" placeholder="<?= app_escape($settings['qr']['link']) ?>" value="<?= app_escape($roadmap['qr']['link']) ?>">
                        </div>
                      </div>
                      <label for="roadmap-qr-image">Image du QR code (PNG, JPEG, GIF ou WebP, 2 Mo maximum)</label>
                      <input id="roadmap-qr-image" name="qr_image" type="file" accept="image/png,image/jpeg,image/gif,image/webp">
                      <?php if ($roadmap['qr']['image'] !== null): ?>
                      <label class="active-toggle"><input type="checkbox" name="qr_reset" value="1"> Revenir à l’image de la Configuration</label>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
              </details>
            </fieldset>

            <div class="form-actions">
              <button class="primary-button" type="submit"><?= $editing === null ? 'Ajouter la date' : 'Enregistrer les modifications' ?></button>
              <a class="secondary-button" href="manage-events.php">Annuler</a>
            </div>
          </form>
        </section>
        <?php else: ?>

        <section class="management-panel detail-panel has-tabs" aria-labelledby="events-list-title">
          <h2 id="events-list-title" class="sr-only">Dates enregistrées</h2>
          <?php if ($events === []): ?>
          <p class="muted-copy">Aucune date n’a été ajoutée.</p>
          <?php else: ?>
          <div class="managed-events">
            <?php foreach ($events as $event): ?>
            <article class="managed-event">
              <div>
                <strong><?php if (is_string($event['id'] ?? null)): ?><a class="event-title-link" href="event.php?id=<?= rawurlencode($event['id']) ?>"><?= app_escape((string) ($event['title'] ?? 'Événement sans titre')) ?></a><?php else: ?><?= app_escape((string) ($event['title'] ?? 'Événement sans titre')) ?><?php endif; ?></strong>
                <span><?= app_escape((string) ($event['date'] ?? '')) ?><?= !empty($event['end_date']) ? ' – ' . app_escape((string) $event['end_date']) : '' ?><?= ($event['all_day'] ?? false) === true ? ' · Journée entière' : (app_event_time_range($event) !== '' ? ' · ' . app_escape(app_event_time_range($event)) : '') ?></span>
                <?php $eventStatus = isset($event['status']) && is_string($event['status']) && isset(EVENT_STATUSES[$event['status']]) ? $event['status'] : 'pending'; ?>
                <span class="status-badge status-badge-<?= app_escape($eventStatus) ?>"><?= app_escape(app_statuses()[$eventStatus]) ?></span>
                <?php $eventSections = isset($event['sections']) && is_array($event['sections']) ? app_section_display_items(array_filter($event['sections'], 'is_string')) : []; ?>
                <?php if ($eventSections !== [] || app_event_is_reserved($event)): ?>
                <span class="managed-event-sections">
                  <?php foreach ($eventSections as $eventSection): ?>
                  <span class="section-tag <?= app_escape(app_section_css_class($eventSection)) ?>"><?= app_escape($sectionLabels[$eventSection]) ?></span>
                  <?php endforeach; ?>
                  <?php if (app_event_is_reserved($event)): ?><span class="reserved-tag" title="Visible uniquement par les membres des sous-sections concernées, leurs managers, les organisateurs et les administrateurs">Réservée</span><?php endif; ?>
                </span>
                <?php endif; ?>
              </div>
              <div class="managed-event-actions">
                <?php if (app_user_can_edit_event($user, $event)): ?>
                <a class="secondary-button compact-button" href="manage-events.php?edit=<?= rawurlencode((string) ($event['id'] ?? '')) ?>">Modifier</a>
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
