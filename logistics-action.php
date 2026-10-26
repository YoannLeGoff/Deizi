<?php
declare(strict_types=1);

// Saves the administrative follow-up of a date from the "Logistique" page: fee, and quotes
// sent to the organizers of the events or received from carriers (add, edit, delete).
require __DIR__ . '/lib/bootstrap.php';
$user = app_require_roles(LOGISTICS_ROLES);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Méthode non autorisée.');
}

app_verify_csrf();
$action = $_POST['action'] ?? '';
$eventId = $_POST['event_id'] ?? '';
if (!is_string($action) || !in_array($action, ['save_status', 'save_admin_note', 'save_fee', 'save_documents', 'add_need', 'remove_need', 'save_quote', 'save_generated_quote', 'delete_quote', 'publish_quote', 'unpublish_quote', 'save_quote_tracking'], true) || !is_string($eventId) || $eventId === '') {
    http_response_code(400);
    exit('Demande invalide.');
}
$eventExists = false;
foreach (read_json_file(app_data_path('events.json')) as $event) {
    if (is_array($event) && ($event['id'] ?? null) === $eventId) {
        $eventExists = true;
        break;
    }
}
if (!$eventExists) {
    app_flash('Cette date n’existe plus.', 'error');
    app_redirect('logistics.php');
}
$pageUrl = 'logistics.php?event=' . rawurlencode($eventId);
$now = (new DateTimeImmutable('now', new DateTimeZone('Europe/Paris')))->format(DATE_ATOM);
$author = (string) $user['username'];

// Logistics status: stored with the follow-up, and the status of the date follows it (with the
// history of entries and the "Modifié" label, as when the date is edited).
// Admin note of the date, from the Logistique page: saved on the date as from the date form
// (history of entries, "Modifié" label).
if ($action === 'save_admin_note') {
    $note = app_clean_text($_POST['admin_note'] ?? '', 3000);
    $returnTab = $_POST['return_tab'] ?? '';
    $returnUrl = is_string($returnTab) && isset(LOGISTICS_TABS[$returnTab]) ? app_logistics_tab_url($eventId, $returnTab) : 'logistics.php?event=' . rawurlencode($eventId);
    $impersonator = app_impersonator();
    update_json_file(app_data_path('events.json'), static function (array $events) use ($eventId, $note, $user, $impersonator, $now): array {
        foreach ($events as &$event) {
            if (!is_array($event) || ($event['id'] ?? null) !== $eventId || app_event_admin_note($event) === $note) {
                continue;
            }
            $before = $event;
            if ($note === '') {
                unset($event['admin_note']);
            } else {
                $event['admin_note'] = $note;
            }
            $event = app_event_record_change($before, $event, $user, $now);
            $logEntry = app_event_log_entry($before, $event, $user, $impersonator);
            if ($logEntry !== null) {
                app_attendance_log_append([$logEntry]);
            }
        }
        unset($event);
        return $events;
    });
    app_flash('La note admin a été enregistrée.');
    app_redirect($returnUrl);
}

