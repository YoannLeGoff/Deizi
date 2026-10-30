<?php
declare(strict_types=1);

// File of a roadmap document (feuille de route de l'organisateur, plan d'accès…), shown in the
// browser. Same access as the roadmap: a member who sees it (managers and above, or everyone once
// the date is confirmed), or anyone with the public link of the roadmap (share token).
require __DIR__ . '/lib/bootstrap.php';

$eventId = $_GET['event'] ?? '';
$name = $_GET['f'] ?? '';
$event = null;
if (is_string($eventId) && $eventId !== '') {
    foreach (read_json_file(app_data_path('events.json')) as $candidate) {
        if (is_array($candidate) && ($candidate['id'] ?? null) === $eventId) {
            $event = $candidate;
            break;
        }
    }
}
$document = null;
foreach ($event !== null && is_string($name) ? app_event_roadmap($event)['documents'] : [] as $candidate) {
    if ($candidate['file'] === $name) {
        $document = $candidate;
    }
}
$path = $document !== null ? app_roadmap_document_path($document['file']) : null;

$allowed = false;
if ($path !== null && is_file($path) && app_roadmap_available($event)) {
    $shareToken = $_GET['share'] ?? null;
    $user = app_current_user();
    if (is_string($shareToken) && app_roadmap_share_token($event) !== null && hash_equals(app_roadmap_share_token($event), $shareToken)) {
        $allowed = true;
    } elseif ($user !== null && app_user_can_see_event($user, $event)) {
        $status = is_string($event['status'] ?? null) ? $event['status'] : 'pending';
        $allowed = in_array($user['role'], EVENT_MANAGER_ROLES, true) || $status === 'confirmed';
    }
}
if (!$allowed) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit(__('document_introuvable_phrase'));
}

$extension = pathinfo($path, PATHINFO_EXTENSION);
// File name offered when saving: the label of the document.
$fileName = trim(preg_replace('/[^\p{L}\p{N} ._-]+/u', ' ', $document['label']) ?? '') ?: 'document';
header('Content-Type: ' . ROADMAP_DOCUMENT_TYPES[$extension]);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="document.' . $extension . '"; filename*=UTF-8\'\'' . rawurlencode($fileName . '.' . $extension));
header('X-Content-Type-Options: nosniff');
// Images are locked down; PDFs need the browser's own viewer, which a sandbox would block.
if ($extension !== 'pdf') {
    header('Content-Security-Policy: sandbox');
}
header('Cache-Control: private, max-age=3600');
header('Referrer-Policy: no-referrer');
readfile($path);
