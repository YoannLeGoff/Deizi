<?php
declare(strict_types=1);

// Quote generated from the "Logistique" page for the organizer of a date, laid out like the
// association's paper quotes (logo, address and footer set in "Paramètres des devis").
// ?event=…            form of a new quote, pre-filled from the date and its contract amount;
// ?event=…&devis=…    the quote as a printable document ("Imprimer / enregistrer en PDF");
// …&modifier=1        form of an existing generated quote (its number is kept).
require __DIR__ . '/lib/bootstrap.php';
$user = app_require_roles(LOGISTICS_ROLES);

$eventId = is_string($_GET['event'] ?? null) ? $_GET['event'] : '';
$event = null;
foreach (read_json_file(app_data_path('events.json')) as $candidate) {
    if (is_array($candidate) && ($candidate['id'] ?? null) === $eventId && $eventId !== '' && is_string($candidate['title'] ?? null)) {
        $event = $candidate;
    }
}
$eventDate = $event !== null ? app_parse_date($event['date'] ?? null) : null;
if ($event === null || $eventDate === null) {
    app_flash('Cette date n’existe plus.', 'error');
    app_redirect('logistics.php');
}
$followUp = app_event_logistics(app_logistics_read(), $eventId);
$quoteId = is_string($_GET['devis'] ?? null) ? $_GET['devis'] : '';
$quote = null;
foreach ($followUp['quotes'] as $candidate) {
    if ($candidate['id'] === $quoteId && $candidate['kind'] === 'sent') {
        $quote = $candidate;
    }
}
if ($quoteId !== '' && $quote === null) {
    app_flash('Ce devis n’existe plus.', 'error');
    app_redirect('logistics.php?event=' . rawurlencode($eventId));
}
$issuer = app_quote_issuer();
$pageUrl = 'logistics.php?event=' . rawurlencode($eventId);
// A quote entered before the editor (an amount per option, no line) only opens in the form, its
// options becoming options of one line each.
$editing = $quote === null || ($_GET['modifier'] ?? '') === '1' || !app_quote_is_generated($quote);
if ($editing && $quote !== null && $quote['share'] !== null) {
    app_flash('Ce devis est publié et donc figé' . "\u{00A0}" . ': retirez d’abord son lien public pour le modifier.', 'error');
    app_redirect('logistics-quote.php?' . http_build_query(['event' => $eventId, 'devis' => $quote['id']]));
}
if ($quote !== null && !app_quote_is_generated($quote)) {
    $quote['groups'] = array_map(
        static fn (array $option): array => ['title' => $option['title'], 'lines' => [['label' => $option['title'] !== '' ? $option['title'] : 'Prestation', 'detail' => '', 'quantity' => 1, 'unit' => $option['amount']]]],
        app_quote_options($quote)
    ) ?: [['title' => '', 'lines' => [['label' => 'Prestation', 'detail' => '', 'quantity' => 1, 'unit' => null]]]];
    $quote['date'] = $quote['date'] !== '' ? $quote['date'] : (new DateTimeImmutable('today', new DateTimeZone('Europe/Paris')))->format('Y-m-d');
}