if ($action === 'save_status') {
    $status = $_POST['logistics_status'] ?? '';
    $returnTab = $_POST['return_tab'] ?? '';
    $returnUrl = is_string($returnTab) && isset(LOGISTICS_TABS[$returnTab]) ? app_logistics_tab_url($eventId, $returnTab) : 'logistics.php?event=' . rawurlencode($eventId);
    $logisticsStatuses = app_logistics_statuses();
    if (!is_string($status) || !isset($logisticsStatuses[$status])) {
        app_flash('Choisissez un statut logistique valide.', 'error');
        app_redirect($returnUrl);
    }
    $eventStatus = $logisticsStatuses[$status][2];
    $impersonator = app_impersonator();
    $changed = false;
    update_json_file(app_data_path('events.json'), static function (array $events) use ($eventId, $eventStatus, $user, $impersonator, $now, &$changed): array {
        foreach ($events as &$event) {
            if (is_array($event) && ($event['id'] ?? null) === $eventId && ($event['status'] ?? 'pending') !== $eventStatus) {
                $before = $event;
                $event['status'] = $eventStatus;
                $event = app_event_record_change($before, $event, $user, $now);
                $logEntry = app_event_log_entry($before, $event, $user, $impersonator);
                if ($logEntry !== null) {
                    app_attendance_log_append([$logEntry]);
                }
                $changed = true;
            }
        }
        unset($event);
        return $events;
    });
    update_json_file(app_data_path('logistics.json'), static function (array $logistics) use ($eventId, $status): array {
        $entry = is_array($logistics[$eventId] ?? null) ? $logistics[$eventId] : [];
        $entry['status'] = $status;
        $logistics[$eventId] = $entry;
        return $logistics;
    });
    [$code, $label] = $logisticsStatuses[$status];
    app_flash('Statut logistique' . "\u{00A0}" . ': ' . $code . ' · ' . $label . ($changed ? ' — statut de la date' . "\u{00A0}" . ': ' . app_statuses()[$eventStatus] . '.' : '.'));
    app_redirect($returnUrl);
}

// Needs of the quotes received (Devis annexes): added by name, removed while they have no quote.
if ($action === 'add_need' || $action === 'remove_need') {
    $need = app_clean_line($_POST['need'] ?? '', 60);
    $annexesUrl = app_logistics_tab_url($eventId, 'annexes');
    if ($need === '') {
        app_flash('Indiquez le nom du besoin (Transport, Hébergement…).', 'error');
        app_redirect($annexesUrl);
    }
    $error = null;
    update_json_file(app_data_path('logistics.json'), static function (array $logistics) use ($eventId, $need, $action, &$error): array {
        $entry = is_array($logistics[$eventId] ?? null) ? $logistics[$eventId] : [];
        $followUp = app_event_logistics([$eventId => $entry], $eventId);
        $needs = $followUp['needs'];
        if ($action === 'add_need') {
            // Case-insensitive comparison, without requiring the mbstring extension.
            $lower = static fn (string $value): string => function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
            $existing = array_map($lower, array_column(app_logistics_need_blocks($followUp), 'need'));
            if (in_array($lower($need), $existing, true)) {
                $error = 'Le besoin «' . "\u{00A0}" . $need . "\u{00A0}" . '» existe déjà pour cette date.';
                return $logistics;
            }
            if (count($needs) >= LOGISTICS_MAX_NEEDS) {
                $error = 'Une date compte au plus ' . LOGISTICS_MAX_NEEDS . ' besoins.';
                return $logistics;
            }
            $needs[] = $need;
        } else {
            if (array_filter(app_logistics_quotes($followUp, 'received'), static fn (array $quote): bool => $quote['need'] === $need) !== []) {
                $error = 'Supprimez ou déplacez d’abord les devis de ce besoin.';
                return $logistics;
            }
            $needs = array_values(array_diff($needs, [$need]));
        }
        $entry['needs'] = $needs;
        $logistics[$eventId] = $entry;
        return $logistics;
    });
    app_flash($error ?? ($action === 'add_need' ? 'Le besoin «' . "\u{00A0}" . $need . "\u{00A0}" . '» a été ajouté.' : 'Le besoin «' . "\u{00A0}" . $need . "\u{00A0}" . '» a été retiré.'), $error !== null ? 'error' : 'success');
    app_redirect($annexesUrl . '#besoin-' . rawurlencode($need));
}

