<?php
declare(strict_types=1);

// Configuration of the public holidays shown in the annual planning.
require __DIR__ . '/lib/bootstrap.php';
$admin = app_require_roles(ACCOUNT_MANAGER_ROLES);

$yearInput = $_GET['annee'] ?? '';
$year = is_string($yearInput) && preg_match('/^\d{4}$/', $yearInput) === 1 && (int) $yearInput >= 2000 && (int) $yearInput <= 2100
    ? (int) $yearInput
    : (int) date('Y');
$pageUrl = 'holidays-settings.php?annee=' . $year;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    app_verify_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'save_rules') {
        $enabled = app_clean_choices($_POST['enabled'] ?? [], PUBLIC_HOLIDAY_RULES);
        $disabled = array_values(array_diff(array_keys(PUBLIC_HOLIDAY_RULES), $enabled));
        app_update_calendar_settings(static fn (array $data): array => ['disabled_holidays' => $disabled] + $data);
        app_flash(__('jours_feries_legaux_ont_ete_enregistres_phrase'));
    } elseif ($action === 'add_custom') {
        $date = app_parse_date($_POST['date'] ?? null);
        $label = app_clean_line($_POST['label'] ?? '', 80);
        if ($date === null || $label === '') {
            app_flash(__('indiquez_date_libelle_phrase'), 'error');
            app_redirect($pageUrl);
        }
        $recurring = ($_POST['recurring'] ?? '') === '1';
        app_update_calendar_settings(static function (array $data) use ($date, $label, $recurring): array {
            $data['custom_holidays'][] = ['id' => bin2hex(random_bytes(6)), 'date' => $date->format('Y-m-d'), 'label' => $label, 'recurring' => $recurring];
            return $data;
        });
        app_flash(__('jour_label_ete_ajoute_phrase', ['label' => $label]));
    } elseif ($action === 'delete_custom') {
        $id = $_POST['id'] ?? '';
        app_update_calendar_settings(static function (array $data) use ($id): array {
            $data['custom_holidays'] = array_values(array_filter(
                is_array($data['custom_holidays'] ?? null) ? $data['custom_holidays'] : [],
                static fn (mixed $holiday): bool => !is_array($holiday) || ($holiday['id'] ?? null) !== $id
            ));
            return $data;
        });
        app_flash(__('jour_ete_supprime_phrase'));
    } else {
        http_response_code(400);
        exit(__('action_inconnue_phrase'));
    }
    app_redirect($pageUrl);
}

$settings = app_calendar_settings();
$holidays = app_public_holidays($year, $settings);
$formatDay = static fn (DateTimeImmutable $date): string => app_weekday_name((int) $date->format('N')) . ' ' . $date->format('d/m/Y');

