<?php
declare(strict_types=1);

// "Fermeture temporaire" (Configuration, administrators only): closes the application to everyone
// but the administrators while it is updated, with a message of the administrator's own (shown on
// the login page and instead of every page). Stored under "maintenance" in data/settings.json.
require __DIR__ . '/lib/bootstrap.php';
$admin = app_require_roles(['admin']);

// What the members see, for the administrator to check the message.
if (($_GET['apercu'] ?? '') === '1' && app_maintenance() !== null) {
    app_render_maintenance_page();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    app_verify_csrf();
    $action = $_POST['action'] ?? '';
    $message = app_clean_text($_POST['message'] ?? '', MAINTENANCE_MESSAGE_MAX_LENGTH);
    if (!in_array($action, ['close', 'reopen', 'save'], true)) {
        http_response_code(400);
        exit(__('action_inconnue_phrase'));
    }
    $now = (new DateTimeImmutable('now', new DateTimeZone('Europe/Paris')))->format(DATE_ATOM);
    update_json_file(app_data_path('settings.json'), static function (array $settings) use ($action, $message, $now, $admin): array {
        $current = is_array($settings['maintenance'] ?? null) ? $settings['maintenance'] : [];
        $settings['maintenance'] = match ($action) {
            'close' => ['active' => true, 'message' => $message, 'since' => $now, 'by' => (string) $admin['username']],
            'reopen' => ['active' => false, 'message' => $message],
            default => ['message' => $message] + $current,
        };
        return $settings;
    });
    app_flash(match ($action) {
        'close' => __('maintenance_fermee_flash'),
        'reopen' => __('maintenance_rouverte_flash'),
        default => __('maintenance_message_enregistre_flash'),
    });
    app_redirect('maintenance-settings.php');
}

$maintenance = app_maintenance();
$stored = read_json_file(app_data_path('settings.json'))['maintenance'] ?? [];
$message = is_array($stored) && is_string($stored['message'] ?? null) ? $stored['message'] : '';

app_header('Fermeture temporaire', $admin);
?>
      <section class="management-section">
        <div class="eyebrow-row">
          <p class="eyebrow"><span class="eyebrow-line"></span> <?= app_escape(__('configuration_suivi_securite_majuscules')) ?></p>
          <?php app_page_links([['Configuration', 'settings.php']]); ?>
        </div>
        <div class="section-heading">
          <div>
            <h1><?= app_escape(__('fermeture_temporaire')) ?><span class="heading-dot">.</span></h1>
          </div>
        </div>
        <?php app_settings_nav('suivi', 'maintenance-settings.php', $admin); ?>

        <p class="accounts-help"><?= app_escape(__('maintenance_aide_page')) ?></p>

        <section class="management-panel maintenance-state<?= $maintenance !== null ? ' is-closed' : '' ?>" aria-labelledby="maintenance-state-title">
          <h2 class="detail-heading" id="maintenance-state-title"><?= app_escape(__('maintenance_etat')) ?></h2>
          <?php if ($maintenance !== null): ?>
          <?php $since = $maintenance['since'] !== '' ? app_format_log_time($maintenance['since']) : ''; ?>
          <p><strong><?= app_escape(__('maintenance_etat_fermee')) ?></strong> <?= app_escape($since !== '' ? __('maintenance_depuis_par', ['time' => $since, 'name' => $maintenance['by']]) : '') ?></p>
          <p><a class="page-switch" href="maintenance-settings.php?apercu=1" target="_blank" rel="noopener"><?= app_escape(__('maintenance_voir_apercu')) ?></a></p>
          <?php else: ?>
          <p><strong><?= app_escape(__('maintenance_etat_ouverte')) ?></strong></p>
          <?php endif; ?>
        </section>

        <section class="management-panel" aria-labelledby="maintenance-message-title">
          <h2 class="detail-heading" id="maintenance-message-title"><?= app_escape(__('maintenance_message')) ?></h2>
          <form method="post" action="maintenance-settings.php" class="form-stack">
            <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
            <label for="maintenance-text"><?= app_escape(__('maintenance_message_label')) ?></label>
            <textarea id="maintenance-text" name="message" rows="4" maxlength="<?= MAINTENANCE_MESSAGE_MAX_LENGTH ?>" placeholder="<?= app_escape(__('maintenance_message_defaut')) ?>"><?= app_escape($message) ?></textarea>
            <p class="field-help"><?= app_escape(__('maintenance_message_aide')) ?></p>
            <div class="form-actions">
              <?php if ($maintenance === null): ?>
              <button class="danger-button" type="submit" name="action" value="close" onclick="return confirm(<?= app_escape(json_encode(__('maintenance_confirmer_fermeture'), JSON_UNESCAPED_UNICODE)) ?>);"><?= app_escape(__('maintenance_fermer')) ?></button>
              <button class="secondary-button" type="submit" name="action" value="save"><?= app_escape(__('maintenance_enregistrer_message')) ?></button>
              <?php else: ?>
              <button class="primary-button" type="submit" name="action" value="reopen"><?= app_escape(__('maintenance_rouvrir')) ?></button>
              <button class="secondary-button" type="submit" name="action" value="save"><?= app_escape(__('maintenance_enregistrer_message')) ?></button>
              <?php endif; ?>
            </div>
          </form>
        </section>
      </section>
<?php app_footer(); ?>
