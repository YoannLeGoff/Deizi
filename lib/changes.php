<?php
declare(strict_types=1);

// "Nouveau" / "Modifié" labels: dates created or changed since a member last opened them.
// Each date keeps its creation time and its last changes of fields useful to members
// ("created_at", "changes"); data/seen.json keeps, per member, when tracking started for them
// ("since") and when they last opened each date ("events").

// Fields that count as a change, with the word shown to members.
const EVENT_CHANGE_FIELDS = [
    'title' => 'titre',
    'date' => 'date',
    'end_date' => 'date',
    'all_day' => 'date',
    'time' => 'horaire',
    'end_time' => 'horaire',
    'location' => 'lieu',
    'status' => 'statut',
    'sections' => 'sections',
    'comment' => 'commentaire',
    'roadmap' => 'feuille de route',
    'roadmap_disabled' => 'feuille de route',
];
// Changes kept on a date: enough for members who have not opened it for a long time.
const EVENT_CHANGES_MAX = 30;

// Records the creation ($before null) or the member-useful changes of $after by $author, and
// queues the push notifications of the members concerned (sent once the page has answered).
function app_event_record_change(?array $before, array $after, array $author, string $now): array
{
    if ($before === null) {
        $after['created_at'] = $now;
        $after['created_by'] = (string) $author['id'];
        if (is_string($after['id'] ?? null)) {
            app_push_queue_event($after['id'], 'new');
        }
        return $after;
    }
    $fields = [];
    foreach (EVENT_CHANGE_FIELDS as $field => $word) {
        // Roadmap changes do not matter while the roadmap is not available.
        if ($field === 'roadmap' && ($after['roadmap_disabled'] ?? false) === true) {
            continue;
        }
        if (app_event_log_value($before, $field) !== app_event_log_value($after, $field)) {
            $fields[$word] = true;
        }
    }
    if ($fields !== []) {
        $changes = is_array($before['changes'] ?? null) ? $before['changes'] : [];
        $changes[] = ['at' => $now, 'by' => (string) $author['id'], 'fields' => array_keys($fields)];
        $after['changes'] = array_slice($changes, -EVENT_CHANGES_MAX);
        if (is_string($after['id'] ?? null)) {
            app_push_queue_event($after['id'], 'changed');
        }
    }
    return $after;
}

function app_change_time(mixed $value): ?int
{
    $time = is_string($value) ? strtotime($value) : false;
    return $time === false ? null : $time;
}

// Tracking state of $user: ['since' => timestamp, 'events' => [event id => timestamp]]. The first
// call for a member starts their tracking now, so that existing dates are not all flagged.
function app_seen_state(array $user): array
{
    $userId = (string) $user['id'];
    $entry = read_json_file(app_data_path('seen.json'))[$userId] ?? null;
    if (!is_array($entry) || app_change_time($entry['since'] ?? null) === null) {
        $now = (new DateTimeImmutable('now', new DateTimeZone('Europe/Paris')))->format(DATE_ATOM);
        update_json_file(app_data_path('seen.json'), static function (array $seen) use ($userId, $now): array {
            if (!is_array($seen[$userId] ?? null) || app_change_time($seen[$userId]['since'] ?? null) === null) {
                $seen[$userId] = ['since' => $now, 'events' => []];
            }
            return $seen;
        });
        return ['since' => (int) app_change_time($now), 'events' => []];
    }
    $events = [];
    foreach (is_array($entry['events'] ?? null) ? $entry['events'] : [] as $eventId => $at) {
        if (($time = app_change_time($at)) !== null) {
            $events[(string) $eventId] = $time;
        }
    }
    return ['since' => (int) app_change_time($entry['since']), 'events' => $events];
}

// Notice to show on $event for $user: ['type' => 'new'|'changed', 'fields' => [...]], or null.
// Changes made by the member themselves are not flagged.
function app_event_change_notice(array $event, array $user, array $seenState): ?array
{
    $eventId = is_string($event['id'] ?? null) ? $event['id'] : '';
    if ($eventId === '') {
        return null;
    }
    $userId = (string) $user['id'];
    $seenAt = max($seenState['since'], $seenState['events'][$eventId] ?? 0);
    $createdAt = app_change_time($event['created_at'] ?? null);
    if ($createdAt !== null && $createdAt > $seenAt && ($event['created_by'] ?? null) !== $userId) {
        return ['type' => 'new', 'fields' => []];
    }
    $fields = [];
    foreach (is_array($event['changes'] ?? null) ? $event['changes'] : [] as $change) {
        $at = is_array($change) ? app_change_time($change['at'] ?? null) : null;
        if ($at !== null && $at > $seenAt && ($change['by'] ?? null) !== $userId && is_array($change['fields'] ?? null)) {
            $fields += array_flip(array_filter($change['fields'], 'is_string'));
        }
    }
    // In the order of EVENT_CHANGE_FIELDS.
    $fields = array_values(array_intersect(array_unique(EVENT_CHANGE_FIELDS), array_keys($fields)));
    return $fields === [] ? null : ['type' => 'changed', 'fields' => $fields];
}

