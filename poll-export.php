<?php
declare(strict_types=1);

// CSV of poll answers for a date: one row per member concerned, one column per poll
// (all the polls of the date, or the one given by "poll").
require __DIR__ . '/lib/bootstrap.php';
$user = app_require_roles(EVENT_MANAGER_ROLES);

$eventId = $_GET['event'] ?? '';
$event = null;
foreach (read_json_file(app_data_path('events.json')) as $candidate) {
    if (is_array($candidate) && is_string($eventId) && ($candidate['id'] ?? null) === $eventId) {
        $event = $candidate;
        break;
    }
}
$polls = $event !== null ? app_event_polls($event) : [];
$pollId = $_GET['poll'] ?? '';
if (is_string($pollId) && $pollId !== '') {
    $polls = isset($polls[$pollId]) ? [$pollId => $polls[$pollId]] : [];
}
if ($event === null || $polls === [] || !app_user_can_see_event($user, $event)) {
    http_response_code(404);
    exit(__('sondage_introuvable_phrase'));
}

$members = array_values(array_filter(
    read_json_file(app_data_path('users.json')),
    static fn (mixed $member): bool => app_is_active_user($member) && app_user_concerned_by_event($member, $event)
));
usort($members, static fn (array $first, array $second): int => strcasecmp($first['username'], $second['username']));
$labels = app_section_labels();
$eventLeaves = app_event_leaves($event);

// Account information columns: those chosen for at least one of the exported polls.
$userInfo = array_values(array_intersect(array_keys(POLL_USER_INFO), array_merge(...array_values(array_map(static fn (array $poll): array => $poll['user_info'], $polls)))));
$header = [__('membre'), __('prenom'), __('nom'), __('role'), __('sous_sections'), ...array_map(static fn (string $flag): string => app_label(ACCOUNT_FLAGS[$flag]), $userInfo)];
foreach ($polls as $poll) {
    $header[] = $poll['question'];
}

$rows = [];
foreach ($members as $member) {
    $memberLeaves = array_intersect(app_expand_sections(app_user_sections($member)), $eventLeaves);
    $row = [
        $member['username'],
        (string) ($member['first_name'] ?? ''),
        (string) ($member['last_name'] ?? ''),
        app_user_role_label($member),
        implode(', ', array_map(static fn (string $leaf): string => $labels[$leaf] ?? $leaf, $memberLeaves)),
    ];
    foreach ($userInfo as $flag) {
        $row[] = ($member[$flag] ?? false) === true ? __('oui') : __('non');
    }
    foreach ($polls as $poll) {
        $answer = $poll['responses'][$member['id']] ?? null;
        $row[] = $answer !== null ? $poll['options'][$answer] : __('sans_reponse');
    }
    $rows[] = $row;
}

$date = app_parse_date($event['date'] ?? null);
app_send_csv(
    (count($polls) > 1 ? 'sondages-' : 'sondage-') . ($date !== null ? $date->format('Y-m-d') : 'date') . '.csv',
    $header,
    $rows
);
