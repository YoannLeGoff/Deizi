<?php
declare(strict_types=1);

$showAllEvents = $showAllEvents ?? false;
require __DIR__ . '/lib/bootstrap.php';
$isCalendarRequest = ($_GET['format'] ?? null) === 'ical';
$calendarTokenInput = $_GET['token'] ?? '';
if ($isCalendarRequest && is_string($calendarTokenInput) && $calendarTokenInput !== '') {
    $user = app_user_by_calendar_token($calendarTokenInput);
    if ($user === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        exit(__('lien_calendrier_invalide_renouvele_phrase'));
    }
    // No session for a calendar application: the language of the owner of the link.
    app_set_locale_for($user);
    // While the application is closed, calendar applications keep the dates they already have.
    if (app_maintenance() !== null) {
        http_response_code(503);
        header('Retry-After: 3600');
        header('Content-Type: text/plain; charset=utf-8');
        exit(__('maintenance_titre'));
    }
} else {
    $user = app_require_login();
}
$siteTitle = app_site_title();
$events = read_json_file(app_data_path('events.json'));

$sectionLabels = app_section_labels();
$eventSections = app_section_leaves();
$userSections = app_user_sections($user);
// The home page starts on the member's "Vue par défaut" (a branch or "Toutes"), whatever their
// sections; the full agenda ("Tous les événements") and the iCal feed start on all sections.
$userDefaultView = app_user_default_view($user);
$defaultSection = $isCalendarRequest || $showAllEvents ? 'Toutes' : $userDefaultView;
$availableSections = $user['role'] === 'musician'
    ? array_values(array_unique([...($userDefaultView !== 'Toutes' ? [$userDefaultView] : []), ...$userSections]))
    : array_keys($sectionLabels);
$requestedSection = $_GET['section'] ?? $defaultSection;
$activeSection = is_string($requestedSection) && ($requestedSection === 'Toutes' || in_array($requestedSection, $availableSections, true))
    ? $requestedSection
    : $defaultSection;
$timezone = new DateTimeZone('Europe/Paris');
$today = new DateTimeImmutable('today', $timezone);
$restrictSections = $user['role'] === 'musician';
$restrictedSections = app_expand_sections($userSections);
$allEvents = [];
$upcomingEvents = [];

foreach ($events as $event) {
    if (!is_array($event) || !isset($event['date'], $event['title']) || !is_string($event['date']) || !is_string($event['title'])) {
        continue;
    }
    $eventDate = DateTimeImmutable::createFromFormat('!Y-m-d', $event['date'], $timezone);
    $dateErrors = DateTimeImmutable::getLastErrors();
    if ($eventDate === false || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0)) || $eventDate->format('Y-m-d') !== $event['date']) {
        continue;
    }

    $eventEndDate = null;
    if (isset($event['end_date']) && is_string($event['end_date']) && $event['end_date'] !== '') {
        $eventEndDate = DateTimeImmutable::createFromFormat('!Y-m-d', $event['end_date'], $timezone);
        $endDateErrors = DateTimeImmutable::getLastErrors();
        if (
            $eventEndDate === false
            || ($endDateErrors !== false && ($endDateErrors['warning_count'] > 0 || $endDateErrors['error_count'] > 0))
            || $eventEndDate->format('Y-m-d') !== $event['end_date']
            || $eventEndDate < $eventDate
        ) {
            $eventEndDate = null;
        }
    }
    // Reserved dates outside the user's sub-sections (and a manager's scope) are not shown at all.
    if (!app_user_can_see_event($user, $event)) {
        continue;
    }
    $event['can_edit'] = app_user_can_edit_event($user, $event);
    $event['date_object'] = $eventDate;
    $event['end_date_object'] = $eventEndDate;
    $event['all_day'] = ($event['all_day'] ?? false) === true;
    $event['status'] = isset($event['status'])
        && is_string($event['status'])
        && isset(EVENT_STATUSES[$event['status']])
        ? $event['status']
        : 'pending';
    $event['time'] = isset($event['time']) && is_string($event['time']) ? $event['time'] : '';
    $event['location'] = isset($event['location']) && is_string($event['location']) ? $event['location'] : '';
    $event['url'] = isset($event['url']) && is_string($event['url']) ? $event['url'] : '';
    $rawEventSections = isset($event['sections']) && is_array($event['sections'])
        ? array_filter($event['sections'], 'is_string')
        : [];
    $event['sections'] = array_values(array_intersect(app_expand_sections($rawEventSections), $eventSections));
    // All sections of the date, for display: an artist sees "Bagad" when the whole branch is concerned.
    $event['all_sections'] = $event['sections'];
    if ($restrictSections) {
        $event['sections'] = array_values(array_intersect($event['sections'], $restrictedSections));
    }
    // Artists do not see refused dates in the agenda nor its iCal feed (only in the advanced views).
    if ($restrictSections && ($event['sections'] === [] || in_array($event['status'], AGENDA_HIDDEN_STATUSES_FOR_ARTISTS, true))) {
        continue;
    }
    $allEvents[] = $event;
    if ($eventDate >= $today) {
        $upcomingEvents[] = $event;
    }
}

