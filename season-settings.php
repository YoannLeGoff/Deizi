<?php
declare(strict_types=1);

// "Saisons" (Configuration, administrators only): first day of the seasons, which group the
// dates (Logistique). A season ends the day before the next start.
require __DIR__ . '/lib/bootstrap.php';
$admin = app_require_roles(['admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    app_verify_csrf();
    $day = $_POST['start_day'] ?? '';
    $month = $_POST['start_month'] ?? '';
    if (!is_string($day) || !ctype_digit($day) || !is_string($month) || !ctype_digit($month) || !app_season_start_is_valid((int) $day, (int) $month)) {
        app_flash(__('choisissez_jour_existe_chaque_annee_29'), 'error');
        app_redirect('season-settings.php');
    }
    update_json_file(app_data_path('settings.json'), static function (array $settings) use ($day, $month): array {
        $settings['season'] = ['start_day' => (int) $day, 'start_month' => (int) $month];
        return $settings;
    });
    app_flash(__('debut_saisons_ete_enregistre_phrase'));
    app_redirect('season-settings.php');
}

$start = app_season_start();
$today = new DateTimeImmutable('today', new DateTimeZone('Europe/Paris'));
$current = app_season_of($today);
$next = app_season_of($current['last']->modify('+1 day'));

app_header('Saisons', $admin);
?>
      <section class="management-section">
        <div class="eyebrow-row">
          <p class="eyebrow"><span class="eyebrow-line"></span> <?= app_escape(__('configuration_calendrier_majuscules')) ?></p>
          <?php app_page_links([['Configuration', 'settings.php']]); ?>
        </div>
        <div class="section-heading">
          <div>
            <h1><?= app_escape(__('saisons')) ?><span class="heading-dot">.</span></h1>
          </div>
        </div>
        <?php app_settings_nav('calendrier', 'season-settings.php', $admin); ?>

        <p class="accounts-help"><?= app_escape(__('dates_sont_regroupees_saison_dans_logistique')) ?></p>

        <section class="management-panel" aria-labelledby="season-start-title">
          <h2 class="detail-heading" id="season-start-title"><?= app_escape(__('debut_saisons')) ?></h2>
          <form method="post" action="season-settings.php" class="form-stack">
            <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
            <div class="form-grid align-top season-start-grid">
              <div class="form-stack">
                <label for="season-day"><?= app_escape(__('jour')) ?></label>
                <select id="season-day" name="start_day">
                  <?php for ($day = 1; $day <= 31; $day++): ?>
                  <option value="<?= $day ?>"<?= $start['day'] === $day ? ' selected' : '' ?>><?= app_escape(app_language_root(app_locale()) === 'fr' && $day === 1 ? '1er' : (string) $day) ?></option>
                  <?php endfor; ?>
                </select>
              </div>
              <div class="form-stack">
                <label for="season-month"><?= app_escape(__('mois')) ?></label>
                <select id="season-month" name="start_month">
                  <?php for ($month = 1; $month <= 12; $month++): ?>
                  <option value="<?= $month ?>"<?= $start['month'] === $month ? ' selected' : '' ?>><?= app_escape(app_month_name($month)) ?></option>
                  <?php endfor; ?>
                </select>
              </div>
            </div>
            <p class="field-help"><?= app_escape(__('defaut_1er_septembre_debut_1er_janvier', ['season' => __('saison_years', ['years' => '2026'])])) ?></p>
            <div class="form-actions"><button class="primary-button" type="submit"><?= app_escape(__('enregistrer')) ?></button></div>
          </form>
        </section>

        <section class="management-panel" aria-labelledby="season-preview-title">
          <h2 class="detail-heading" id="season-preview-title"><?= app_escape(__('apercu')) ?></h2>
          <ul class="season-preview">
            <li><strong><?= app_escape($current['label']) ?></strong> <?= app_escape(__('cours_range', ['range' => app_season_range($current)])) ?></li>
            <li><strong><?= app_escape($next['label']) ?></strong><?= app_escape(__('suffixe_range', ['range' => app_season_range($next)])) ?></li>
          </ul>
        </section>
      </section>
<?php app_footer(); ?>
