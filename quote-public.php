<?php
declare(strict_types=1);

// Public page of a published quote: no account needed, reached through its unique link. It shows
// the document as frozen at publication; a link withdrawn or replaced by a new publication no
// longer works.
require __DIR__ . '/lib/bootstrap.php';

$found = app_quote_by_public_token($_GET['t'] ?? null);
header('Cache-Control: private, no-store');

if ($found === null) {
    http_response_code(404);
    app_header('Devis introuvable');
    ?>
      <section class="management-section">
        <p class="management-panel muted-copy">Ce devis n’existe pas ou son lien a été retiré (le devis a peut-être été mis à jour). Demandez le lien à jour à l’association.</p>
      </section>
<?php
    app_footer();
    exit;
}

[, $quote] = $found;
$document = $quote['share']['document'];
$token = $quote['share']['token'];
app_header('Devis ' . $document['number'] . ' — ' . $document['issuer']['name']);
?>
      <section class="management-section quote-public">
        <div class="roadmap-actions">
          <button class="primary-button compact-button" type="button" data-print>Imprimer / enregistrer en PDF</button>
        </div>
        <?php app_render_quote_document($document, $document['issuer']['logo'] !== null ? 'quote-logo.php?' . http_build_query(['t' => $token]) : null); ?>
      </section>
<?php app_footer(); ?>
