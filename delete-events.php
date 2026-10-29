<?php
declare(strict_types=1);

// Console for deleting dates, reserved to administrators (Configuration › Suppression des dates).
// A deletion removes the date with its answers, polls and roadmap, and is recorded in the history.
require __DIR__ . '/lib/bootstrap.php';
$admin = app_require_roles(['admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    app_verify_csrf();
    $eventId = $_POST['id'] ?? '';
    if (!is_string($eventId) || $eventId === '') {
        http_response_code(400);
        exit(__('demande_invalide_phrase'));
    }
    $deleted = null;
    $impersonator = app_impersonator();
    update_json_file(app_data_path('events.json'), static function (array $events) use ($eventId, $admin, $impersonator, &$deleted): array {
        $remaining = [];
        foreach ($events as $event) {
            if (is_array($event) && ($event['id'] ?? null) === $eventId) {
                $deleted = $event;
                app_attendance_log_append([app_event_log_entry($event, null, $admin, $impersonator)]);
                continue;
            }
            $remaining[] = $event;
        }
        return $remaining;
    });
    if ($deleted === null) {
        app_flash(__('cette_date_nexiste_plus_phrase'), 'error');
    } else {
        app_delete_upload(app_event_roadmap($deleted)['qr']['image']);
        array_map('app_delete_roadmap_document', array_column(app_event_roadmap($deleted)['documents'], 'file'));
        app_logistics_delete_event($eventId);
        app_flash(__('date_title_ete_supprimee_phrase', ['title' => (string) ($deleted['title'] ?? '')]));
    }
    app_redirect('delete-events.php');
}

$events = array_values(array_filter(
    read_json_file(app_data_path('events.json')),
    static fn (mixed $event): bool => is_array($event) && is_string($event['id'] ?? null)
));
usort($events, 'app_compare_events');
$sectionLabels = app_section_labels();

app_header('Suppression des dates', $admin);
?>
      <section class="management-section">
        <div class="eyebrow-row">
          <p class="eyebrow"><span class="eyebrow-line"></span> <?= app_escape(__('configuration_suivi_securite_majuscules')) ?></p>
          <?php app_page_links([['Configuration', 'settings.php'], ['Journal d’audit', 'attendance-log.php?source=event']]); ?>
        </div>
        <div class="section-heading">
          <div>
            <h1><?= app_escape(__('suppression_dates')) ?><span class="heading-dot">.</span></h1>
          </div>
        </div>
        <?php app_settings_nav('suivi', 'delete-events.php', $admin); ?>

        <p class="accounts-help delete-events-help"><?= app_escape(__('suppression_dune_date_definitive_efface_aussi')) ?></p>

        <section class="management-panel" aria-labelledby="delete-events-title">
          <h2 class="detail-heading" id="delete-events-title"><?= app_escape(__('dates_enregistrees')) ?> <span class="count-badge"><?= count($events) ?></span></h2>
          <?php if ($events === []): ?>
          <p class="muted-copy"><?= app_escape(__('aucune_date_na_ete_ajoutee_phrase')) ?></p>
          <?php else: ?>
          <div class="managed-events">
            <?php foreach ($events as $event): ?>
            <?php
                $title = (string) ($event['title'] ?? __('evenement_sans_titre'));
                $date = app_parse_date($event['date'] ?? null);
                $status = is_string($event['status'] ?? null) && isset(EVENT_STATUSES[$event['status']]) ? $event['status'] : 'pending';
                $answerCount = is_array($event['attendance'] ?? null) ? count($event['attendance']) : 0;
                $pollCount = count(app_event_polls($event));
                $details = [__n('n_reponse', 'reponses', $answerCount)];
                if ($pollCount > 0) {
                    $details[] = __n('sondage_2', 'sondages_2', $pollCount);
                }
                $confirm = $date !== null
                    ? __('supprimer_definitivement_title_date_details_seront', ['title' => $title, 'date' => $date->format('d/m/Y'), 'details' => implode(__('et'), $details)])
                    : __('supprimer_definitivement_title_details_seront_effaces', ['title' => $title, 'details' => implode(__('et'), $details)]);
            ?>
            <article class="managed-event">
              <div>
                <strong><a class="event-title-link" href="event.php?id=<?= rawurlencode($event['id']) ?>"><?= app_escape($title) ?></a></strong>
                <span><?= $date !== null ? app_escape($date->format('d/m/Y')) : '' ?><?= !empty($event['end_date']) && ($endDate = app_parse_date($event['end_date'])) !== null ? ' – ' . app_escape($endDate->format('d/m/Y')) : '' ?> · <?= app_escape(implode(' · ', $details)) ?></span>
                <span class="status-badge status-badge-<?= app_escape($status) ?>"><?= app_escape(app_statuses()[$status]) ?></span>
              </div>
              <div class="managed-event-actions">
                <form method="post" action="delete-events.php" onsubmit="return confirm(<?= app_escape(json_encode($confirm, JSON_UNESCAPED_UNICODE)) ?>);">
                  <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
                  <input type="hidden" name="id" value="<?= app_escape($event['id']) ?>">
                  <button class="danger-button compact-button" type="submit"><?= app_escape(__('supprimer')) ?></button>
                </form>
              </div>
            </article>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </section>
      </section>
<?php app_footer(); ?>
