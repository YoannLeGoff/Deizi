<?php
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';
$user = app_require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit(__('methode_non_autorisee_phrase'));
}

app_verify_csrf();
$eventId = $_POST['event_id'] ?? '';
$pollId = $_POST['poll_id'] ?? '';
$action = $_POST['action'] ?? '';
if (!is_string($eventId) || $eventId === '' || !is_string($pollId) || !in_array($action, ['respond', 'respond_for', 'create', 'update', 'toggle', 'hide', 'delete'], true)) {
    http_response_code(400);
    exit(__('demande_invalide_phrase'));
}
$isManager = in_array($user['role'], EVENT_MANAGER_ROLES, true);
if ($action !== 'respond' && !$isManager) {
    http_response_code(403);
    exit(__('seuls_managers_organisateurs_administrateurs_gerent_sondages'));
}

$question = app_clean_line($_POST['question'] ?? '', POLL_QUESTION_MAX_LENGTH);
$description = app_clean_text($_POST['description'] ?? '', POLL_DESCRIPTION_MAX_LENGTH);
$userInfo = app_clean_choices($_POST['user_info'] ?? [], POLL_USER_INFO);
$optionsInput = $_POST['options'] ?? '';
$optionId = is_string($_POST['option'] ?? null) ? $_POST['option'] : '';
$memberId = is_string($_POST['member_id'] ?? null) ? $_POST['member_id'] : '';
$members = array_filter(read_json_file(app_data_path('users.json')), 'app_is_active_user');
$impersonator = app_impersonator();
$author = $user['username'] . ($impersonator !== null ? '-i-' . $impersonator['username'] : '');
$error = null;
$message = '';
$tab = $pollId !== '' ? 'sondage-' . $pollId : 'details';

update_json_file(app_data_path('events.json'), static function (array $events) use ($eventId, &$pollId, $action, $user, $question, $description, $userInfo, $optionsInput, $optionId, $memberId, $members, $author, &$error, &$message, &$tab): array {
    foreach ($events as &$event) {
        if (!is_array($event) || ($event['id'] ?? null) !== $eventId) {
            continue;
        }
        if (!app_user_can_see_event($user, $event)) {
            // Reserved date of other sub-sections: treated as missing.
            break;
        }
        $polls = app_event_polls($event);
        if ($action !== 'create' && !isset($polls[$pollId])) {
            $error = __('ce_sondage_nexiste_plus_phrase');
            $tab = 'details';
            return $events;
        }

        switch ($action) {
            case 'respond':
                if (!app_user_concerned_by_event($user, $event)) {
                    $error = __('ce_sondage_concerne_vos_sections_phrase');
                } elseif ($polls[$pollId]['closed']) {
                    $error = __('ce_sondage_clos_reponses_sont_plus');
                } elseif (app_poll_hidden_reason($event, $polls[$pollId]) !== null) {
                    $error = __('ce_sondage_masque_reponses_sont_possibles');
                } elseif (!isset($polls[$pollId]['options'][$optionId])) {
                    $error = __('cette_reponse_nexiste_plus_phrase');
                } else {
                    $polls[$pollId]['responses'][$user['id']] = $optionId;
                    unset($polls[$pollId]['entered_by'][$user['id']]);
                    $message = __('votre_reponse_ete_enregistree_answer_phrase', ['answer' => $polls[$pollId]['options'][$optionId]]);
                }
                break;

            case 'respond_for':
                // A manager answers on behalf of a member of their scope, even once the poll is closed.
                $target = null;
                foreach (app_poll_proxy_members($user, $event, $members) as $candidate) {
                    if ($candidate['id'] === $memberId) {
                        $target = $candidate;
                        break;
                    }
                }
                if ($target === null) {
                    $error = __('ce_membre_nest_concerne_date_fait');
                } elseif ($optionId === '') {
                    unset($polls[$pollId]['responses'][$memberId], $polls[$pollId]['entered_by'][$memberId]);
                    $message = __('reponse_name_ete_effacee_phrase', ['name' => $target['username']]);
                } elseif (!isset($polls[$pollId]['options'][$optionId])) {
                    $error = __('cette_reponse_nexiste_plus_phrase');
                } else {
                    $polls[$pollId]['responses'][$memberId] = $optionId;
                    if ($memberId === $user['id']) {
                        unset($polls[$pollId]['entered_by'][$memberId]);
                    } else {
                        $polls[$pollId]['entered_by'][$memberId] = $author;
                    }
                    $message = __('reponse_enregistree_name_answer_phrase', ['name' => $target['username'], 'answer' => $polls[$pollId]['options'][$optionId]]);
                }
                break;

            case 'create':
            case 'update':
                $options = app_poll_options_from_text($optionsInput, $action === 'update' ? $polls[$pollId]['options'] : []);
                if ($question === '' || count($options) < 2) {
                    $error = __('indiquez_question_moins_deux_reponses_possibles');
                    $tab = $action === 'create' ? 'nouveau-sondage' : $tab;
                    break;
                }
                if ($action === 'create') {
                    $pollId = 'p' . bin2hex(random_bytes(4));
                    $polls[$pollId] = ['id' => $pollId, 'question' => $question, 'description' => $description, 'user_info' => $userInfo, 'options' => $options, 'closed' => false, 'hidden' => false, 'responses' => [], 'entered_by' => []];
                    $message = __('sondage_ete_cree_phrase');
                } else {
                    $polls[$pollId]['question'] = $question;
                    $polls[$pollId]['description'] = $description;
                    $polls[$pollId]['user_info'] = $userInfo;
                    $polls[$pollId]['options'] = $options;
                    // Answers to removed options are dropped.
                    $polls[$pollId]['responses'] = array_filter($polls[$pollId]['responses'], static fn (string $answer): bool => isset($options[$answer]));
                    $polls[$pollId]['entered_by'] = array_intersect_key($polls[$pollId]['entered_by'], $polls[$pollId]['responses']);
                    $message = __('sondage_ete_mis_jour_phrase');
                }
                $tab = 'sondage-' . $pollId;
                break;

            case 'toggle':
                $polls[$pollId]['closed'] = !$polls[$pollId]['closed'];
                $message = $polls[$pollId]['closed'] ? __('sondage_clos_phrase') : __('sondage_rouvert_phrase');
                break;

            case 'hide':
                $polls[$pollId]['hidden'] = !$polls[$pollId]['hidden'];
                $message = $polls[$pollId]['hidden'] ? __('sondage_masque_membres_phrase') : __('sondage_nouveau_visible_membres');
                break;

            case 'delete':
                unset($polls[$pollId]);
                $message = __('sondage_ete_supprime_phrase');
                $tab = 'details';
                break;
        }

        if ($error === null) {
            if ($polls === []) {
                unset($event['polls']);
            } else {
                $event['polls'] = app_serialize_polls($polls);
            }
        }
        return $events;
    }
    unset($event);
    $error = __('cette_date_nexiste_plus_phrase');
    return $events;
});

if ($error === __('cette_date_nexiste_plus_phrase')) {
    app_flash($error, 'error');
    app_redirect('index.php');
}
app_flash($error ?? $message, $error === null ? 'success' : 'error');
app_redirect('event.php?id=' . rawurlencode($eventId) . ($tab === 'details' ? '' : '&tab=' . $tab));