usort($allEvents, 'app_compare_events');
usort($upcomingEvents, 'app_compare_events');
$listedEvents = $showAllEvents
    ? $allEvents
    : array_values(array_filter(
        $upcomingEvents,
        static fn (array $event): bool => !in_array($event['status'], HOME_HIDDEN_STATUSES, true)
    ));
// Full agenda: filter on the years of the dates (year of their first day). By default, the current
// year and the following ones; "annees" in the URL holds the chosen years ("" when none is chosen).
$availableYears = [];
$defaultYears = [];
$selectedYears = [];
if ($showAllEvents) {
    foreach ($allEvents as $event) {
        $availableYears[(int) $event['date_object']->format('Y')] = true;
    }
    $availableYears = array_keys($availableYears);
    sort($availableYears);
    $currentYear = (int) $today->format('Y');
    $defaultYears = array_values(array_filter($availableYears, static fn (int $year): bool => $year >= $currentYear)) ?: $availableYears;
    $yearsInput = $_GET['annees'] ?? null;
    $selectedYears = is_string($yearsInput)
        ? array_values(array_intersect($availableYears, array_map('intval', array_filter(explode(',', $yearsInput), 'ctype_digit'))))
        : $defaultYears;
    $listedEvents = array_values(array_filter(
        $listedEvents,
        static fn (array $event): bool => in_array((int) $event['date_object']->format('Y'), $selectedYears, true)
    ));
}
// Filter on the user's own answer: Présent, Je ne sais pas, Absent, or no answer yet.
$canFilterPresence = $userSections !== [];
$presenceRequest = $_GET['presence'] ?? '';
$presenceFilter = $canFilterPresence && is_string($presenceRequest) && isset(ATTENDANCE_FILTERS[$presenceRequest]) ? $presenceRequest : null;
$userResponse = static fn (array $event): string => app_attendance_response($event['attendance'] ?? null, (string) $user['id']);
$visibleEvents = array_values(array_filter(
    $listedEvents,
    static fn (array $event): bool => ($activeSection === 'Toutes'
        || array_intersect($event['sections'], app_expand_sections([$activeSection])) !== [])
        && ($presenceFilter === null || $userResponse($event) === $presenceFilter)
));

// $presence: false keeps the current answer filter, null removes it, a response sets it.
// $years: null keeps the current years of the full agenda, an array sets them.
function sectionUrl(string $section, string|false|null $presence = false, ?array $years = null): string
{
    global $showAllEvents, $presenceFilter, $selectedYears, $defaultYears;

    $query = 'section=' . rawurlencode($section);
    $presence = $presence === false ? $presenceFilter : $presence;
    if ($presence !== null) {
        $query .= '&presence=' . rawurlencode($presence);
    }
    $years ??= $selectedYears;
    sort($years);
    if ($showAllEvents && $years !== $defaultYears) {
        $query .= '&annees=' . implode(',', $years);
    }

    return ($showAllEvents ? 'agenda.php' : 'index.php') . '?' . $query . '#agenda';
}

function escapeIcalText(string $value): string
{
    return str_replace(
        ["\\", "\r\n", "\r", "\n", ";", ","],
        ["\\\\", '', '', "\\n", "\\;", "\\,"],
        $value
    );
}

function foldIcalLine(string $line): string
{
    $characters = preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY);
    if ($characters === false) {
        return $line;
    }

    $folded = '';
    $lineLength = 0;
    foreach ($characters as $character) {
        $characterLength = strlen($character);
        if ($lineLength + $characterLength > 75) {
            $folded .= "\r\n ";
            $lineLength = 1;
        }
        $folded .= $character;
        $lineLength += $characterLength;
    }

    return $folded;
}

function eventTimeOn(DateTimeImmutable $date, mixed $time): ?DateTimeImmutable
{
    $parsed = app_parse_time($time);
    return $parsed !== null ? $date->setTime($parsed[0], $parsed[1]) : null;
}

