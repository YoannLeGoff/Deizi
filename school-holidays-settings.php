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
            app_flash('Indiquez un nom, une date de début et une date de fin égale ou postérieure.', 'error');
            app_redirect('school-holidays-settings.php');
        }
        if ((int) $start->diff($end)->days > SCHOOL_HOLIDAY_MAX_DAYS) {
            app_flash('Une période ne peut pas dépasser ' . SCHOOL_HOLIDAY_MAX_DAYS . ' jours.', 'error');
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
        app_flash($action === 'add' ? 'La période « ' . $label . ' » a été ajoutée.' : 'La période a été mise à jour.');
    } elseif ($action === 'delete') {
        $id = $_POST['id'] ?? '';
        app_update_calendar_settings(static function (array $data) use ($id): array {
            $data['school_holidays'] = array_values(array_filter(
                is_array($data['school_holidays'] ?? null) ? $data['school_holidays'] : [],
                static fn (mixed $period): bool => !is_array($period) || ($period['id'] ?? null) !== $id
            ));
            return $data;
        });
        app_flash('La période a été supprimée.');
    } else {
        http_response_code(400);
        exit('Action inconnue.');
    }
    app_redirect('school-holidays-settings.php');
}

$periods = app_calendar_settings()['school_holidays'];
$weekdays = ['', 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.', 'dim.'];
$formatDay = static fn (DateTimeImmutable $date): string => $weekdays[(int) $date->format('N')] . ' ' . $date->format('d/m/Y');
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
          <p class="eyebrow"><span class="eyebrow-line"></span> CONFIGURATION · CALENDRIER</p>
          <?php app_page_links([['Planning annuel', 'reporting.php?vue=planning'], ['Configuration', 'settings.php']]); ?>
        </div>
        <div class="section-heading">
          <div>
            <h1>Vacances scolaires<span class="heading-dot">.</span></h1>
          </div>
        </div>
        <?php app_settings_nav('calendrier', 'school-holidays-settings.php', $admin); ?>
        <p class="accounts-help">Les périodes de vacances apparaissent dans le planning annuel par un trait orange le long des jours concernés, avec le nom de la période au survol. Saisissez les dates de votre zone, du premier au dernier jour de vacances inclus.</p>

        <section class="management-panel" aria-labelledby="new-period-title">
          <h2 class="detail-heading" id="new-period-title">Ajouter une période</h2>
          <form method="post" action="school-holidays-settings.php" class="calendar-add-form">
            <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
            <input type="hidden" name="action" value="add">
            <div class="calendar-add-label">
              <label for="period-label">Nom</label>
              <input id="period-label" name="label" maxlength="80" required list="school-holiday-names" placeholder="Vacances de printemps">
              <datalist id="school-holiday-names">
                <?php foreach (SCHOOL_HOLIDAY_NAMES as $name): ?><option value="<?= app_escape($name) ?>"><?php endforeach; ?>
              </datalist>
            </div>
            <div>
              <label for="period-start">Du</label>
              <input id="period-start" name="start" type="date" required>
            </div>
            <div>
              <label for="period-end">Au (inclus)</label>
              <input id="period-end" name="end" type="date" required>
            </div>
            <button class="primary-button compact-button" type="submit">Ajouter</button>
          </form>
        </section>

        <?php if ($byYear === []): ?>
        <p class="management-panel muted-copy">Aucune période enregistrée.</p>
        <?php endif; ?>
        <?php foreach ($byYear as $label => $yearPeriods): ?>
        <section class="management-panel" aria-label="Année scolaire <?= app_escape($label) ?>">
          <h2 class="detail-heading">Année scolaire <?= app_escape($label) ?></h2>
          <ul class="calendar-items school-periods">
            <?php foreach ($yearPeriods as $period): ?>
            <li>
              <form method="post" action="school-holidays-settings.php" class="calendar-period-form">
                <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" value="<?= app_escape($period['id']) ?>">
                <span class="school-period-bar" aria-hidden="true"></span>
                <label class="sr-only" for="label-<?= app_escape($period['id']) ?>">Nom</label>
                <input id="label-<?= app_escape($period['id']) ?>" name="label" maxlength="80" required list="school-holiday-names" value="<?= app_escape($period['label']) ?>">
                <label class="sr-only" for="start-<?= app_escape($period['id']) ?>">Du</label>
                <input id="start-<?= app_escape($period['id']) ?>" name="start" type="date" required value="<?= $period['start']->format('Y-m-d') ?>">
                <label class="sr-only" for="end-<?= app_escape($period['id']) ?>">Au</label>
                <input id="end-<?= app_escape($period['id']) ?>" name="end" type="date" required value="<?= $period['end']->format('Y-m-d') ?>">
                <span class="log-sub"><?= (int) $period['start']->diff($period['end'])->days + 1 ?> jours · <?= app_escape($formatDay($period['start'])) ?> → <?= app_escape($formatDay($period['end'])) ?></span>
                <button class="secondary-button compact-button" type="submit">Enregistrer</button>
              </form>
              <form method="post" action="school-holidays-settings.php" onsubmit="return confirm('Supprimer cette période ?');">
                <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= app_escape($period['id']) ?>">
                <button class="text-button" type="submit">Supprimer</button>
              </form>
            </li>
            <?php endforeach; ?>
          </ul>
        </section>
        <?php endforeach; ?>
      </section>
<?php app_footer(); ?>