// Contract amount (paid by the organizer) and costs borne by the group are entered; the fee is
// computed (contract − costs) and stored with them.
// Documents annexes: the whole list of the form replaces the stored one. A row with an address that
// is not http(s) stops the saving, so that nothing typed is lost silently.
if ($action === 'save_documents') {
    $rows = is_array($_POST['documents'] ?? null) ? $_POST['documents'] : [];
    foreach ($rows as $row) {
        $url = is_array($row) ? app_clean_line($row['url'] ?? '', 2000) : '';
        $label = is_array($row) ? app_clean_line($row['label'] ?? '', LOGISTICS_DOCUMENT_LABEL_MAX) : '';
        if ($url !== '' && !app_is_http_url($url)) {
            app_flash('Le lien « ' . app_truncate($url, 60) . ' » n’est pas une adresse web valide (https://…).', 'error');
            app_redirect(app_logistics_tab_url($eventId, 'documents'));
        }
        if ($url === '' && $label !== '') {
            app_flash('Indiquez le lien du document « ' . $label . ' ».', 'error');
            app_redirect(app_logistics_tab_url($eventId, 'documents'));
        }
    }
    $documents = app_logistics_documents($rows);
    update_json_file(app_data_path('logistics.json'), static function (array $logistics) use ($eventId, $documents): array {
        $entry = is_array($logistics[$eventId] ?? null) ? $logistics[$eventId] : [];
        $entry['documents'] = $documents;
        $logistics[$eventId] = $entry;
        return $logistics;
    });
    app_flash($documents === [] ? 'Aucun document annexe pour cette date.' : 'Les documents annexes ont été enregistrés.');
    app_redirect(app_logistics_tab_url($eventId, 'documents'));
}

if ($action === 'save_fee') {
    // A negative contract is accepted; costs higher than the contract give a negative fee.
    $contract = app_parse_amount($_POST['contract'] ?? '', true);
    $costs = app_parse_amount($_POST['costs'] ?? '');
    $error = match (true) {
        $contract === false => 'Le montant du contrat est invalide (par exemple «' . "\u{00A0}" . '2400,00' . "\u{00A0}" . '»).',
        $costs === false => 'Le montant des frais est invalide (par exemple «' . "\u{00A0}" . '900,00' . "\u{00A0}" . '»).',
        $contract === null && $costs !== null => 'Indiquez le montant du contrat pour calculer le cachet.',
        default => null,
    };
    $fee = $contract !== null ? $contract - ($costs ?? 0) : null;
    if ($error !== null) {
        app_flash($error, 'error');
        app_redirect(app_logistics_tab_url($eventId, 'cachet'));
    }
    $feeNote = app_clean_text($_POST['fee_note'] ?? '', 1000);
    $formula = is_string($_POST['formula'] ?? null) && isset(app_logistics_formulas()[$_POST['formula']]) ? $_POST['formula'] : null;
    update_json_file(app_data_path('logistics.json'), static function (array $logistics) use ($eventId, $contract, $costs, $fee, $feeNote, $formula): array {
        $entry = is_array($logistics[$eventId] ?? null) ? $logistics[$eventId] : [];
        // A fee entered before the costs, without contract, is kept until a contract is entered.
        $legacyFee = $contract === null && is_int($entry['fee'] ?? null) && !array_key_exists('costs', $entry) ? $entry['fee'] : null;
        $entry['contract'] = $contract;
        $entry['costs'] = $costs;
        $entry['fee'] = $fee ?? $legacyFee;
        if ($legacyFee !== null) {
            unset($entry['costs']);
        }
        $entry['fee_note'] = $feeNote;
        $entry['formula'] = $formula;
        $logistics[$eventId] = $entry;
        return $logistics;
    });
    app_flash('Le contrat et le cachet ont été enregistrés.');
    app_redirect(app_logistics_tab_url($eventId, 'cachet'));
}

$quoteId = $_POST['quote_id'] ?? '';
if (!is_string($quoteId)) {
    http_response_code(400);
    exit('Demande invalide.');
}