app_header('Jours fériés', $admin);
?>
      <section class="management-section">
        <div class="eyebrow-row">
          <p class="eyebrow"><span class="eyebrow-line"></span> <?= app_escape(__('configuration_calendrier_majuscules')) ?></p>
          <?php app_page_links([[__('planning_year', ['year' => $year]), 'reporting.php?vue=planning&annee=' . $year], ['Configuration', 'settings.php']]); ?>
        </div>
        <div class="section-heading">
          <div>
            <h1><?= app_escape(__('jours_feries')) ?><span class="heading-dot">.</span></h1>
          </div>
        </div>
        <?php app_settings_nav('calendrier', 'holidays-settings.php', $admin); ?>
        <p class="accounts-help"><?= app_escape(__('jours_feries_apparaissent_fonce_dans_planning')) ?></p>

        <div class="calendar-settings-layout">
          <div>
            <section class="management-panel" aria-labelledby="legal-holidays-title">
              <h2 class="detail-heading" id="legal-holidays-title"><?= app_escape(__('jours_feries_legaux')) ?></h2>
              <form method="post" action="<?= app_escape($pageUrl) ?>" class="form-stack">
                <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
                <input type="hidden" name="action" value="save_rules">
                <ul class="holiday-rules">
                  <?php foreach (PUBLIC_HOLIDAY_RULES as $rule => [$label, $when]): ?>
                  <li>
                    <label class="active-toggle"><input type="checkbox" name="enabled[]" value="<?= $rule ?>"<?= in_array($rule, $settings['disabled_holidays'], true) ? '' : ' checked' ?>> <?= app_escape(app_label($label)) ?></label>
                    <span class="log-sub"><?= app_escape(is_int($when) ? __('paques_jours', ['n' => $when]) : __('le_date', ['date' => app_public_holiday_rule_date($rule, $year)->format('d/m')])) ?> · <?= app_escape($formatDay(app_public_holiday_rule_date($rule, $year))) ?></span>
                  </li>
                  <?php endforeach; ?>
                </ul>
                <div class="form-actions"><button class="primary-button compact-button" type="submit"><?= app_escape(__('enregistrer')) ?></button></div>
              </form>
            </section>

            <section class="management-panel" aria-labelledby="custom-holidays-title">
              <h2 class="detail-heading" id="custom-holidays-title"><?= app_escape(__('autres_jours')) ?></h2>
              <p class="muted-copy"><?= app_escape(__('jours_chomes_propres_groupe_commune_annee')) ?></p>
              <?php if ($settings['custom_holidays'] !== []): ?>
              <ul class="calendar-items">
                <?php foreach ($settings['custom_holidays'] as $holiday): ?>
                <li>
                  <span><strong><?= app_escape($holiday['label']) ?></strong> <span class="log-sub"><?= app_escape($holiday['recurring'] ? __('chaque_annee_date', ['date' => $holiday['date']->format('d/m')]) : $formatDay($holiday['date'])) ?></span></span>
                  <form method="post" action="<?= app_escape($pageUrl) ?>" onsubmit="return confirm(<?= app_escape(json_encode(__('supprimer_ce_jour'), JSON_UNESCAPED_UNICODE)) ?>);">
                    <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
                    <input type="hidden" name="action" value="delete_custom">
                    <input type="hidden" name="id" value="<?= app_escape($holiday['id']) ?>">
                    <button class="text-button" type="submit"><?= app_escape(__('supprimer')) ?></button>
                  </form>
                </li>
                <?php endforeach; ?>
              </ul>
              <?php endif; ?>
              <form method="post" action="<?= app_escape($pageUrl) ?>" class="calendar-add-form">
                <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
                <input type="hidden" name="action" value="add_custom">
                <div>
                  <label for="custom-date"><?= app_escape(__('date')) ?></label>
                  <input id="custom-date" name="date" type="date" required>
                </div>
                <div class="calendar-add-label">
                  <label for="custom-label"><?= app_escape(__('libelle')) ?></label>
                  <input id="custom-label" name="label" maxlength="80" required placeholder="<?= app_escape(__('pardon_pontivy')) ?>">
                </div>
                <label class="active-toggle"><input type="checkbox" name="recurring" value="1"> <?= app_escape(__('tous_ans')) ?></label>
                <button class="secondary-button compact-button" type="submit"><?= app_escape(__('ajouter')) ?></button>
              </form>
            </section>
          </div>

          <aside class="management-panel calendar-preview" aria-labelledby="holidays-preview-title">
            <div class="detail-panel-heading">
              <h2 class="detail-heading" id="holidays-preview-title"><?= app_escape(__('jours_retenus_year', ['year' => $year])) ?></h2>
              <nav class="calendar-preview-years" aria-label="<?= app_escape(__('changer_dannee')) ?>">
                <a class="page-switch" href="holidays-settings.php?annee=<?= $year - 1 ?>">← <?= $year - 1 ?></a>
                <a class="page-switch" href="holidays-settings.php?annee=<?= $year + 1 ?>"><?= $year + 1 ?> →</a>
              </nav>
            </div>
            <?php if ($holidays === []): ?>
            <p class="muted-copy"><?= app_escape(__('aucun_jour_ferie_retenu_phrase')) ?></p>
            <?php else: ?>
            <ul class="calendar-items">
              <?php foreach ($holidays as $day => $label): ?>
              <li><span><?= app_escape(app_label($label)) ?></span> <span class="log-sub"><?= app_escape($formatDay(new DateTimeImmutable($day))) ?></span></li>
              <?php endforeach; ?>
            </ul>
            <?php endif; ?>
          </aside>
        </div>
      </section>
<?php app_footer(); ?>
