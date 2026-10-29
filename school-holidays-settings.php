<?php
declare(strict_types=1);

// Configuration of the school holiday periods shown in the annual planning.
require __DIR__ . '/lib/bootstrap.php';
$admin = app_require_roles(ACCOUNT_MANAGER_ROLES);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    app_verify_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'add' || $action === 'update') {
        $label = app_clean_line($_POST['label'] ?? '', 80);
        $start = app_parse_date($_POST['start'] ?? null);
        $end = app_parse_date($_POST['end'] ?? null);
        $id = $action === 'update' && is_string($_POST['id'] ?? null) ? $_POST['id'] : bin2hex(random_bytes(6));
        if ($label === '' || $start === null || $end === null || $end < $start) {
            app_flash(__('indiquez_nom_date_debut_date_fin'), 'error');
            app_redirect('school-holidays-settings.php');
        }
        if ((int) $start->diff($end)->days > SCHOOL_HOLIDAY_MAX_DAYS) {
            app_flash(__('periode_peut_depasser_jours_phrase', ['n' => SCHOOL_HOLIDAY_MAX_DAYS]), 'error');
            app_redirect('school-holidays-settings.php');
        }
        $period = ['id' => $id, 'label' => $label, 'start' => $start->format('Y-m-d'), 'end' => $end->format('Y-m-d')];
        app_update_calendar_settings(static function (array $data) use ($period): array {
            $periods = array_values(array_filter(
                is_array($data['school_holidays'] ?? null) ? $data['school_holidays'] : [],
                static fn (mixed $existing): bool => !is_array($existing) || ($existing['id'] ?? null) !== $period['id']
            ));
            $periods[] = $period;
            $data['school_holidays'] = $periods;
            return $data;
        });
        app_flash($action === 'add' ? __('periode_label_ete_ajoutee_phrase', ['label' => $label]) : __('periode_ete_mise_jour_phrase'));
    } elseif ($action === 'delete') {
        $id = $_POST['id'] ?? '';
        app_update_calendar_settings(static function (array $data) use ($id): array {
            $data['school_holidays'] = array_values(array_filter(
                is_array($data['school_holidays'] ?? null) ? $data['school_holidays'] : [],
                static fn (mixed $period): bool => !is_array($period) || ($period['id'] ?? null) !== $id
            ));
            return $data;
        });
        app_flash(__('periode_ete_supprimee_phrase'));
    } else {
        http_response_code(400);
        exit(__('action_inconnue_phrase'));
    }
    app_redirect('school-holidays-settings.php');
}

$periods = app_calendar_settings()['school_holidays'];
$formatDay = static fn (DateTimeImmutable $date): string => app_weekday_name((int) $date->format('N')) . ' ' . $date->format('d/m/Y');
// School years: a period belongs to the school year of its start (September to August).
$schoolYear = static function (DateTimeImmutable $start): string {
    $year = (int) $start->format('Y') - ((int) $start->format('n') < 9 ? 1 : 0);
    return $year . '-' . ($year + 1);
};
$byYear = [];
foreach ($periods as $period) {
    $byYear[$schoolYear($period['start'])][] = $period;
}

