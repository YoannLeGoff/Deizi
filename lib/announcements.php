<?php
declare(strict_types=1);

// Announcements ("Annonces"): a text with an optional link and image, shown at the top of the home
// page of the members of the chosen sections, during a period. Managers publish them for the
// sub-sections of their management scope; organizers, logisticians and administrators for any
// section or for everybody. Each member can hide an announcement once read. An announcement can
// also be sent as a push notification (see lib/push.php) when it starts being shown.
// Stored in data/announcements.json: a list of announcements, newest first.

const ANNOUNCEMENT_ROLES = ['manager', 'organizer', 'logistician', 'admin'];
const ANNOUNCEMENT_TITLE_MAX = 120;
const ANNOUNCEMENT_BODY_MAX = 3000;
const ANNOUNCEMENT_LINK_LABEL_MAX = 80;
// Default display period of a new announcement, in days.
const ANNOUNCEMENT_DEFAULT_DAYS = 14;
const ANNOUNCEMENT_UPLOAD_PREFIX = 'annonce';

function app_can_publish_announcements(array $user): bool
{
    return in_array($user['role'] ?? null, ANNOUNCEMENT_ROLES, true)
        && (($user['role'] ?? null) !== 'manager' || app_user_managed_leaves($user) !== []);
}

function app_today(): string
{
    return (new DateTimeImmutable('today', new DateTimeZone('Europe/Paris')))->format('Y-m-d');
}

// Stored announcements, normalized (invalid entries are skipped).
function app_announcements(): array
{
    $announcements = [];
    foreach (read_json_file(app_data_path('announcements.json')) as $raw) {
        if (!is_array($raw) || !is_string($raw['id'] ?? null) || preg_match('/^[0-9a-f]{32}$/', $raw['id']) !== 1
            || !is_string($raw['title'] ?? null) || app_parse_date($raw['starts'] ?? null) === null) {
            continue;
        }
        $announcements[] = [
            'id' => $raw['id'],
            'title' => $raw['title'],
            'body' => is_string($raw['body'] ?? null) ? $raw['body'] : '',
            'link_url' => is_string($raw['link_url'] ?? null) ? $raw['link_url'] : '',
            'link_label' => is_string($raw['link_label'] ?? null) ? $raw['link_label'] : '',
            'image' => is_string($raw['image'] ?? null) && app_upload_path($raw['image']) !== null ? $raw['image'] : null,
            // Everybody (the sections added later too), or the chosen sub-sections.
            'everyone' => ($raw['everyone'] ?? false) === true,
            'sections' => array_values(array_intersect(is_array($raw['sections'] ?? null) ? $raw['sections'] : [], app_section_leaves())),
            'starts' => $raw['starts'],
            'ends' => app_parse_date($raw['ends'] ?? null) !== null ? $raw['ends'] : null,
            'push' => ($raw['push'] ?? false) === true,
            'push_sent_at' => is_string($raw['push_sent_at'] ?? null) ? $raw['push_sent_at'] : null,
            'author' => is_string($raw['author'] ?? null) ? $raw['author'] : '',
            'created_at' => is_string($raw['created_at'] ?? null) ? $raw['created_at'] : '',
            'updated_at' => is_string($raw['updated_at'] ?? null) ? $raw['updated_at'] : null,
            'dismissed' => array_values(array_filter(is_array($raw['dismissed'] ?? null) ? $raw['dismissed'] : [], 'is_string')),
        ];
    }
    return $announcements;
}

function app_announcement(string $id): ?array
{
    foreach (app_announcements() as $announcement) {
        if ($announcement['id'] === $id) {
            return $announcement;
        }
    }
    return null;
}

// Sub-sections an announcement is shown to.
function app_announcement_leaves(array $announcement): array
{
    return $announcement['everyone'] ? app_section_leaves() : $announcement['sections'];
}

// 'scheduled' (not shown yet), 'active' or 'ended'.
function app_announcement_state(array $announcement, ?string $today = null): string
{
    $today ??= app_today();
    if ($announcement['starts'] > $today) {
        return 'scheduled';
    }
    return $announcement['ends'] !== null && $announcement['ends'] < $today ? 'ended' : 'active';
}

// Whether $user is one of the members an announcement is meant for.
function app_announcement_targets(array $announcement, array $user): bool
{
    return $announcement['everyone']
        || array_intersect(app_expand_sections(app_user_sections($user)), $announcement['sections']) !== [];
}