// Public link of a generated quote. Publishing freezes the document (a copy is kept with the
// link) and a new link is created each time; withdrawing the link allows editing again.
// While published, only the follow-up (status, accepted option, internal note) can change.
if (in_array($action, ['publish_quote', 'unpublish_quote', 'save_quote_tracking'], true)) {
    $viewUrl = 'logistics-quote.php?' . http_build_query(['event' => $eventId, 'devis' => $quoteId]);
    $events = read_json_file(app_data_path('events.json'));
    $event = array_values(array_filter($events, static fn (mixed $item): bool => is_array($item) && ($item['id'] ?? null) === $eventId))[0] ?? [];
    $issuer = app_quote_issuer();
    $trackingStatus = $_POST['status'] ?? '';
    $trackingOption = $_POST['accepted_option'] ?? '';
    $trackingNote = app_clean_text($_POST['note'] ?? '', 1000);
    if ($action === 'save_quote_tracking' && !is_string($trackingStatus)) {
        http_response_code(400);
        exit('Demande invalide.');
    }
    $error = null;
    update_json_file(app_data_path('logistics.json'), static function (array $logistics) use ($eventId, $quoteId, $action, $event, $issuer, $user, $now, $trackingStatus, $trackingOption, $trackingNote, &$error): array {
        $entry = is_array($logistics[$eventId] ?? null) ? $logistics[$eventId] : [];
        $quotes = is_array($entry['quotes'] ?? null) ? array_values($entry['quotes']) : [];
        $quote = null;
        foreach (app_event_logistics([$eventId => $entry], $eventId)['quotes'] as $candidate) {
            if ($candidate['id'] === $quoteId && $candidate['kind'] === 'sent' && app_quote_is_generated($candidate)) {
                $quote = $candidate;
            }
        }
        if ($quote === null) {
            $error = 'Ce devis n’existe plus.';
            return $logistics;
        }
        if ($action === 'save_quote_tracking' && !isset(app_quote_selectable_statuses($quote['share'] !== null)[$trackingStatus])) {
            $error = 'Ce statut n’est pas possible pour ce devis (le statut «' . "\u{00A0}" . 'Publié' . "\u{00A0}" . '» suit la publication du lien).';
            return $logistics;
        }
        foreach ($quotes as &$stored) {
            if (!is_array($stored) || ($stored['id'] ?? null) !== $quoteId) {
                continue;
            }
            if ($action === 'publish_quote') {
                $stored['share'] = [
                    'token' => bin2hex(random_bytes(24)),
                    'published_at' => $now,
                    'published_by' => (string) $user['username'],
                    'document' => app_quote_document($quote, $event, $issuer),
                ];
                // A draft becomes published; a declined or validated quote keeps its status.
                if ($quote['status_type'] === 'draft') {
                    $stored['status'] = app_quote_status_default('published');
                }
            } elseif ($action === 'unpublish_quote') {
                unset($stored['share']);
                if ($quote['status_type'] === 'published') {
                    $stored['status'] = app_quote_status_default('draft');
                }
            } else {
                $optionCount = count($quote['groups']);
                $stored['status'] = $trackingStatus;
                $stored['accepted_option'] = is_string($trackingOption) && ctype_digit($trackingOption) && (int) $trackingOption < $optionCount ? (int) $trackingOption : null;
                $stored['note'] = $trackingNote;
                $stored['updated_at'] = $now;
                $stored['updated_by'] = (string) $user['username'];
            }
        }
        unset($stored);
        $entry['quotes'] = $quotes;
        $logistics[$eventId] = $entry;
        return $logistics;
    });
    if ($error !== null) {
        app_flash($error, 'error');
        app_redirect(app_logistics_tab_url($eventId, 'organisateurs'));
    }
    app_flash([
        'publish_quote' => 'Le devis est publié : son lien public est prêt à être envoyé. Le devis ne peut plus être modifié tant que le lien est publié.',
        'unpublish_quote' => 'Le lien public a été retiré : il ne fonctionne plus et le devis peut de nouveau être modifié. Republiez-le ensuite pour obtenir un nouveau lien.',
        'save_quote_tracking' => 'Le suivi du devis a été enregistré.',
    ][$action]);
    app_redirect($viewUrl);
}