app_header('Vacances scolaires', $admin);
?>
      <section class="management-section">
        <div class="eyebrow-row">
          <p class="eyebrow"><span class="eyebrow-line"></span> <?= app_escape(__('configuration_calendrier_majuscules')) ?></p>
          <?php app_page_links([[__('planning_annuel'), 'reporting.php?vue=planning'], ['Configuration', 'settings.php']]); ?>
        </div>
        <div class="section-heading">
          <div>
            <h1><?= app_escape(__('vacances_scolaires')) ?><span class="heading-dot">.</span></h1>
          </div>
        </div>
        <?php app_settings_nav('calendrier', 'school-holidays-settings.php', $admin); ?>
        <p class="accounts-help"><?= app_escape(__('periodes_vacances_apparaissent_dans_planning_annuel')) ?></p>

        <section class="management-panel" aria-labelledby="new-period-title">
          <h2 class="detail-heading" id="new-period-title"><?= app_escape(__('ajouter_periode')) ?></h2>
          <form method="post" action="school-holidays-settings.php" class="calendar-add-form">
            <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
            <input type="hidden" name="action" value="add">
            <div class="calendar-add-label">
              <label for="period-label"><?= app_escape(__('nom')) ?></label>
              <input id="period-label" name="label" maxlength="80" required list="school-holiday-names" placeholder="<?= app_escape(__('vacances_printemps')) ?>">
              <datalist id="school-holiday-names">
                <?php foreach (SCHOOL_HOLIDAY_NAMES as $name): ?><option value="<?= app_escape($name) ?>"><?php endforeach; ?>
              </datalist>
            </div>
            <div>
              <label for="period-start"><?= app_escape(__('du')) ?></label>
              <input id="period-start" name="start" type="date" required>
            </div>
            <div>
              <label for="period-end"><?= app_escape(__('inclus')) ?></label>
              <input id="period-end" name="end" type="date" required>
            </div>
            <button class="primary-button compact-button" type="submit"><?= app_escape(__('ajouter')) ?></button>
          </form>
        </section>

        <?php if ($byYear === []): ?>
        <p class="management-panel muted-copy"><?= app_escape(__('aucune_periode_enregistree_phrase')) ?></p>
        <?php endif; ?>
        <?php foreach ($byYear as $label => $yearPeriods): ?>
        <section class="management-panel" aria-label="<?= app_escape(__('annee_scolaire_years', ['years' => $label])) ?>">
          <h2 class="detail-heading"><?= app_escape(__('annee_scolaire_years', ['years' => $label])) ?></h2>
          <ul class="calendar-items school-periods">
            <?php foreach ($yearPeriods as $period): ?>
            <li>
              <form method="post" action="school-holidays-settings.php" class="calendar-period-form">
                <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" value="<?= app_escape($period['id']) ?>">
                <span class="school-period-bar" aria-hidden="true"></span>
                <label class="sr-only" for="label-<?= app_escape($period['id']) ?>"><?= app_escape(__('nom')) ?></label>
                <input id="label-<?= app_escape($period['id']) ?>" name="label" maxlength="80" required list="school-holiday-names" value="<?= app_escape($period['label']) ?>">
                <label class="sr-only" for="start-<?= app_escape($period['id']) ?>"><?= app_escape(__('du')) ?></label>
                <input id="start-<?= app_escape($period['id']) ?>" name="start" type="date" required value="<?= $period['start']->format('Y-m-d') ?>">
                <label class="sr-only" for="end-<?= app_escape($period['id']) ?>"><?= app_escape(__('au')) ?></label>
                <input id="end-<?= app_escape($period['id']) ?>" name="end" type="date" required value="<?= $period['end']->format('Y-m-d') ?>">
                <span class="log-sub"><?= app_escape(__n('n_jour', 'jours', (int) $period['start']->diff($period['end'])->days + 1)) ?> · <?= app_escape($formatDay($period['start'])) ?> → <?= app_escape($formatDay($period['end'])) ?></span>
                <button class="secondary-button compact-button" type="submit"><?= app_escape(__('enregistrer')) ?></button>
              </form>
              <form method="post" action="school-holidays-settings.php" onsubmit="return confirm(<?= app_escape(json_encode(__('supprimer_cette_periode'), JSON_UNESCAPED_UNICODE)) ?>);">
                <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= app_escape($period['id']) ?>">
                <button class="text-button" type="submit"><?= app_escape(__('supprimer')) ?></button>
              </form>
            </li>
            <?php endforeach; ?>
          </ul>
        </section>
        <?php endforeach; ?>
      </section>
<?php app_footer(); ?>