if ($editing):
    $form = $quote ?? [
        'party' => '',
        'client_address' => '',
        'subject' => $event['title'],
        'date' => (new DateTimeImmutable('today', new DateTimeZone('Europe/Paris')))->format('Y-m-d'),
        'due' => $issuer['due'],
        'accepted_option' => null,
        'groups' => [['title' => 'Option 1', 'lines' => [['label' => 'Prestation', 'detail' => '', 'quantity' => 1, 'unit' => $followUp['contract'] ?? $followUp['fee']]]]],
        'conditions' => $issuer['conditions'],
        'status' => app_quote_status_default('draft'),
        'note' => '',
    ];
    // Previous organizers of this date's quotes, proposed in the "client" field.
    $knownClients = array_values(array_unique(array_column(app_logistics_quotes($followUp, 'sent'), 'party')));
    // Each group is shown with an empty line to fill (more lines and groups are added in the page).
    $emptyLine = ['label' => '', 'detail' => '', 'quantity' => 1, 'unit' => null];
    $formGroups = array_map(static function (array $group) use ($emptyLine): array {
        $group['lines'][] = $emptyLine;
        return $group;
    }, $form['groups']);
    $acceptedOption = $form['accepted_option'];

    // One line of the form; "__G__" and "__L__" stay in the templates used to add groups and lines.
    $renderLine = static function (string $g, string $l, array $line): string {
        $id = 'group-' . $g . '-line-' . $l;
        $name = 'groups[' . $g . '][lines][' . $l . ']';
        $total = $line['unit'] !== null ? app_format_amount($line['quantity'] * $line['unit']) : '';
        return '<fieldset class="quote-line-input" data-quote-line>'
            . '<legend class="sr-only">Ligne</legend>'
            . '<div class="quote-line-main">'
            . '<div class="form-stack"><label for="' . $id . '-label">Description</label><input id="' . $id . '-label" name="' . $name . '[label]" maxlength="200" value="' . app_escape($line['label']) . '"></div>'
            . '<div class="form-stack"><label for="' . $id . '-quantity">Qté</label><input id="' . $id . '-quantity" name="' . $name . '[quantity]" type="number" min="1" max="999" value="' . $line['quantity'] . '" data-quote-quantity></div>'
            . '<div class="form-stack"><label for="' . $id . '-unit">Prix unitaire (€)</label><input id="' . $id . '-unit" name="' . $name . '[unit]" inputmode="decimal" placeholder="0,00" value="' . app_escape(app_amount_input($line['unit'])) . '" data-quote-unit></div>'
            . '<div class="form-stack"><span class="quote-line-total-label">Prix total</span><output class="quote-line-total" data-quote-line-total>' . app_escape($total) . '</output></div>'
            . '</div>'
            . '<div class="quote-line-detail-row">'
            . '<div class="form-stack"><label for="' . $id . '-detail">Détail (facultatif, en petit sous la ligne)</label><textarea id="' . $id . '-detail" name="' . $name . '[detail]" rows="1" maxlength="400">' . app_escape($line['detail']) . '</textarea></div>'
            . '<button class="text-button quote-remove" type="button" data-remove-line>Retirer la ligne</button>'
            . '</div>'
            . '</fieldset>';
    };
    // One option of the quote: its title, its lines and its total; the radio button marks the option
    // accepted by the organizer.
    $renderGroup = static function (string $g, array $group, bool $accepted = false) use ($renderLine): string {
        $lines = '';
        foreach ($group['lines'] as $l => $line) {
            $lines .= $renderLine($g, (string) $l, $line);
        }
        return '<fieldset class="quote-group-input" data-quote-group data-group-index="' . $g . '">'
            . '<legend class="sr-only">Option</legend>'
            . '<div class="quote-group-head">'
            . '<div class="form-stack"><label for="group-' . $g . '-title">Titre de l’option</label><input id="group-' . $g . '-title" name="groups[' . $g . '][title]" maxlength="120" placeholder="Option 1 : Bagad seul" value="' . app_escape($group['title']) . '"></div>'
            . '<p class="quote-group-subtotal">Montant de l’option' . "\u{00A0}" . ': <strong data-quote-group-total></strong></p>'
            . '<button class="text-button quote-remove" type="button" data-remove-group>Retirer l’option</button>'
            . '</div>'
            . '<label class="active-toggle quote-accepted-choice"><input type="radio" name="accepted_group" value="' . $g . '"' . ($accepted ? ' checked' : '') . '> Option retenue par l’organisateur</label>'
            . '<div class="quote-line-inputs" data-quote-lines>' . $lines . '</div>'
            . '<button class="secondary-button compact-button" type="button" data-add-line>+ Ajouter une ligne</button>'
            . '</fieldset>';
    };

    app_header($quote === null ? 'Nouveau devis organisateur' : 'Modifier le devis' . ($quote['number'] !== '' ? ' ' . $quote['number'] : ''), $user);