// A published quote is frozen: withdraw its link before editing or deleting it.
$isPublished = static function (string $eventId, string $quoteId): bool {
    foreach (app_event_logistics(app_logistics_read(), $eventId)['quotes'] as $quote) {
        if ($quote['id'] === $quoteId && $quote['share'] !== null) {
            return true;
        }
    }
    return false;
};
if (in_array($action, ['delete_quote', 'save_generated_quote', 'save_quote'], true) && $quoteId !== '' && $isPublished($eventId, $quoteId)) {
    app_flash('Ce devis est publié et donc figé : retirez d’abord son lien public pour le modifier ou le supprimer.', 'error');
    app_redirect('logistics-quote.php?' . http_build_query(['event' => $eventId, 'devis' => $quoteId]));
}

if ($action === 'delete_quote') {
    $removed = null;
    update_json_file(app_data_path('logistics.json'), static function (array $logistics) use ($eventId, $quoteId, &$removed): array {
        $entry = is_array($logistics[$eventId] ?? null) ? $logistics[$eventId] : [];
        $quotes = is_array($entry['quotes'] ?? null) ? $entry['quotes'] : [];
        foreach ($quotes as $index => $quote) {
            if (is_array($quote) && ($quote['id'] ?? null) === $quoteId) {
                $removed = $quote;
                unset($quotes[$index]);
            }
        }
        $entry['quotes'] = array_values($quotes);
        $logistics[$eventId] = $entry;
        return $logistics;
    });
    if ($removed === null) {
        app_flash('Ce devis n’existe plus.', 'error');
    } else {
        app_delete_quote_file(is_string($removed['file'] ?? null) ? $removed['file'] : null);
        app_flash('Le devis a été supprimé.');
    }
    $removedKind = is_string($removed['kind'] ?? null) && isset(QUOTE_KIND_TABS[$removed['kind']]) ? $removed['kind'] : 'sent';
    app_redirect(app_logistics_tab_url($eventId, QUOTE_KIND_TABS[$removedKind]));
}