if ($isCalendarRequest) {
    $calendarEvents = array_values(array_filter(
        $upcomingEvents,
        static fn (array $event): bool => $activeSection === 'Toutes'
            || array_intersect($event['sections'], app_expand_sections([$activeSection])) !== []
    ));
    $calendarLines = [
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        'PRODID:-//Shared calendar//FR',
        'CALSCALE:GREGORIAN',
        'METHOD:PUBLISH',
        'X-WR-CALNAME:' . escapeIcalText($siteTitle),
        // Suggested refresh every 6 hours (Apple Calendar, Outlook…; Google Calendar ignores it).
        'REFRESH-INTERVAL;VALUE=DURATION:PT6H',
        'X-PUBLISHED-TTL:PT6H',
    ];

    foreach ($calendarEvents as $event) {
        $date = $event['date_object'];
        $eventEndDate = $event['end_date_object'] ?? null;
        $start = $event['all_day'] ? null : eventTimeOn($date, $event['time']);
        $calendarLines[] = 'BEGIN:VEVENT';
        $uid = isset($event['id']) && is_string($event['id']) && $event['id'] !== ''
            ? $event['id']
            : hash('sha256', $event['date'] . "\0" . $event['title'] . "\0" . ($event['location'] ?? ''));
        $calendarLines[] = 'UID:' . $uid . '@agenda-groupe';
        $calendarLines[] = 'DTSTAMP:' . gmdate('Ymd\THis\Z');
        if ($start === null) {
            $calendarLines[] = 'DTSTART;VALUE=DATE:' . $date->format('Ymd');
            $calendarEndDate = $eventEndDate instanceof DateTimeImmutable ? $eventEndDate : $date;
            $calendarLines[] = 'DTEND;VALUE=DATE:' . $calendarEndDate->modify('+1 day')->format('Ymd');
        } else {
            $calendarLines[] = 'DTSTART:' . $start->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
            $lastDay = $eventEndDate instanceof DateTimeImmutable && $eventEndDate > $date ? $eventEndDate : $date;
            $calendarEnd = eventTimeOn($lastDay, app_event_end_time($event));
            if ($calendarEnd === null && $lastDay > $date) {
                $calendarEnd = $lastDay->modify('+1 day')->setTime((int) $start->format('H'), (int) $start->format('i'));
            }
            if ($calendarEnd !== null && $calendarEnd <= $start) {
                // An end time before the start time finishes the next day (e.g. 21h00 – 01h00).
                $calendarEnd = $calendarEnd->modify('+1 day');
            }
            $calendarLines[] = $calendarEnd !== null
                ? 'DTEND:' . $calendarEnd->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z')
                : 'DURATION:PT60M';
        }
        $calendarLines[] = 'SUMMARY:' . escapeIcalText($event['title']);
        $calendarStatus = [
            'lead' => 'TENTATIVE',
            'pending' => 'TENTATIVE',
            'confirmed' => 'CONFIRMED',
            'cancelled' => 'CANCELLED',
            'refused' => 'CANCELLED',
        ][$event['status']] ?? null;
        if ($calendarStatus !== null) {
            $calendarLines[] = 'STATUS:' . $calendarStatus;
        }

        $description = [];
        if (!$event['all_day'] && app_event_time_range($event) !== '') {
            $description[] = __('horaire_time', ['time' => app_event_time_range($event)]);
        }
        if ($eventEndDate instanceof DateTimeImmutable) {
            $description[] = __('date_fin_date', ['date' => $eventEndDate->format('d/m/Y')]);
        }
        if ($event['sections'] !== []) {
            $description[] = __('sections_sections', ['sections' => implode(', ', array_map(
                static fn (string $section): string => app_section_labels()[$section] ?? $section,
                $event['sections']
            ))]);
        }
        $description[] = __('statut_status', ['status' => app_statuses()[$event['status']]]);
        $comment = app_event_comment($event);
        if ($comment !== '') {
            $description[] = '';
            $description[] = __('commentaire_label');
            $description[] = $comment;
        }
        // Roadmap details, with the same visibility as the roadmap tab of the date: managers and
        // administrators always, artists once the date is confirmed, nobody if it is not available.
        $canSeeRoadmap = app_roadmap_available($event)
            && (in_array($user['role'], EVENT_MANAGER_ROLES, true) || $event['status'] === 'confirmed');
        if ($canSeeRoadmap) {
            $calendarRoadmap = app_event_roadmap($event);
            foreach (['penn_notes' => __('note_penn'), 'notes' => __('notes')] as $field => $label) {
                $text = trim((string) $calendarRoadmap[$field]);
                if ($text !== '') {
                    $description[] = '';
                    $description[] = __('label_label', ['label' => $label]);
                    $description[] = $text;
                }
            }
            $shareToken = app_roadmap_share_token($event);
            if ($shareToken !== null) {
                $description[] = '';
                $description[] = __('feuille_route_url', ['url' => app_roadmap_share_url($shareToken)]);
            }
        }
        // Polls of the date shown to the members, with the state for this account (to answer, its
        // answer, closed); the polls hidden from the members are left out.
        $calendarPolls = array_filter(app_visible_polls($event, $user), static fn (array $poll): bool => app_poll_hidden_reason($event, $poll) === null);
        if ($calendarPolls !== []) {
            $concerned = app_user_concerned_by_event($user, $event);
            $description[] = '';
            $description[] = count($calendarPolls) > 1 ? __('sondages_label') : __('sondage_label');
            foreach ($calendarPolls as $poll) {
                $answer = $poll['responses'][$user['id']] ?? null;
                $state = match (true) {
                    $answer !== null => __('votre_reponse_answer', ['answer' => $poll['options'][$answer]]),
                    $poll['closed'] => __('clos_minuscule'),
                    $concerned => __('remplir'),
                    default => __('ouvert_minuscule'),
                };
                $description[] = '- ' . $poll['question'] . ' (' . $state . ')';
            }
        }
        // Link to the page of the date (polls, attendance, details; opening it needs to be logged in).
        if (is_string($event['id'] ?? null) && $event['id'] !== '') {
            $description[] = '';
            $description[] = ($calendarPolls !== [] ? __('repondre_voir_date_dans_app_url', ['app' => app_site_nickname(), 'url' => app_absolute_url('event.php?id=' . rawurlencode($event['id']))]) : __('voir_date_dans_app_url', ['app' => app_site_nickname(), 'url' => app_absolute_url('event.php?id=' . rawurlencode($event['id']))]));
        }
        if ($description !== []) {
            $calendarLines[] = 'DESCRIPTION:' . escapeIcalText(implode("\n", $description));
        }

        if (isset($event['location']) && is_string($event['location']) && $event['location'] !== '') {
            $calendarLines[] = 'LOCATION:' . escapeIcalText($event['location']);
        }
        $eventUrl = isset($event['url']) && is_string($event['url']) ? $event['url'] : '';
        if (filter_var($eventUrl, FILTER_VALIDATE_URL) !== false && in_array(parse_url($eventUrl, PHP_URL_SCHEME), ['http', 'https'], true)) {
            $calendarLines[] = 'URL:' . $eventUrl;
        }
        $calendarLines[] = 'END:VEVENT';
    }

    $calendarLines[] = 'END:VCALENDAR';
    header('Content-Type: text/calendar; charset=utf-8');
    // File named after the account, e.g. "calendrier-paul.ics"; logins may hold accented letters,
    // hence an ASCII fallback name and the UTF-8 one (RFC 6266).
    $calendarFile = 'calendrier-' . $user['username'] . '.ics';
    $unaccented = strtr($calendarFile, array_map(static fn (string $decomposed): string => $decomposed[0], array_flip(USERNAME_COMPOSITIONS)));
    $asciiFile = preg_replace('/[^A-Za-z0-9_.-]/u', '_', $unaccented);
    header('Content-Disposition: attachment; filename="' . $asciiFile . '"; filename*=UTF-8\'\'' . rawurlencode($calendarFile));
    header('Cache-Control: no-store');
    echo implode("\r\n", array_map('foldIcalLine', $calendarLines)) . "\r\n";
    exit;
}