// Whether $user may edit or delete an announcement: organizers, logisticians and administrators
// all of them; a manager those whose sections are all in their management scope.
function app_can_edit_announcement(array $user, array $announcement): bool
{
    if (in_array($user['role'] ?? null, ALL_SECTIONS_ROLES, true)) {
        return true;
    }
    return ($user['role'] ?? null) === 'manager' && !$announcement['everyone'] && $announcement['sections'] !== []
        && array_diff($announcement['sections'], app_user_managed_leaves($user)) === [];
}

// Whether $user wrote the announcement.
function app_is_announcement_author(array $announcement, array $user): bool
{
    return $announcement['author'] !== '' && $announcement['author'] === (string) $user['id'];
}

// Announcements of the home page of $user, being shown and meant for them (or written by them, so
// that the author sees what they published), the latest first (by first day, then publication).
// Hidden ones are included: they stay folded under "Toutes les annonces".
function app_home_announcements(array $user): array
{
    $today = app_today();
    $announcements = array_values(array_filter(app_announcements(), static fn (array $announcement): bool => app_announcement_state($announcement, $today) === 'active'
        && (app_announcement_targets($announcement, $user) || app_is_announcement_author($announcement, $user))));
    usort($announcements, static fn (array $first, array $second): int => [$second['starts'], $second['created_at']] <=> [$first['starts'], $first['created_at']]);
    return $announcements;
}

function app_announcement_dismissed_by(array $announcement, array $user): bool
{
    return in_array((string) $user['id'], $announcement['dismissed'], true);
}

// "Bagad, Danseurs": whole branches by their name, otherwise the sub-sections; "Tout le monde".
function app_announcement_audience(array $announcement): string
{
    if ($announcement['everyone']) {
        return 'Tout le monde';
    }
    $parts = [];
    foreach (app_section_tree() as $definition) {
        $chosen = array_intersect_key($definition['children'], array_flip($announcement['sections']));
        if ($chosen !== [] && count($chosen) === count($definition['children'])) {
            $parts[] = $definition['label'];
        } else {
            array_push($parts, ...array_values($chosen));
        }
    }
    return implode(', ', $parts);
}

// Address of the image of an announcement (served by announcement-image.php).
function app_announcement_image_url(array $announcement): string
{
    return 'announcement-image.php?id=' . rawurlencode($announcement['id']);
}

// Applies $update to the stored list (raw entries) and saves it.
function app_update_announcements(callable $update): array
{
    return update_json_file(app_data_path('announcements.json'), static fn (array $announcements): array => array_values($update($announcements)));
}

// Sends the push notifications of the announcements being shown whose notification was asked
// and not sent yet: at their publication, or on the first visit once their first day has come.
function app_announcements_dispatch_push(): void
{
    $today = app_today();
    $due = array_filter(app_announcements(), static fn (array $announcement): bool => $announcement['push']
        && $announcement['push_sent_at'] === null && app_announcement_state($announcement, $today) === 'active');
    if ($due === []) {
        return;
    }
    // Marked as sent first, so that two visits at the same time do not send it twice.
    $now = (new DateTimeImmutable('now', new DateTimeZone('Europe/Paris')))->format(DATE_ATOM);
    $dueIds = array_column($due, 'id');
    $claimed = [];
    app_update_announcements(static function (array $announcements) use ($dueIds, $now, &$claimed): array {
        foreach ($announcements as &$raw) {
            if (is_array($raw) && in_array($raw['id'] ?? null, $dueIds, true) && ($raw['push_sent_at'] ?? null) === null) {
                $raw['push_sent_at'] = $now;
                $claimed[] = $raw['id'];
            }
        }
        unset($raw);
        return $announcements;
    });
    foreach ($due as $announcement) {
        if (in_array($announcement['id'], $claimed, true)) {
            app_push_queue_announcement($announcement);
        }
    }
}

// Megaphone pictogram placed before "Annonces" (menu, page title); decorative.
function app_megaphone_icon(string $class = 'menu-icon'): string
{
    return '<svg class="' . app_escape($class) . '" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
        . '<path d="M3 10v4a1 1 0 0 0 1 1h3l7 4V5L7 9H4a1 1 0 0 0-1 1Z"/><path d="M17.5 8.5a5 5 0 0 1 0 7"/><path d="M20 6a8.5 8.5 0 0 1 0 12"/><path d="M7 15l1.5 5h2.5L9.8 15.6"/></svg>';
}