// save_generated_quote: quote written from the form of logistics-quote.php, numbered on creation.
if ($action === 'save_generated_quote') {
    $formUrl = 'logistics-quote.php?' . http_build_query(['event' => $eventId] + ($quoteId !== '' ? ['devis' => $quoteId, 'modifier' => '1'] : []));
    $status = $_POST['status'] ?? '';
    $party = app_clean_line($_POST['party'] ?? '', 120);
    $date = app_parse_date($_POST['date'] ?? null);
    if (!is_string($status) || !isset(app_quote_selectable_statuses(false)[$status])) {
        http_response_code(400);
        exit('Demande invalide.');
    }
    // Options in the order of the form; lines without description and options without line are
    // ignored. The option accepted by the organizer is sent by its key in the form.
    $groups = [];
    $acceptedKey = $_POST['accepted_group'] ?? '';
    $acceptedOption = null;
    foreach (array_slice(is_array($_POST['groups'] ?? null) ? $_POST['groups'] : [], 0, QUOTE_MAX_OPTIONS, true) as $groupKey => $group) {
        $lines = [];
        $rawLines = is_array($group) && is_array($group['lines'] ?? null) ? $group['lines'] : [];
        foreach (array_slice($rawLines, 0, QUOTE_MAX_LINES) as $line) {
            $label = app_clean_line(is_array($line) ? ($line['label'] ?? '') : '', 200);
            if ($label === '') {
                continue;
            }
            $quantityInput = $line['quantity'] ?? '1';
            $quantity = is_string($quantityInput) && ctype_digit(trim($quantityInput)) ? (int) trim($quantityInput) : 0;
            $unit = app_parse_amount($line['unit'] ?? '');
            if ($quantity < 1 || $quantity > 999 || $unit === false || $unit === null) {
                app_flash('Ligne «' . "\u{00A0}" . $label . "\u{00A0}" . '»' . "\u{00A0}" . ': indiquez une quantité (1 à 999) et un prix unitaire valide.', 'error');
                app_redirect($formUrl);
            }
            $lines[] = ['label' => $label, 'detail' => app_clean_text($line['detail'] ?? '', 400), 'quantity' => $quantity, 'unit' => $unit];
        }
        if ($lines !== []) {
            if (is_string($acceptedKey) && $acceptedKey !== '' && $acceptedKey === (string) $groupKey) {
                $acceptedOption = count($groups);
            }
            $groups[] = ['title' => app_clean_line($group['title'] ?? '', 120), 'lines' => $lines];
        }
    }
    $error = match (true) {
        $party === '' => 'Indiquez l’organisateur destinataire du devis.',
        $date === null => 'La date du devis est invalide.',
        $groups === [] => 'Ajoutez au moins une ligne au devis.',
        max(array_map(static fn (array $group): int => app_quote_lines_total($group['lines']), $groups)) > LOGISTICS_MAX_CENTS => 'Le montant d’une option est trop élevé.',
        default => null,
    };
    if ($error !== null) {
        app_flash($error, 'error');
        app_redirect($formUrl);
    }
    $values = [
        'kind' => 'sent',
        'party' => $party,
        'client_address' => app_clean_text($_POST['client_address'] ?? '', 400),
        'subject' => app_clean_line($_POST['subject'] ?? '', 160),
        'groups' => $groups,
        'accepted_option' => $acceptedOption,
        'date' => $date->format('Y-m-d'),
        'due' => app_clean_line($_POST['due'] ?? '', 80),
        'conditions' => app_clean_text($_POST['conditions'] ?? '', 2000),
        'status' => $status,
        'note' => app_clean_text($_POST['note'] ?? '', 1000),
        'updated_at' => $now,
        'updated_by' => $author,
    ];
    // File of a quote made elsewhere (PDF or image), kept with the options entered here.
    $removeFile = ($_POST['remove_file'] ?? '') === '1';
    try {
        $uploaded = app_store_quote_file($_FILES['file'] ?? null);
    } catch (InvalidArgumentException $exception) {
        app_flash($exception->getMessage(), 'error');
        app_redirect($formUrl);
    }
    $obsoleteFile = null;
    $prefix = app_quote_issuer()['prefix'];
    $savedId = $quoteId;
    update_json_file(app_data_path('logistics.json'), static function (array $logistics) use ($eventId, $quoteId, $values, $prefix, $uploaded, $removeFile, &$error, &$savedId, &$obsoleteFile): array {
        $entry = is_array($logistics[$eventId] ?? null) ? $logistics[$eventId] : [];
        $quotes = is_array($entry['quotes'] ?? null) ? array_values($entry['quotes']) : [];
        if ($quoteId === '') {
            if (count($quotes) >= QUOTE_MAX_PER_EVENT) {
                $error = 'Une date compte au plus ' . QUOTE_MAX_PER_EVENT . ' devis.';
                return $logistics;
            }
            $savedId = bin2hex(random_bytes(8));
            $quote = ['id' => $savedId, 'number' => app_next_quote_number($logistics, $prefix, substr($values['date'], 0, 4))] + $values;
            if ($uploaded !== null) {
                [$quote['file'], $quote['file_name']] = $uploaded;
            }
            $quotes[] = $quote;
        } else {
            $found = false;
            foreach ($quotes as &$quote) {
                if (is_array($quote) && ($quote['id'] ?? null) === $quoteId && ($quote['kind'] ?? null) === 'sent') {
                    // The number is kept, so a quote edited after printing keeps its reference;
                    // a quote entered before the editor gets one.
                    $number = is_string($quote['number'] ?? null) && $quote['number'] !== '' ? $quote['number'] : app_next_quote_number($logistics, $prefix, substr($values['date'], 0, 4));
                    $quote = array_merge($quote, $values, ['number' => $number]);
                    // Lines of the quotes generated before the options, single amount and options
                    // entered by hand before the editor.
                    unset($quote['lines'], $quote['amount'], $quote['options']);
                    $previousFile = is_string($quote['file'] ?? null) ? $quote['file'] : null;
                    if ($uploaded !== null) {
                        [$quote['file'], $quote['file_name']] = $uploaded;
                        $obsoleteFile = $previousFile;
                    } elseif ($removeFile) {
                        unset($quote['file'], $quote['file_name']);
                        $obsoleteFile = $previousFile;
                    }
                    $found = true;
                }
            }
            unset($quote);
            if (!$found) {
                $error = 'Ce devis n’existe plus.';
                return $logistics;
            }
        }
        $entry['quotes'] = $quotes;
        $logistics[$eventId] = $entry;
        return $logistics;
    });
    if ($error !== null) {
        app_delete_quote_file($uploaded[0] ?? null);
        app_flash($error, 'error');
        app_redirect(app_logistics_tab_url($eventId, 'organisateurs'));
    }
    app_delete_quote_file($obsoleteFile);
    app_flash($quoteId === '' ? 'Le devis a été généré.' : 'Le devis a été enregistré.');
    app_redirect('logistics-quote.php?' . http_build_query(['event' => $eventId, 'devis' => $savedId]));
}