?>
      <section class="management-section">
        <div class="eyebrow-row">
          <p class="eyebrow"><span class="eyebrow-line"></span> LOGISTIQUE</p>
          <?php app_page_links([['Retour à la date', app_logistics_tab_url($eventId, 'organisateurs')], ['Paramètres des devis', 'logistics-settings.php']]); ?>
        </div>
        <div class="section-heading">
          <div>
            <h1><?= $quote === null ? 'Nouveau devis' : 'Devis' . ($quote['number'] !== '' ? ' ' . app_escape($quote['number']) : '') ?><span class="heading-dot">.</span></h1>
            <p class="section-note"><?= app_escape($event['title'] . ' · ' . $eventDate->format('d/m/Y')) ?></p>
          </div>
        </div>

        <?php if ($issuer['logo'] === null): ?>
        <p class="management-panel muted-copy">Aucun logo n’est encore enregistré pour les devis : <a class="page-switch" href="logistics-settings.php">ajouter le logo dans les paramètres des devis</a>.</p>
        <?php endif; ?>

        <form method="post" action="logistics-action.php" enctype="multipart/form-data" class="form-stack">
          <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
          <input type="hidden" name="action" value="save_generated_quote">
          <input type="hidden" name="event_id" value="<?= app_escape($eventId) ?>">
          <input type="hidden" name="quote_id" value="<?= app_escape($quote['id'] ?? '') ?>">

          <section class="management-panel" aria-labelledby="client-title">
            <h2 class="detail-heading" id="client-title">Facturation pour</h2>
            <div class="form-grid align-top">
              <div class="form-stack">
                <label for="quote-client">Organisateur (contact et structure)</label>
                <input id="quote-client" name="party" maxlength="120" required list="quote-clients" placeholder="Marine GUEGANO / Danserion Bro Penquesten" value="<?= app_escape($form['party']) ?>">
                <datalist id="quote-clients"><?php foreach ($knownClients as $client): ?><option value="<?= app_escape($client) ?>"><?php endforeach; ?></datalist>
                <label for="quote-subject">Projet</label>
                <input id="quote-subject" name="subject" maxlength="160" value="<?= app_escape($form['subject']) ?>">
              </div>
              <div class="form-stack">
                <label for="quote-client-address">Adresse (facultative)</label>
                <textarea id="quote-client-address" name="client_address" rows="4" maxlength="400"><?= app_escape($form['client_address']) ?></textarea>
              </div>
            </div>
          </section>

          <section class="management-panel" aria-labelledby="content-title">
            <div class="detail-panel-heading">
              <h2 class="detail-heading" id="content-title">Options du devis</h2>
            </div>
            <p class="field-help">Le devis n’a pas de montant global : chaque option (par exemple « Bagad seul » et « Bagad et Cercle ») a ses lignes et son propre montant. Quand l’organisateur répond, indiquez-le dans le panneau Suivi du devis (statut Validé et option retenue, ou Refusé). Les lignes sans description et les options sans ligne sont ignorées<?= ($followUp['contract'] ?? $followUp['fee']) !== null && $quote === null ? ' ; la première ligne reprend le montant du contrat de la date' : '' ?>.</p>
            <div class="quote-groups" data-quote-groups data-max-groups="<?= QUOTE_MAX_OPTIONS ?>" data-max-lines="<?= QUOTE_MAX_LINES ?>">
              <?php foreach ($formGroups as $g => $group): ?>
              <?= $renderGroup((string) $g, $group, $acceptedOption === $g) ?>
              <?php endforeach; ?>
              <template data-group-template><?= $renderGroup('__G__', ['title' => '', 'lines' => [$emptyLine]]) ?></template>
              <template data-line-template><?= $renderLine('__G__', '__L__', $emptyLine) ?></template>
              <button class="secondary-button" type="button" data-add-group>+ Ajouter une option</button>
            </div>
          </section>

          <section class="management-panel" aria-labelledby="terms-title">
            <h2 class="detail-heading" id="terms-title">Dates et remarques</h2>
            <div class="form-grid logistics-quote-grid">
              <div class="form-stack">
                <label for="quote-date">Envoyé le</label>
                <input id="quote-date" name="date" type="date" required value="<?= app_escape($form['date']) ?>">
              </div>
              <div class="form-stack">
                <label for="quote-due">Date d’échéance</label>
                <input id="quote-due" name="due" maxlength="80" value="<?= app_escape($form['due']) ?>">
              </div>
              <div class="form-stack">
                <label for="quote-status">Statut</label>
                <select id="quote-status" name="status">
                  <?php foreach (app_quote_selectable_statuses(false) as $status => [$statusLabel]): ?>
                  <option value="<?= app_escape($status) ?>"<?= $form['status'] === $status ? ' selected' : '' ?>><?= app_escape($statusLabel) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="form-stack">
              <label for="quote-conditions">Remarques (une par ligne, imprimées avec une puce)</label>
              <textarea id="quote-conditions" name="conditions" rows="5" maxlength="2000"><?= app_escape($form['conditions']) ?></textarea>
              <label for="quote-file"><?= ($form['file'] ?? null) !== null ? 'Remplacer le fichier joint' : 'Fichier joint (facultatif, devis établi ailleurs : PDF ou image, 5 Mo maximum)' ?></label>
              <input id="quote-file" name="file" type="file" accept="application/pdf,image/png,image/jpeg,image/webp">
              <?php if (($form['file'] ?? null) !== null): ?>
              <p class="field-help">Fichier actuel : <a class="page-switch" href="logistics-file.php?<?= app_escape(http_build_query(['event' => $eventId, 'devis' => $form['id']])) ?>" target="_blank" rel="noopener"><?= app_escape($form['file_name']) ?></a></p>
              <label class="active-toggle"><input type="checkbox" name="remove_file" value="1"> Retirer ce fichier</label>
              <?php endif; ?>
              <label for="quote-note">Note interne (non imprimée)</label>
              <textarea id="quote-note" name="note" rows="2" maxlength="1000"><?= app_escape($form['note']) ?></textarea>
            </div>
          </section>

          <div class="form-actions">
            <button class="primary-button" type="submit"><?= $quote === null ? 'Générer le devis' : 'Enregistrer le devis' ?></button>
            <a class="secondary-button" href="<?= app_escape($quote === null || $quote['number'] === '' ? app_logistics_tab_url($eventId, 'organisateurs') : 'logistics-quote.php?' . http_build_query(['event' => $eventId, 'devis' => $quote['id']])) ?>">Annuler</a>
          </div>
        </form>
      </section>