// Personal iCal link of the displayed account (the member's own link while impersonating).
$calendarQuery = ['format' => 'ical', 'token' => app_calendar_token($user)];
if ($activeSection !== 'Toutes') {
    $calendarQuery['section'] = $activeSection;
}
$calendarUrl = 'agenda.php?' . http_build_query($calendarQuery, '', '&', PHP_QUERY_RFC3986);

// Announcements of the home page (not on the full agenda); the notifications of announcements
// whose first day has come leave on the first visit.
$homeAnnouncements = [];
if (!$showAllEvents) {
    app_announcements_dispatch_push();
    $homeAnnouncements = app_home_announcements($user);
}
// The latest announcement is shown above the dates until the member hides it ("Masquer"); the
// others, and the hidden one, stay folded ("Autres annonces", or "Toutes les annonces" once the
// latest is hidden).
$featuredAnnouncement = $homeAnnouncements !== [] && !app_announcement_dismissed_by($homeAnnouncements[0], $user) ? $homeAnnouncements[0] : null;
$foldedAnnouncements = $featuredAnnouncement !== null ? array_slice($homeAnnouncements, 1) : $homeAnnouncements;
// Without an announcement shown, nothing is left above the dates: the hidden ones open from the
// "Annonces (n)" link next to "Agenda complet" (index.php?annonces=1).
$showAllAnnouncements = $featuredAnnouncement === null && $foldedAnnouncements !== [] && ($_GET['annonces'] ?? '') === '1';
$announcementAuthors = [];
foreach ($homeAnnouncements !== [] ? read_json_file(app_data_path('users.json')) : [] as $account) {
    if (is_array($account) && is_string($account['id'] ?? null)) {
        $announcementAuthors[$account['id']] = (string) ($account['username'] ?? '');
    }
}