// save_quote: a new quote ($quoteId empty) or the edition of an existing one.
$kind = $_POST['kind'] ?? '';
$status = $_POST['status'] ?? '';
if (!is_string($kind) || !isset(QUOTE_KINDS[$kind]) || !is_string($status) || !isset(QUOTE_STATUSES[$status])) {
    http_response_code(400);
    exit('Demande invalide.');
}
$formUrl = app_logistics_tab_url($eventId, QUOTE_KIND_TABS[$kind], $quoteId !== '' ? ['devis' => $quoteId] : []);
$party = app_clean_line($_POST['party'] ?? '', 120);
$subject = app_clean_line($_POST['subject'] ?? '', 160);
$note = app_clean_text($_POST['note'] ?? '', 1000);
// A quote to an organizer has options ([title, amount], several offers); a quote received an amount.
$amount = $kind === 'received' ? app_parse_amount($_POST['amount'] ?? '') : null;
$options = [];
$acceptedOption = null;
if ($kind === 'sent') {
    $acceptedKey = $_POST['accepted_option'] ?? '';
    foreach (array_slice(is_array($_POST['options'] ?? null) ? $_POST['options'] : [], 0, QUOTE_MAX_OPTIONS, true) as $optionKey => $option) {
        $optionTitle = app_clean_line(is_array($option) ? ($option['title'] ?? '') : '', 120);
        $optionAmount = app_parse_amount(is_array($option) ? ($option['amount'] ?? '') : '');
        if ($optionAmount === null && $optionTitle === '') {
            continue;
        }
        if ($optionAmount === false || $optionAmount === null) {
            app_flash('Indiquez un montant valide pour chaque option (par exemple «' . "\u{00A0}" . '1250,00' . "\u{00A0}" . '»).', 'error');
            app_redirect($formUrl);
        }
        if (is_string($acceptedKey) && $acceptedKey === (string) $optionKey) {
            $acceptedOption = count($options);
        }
        $options[] = ['title' => $optionTitle, 'amount' => $optionAmount];
    }
}
$dateInput = $_POST['date'] ?? '';
$date = is_string($dateInput) && $dateInput !== '' ? app_parse_date($dateInput) : null;
if ($party === '') {
    app_flash('Indiquez ' . ($kind === 'sent' ? 'l’organisateur' : 'le prestataire') . ' du devis.', 'error');
    app_redirect($formUrl);
}
if ($amount === false) {
    app_flash('Le montant du devis est invalide (par exemple «' . "\u{00A0}" . '1250,00' . "\u{00A0}" . '»).', 'error');
    app_redirect($formUrl);
}
if (is_string($dateInput) && $dateInput !== '' && $date === null) {
    app_flash('La date du devis est invalide.', 'error');
    app_redirect($formUrl);
}
$removeFile = ($_POST['remove_file'] ?? '') === '1';
try {
    $uploaded = app_store_quote_file($_FILES['file'] ?? null);
} catch (InvalidArgumentException $exception) {
    app_flash($exception->getMessage(), 'error');
    app_redirect($formUrl);
}

