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
        exit('Demande invalide.');
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
        app_flash('Cette date n’existe plus.', 'error');
    } else {
        app_delete_upload(app_event_roadmap($deleted)['qr']['image']);
        array_map('app_delete_roadmap_document', array_column(app_event_roadmap($deleted)['documents'], 'file'));
        app_logistics_delete_event($eventId);
        app_flash('La date « ' . (string) ($deleted['title'] ?? '') . ' » a été supprimée.');
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
          <p class="eyebrow"><span class="eyebrow-line"></span> CONFIGURATION · SUIVI ET SÉCURITÉ</p>
          <?php app_page_links([['Configuration', 'settings.php'], ['Journal d’audit', 'attendance-log.php?source=event']]); ?>
        </div>
        <div class="section-heading">
          <div>
            <h1>Suppression des dates<span class="heading-dot">.</span></h1>
          </div>
        </div>
        <?php app_settings_nav('suivi', 'delete-events.php', $admin); ?>

        <p class="accounts-help delete-events-help">La suppression d’une date est définitive : elle efface aussi les réponses de présence, les sondages et la feuille de route de la date, et son lien public cesse de fonctionner. Elle est inscrite dans le journal d’audit. Pour simplement écarter une date, préférez le statut « Annulé » ou « Refusé ».</p>

        <section class="management-panel" aria-labelledby="delete-events-title">
          <h2 class="detail-heading" id="delete-events-title">Dates enregistrées <span class="count-badge"><?= count($events) ?></span></h2>
          <?php if ($events === []): ?>
          <p class="muted-copy">Aucune date n’a été ajoutée.</p>
          <?php else: ?>
          <div class="managed-events">
            <?php foreach ($events as $event): ?>
            <?php
                $title = (string) ($event['title'] ?? 'Événement sans titre');
                $date = app_parse_date($event['date'] ?? null);
                $status = is_string($event['status'] ?? null) && isset(EVENT_STATUSES[$event['status']]) ? $event['status'] : 'pending';
                $answerCount = is_array($event['attendance'] ?? null) ? count($event['attendance']) : 0;
                $pollCount = count(app_event_polls($event));
                $details = [$answerCount . ' réponse' . ($answerCount > 1 ? 's' : '')];
                if ($pollCount > 0) {
                    $details[] = $pollCount . ' sondage' . ($pollCount > 1 ? 's' : '');
                }
                $confirm = 'Supprimer définitivement « ' . $title . ' »' . ($date !== null ? ' du ' . $date->format('d/m/Y') : '') . ' ? ' . implode(' et ', $details) . ' seront effacés.';
            ?>
            <article class="managed-event">
              <div>
                <strong><a class="event-title-link" href="event.php?id=<?= rawurlencode($event['id']) ?>"><?= app_escape($title) ?></a></strong>
                <span><?= $date !== null ? app_escape($date->format('d/m/Y')) : '' ?><?= !empty($event['end_date']) && ($endDate = app_parse_date($event['end_date'])) !== null ? ' – ' . app_escape($endDate->format('d/m/Y')) : '' ?> · <?= app_escape(implode(' · ', $details)) ?></span>
                <span class="status-badge status-badge-<?= app_escape($status) ?>"><?= app_escape(EVENT_STATUSES[$status]) ?></span>
              </div>
              <div class="managed-event-actions">
                <form method="post" action="delete-events.php" onsubmit="return confirm(<?= app_escape(json_encode($confirm, JSON_UNESCAPED_UNICODE)) ?>);">
                  <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
                  <input type="hidden" name="id" value="<?= app_escape($event['id']) ?>">
                  <button class="danger-button compact-button" type="submit">Supprimer</button>
                </form>
              </div>
            </article>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </section>
      </section>
<?php app_footer(); ?>
