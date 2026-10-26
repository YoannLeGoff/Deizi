<?php
declare(strict_types=1);

// Polls of a date (e.g. "Bus aller / retour"): single-choice questions answered by the
// members concerned by the date, like their attendance. Stored in events.json as
// "polls": [{"id", "question", "description", "user_info": ["vegetarian", …], "options": [{"id", "label"}], "closed",
// "responses": {userId: optionId}, "entered_by": {userId: author}}]; "entered_by" names who answered on a member's behalf.

const POLL_MAX_OPTIONS = 10;
const POLL_QUESTION_MAX_LENGTH = 200;
const POLL_OPTION_MAX_LENGTH = 80;
const POLL_DESCRIPTION_MAX_LENGTH = 500;
// Account information a poll can show next to the answers (unchecked by default).
const POLL_USER_INFO = [
    'vegetarian' => 'Régime alimentaire (végétarien)',
    'minor' => 'Mineur',
];

// Returns [pollId => ['id', 'question', 'description', 'user_info' => [flags], 'options' => [optionId => label], 'closed', 'responses' => [userId => optionId]]].
function app_event_polls(array $event): array
{
    $polls = [];
    foreach (is_array($event['polls'] ?? null) ? $event['polls'] : [] as $poll) {
        $id = $poll['id'] ?? null;
        $question = is_string($poll['question'] ?? null) ? trim($poll['question']) : '';
        if (!is_string($id) || preg_match('/^p[0-9a-f]{8}$/', $id) !== 1 || isset($polls[$id]) || $question === '') {
            continue;
        }
        $options = [];
        foreach (is_array($poll['options'] ?? null) ? $poll['options'] : [] as $option) {
            $optionId = $option['id'] ?? null;
            $label = is_string($option['label'] ?? null) ? trim($option['label']) : '';
            if (is_string($optionId) && preg_match('/^o\d{1,3}$/', $optionId) === 1 && $label !== '') {
                $options[$optionId] = $label;
            }
        }
        if (count($options) < 2) {
            continue;
        }
        $responses = [];
        foreach (is_array($poll['responses'] ?? null) ? $poll['responses'] : [] as $userId => $optionId) {
            if (is_string($optionId) && isset($options[$optionId])) {
                $responses[(string) $userId] = $optionId;
            }
        }
        $enteredBy = [];
        foreach (is_array($poll['entered_by'] ?? null) ? $poll['entered_by'] : [] as $userId => $author) {
            if (is_string($author) && isset($responses[(string) $userId])) {
                $enteredBy[(string) $userId] = $author;
            }
        }
        $polls[$id] = [
            'id' => $id,
            'question' => $question,
            'description' => is_string($poll['description'] ?? null) ? trim($poll['description']) : '',
            'user_info' => app_clean_choices($poll['user_info'] ?? [], POLL_USER_INFO),
            'options' => $options,
            'closed' => ($poll['closed'] ?? false) === true,
            // Hidden from members by a manager ("Masquer le sondage"), see app_poll_hidden_reason().
            'hidden' => ($poll['hidden'] ?? false) === true,
            'responses' => $responses,
            'entered_by' => $enteredBy,
        ];
    }
    return $polls;
}

function app_serialize_polls(array $polls): array
{
    return array_values(array_map(static fn (array $poll): array => [
        'id' => $poll['id'],
        'question' => $poll['question'],
        'description' => $poll['description'],
        'user_info' => $poll['user_info'],
        'options' => array_map(
            static fn (string $id, string $label): array => ['id' => $id, 'label' => $label],
            array_keys($poll['options']),
            array_values($poll['options'])
        ),
        'closed' => $poll['closed'],
        'hidden' => $poll['hidden'] ?? false,
        'responses' => (object) $poll['responses'],
        'entered_by' => (object) ($poll['entered_by'] ?? []),
    ], $polls));
}

// Options typed one per line. An unchanged label keeps its identifier, so its answers are kept.
function app_poll_options_from_text(mixed $text, array $existing = []): array
{
    $labels = app_clean_lines($text, POLL_MAX_OPTIONS, POLL_OPTION_MAX_LENGTH);
    $nextNumber = 1;
    foreach (array_keys($existing) as $id) {
        $nextNumber = max($nextNumber, (int) substr($id, 1) + 1);
    }
    $options = [];
    foreach ($labels as $label) {
        $id = null;
        foreach ($existing as $existingId => $existingLabel) {
            if (strcasecmp(app_lowercase($existingLabel), app_lowercase($label)) === 0 && !isset($options[$existingId])) {
                $id = $existingId;
                break;
            }
        }
        $options[$id ?? 'o' . $nextNumber++] = $label;
    }
    return $options;
}