<?php
    app_footer();
    exit;
endif;

// Printable document: as published when the quote has a public link (frozen), otherwise live.
$share = $quote['share'];
$document = $share !== null ? $share['document'] : app_quote_document($quote, $event, $issuer);
$documentLogo = $document['issuer']['logo'] !== null ? 'quote-logo.php?v=' . rawurlencode($document['issuer']['logo']) : null;
$options = app_quote_options($quote);
$csrf = app_csrf_token();
app_header('Devis ' . $quote['number'], $user);
?>
      <section class="management-section">
        <div class="eyebrow-row">
          <p class="eyebrow"><span class="eyebrow-line"></span> LOGISTIQUE</p>
          <?php app_page_links([['Retour à la date', app_logistics_tab_url($eventId, 'organisateurs')], ['Paramètres des devis', 'logistics-settings.php']]); ?>
        </div>
        <div class="section-heading">
          <div>
            <h1>Devis <?= app_escape($quote['number']) ?><span class="heading-dot">.</span></h1>
            <p class="section-note"><?= app_quote_status_badge($quote) ?><?php if ($share !== null): ?> <span class="quote-published-badge">Lien public · figé</span><?php endif; ?></p>
          </div>
          <div class="page-actions">
            <?php if ($share === null): ?>
            <a class="secondary-button compact-button" href="<?= app_escape('logistics-quote.php?' . http_build_query(['event' => $eventId, 'devis' => $quote['id'], 'modifier' => '1'])) ?>">Modifier</a>
            <?php endif; ?>
            <button class="primary-button compact-button" type="button" data-print>Imprimer / enregistrer en PDF</button>
          </div>
        </div>

        <section class="management-panel quote-share-panel" aria-labelledby="quote-share-title">
          <h2 class="detail-heading" id="quote-share-title">Lien public</h2>
          <?php if ($share !== null): ?>
          <div class="roadmap-share-link">
            <label class="sr-only" for="quote-share-url">Lien public du devis</label>
            <input id="quote-share-url" type="url" readonly value="<?= app_escape(app_quote_public_url($share['token'])) ?>">
            <button class="secondary-button compact-button" type="button" data-copy="quote-share-url">Copier le lien</button>
            <a class="secondary-button compact-button" href="<?= app_escape(app_quote_public_url($share['token'])) ?>" target="_blank" rel="noopener">Ouvrir</a>
          </div>
          <p class="field-help">Publié le <?= app_escape(app_format_log_time($share['published_at'])) ?><?= $share['published_by'] !== '' ? ' par ' . app_escape($share['published_by']) : '' ?>. Toute personne qui a ce lien peut consulter et imprimer le devis sans compte. Le devis est <strong>figé</strong> : pour le modifier, retirez le lien, modifiez-le, puis republiez-le ; un nouveau lien sera créé et l’ancien ne fonctionnera plus.</p>
          <form method="post" action="logistics-action.php" onsubmit="return confirm('Retirer le lien public ? Il cessera de fonctionner et le devis pourra de nouveau être modifié.');">
            <input type="hidden" name="csrf_token" value="<?= app_escape($csrf) ?>">
            <input type="hidden" name="action" value="unpublish_quote">
            <input type="hidden" name="event_id" value="<?= app_escape($eventId) ?>">
            <input type="hidden" name="quote_id" value="<?= app_escape($quote['id']) ?>">
            <button class="danger-button compact-button" type="submit">Retirer le lien pour modifier le devis</button>
          </form>
          <?php else: ?>
          <p class="muted-copy">Publiez le devis pour obtenir un lien unique à envoyer à l’organisateur : il pourra le consulter et l’imprimer sans compte. Une fois publié, le devis est figé jusqu’au retrait du lien.</p>
          <form method="post" action="logistics-action.php">
            <input type="hidden" name="csrf_token" value="<?= app_escape($csrf) ?>">
            <input type="hidden" name="action" value="publish_quote">
            <input type="hidden" name="event_id" value="<?= app_escape($eventId) ?>">
            <input type="hidden" name="quote_id" value="<?= app_escape($quote['id']) ?>">
            <button class="primary-button compact-button" type="submit">Publier le lien</button>
          </form>
          <?php endif; ?>
        </section>

        <section class="management-panel quote-tracking-panel" aria-labelledby="quote-tracking-title">
          <h2 class="detail-heading" id="quote-tracking-title">Suivi</h2>
          <p class="muted-copy">Réponse de l’organisateur et note interne : modifiables à tout moment, sans toucher au document.</p>
          <form method="post" action="logistics-action.php" class="form-stack">
            <input type="hidden" name="csrf_token" value="<?= app_escape($csrf) ?>">
            <input type="hidden" name="action" value="save_quote_tracking">
            <input type="hidden" name="event_id" value="<?= app_escape($eventId) ?>">
            <input type="hidden" name="quote_id" value="<?= app_escape($quote['id']) ?>">
            <div class="form-grid align-top">
              <div class="form-stack">
                <label for="tracking-status">Statut</label>
                <select id="tracking-status" name="status">
                  <?php foreach (app_quote_selectable_statuses($share !== null) as $status => [$statusLabel]): ?>
                  <option value="<?= app_escape($status) ?>"<?= $quote['status'] === $status ? ' selected' : '' ?>><?= app_escape($statusLabel) ?></option>
                  <?php endforeach; ?>
                </select>
                <?php if (count($options) > 1): ?>
                <label for="tracking-option">Option retenue par l’organisateur</label>
                <select id="tracking-option" name="accepted_option">
                  <option value="">Aucune pour l’instant</option>
                  <?php foreach ($options as $optionIndex => $option): ?>
                  <option value="<?= $optionIndex ?>"<?= $quote['accepted_option'] === $optionIndex ? ' selected' : '' ?>><?= app_escape($option['title'] . ' — ' . app_format_amount($option['amount'])) ?></option>
                  <?php endforeach; ?>
                </select>
                <?php endif; ?>
              </div>
              <div class="form-stack">
                <label for="tracking-note">Note interne (non imprimée)</label>
                <textarea id="tracking-note" name="note" rows="3" maxlength="1000"><?= app_escape($quote['note']) ?></textarea>
              </div>
            </div>
            <div class="form-actions"><button class="secondary-button compact-button" type="submit">Enregistrer le suivi</button></div>
          </form>
        </section>

        <?php app_render_quote_document($document, $documentLogo); ?>
      </section>
<?php app_footer(); ?>