// "Nouveau" / "Modifié" labels since the member last opened each date.
$seenState = app_seen_state($user);
$attendanceOptions = app_attendance_responses();
$showAttendanceSummary = in_array($user['role'], EVENT_MANAGER_ROLES, true);
$userLeafSections = app_expand_sections($userSections);
$members = [];
if ($showAttendanceSummary) {
    foreach (read_json_file(app_data_path('users.json')) as $account) {
        if (app_is_active_user($account)) {
            $members[] = [
                'id' => $account['id'],
                'username' => $account['username'],
                'sections' => app_expand_sections(app_user_sections($account)),
            ];
        }
    }
    usort($members, static fn (array $first, array $second): int => strcasecmp($first['username'], $second['username']));
}
?>
<?php
// One announcement of the home page; $canHide: the "Masquer" button of the latest one.
function announcementCard(array $announcement, array $user, array $authors, bool $canHide): void
{
    ?>
        <?php // Order: "Annonce · author", title, photo, description, then the link. ?>
        <article class="announcement" id="annonce-<?= app_escape($announcement['id']) ?>">
          <div class="announcement-content">
            <p class="announcement-eyebrow"><?= app_escape(__('annonce')) ?><?php if (($authors[$announcement['author']] ?? '') !== ''): ?> · <?= app_escape($authors[$announcement['author']]) ?><?php endif; ?></p>
            <h2><?= app_escape($announcement['title']) ?></h2>
            <?php if ($announcement['image'] !== null): ?>
            <a class="announcement-image" href="<?= app_escape(app_announcement_image_url($announcement)) ?>" target="_blank" rel="noopener"><img src="<?= app_escape(app_announcement_image_url($announcement)) ?>" alt=""></a>
            <?php endif; ?>
            <?php if ($announcement['body'] !== ''): ?>
            <p class="announcement-body"><?= implode('<br>', array_map('app_linkify', app_text_lines($announcement['body']))) ?></p>
            <?php endif; ?>
            <?php if (app_is_announcement_author($announcement, $user)): ?>
            <p class="announcement-audience"><?= app_escape(__('votre_annonce_visible_audience', ['audience' => app_announcement_audience($announcement)])) ?><?php if (app_can_edit_announcement($user, $announcement)): ?> · <a href="announcements.php?modifier=<?= rawurlencode($announcement['id']) ?>"><?= app_escape(__('modifier')) ?></a><?php endif; ?></p>
            <?php endif; ?>
            <?php if ($announcement['link_url'] !== ''): ?>
            <a class="primary-button compact-button announcement-link" href="<?= app_escape($announcement['link_url']) ?>" target="_blank" rel="noopener noreferrer"><?= app_escape($announcement['link_label'] !== '' ? $announcement['link_label'] : __('ouvrir_lien')) ?> <span aria-hidden="true">↗</span></a>
            <?php endif; ?>
          </div>
          <?php if ($canHide): ?>
          <form class="announcement-dismiss" method="post" action="announcement-action.php">
            <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
            <input type="hidden" name="action" value="dismiss">
            <input type="hidden" name="id" value="<?= app_escape($announcement['id']) ?>">
            <button class="text-button" type="submit" title="<?= app_escape(__('ranger_cette_annonce_dans_toutes_annonces')) ?>"><?= app_escape(__('masquer')) ?> <span aria-hidden="true">×</span></button>
          </form>
          <?php endif; ?>
        </article>
<?php
}
?>
<?php app_header($showAllEvents ? __('agenda_complet') : __('mes_prochains_rendez_vous'), $user); ?>
      <?php if ($featuredAnnouncement !== null || $showAllAnnouncements): ?>
      <section class="announcements" id="annonces" aria-label="<?= app_escape(__('annonces')) ?>">
        <?php if ($featuredAnnouncement !== null): ?>
        <?php announcementCard($featuredAnnouncement, $user, $announcementAuthors, true); ?>
        <?php endif; ?>
        <?php if ($foldedAnnouncements !== []): ?>
        <details class="announcements-more"<?= $showAllAnnouncements ? ' open' : '' ?>>
          <summary><?= $featuredAnnouncement !== null ? __('autres_annonces') : __('toutes_annonces') ?> (<?= count($foldedAnnouncements) ?>)</summary>
          <?php foreach ($foldedAnnouncements as $announcement): ?>
          <?php announcementCard($announcement, $user, $announcementAuthors, false); ?>
          <?php endforeach; ?>
        </details>
        <?php endif; ?>
      </section>
      <?php endif; ?>
      <section class="agenda-section" id="agenda" aria-labelledby="agenda-title">
        <div class="agenda-topline">
          <p class="eyebrow"><span class="eyebrow-line"></span> <?= app_escape($showAllEvents ? __('agenda_groupe_majuscules') : __('vos_agendas_majuscules')) ?></p>
          <div class="agenda-topline-end">
          <?php
              $isManagerView = in_array($user['role'], EVENT_MANAGER_ROLES, true);
              // The other view keeps the section and the "Mes réponses" filter (the years only
              // apply to the full agenda).
              $otherViewQuery = array_filter(['section' => $activeSection !== $defaultSection ? $activeSection : null, 'presence' => $presenceFilter]);
              $otherViewUrl = ($showAllEvents ? 'index.php' : 'agenda.php') . ($otherViewQuery !== [] ? '?' . http_build_query($otherViewQuery, '', '&', PHP_QUERY_RFC3986) : '');
              // Shortcut to add a date (managers and administrators), then the other agenda view.
              app_page_links([
                  $isManagerView ? [__('ajouter_date'), 'manage-events.php?onglet=ajouter'] : null,
                  !$showAllEvents && $featuredAnnouncement === null && $foldedAnnouncements !== [] && !$showAllAnnouncements ? ['Annonces (' . count($foldedAnnouncements) . ')', 'index.php?annonces=1#annonces'] : null,
                  $showAllEvents ? [__('mes_prochains_rendez_vous'), $otherViewUrl] : [__('agenda_complet'), $otherViewUrl],
              ]);
          ?>
          </div>
        </div>
        <div class="section-heading agenda-heading">
          <div>
            <h2 id="agenda-title"><?= app_escape($showAllEvents ? trim(__('tous_evenements_association', ['association' => app_site_identity()['association']])) : __('mes_prochains_rendez_vous')) ?><span class="heading-dot">.</span></h2>
          </div>
          <div class="page-actions">
            <a class="ical-link" href="<?= app_escape($calendarUrl) ?>">
              <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" role="img" aria-label="<?= app_escape(__('sabonner_calendrier_ical')) ?>">
                <title><?= app_escape(__('sabonner_au_calendrier_ical')) ?></title>
                <path d="M12 21H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v6"/>
                <path d="M8 2v4M16 2v4M3 10h18"/>
                <path d="M7 14h.01M11 14h.01M15 14h.01M7 17.5h.01"/>
                <path d="M16 22l6-6M17.5 16H22v4.5"/>
              </svg>
            </a>
          </div>
        </div>

        <?php
            // Filters folded in an accordion, open while a filter other than the default one is
            // active; its title sums up what is shown.
            $activeFilters = array_filter([
                $presenceFilter !== null ? app_attendance_filters()[$presenceFilter] : null,
                $activeSection === 'Toutes' ? __('toutes_sections') : ($sectionLabels[$activeSection] ?? null),
            ]);
            $filtersOpen = $presenceFilter !== null || $activeSection !== $defaultSection;
        ?>
        <details class="filters-accordion"<?= $filtersOpen ? ' open' : '' ?>>
          <summary>
            <span class="filters-accordion-title"><?= app_escape(__('filtres')) ?></span>
            <span class="filters-accordion-summary"><?= app_escape(implode(' · ', $activeFilters)) ?></span>
          </summary>
        <nav class="filters" aria-label="<?= app_escape(__('filtrer_dates')) ?>">
          <div class="filter-rows">
          <?php if ($canFilterPresence): ?>
          <div class="response-filters" role="group" aria-label="<?= app_escape(__('filtrer_selon_mes_reponses')) ?>">
            <span class="filter-row-label"><?= app_escape(__('mes_reponses')) ?></span>
            <?php foreach (app_attendance_filters() as $response => $label): ?>
            <?php $isActive = $presenceFilter === $response; ?>
            <a class="filter-button filter-response filter-response-<?= $response ?><?= $isActive ? ' is-active' : '' ?>" href="<?= app_escape(sectionUrl($activeSection, $isActive ? null : $response)) ?>" aria-pressed="<?= $isActive ? 'true' : 'false' ?>" title="<?= app_escape($isActive ? __('afficher_toutes_dates') : ($response === ATTENDANCE_NO_RESPONSE ? __('nafficher_dates_auxquelles_je_nai_encore') : __('nafficher_dates_ma_reponse_answer', ['answer' => $label]))) ?>"><?= app_escape($label) ?></a>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
          <?php if ($showAllEvents && count($availableYears) > 1): ?>
          <div class="response-filters year-filters" role="group" aria-label="<?= app_escape(__('filtrer_annee')) ?>">
            <span class="filter-row-label"><?= app_escape(__('annees')) ?></span>
            <?php foreach ($availableYears as $year): ?>
            <?php
                $isActive = in_array($year, $selectedYears, true);
                $toggledYears = $isActive ? array_values(array_diff($selectedYears, [$year])) : [...$selectedYears, $year];
            ?>
            <a class="filter-button filter-year<?= $isActive ? ' is-active' : '' ?>" href="<?= app_escape(sectionUrl($activeSection, false, $toggledYears)) ?>" aria-pressed="<?= $isActive ? 'true' : 'false' ?>" title="<?= app_escape($isActive ? __('retirer_year', ['year' => $year]) : __('ajouter_year', ['year' => $year])) ?>"><?= $year ?></a>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
          <?php // An artist with a single sub-section as only choice already sees just their dates: no filter needed. ?>
          <?php if ($user['role'] !== 'musician' || count($availableSections) > 1): ?>
          <div class="section-filters">
            <a class="filter-button filter-all<?= $activeSection === 'Toutes' ? ' is-active' : '' ?>" href="<?= app_escape(sectionUrl('Toutes')) ?>"<?= $activeSection === 'Toutes' ? ' aria-current="page"' : '' ?>><?= app_escape(__('toutes_sections')) ?></a>
            <?php // Sections unchecked under "Filtres" in Configuration > Sections are not offered (the active one stays). ?>
            <?php foreach (array_filter($availableSections, static fn (string $section): bool => $section === $activeSection || app_section_shown($section, 'filters')) as $section): ?>
            <a class="filter-button<?= isset(app_section_tree()[$section]) ? ' filter-branch' : ' filter-child' ?><?= $activeSection === $section ? ' is-active' : '' ?>" href="<?= app_escape(sectionUrl($section)) ?>"<?= $activeSection === $section ? ' aria-current="page"' : '' ?>><?= app_escape($sectionLabels[$section]) ?></a>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
          </div>
        </nav>
        </details>

        <?php if ($visibleEvents !== []): ?>
        <div class="event-list">
          <?php foreach ($visibleEvents as $event): ?>
          <?php
              $date = $event['date_object'];
              $eventEndDate = $event['end_date_object'] ?? null;
              $weekday = app_weekday_name((int) $date->format('N'));
              $month = app_month_short((int) $date->format('n'));
              $eventResponses = isset($event['attendance']) && is_array($event['attendance']) ? $event['attendance'] : [];
              // No answer selected while the member has not answered.
              $attendance = app_attendance_response($eventResponses, (string) $user['id']);
              // Names of the members who answered "Présent", per sub-section of the date.
              $presentBySection = [];
              foreach ($showAttendanceSummary ? $event['sections'] : [] as $leaf) {
                  $presentBySection[$leaf] = [];
                  foreach ($members as $member) {
                      if (in_array($leaf, $member['sections'], true) && ($eventResponses[$member['id']] ?? null) === 'present') {
                          $presentBySection[$leaf][] = $member['username'];
                      }
                  }
              }
              $eventId = isset($event['id']) && is_string($event['id']) ? $event['id'] : '';
              $canRespond = $eventId !== ''
                  && array_intersect($event['sections'], $userLeafSections) !== [];
          ?>
          <article class="event-card status-<?= app_escape($event['status']) ?>"<?= $eventId !== '' ? ' id="event-' . app_escape($eventId) . '"' : '' ?>>
            <span class="status-badge event-status status-badge-<?= app_escape($event['status']) ?>"><?= app_escape(app_statuses()[$event['status']]) ?></span>
            <div class="event-date" aria-label="<?= app_escape(app_long_date($date, true, false, false)) ?>">
              <span class="date-day"><?= app_escape($date->format('d')) ?></span>
              <span class="date-month"><?= app_escape($month) ?></span>
            </div>
            <div class="event-details">
              <p class="event-weekday"><?= app_escape($weekday) ?></p>
              <h3><?php if ($eventId !== ''): ?><a class="event-title-link" href="event.php?id=<?= rawurlencode($eventId) ?>"><?= app_escape($event['title']) ?></a><?php else: ?><?= app_escape($event['title']) ?><?php endif; ?><?= app_change_badge(app_event_change_notice($event, $user, $seenState)) ?></h3>
              <div class="event-sections" aria-label="<?= app_escape(__('sections_concernees')) ?>">
                <?php foreach (app_section_display_items($event['all_sections']) as $eventSection): ?>
                <span class="section-tag <?= app_escape(app_section_css_class($eventSection)) ?>"><?= app_escape($sectionLabels[$eventSection]) ?></span>
                <?php endforeach; ?>
              </div>
              <div class="event-metadata">
                <?php if ($event['all_day']): ?>
                <span><?= app_escape(__('journee_entiere')) ?></span>
                <?php elseif (app_event_time_range($event) !== ''): ?>
                <span>◷ <?= app_escape(app_event_time_range($event)) ?></span>
                <?php endif; ?>
                <?php if ($eventEndDate instanceof DateTimeImmutable): ?>
                <span><?= app_escape(__('jusquau_date', ['date' => $eventEndDate->format('d/m/Y')])) ?></span>
                <?php endif; ?>
                <?php if (isset($event['location']) && is_string($event['location']) && $event['location'] !== ''): ?>
                <span>⌖ <?= app_escape($event['location']) ?></span>
                <?php endif; ?>
              </div>
            </div>
            <?php // No answer buttons on a finished, cancelled or refused date (shown disabled on its page). ?>
            <?php if ($canRespond && !app_event_attendance_locked($event)): ?>
            <form class="attendance-form" method="post" action="attendance-action.php">
              <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
              <input type="hidden" name="event_id" value="<?= app_escape($eventId) ?>">
              <input type="hidden" name="return_page" value="<?= $showAllEvents ? 'agenda' : 'index' ?>">
              <input type="hidden" name="section" value="<?= app_escape($activeSection) ?>">
              <?php if ($presenceFilter !== null): ?><input type="hidden" name="presence" value="<?= app_escape($presenceFilter) ?>"><?php endif; ?>
              <?php if ($showAllEvents && $selectedYears !== $defaultYears): ?><input type="hidden" name="annees" value="<?= app_escape(implode(',', $selectedYears)) ?>"><?php endif; ?>
              <span class="attendance-label"><?= app_escape(__('ma_reponse')) ?></span>
              <div class="attendance-options" role="group" aria-label="<?= app_escape(__('votre_reponse_title', ['title' => $event['title']])) ?>">
                <?php foreach ($attendanceOptions as $response => $label): ?>
                <button class="attendance-option attendance-<?= app_escape($response) ?><?= $attendance === $response ? ' is-selected' : '' ?>" type="submit" name="response" value="<?= app_escape($response) ?>" aria-pressed="<?= $attendance === $response ? 'true' : 'false' ?>"><?= app_escape($label) ?></button>
                <?php endforeach; ?>
              </div>
            </form>
            <?php endif; ?>
            <?php $pendingPolls = $eventId !== '' ? app_pending_polls($event, $user) : []; ?>
            <?php if ($pendingPolls !== []): ?>
            <p class="event-polls-pending">
              <span class="poll-pending-dot" aria-hidden="true"></span>
              <a href="event.php?id=<?= rawurlencode($eventId) ?>&amp;tab=sondage-<?= rawurlencode($pendingPolls[0]['id']) ?>"><?= app_escape(__n('sondage_remplir_question', 'sondages_remplir_question', count($pendingPolls), ['question' => $pendingPolls[0]['question']])) ?><?= count($pendingPolls) > 1 ? '…' : '' ?> <span aria-hidden="true">→</span></a>
            </p>
            <?php endif; ?>
            <?php if ($presentBySection !== []): ?>
            <p class="attendance-summary">
              <span class="attendance-summary-label"><?= app_escape(__('presents_label')) ?></span>
              <?php $separator = ''; ?>
              <?php foreach ($presentBySection as $leaf => $names): ?>
              <?= $separator ?><span class="attendance-section-count<?= $names === [] ? ' is-empty' : '' ?>"<?= $names !== [] ? ' title="' . app_escape(implode(', ', $names)) . '"' : '' ?>><?= app_escape($sectionLabels[$leaf]) ?> (<?= count($names) ?>)</span>
              <?php $separator = '<span class="attendance-separator" aria-hidden="true">/</span>'; ?>
              <?php endforeach; ?>
            </p>
            <?php endif; ?>
            <?php $hasRoadmap = $eventId !== '' && $event['status'] === 'confirmed' && app_roadmap_available($event); ?>
            <?php // The external link of a date is shown on its detail page only. ?>
            <?php if ($hasRoadmap || (in_array($user['role'], EVENT_MANAGER_ROLES, true) && $eventId !== '')): ?>
            <div class="event-card-actions">
              <?php if ($hasRoadmap): ?>
              <a class="event-roadmap-link" href="event.php?id=<?= rawurlencode($eventId) ?>&amp;tab=feuille-de-route"><?= app_escape(__('feuille_route')) ?> <span aria-hidden="true">→</span></a>
              <?php endif; ?>
              <?php if ($event['can_edit'] && $eventId !== ''): ?>
              <a class="event-edit-link" href="manage-events.php?edit=<?= rawurlencode($eventId) ?>" aria-label="<?= app_escape(__('modifier_title', ['title' => $event['title']])) ?>"><?= app_escape(__('modifier')) ?></a>
              <?php endif; ?>
            </div>
            <?php endif; ?>
          </article>
          <?php endforeach; ?>
        </div>
        <?php elseif ($showAllEvents && $listedEvents === [] && $allEvents !== []): ?>
        <p class="no-filter-results"><?= $selectedYears === [] ? __('selectionnez_moins_annee_afficher_dates') : __('aucune_date_annees_selectionnees_phrase') ?></p>
        <?php elseif ($listedEvents === []): ?>
        <p class="empty-state">
          <span class="empty-icon" aria-hidden="true">✳</span>
          <strong><?= $showAllEvents ? __('aucun_evenement_dans_lagenda_moment_phrase') : __('aucune_date_annoncee_moment_phrase') ?></strong>
          <span><?= $showAllEvents ? __('evenements_ajoutes_liste_apparaitront_ici_phrase') : __('prochains_rendez_vous_apparaitront_ici_tres') ?></span>
        </p>
        <?php else: ?>
        <p class="no-filter-results"><?= $presenceFilter === ATTENDANCE_NO_RESPONSE ? __('vous_avez_repondu_toutes_ces_dates_phrase') : ($presenceFilter !== null ? app_escape(__('aucune_ces_dates_na_answer_comme', ['answer' => app_attendance_filters()[$presenceFilter]])) : __('aucune_date_cette_section_moment_phrase')) ?></p>
        <?php endif; ?>
      </section>

      <?php // Proposal to install the application, shown by menu.js on phones only (see the install banner there). ?>
      <aside class="install-banner" data-install-banner hidden aria-label="<?= app_escape(__('installer_lapplication')) ?>">
        <img class="install-banner-icon" src="icon.php?size=180&amp;v=<?= app_escape(app_icon_version()) ?>" alt="">
        <div class="install-banner-text">
          <strong><?= app_escape(__('installez_app_votre_telephone', ['app' => app_site_nickname()])) ?></strong>
          <span data-install-banner-android><?= app_escape(__('ouvrez_lagenda_dun_geste_plein_ecran')) ?></span>
          <span data-install-banner-ios hidden><?= __('touchez_share_icon_puis_home_phrase', ['share' => '<b>' . app_escape(__('partager')) . '</b>', 'icon' => '<svg class="inline-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v12M8 7l4-4 4 4"/><path d="M6 11v9h12v-9"/></svg>', 'home' => '<b>' . app_escape(__('lecran_daccueil')) . '</b>']) ?></span>
          <span data-install-banner-other hidden><?= __('menu_navigateur_puis_install_home_phrase', ['install' => '<b>' . app_escape(__('installer_lapplication')) . '</b>', 'home' => '<b>' . app_escape(__('ajouter_lecran_daccueil')) . '</b>']) ?></span>
        </div>
        <div class="install-banner-actions">
          <button class="primary-button compact-button" type="button" data-install-banner-button hidden><?= app_escape(__('installer')) ?></button>
          <button class="text-button" type="button" data-install-banner-later><?= app_escape(__('plus_tard')) ?></button>
        </div>
      </aside>
<?php app_footer(); ?>