// Records that $user has just opened $event.
function app_mark_event_seen(array $user, string $eventId): void
{
    $userId = (string) $user['id'];
    $now = (new DateTimeImmutable('now', new DateTimeZone('Europe/Paris')))->format(DATE_ATOM);
    update_json_file(app_data_path('seen.json'), static function (array $seen) use ($userId, $eventId, $now): array {
        $entry = is_array($seen[$userId] ?? null) ? $seen[$userId] : ['since' => $now, 'events' => []];
        $entry['events'] = is_array($entry['events'] ?? null) ? $entry['events'] : [];
        $entry['events'][$eventId] = $now;
        $seen[$userId] = $entry;
        return $seen;
    });
}

// Mark placed after a date title: a "Nouveau" label, or a bright red dot in superscript for a
// changed date, whose tooltip lists the changes ("Modifié depuis votre dernière consultation : horaire, lieu").
function app_change_badge(?array $notice): string
{
    if ($notice === null) {
        return '';
    }
    if ($notice['type'] === 'new') {
        $title = __('Nouvelle date depuis votre dernière visite');
        return '<span class="change-badge is-new" title="' . app_escape($title) . '">' . app_escape(__('Nouveau')) . '<span class="sr-only"> — ' . app_escape($title) . '</span></span>';
    }
    $title = __('Modifié depuis votre dernière consultation : {fields}', ['fields' => implode(', ', array_map('__', $notice['fields']))]);
    return '<sup class="change-dot" title="' . app_escape($title) . '"><span class="sr-only">' . app_escape($title) . '</span></sup>';
}

// Records that $user has seen every date of $eventIds ("Tout marquer comme lu").
function app_mark_events_seen(array $user, array $eventIds): void
{
    $userId = (string) $user['id'];
    $now = (new DateTimeImmutable('now', new DateTimeZone('Europe/Paris')))->format(DATE_ATOM);
    update_json_file(app_data_path('seen.json'), static function (array $seen) use ($userId, $eventIds, $now): array {
        $entry = is_array($seen[$userId] ?? null) ? $seen[$userId] : ['since' => $now, 'events' => []];
        $entry['events'] = is_array($entry['events'] ?? null) ? $entry['events'] : [];
        foreach ($eventIds as $eventId) {
            $entry['events'][(string) $eventId] = $now;
        }
        $seen[$userId] = $entry;
        return $seen;
    });
}

// Whether $user may be told about $event: a date they see in the agenda (artists do not see
// refused dates), not over yet.
function app_event_worth_notifying(array $user, array $event): bool
{
    $status = is_string($event['status'] ?? null) ? $event['status'] : 'pending';
    $date = is_string($event['date'] ?? null) ? $event['date'] : '';
    $lastDay = max($date, is_string($event['end_date'] ?? null) ? $event['end_date'] : '');
    return is_string($event['id'] ?? null) && $date !== ''
        && $lastDay >= (new DateTimeImmutable('today', new DateTimeZone('Europe/Paris')))->format('Y-m-d')
        && app_user_can_see_event($user, $event)
        && !(($user['role'] ?? '') === 'musician' && in_array($status, AGENDA_HIDDEN_STATUSES_FOR_ARTISTS, true));
}

// Dates to point out to $user (bell of the menu, badge of the installed application, push
// messages): new dates of their sections, and dates they have already opened that changed since.
// Returns [['event' => array, 'notice' => array], ...] in date order.
function app_unread_events(array $user, ?array $events = null, ?array $seenState = null): array
{
    $events ??= read_json_file(app_data_path('events.json'));
    $seenState ??= app_seen_state($user);
    $unread = [];
    foreach ($events as $event) {
        if (!is_array($event) || !app_event_worth_notifying($user, $event)) {
            continue;
        }
        $notice = app_event_change_notice($event, $user, $seenState);
        if ($notice === null
            || ($notice['type'] === 'new' && !app_user_concerned_by_event($user, $event))
            || ($notice['type'] === 'changed' && !isset($seenState['events'][$event['id']]))) {
            continue;
        }
        $unread[] = ['event' => $event, 'notice' => $notice];
    }
    usort($unread, static fn (array $first, array $second): int => app_compare_events($first['event'], $second['event']));
    return $unread;
}