function app_event_leaves(array $event): array
{
    $sections = is_array($event['sections'] ?? null) ? array_filter($event['sections'], 'is_string') : [];
    return array_values(array_intersect(app_expand_sections($sections), app_section_leaves()));
}

// A member answers the polls of the dates concerning one of their sub-sections.
function app_user_concerned_by_event(array $user, array $event): bool
{
    return array_intersect(app_expand_sections(app_user_sections($user)), app_event_leaves($event)) !== [];
}

// "Date réservée": a date only shown to the members of its sub-sections, the managers of these
// sub-sections, organizers and administrators. Dates created by managers are always reserved.
// Whether $event needs a logistics follow-up (listed on the Logistique page). Set by organizers,
// logisticians and administrators; the dates created by managers have none; dates saved before
// this option have one.
function app_event_needs_logistics(array $event): bool
{
    return ($event['logistics'] ?? true) !== false;
}

function app_event_is_reserved(array $event): bool
{
    return ($event['reserved'] ?? false) === true;
}

// Whether $user sees $event in the agenda, the views and its detail page. Artists only see the
// dates of their sub-sections; managers see every date except the reserved dates outside their
// own sub-sections and management scope; organizers, logisticians and administrators see everything.
function app_user_can_see_event(array $user, array $event): bool
{
    $role = $user['role'] ?? '';
    if (in_array($role, ALL_SECTIONS_ROLES, true)) {
        return true;
    }
    if ($role === 'manager' && !app_event_is_reserved($event)) {
        return true;
    }
    $leaves = app_expand_sections(app_user_sections($user));
    if ($role === 'manager') {
        $leaves = [...$leaves, ...app_user_managed_leaves($user)];
    }
    return array_intersect($leaves, app_event_leaves($event)) !== [];
}

// Sub-sections of $event outside the management scope of $manager.
function app_event_leaves_outside_scope(array $manager, array $event): array
{
    return array_values(array_diff(app_event_leaves($event), app_user_managed_leaves($manager)));
}

// Whether $user may edit $event. Managers: the dates of their scope, a reserved date being allowed
// invited sub-sections outside it (see event-action.php).
function app_user_can_edit_event(array $user, array $event): bool
{
    $role = $user['role'] ?? '';
    if (in_array($role, ALL_SECTIONS_ROLES, true)) {
        return true;
    }
    if ($role !== 'manager' || array_intersect(app_event_leaves($event), app_user_managed_leaves($user)) === []) {
        return false;
    }
    return app_event_is_reserved($event) || app_event_leaves_outside_scope($user, $event) === [];
}

// Members a manager may answer for: concerned by the date and within their management scope
// (every concerned member for an administrator).
function app_poll_proxy_members(array $manager, array $event, array $members): array
{
    $scope = array_intersect(app_user_managed_leaves($manager), app_event_leaves($event));
    return array_values(array_filter(
        $members,
        static fn (array $member): bool => array_intersect(app_expand_sections(app_user_sections($member)), $scope) !== []
    ));
}

// Statuses of a date whose polls are hidden from members.
const POLL_HIDDEN_STATUSES = ['completed', 'cancelled', 'refused'];

// Why a poll is hidden from members (shown to managers), or null when it is visible: hidden by a
// manager, or its date is "Terminé", "Annulé" or "Refusé".
function app_poll_hidden_reason(array $event, array $poll): ?string
{
    $status = is_string($event['status'] ?? null) ? $event['status'] : '';
    if (in_array($status, POLL_HIDDEN_STATUSES, true)) {
        return __('date au statut « {status} »', ['status' => app_statuses()[$status]]);
    }
    return $poll['hidden'] ? __('masqué temporairement') : null;
}

// Polls of a date shown to $user: managers, organizers and administrators see the hidden ones too.
function app_visible_polls(array $event, array $user): array
{
    if (in_array($user['role'] ?? '', EVENT_MANAGER_ROLES, true)) {
        return app_event_polls($event);
    }
    return array_filter(app_event_polls($event), static fn (array $poll): bool => app_poll_hidden_reason($event, $poll) === null);
}

// Open and visible polls of a date that the user still has to answer.
function app_pending_polls(array $event, array $user): array
{
    if (!app_user_concerned_by_event($user, $event)) {
        return [];
    }
    return array_values(array_filter(
        app_event_polls($event),
        static fn (array $poll): bool => !$poll['closed'] && app_poll_hidden_reason($event, $poll) === null && !isset($poll['responses'][$user['id']])
    ));
}
