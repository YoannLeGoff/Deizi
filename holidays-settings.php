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
        app_flash('Les jours fériés légaux ont été enregistrés.');
    } elseif ($action === 'add_custom') {
        $date = app_parse_date($_POST['date'] ?? null);
        $label = app_clean_line($_POST['label'] ?? '', 80);
        if ($date === null || $label === '') {
            app_flash('Indiquez une date et un libellé.', 'error');
            app_redirect($pageUrl);
        }
        $recurring = ($_POST['recurring'] ?? '') === '1';
        app_update_calendar_settings(static function (array $data) use ($date, $label, $recurring): array {
            $data['custom_holidays'][] = ['id' => bin2hex(random_bytes(6)), 'date' => $date->format('Y-m-d'), 'label' => $label, 'recurring' => $recurring];
            return $data;
        });
        app_flash('Le jour « ' . $label . ' » a été ajouté.');
    } elseif ($action === 'delete_custom') {
        $id = $_POST['id'] ?? '';
        app_update_calendar_settings(static function (array $data) use ($id): array {
            $data['custom_holidays'] = array_values(array_filter(
                is_array($data['custom_holidays'] ?? null) ? $data['custom_holidays'] : [],
                static fn (mixed $holiday): bool => !is_array($holiday) || ($holiday['id'] ?? null) !== $id
            ));
            return $data;
        });
        app_flash('Le jour a été supprimé.');
    } else {
        http_response_code(400);
        exit('Action inconnue.');
    }
    app_redirect($pageUrl);
}

$settings = app_calendar_settings();
$holidays = app_public_holidays($year, $settings);
$weekdays = ['', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];
$formatDay = static fn (DateTimeImmutable $date): string => $weekdays[(int) $date->format('N')] . ' ' . $date->format('d/m/Y');

app_header('Jours fériés', $admin);
?>
      <section class="management-section">
        <div class="eyebrow-row">
          <p class="eyebrow"><span class="eyebrow-line"></span> CONFIGURATION · CALENDRIER</p>
          <?php app_page_links([['Planning ' . $year, 'reporting.php?vue=planning&annee=' . $year], ['Configuration', 'settings.php']]); ?>
        </div>
        <div class="section-heading">
          <div>
            <h1>Jours fériés<span class="heading-dot">.</span></h1>
          </div>
        </div>
        <?php app_settings_nav('calendrier', 'holidays-settings.php', $admin); ?>
        <p class="accounts-help">Les jours fériés apparaissent en foncé dans le planning annuel. Les jours dépendant de Pâques sont recalculés pour chaque année.</p>

        <div class="calendar-settings-layout">
          <div>
            <section class="management-panel" aria-labelledby="legal-holidays-title">
              <h2 class="detail-heading" id="legal-holidays-title">Jours fériés légaux</h2>
              <form method="post" action="<?= app_escape($pageUrl) ?>" class="form-stack">
                <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
                <input type="hidden" name="action" value="save_rules">
                <ul class="holiday-rules">
                  <?php foreach (PUBLIC_HOLIDAY_RULES as $rule => [$label, $when]): ?>
                  <li>
                    <label class="active-toggle"><input type="checkbox" name="enabled[]" value="<?= $rule ?>"<?= in_array($rule, $settings['disabled_holidays'], true) ? '' : ' checked' ?>> <?= app_escape($label) ?></label>
                    <span class="log-sub"><?= is_int($when) ? 'Pâques + ' . $when . ' jours' : 'Le ' . app_escape(app_public_holiday_rule_date($rule, $year)->format('d/m')) ?> · <?= app_escape($formatDay(app_public_holiday_rule_date($rule, $year))) ?></span>
                  </li>
                  <?php endforeach; ?>
                </ul>
                <div class="form-actions"><button class="primary-button compact-button" type="submit">Enregistrer</button></div>
              </form>
            </section>

            <section class="management-panel" aria-labelledby="custom-holidays-title">
              <h2 class="detail-heading" id="custom-holidays-title">Autres jours</h2>
              <p class="muted-copy">Jours chômés propres au groupe ou à la commune, pour une année donnée ou tous les ans.</p>
              <?php if ($settings['custom_holidays'] !== []): ?>
              <ul class="calendar-items">
                <?php foreach ($settings['custom_holidays'] as $holiday): ?>
                <li>
                  <span><strong><?= app_escape($holiday['label']) ?></strong> <span class="log-sub"><?= $holiday['recurring'] ? 'Chaque année le ' . app_escape($holiday['date']->format('d/m')) : app_escape($formatDay($holiday['date'])) ?></span></span>
                  <form method="post" action="<?= app_escape($pageUrl) ?>" onsubmit="return confirm('Supprimer ce jour ?');">
                    <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
                    <input type="hidden" name="action" value="delete_custom">
                    <input type="hidden" name="id" value="<?= app_escape($holiday['id']) ?>">
                    <button class="text-button" type="submit">Supprimer</button>
                  </form>
                </li>
                <?php endforeach; ?>
              </ul>
              <?php endif; ?>
              <form method="post" action="<?= app_escape($pageUrl) ?>" class="calendar-add-form">
                <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
                <input type="hidden" name="action" value="add_custom">
                <div>
                  <label for="custom-date">Date</label>
                  <input id="custom-date" name="date" type="date" required>
                </div>
                <div class="calendar-add-label">
                  <label for="custom-label">Libellé</label>
                  <input id="custom-label" name="label" maxlength="80" required placeholder="Pardon de Pontivy">
                </div>
                <label class="active-toggle"><input type="checkbox" name="recurring" value="1"> Tous les ans</label>
                <button class="secondary-button compact-button" type="submit">Ajouter</button>
              </form>
            </section>
          </div>

          <aside class="management-panel calendar-preview" aria-labelledby="holidays-preview-title">
            <div class="detail-panel-heading">
              <h2 class="detail-heading" id="holidays-preview-title">Jours retenus en <?= $year ?></h2>
              <nav class="calendar-preview-years" aria-label="Changer d’année">
                <a class="page-switch" href="holidays-settings.php?annee=<?= $year - 1 ?>">← <?= $year - 1 ?></a>
                <a class="page-switch" href="holidays-settings.php?annee=<?= $year + 1 ?>"><?= $year + 1 ?> →</a>
              </nav>
            </div>
            <?php if ($holidays === []): ?>
            <p class="muted-copy">Aucun jour férié retenu.</p>
            <?php else: ?>
            <ul class="calendar-items">
              <?php foreach ($holidays as $day => $label): ?>
              <li><span><?= app_escape($label) ?></span> <span class="log-sub"><?= app_escape($formatDay(new DateTimeImmutable($day))) ?></span></li>
              <?php endforeach; ?>
            </ul>
            <?php endif; ?>
          </aside>
        </div>
      </section>
<?php app_footer(); ?>
