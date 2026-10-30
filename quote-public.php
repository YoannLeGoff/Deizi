<?php
declare(strict_types=1);

// Public page of a published quote: no account needed, reached through its unique link. It shows
// the document as frozen at publication; a link withdrawn or replaced by a new publication no
// longer works.
require __DIR__ . '/lib/bootstrap.php';
if (app_maintenance_excludes(app_current_user())) {
    app_render_maintenance_page();
}

$found = app_quote_by_public_token($_GET['t'] ?? null);
header('Cache-Control: private, no-store');

if ($found === null) {
    http_response_code(404);
    app_header(__('devis_introuvable'));
    ?>
      <section class="management-section">
        <p class="management-panel muted-copy"><?= app_escape(__('ce_devis_nexiste_lien_ete_retire')) ?></p>
      </section>
<?php
    app_footer();
    exit;
}

[, $quote] = $found;
$document = $quote['share']['document'];
$token = $quote['share']['token'];
app_header(app_site_text('devis_number_issuer', ['number' => $document['number'], 'issuer' => $document['issuer']['name']]));
?>
      <section class="management-section quote-public">
        <div class="roadmap-actions">
          <button class="primary-button compact-button" type="button" data-print><?= app_escape(__('imprimer_enregistrer_pdf')) ?></button>
        </div>
        <?php app_render_quote_document($document, $document['issuer']['logo'] !== null ? 'quote-logo.php?' . http_build_query(['t' => $token]) : null); ?>
      </section>
<?php app_footer(); ?>
