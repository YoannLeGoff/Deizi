<?php
declare(strict_types=1);

// Public page of a shared roadmap: no account needed, reached through its unique link.
require __DIR__ . '/lib/bootstrap.php';

$token = $_GET['t'] ?? '';
$event = is_string($token) ? app_event_by_share_token($token) : null;
header('Cache-Control: private, no-store');

if ($event === null || app_parse_date($event['date'] ?? null) === null) {
    http_response_code(404);
    app_header('Feuille de route introuvable');
    ?>
      <section class="management-section">
        <p class="management-panel muted-copy">Ce lien de feuille de route n’existe pas ou a été désactivé. Demandez le nouveau lien au groupe.</p>
      </section>
<?php
    app_footer();
    exit;
}

$roadmap = app_event_roadmap($event);
app_header('Feuille de route — ' . (string) ($event['title'] ?? ''));
?>
      <section class="management-section">
        <section class="management-panel roadmap-panel" aria-label="Feuille de route">
          <?php if (app_roadmap_is_empty($roadmap)): ?>
          <p class="muted-copy">La feuille de route de cette date n’a pas encore été renseignée.</p>
          <?php else: ?>
          <div class="roadmap-actions">
            <button class="primary-button compact-button" type="button" data-print>Imprimer / enregistrer en PDF</button>
          </div>
          <?php app_render_roadmap($event, $roadmap, app_settings(), $token); ?>
          <?php endif; ?>
        </section>
      </section>
<?php app_footer(); ?>