// Quotes received: their need; a quote accepted becomes the only accepted quote of its need.
$need = $kind === 'received' ? app_clean_line($_POST['need'] ?? '', 60) : '';
$values = [
    'kind' => $kind,
    'party' => $party,
    'subject' => $subject,
    'need' => $need,
    'amount' => $amount,
    'options' => $options,
    'accepted_option' => $acceptedOption,
    'date' => $date?->format('Y-m-d') ?? '',
    'status' => $status,
    'note' => $note,
    'updated_at' => $now,
    'updated_by' => $author,
];
$error = null;
$obsoleteFile = null;
$declined = 0;
update_json_file(app_data_path('logistics.json'), static function (array $logistics) use ($eventId, $quoteId, $values, $uploaded, $removeFile, &$error, &$obsoleteFile, &$declined): array {
    $entry = is_array($logistics[$eventId] ?? null) ? $logistics[$eventId] : [];
    $quotes = is_array($entry['quotes'] ?? null) ? array_values($entry['quotes']) : [];
    if ($values['kind'] === 'received' && $values['need'] !== '') {
        $needs = is_array($entry['needs'] ?? null) ? $entry['needs'] : [];
        if (!in_array($values['need'], $needs, true) && count($needs) < LOGISTICS_MAX_NEEDS) {
            $entry['needs'] = [...$needs, $values['need']];
        }
    }
    if ($quoteId === '') {
        if (count($quotes) >= QUOTE_MAX_PER_EVENT) {
            $error = 'Une date compte au plus ' . QUOTE_MAX_PER_EVENT . ' devis.';
            return $logistics;
        }
        $quote = ['id' => bin2hex(random_bytes(8))] + $values;
        if ($uploaded !== null) {
            [$quote['file'], $quote['file_name']] = $uploaded;
        }
        $quotes[] = $quote;
    } else {
        $index = null;
        foreach ($quotes as $candidateIndex => $candidate) {
            if (is_array($candidate) && ($candidate['id'] ?? null) === $quoteId) {
                $index = $candidateIndex;
            }
        }
        if ($index === null) {
            $error = 'Ce devis n’existe plus.';
            return $logistics;
        }
        $quote = array_merge($quotes[$index], $values);
        $previousFile = is_string($quote['file'] ?? null) ? $quote['file'] : null;
        if ($uploaded !== null) {
            [$quote['file'], $quote['file_name']] = $uploaded;
            $obsoleteFile = $previousFile;
        } elseif ($removeFile) {
            unset($quote['file'], $quote['file_name']);
            $obsoleteFile = $previousFile;
        }
        $quotes[$index] = $quote;
    }
    if ($values['kind'] === 'received' && $values['status'] === 'accepted') {
        $savedId = $quote['id'];
        foreach ($quotes as &$other) {
            if (is_array($other) && ($other['id'] ?? null) !== $savedId && ($other['kind'] ?? null) === 'received'
                && ($other['need'] ?? '') === $values['need'] && ($other['status'] ?? null) === 'accepted') {
                $other['status'] = 'declined';
                $declined++;
            }
        }
        unset($other);
    }
    $entry['quotes'] = $quotes;
    $logistics[$eventId] = $entry;
    return $logistics;
});
if ($error !== null) {
    app_delete_quote_file($uploaded[0] ?? null);
    app_flash($error, 'error');
    app_redirect(app_logistics_tab_url($eventId, QUOTE_KIND_TABS[$kind]));
}
app_delete_quote_file($obsoleteFile);
app_flash(($quoteId === '' ? 'Le devis a été ajouté.' : 'Le devis a été enregistré.')
    . ($declined > 0 ? ' Il devient le devis retenu de ce besoin' . "\u{00A0}" . ': ' . ($declined > 1 ? 'les autres devis acceptés passent' : 'l’autre devis accepté passe') . ' à «' . "\u{00A0}" . 'Refusé' . "\u{00A0}" . '».' : ''));
app_redirect(app_logistics_tab_url($eventId, QUOTE_KIND_TABS[$kind]) . ($kind === 'received' ? '#besoin-' . rawurlencode($need) : ''));
