<?php
declare(strict_types=1);

// Public page of a shared roadmap: no account needed, reached through its unique link.
require __DIR__ . '/lib/bootstrap.php';

if (app_maintenance_excludes(app_current_user())) {
    app_render_maintenance_page();
}
$token = $_GET['t'] ?? '';
$event = is_string($token) ? app_event_by_share_token($token) : null;
header('Cache-Control: private, no-store');

if ($event === null || app_parse_date($event['date'] ?? null) === null) {
    http_response_code(404);
    app_header(__('feuille_route_introuvable'));
    ?>
      <section class="management-section">
        <p class="management-panel muted-copy"><?= app_escape(__('ce_lien_feuille_route_nexiste_ete')) ?></p>
      </section>
<?php
    app_footer();
    exit;
}

$roadmap = app_event_roadmap($event);
app_header(__('feuille_route_title', ['title' => (string) ($event['title'] ?? '')]));
?>
      <section class="management-section">
        <section class="management-panel roadmap-panel" aria-label="<?= app_escape(__('feuille_route')) ?>">
          <?php if (app_roadmap_is_empty($roadmap)): ?>
          <p class="muted-copy"><?= app_escape(__('feuille_route_cette_date_na_encore')) ?></p>
          <?php else: ?>
          <div class="roadmap-actions">
            <button class="primary-button compact-button" type="button" data-print><?= app_escape(__('imprimer_enregistrer_pdf')) ?></button>
          </div>
          <?php app_render_roadmap($event, $roadmap, app_settings(), $token); ?>
          <?php endif; ?>
        </section>
      </section>
<?php app_footer(); ?>
