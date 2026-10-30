<?php
declare(strict_types=1);

// Administrative follow-up of the dates ("Logistique" page), for logisticians and administrators:
// fee agreed for the date, quotes sent to the organizers of the events (festivals…) and other
// quotes received ("Devis annexes": buses, accommodation…). Stored apart from the dates in data/logistics.json, keyed by
// date id, so that amounts never reach the agenda, the iCal feed or the other roles.

const QUOTE_KINDS = [
    'sent' => 'Devis organisateurs',
    'received' => 'Devis annexes',
];
const QUOTE_PARTY_LABELS = ['sent' => 'Organisateur', 'received' => 'Prestataire'];
// Tabs of the follow-up of a date (logistics.php?event=…&onglet=…): fee, then one per kind of quote.
const LOGISTICS_TABS = ['cachet' => 'Cachet', 'organisateurs' => 'Devis organisateurs', 'annexes' => 'Devis annexes', 'documents' => 'Documents annexes'];
// "Documents annexes" of a date: links to the contract or any other document kept elsewhere.
const LOGISTICS_DOCUMENTS_MAX = 30;
const LOGISTICS_DOCUMENT_LABEL_MAX = 120;
const QUOTE_KIND_TABS = ['sent' => 'organisateurs', 'received' => 'annexes'];

function app_logistics_tab_url(string $eventId, string $tab, array $query = []): string
{
    return 'logistics.php?' . http_build_query(['event' => $eventId, 'onglet' => $tab] + $query);
}
// Logistics status of a date, finer than the status seen by the other roles: key => [code, label,
// status of the date it sets]. Choosing a logistics status updates the status of the date.
// Statuses as installed; administrators edit the list in Configuration > Statuts
// (data/settings.json, key "logistics_statuses"), read with app_logistics_statuses().
const LOGISTICS_ORIGIN_STATUSES = [
    'lead' => ['00', 'Lead', 'lead'],
    'quote_to_send' => ['a1', 'Devis à envoyer', 'lead'],
    'organizer_to_confirm' => ['a2', 'À confirmer organisateur', 'pending'],
    'kp_to_confirm' => ['b2', 'À confirmer KP', 'pending'],
    'awaiting_contract' => ['b3', 'Attente contrat', 'pending'],
    'contract_received' => ['b1', 'Contrat reçu', 'confirmed'],
    'contract_signed' => ['b0', 'Contrat signé', 'confirmed'],
    'confirmed' => ['c2', 'Confirmé', 'confirmed'],
    'to_invoice' => ['c3', 'À facturer', 'completed'],
    'paid' => ['c4', 'Payé / terminé', 'completed'],
    'cancelled' => ['z1', 'Annulé', 'cancelled'],
    'refused' => ['z2', 'Refusé', 'refused'],
    'information' => ['a0', 'Information', 'information'],
];
// Logistics status of a date without one, or whose status was changed outside the Logistique page,
// as installed (each edited status can be marked as the default of its status of the date).
const LOGISTICS_ORIGIN_DEFAULTS = [
    'lead' => 'lead',
    'pending' => 'organizer_to_confirm',
    'confirmed' => 'confirmed',
    'completed' => 'to_invoice',
    'cancelled' => 'cancelled',
    'refused' => 'refused',
    'information' => 'information',
];

const LOGISTICS_STATUS_MAX = 40;

// Edited list of the logistics statuses, in their order: [key, code, label, status, default], or
// null when the stored list is missing or invalid (the statuses as installed apply then).
function app_logistics_stored_statuses(): ?array
{
    // Read once per request (the list is used for every date of the Logistique page).
    static $cache = false;
    if ($cache === false) {
        $cache = app_logistics_parse_statuses(read_json_file(app_data_path('settings.json'))['logistics_statuses'] ?? null);
    }
    return $cache;
}

function app_logistics_parse_statuses(mixed $stored): ?array
{
    if (!is_array($stored) || $stored === [] || count($stored) > LOGISTICS_STATUS_MAX) {
        return null;
    }
    $list = [];
    foreach ($stored as $item) {
        if (!is_array($item) || !is_string($item['key'] ?? null) || preg_match('/^[a-z0-9_-]{1,40}$/', $item['key']) !== 1
            || !is_string($item['code'] ?? null) || !is_string($item['label'] ?? null) || $item['label'] === ''
            || !isset(EVENT_STATUSES[$item['status'] ?? '']) || isset($list[$item['key']])) {
            return null;
        }
        $list[$item['key']] = ['key' => $item['key'], 'code' => $item['code'], 'label' => $item['label'], 'status' => $item['status'], 'default' => ($item['default'] ?? false) === true];
    }
    // Every status of the date needs at least one logistics status (its default).
    foreach (array_keys(EVENT_STATUSES) as $eventStatus) {
        if (!in_array($eventStatus, array_column($list, 'status'), true)) {
            return null;
        }
    }
    return array_values($list);
}

// Logistics statuses, in their order: key => [code, label, status of the date].
function app_logistics_statuses(): array
{
    $stored = app_logistics_stored_statuses();
    if ($stored === null) {
        return LOGISTICS_ORIGIN_STATUSES;
    }
    $statuses = [];
    foreach ($stored as $item) {
        $statuses[$item['key']] = [$item['code'], $item['label'], $item['status']];
    }
    return $statuses;
}

// Default logistics status of each status of the date: the one marked as default, otherwise the
// first one of that status in the list.
function app_logistics_status_defaults(): array
{
    $stored = app_logistics_stored_statuses();
    if ($stored === null) {
        return LOGISTICS_ORIGIN_DEFAULTS;
    }
    $defaults = [];
    foreach ($stored as $item) {
        if ($item['default'] && !isset($defaults[$item['status']])) {
            $defaults[$item['status']] = $item['key'];
        }
    }
    foreach ($stored as $item) {
        $defaults[$item['status']] ??= $item['key'];
    }
    return $defaults;
}

// Logistics status of $event: the one chosen on the Logistique page while it matches the status
// of the date, otherwise the default one for that status.
function app_event_logistics_status(array $event, array $logistics): string
{
    $eventStatus = is_string($event['status'] ?? null) && isset(EVENT_STATUSES[$event['status']]) ? $event['status'] : 'pending';
    $stored = $logistics[(string) ($event['id'] ?? '')]['status'] ?? null;
    $statuses = app_logistics_statuses();
    return is_string($stored) && isset($statuses[$stored]) && $statuses[$stored][2] === $eventStatus
        ? $stored
        : app_logistics_status_defaults()[$eventStatus];
}

// "a1 · Devis à envoyer" badge, coloured like the status of the date it sets.
function app_logistics_status_badge(string $status): string
{
    [$code, $label, $eventStatus] = app_logistics_statuses()[$status];
    return '<span class="status-badge logistics-status status-badge-' . app_escape($eventStatus) . '"><span class="logistics-code">' . app_escape($code) . '</span> ' . app_escape($label) . '</span>';
}

// Statuses of the quotes received (Devis annexes).
const QUOTE_STATUSES = [
    'pending' => 'En attente',
    'accepted' => 'Accepté',
    'declined' => 'Refusé',
];
// Statuses of the quotes to organizers: each has a behaviour (type). "draft": editable, no
// public link; "published": public link active, quote frozen (set by publishing the link);
// "declined"; "accepted": validated, counted in the accepted totals. The list is edited in
// Paramètres des devis > Flux de travail (data/settings.json, key "quote_statuses").
const QUOTE_STATUS_TYPES = [
    'draft' => 'Brouillon — modifiable, sans lien public',
    'published' => 'Publié — lien public actif, devis figé',
    'declined' => 'Refusé par l’organisateur',
    'accepted' => 'Validé — compté dans les totaux acceptés',
];
const QUOTE_ORIGIN_STATUSES = [
    'draft' => ['Brouillon', 'draft'],
    'published' => ['Publié', 'published'],
    'declined' => ['Refusé', 'declined'],
    'validated' => ['Validé', 'accepted'],
];
const QUOTE_STATUS_MAX = 20;

// Edited list of the statuses of the quotes to organizers ([key, label, type, default]), or null
// when missing or invalid (the statuses as installed apply then).
function app_quote_stored_statuses(): ?array
{
    static $cache = false;
    if ($cache === false) {
        $cache = app_quote_parse_statuses(read_json_file(app_data_path('settings.json'))['quote_statuses'] ?? null);
    }
    return $cache;
}

function app_quote_parse_statuses(mixed $stored): ?array
{
    if (!is_array($stored) || $stored === [] || count($stored) > QUOTE_STATUS_MAX) {
        return null;
    }
    $list = [];
    foreach ($stored as $item) {
        if (!is_array($item) || !is_string($item['key'] ?? null) || preg_match('/^[a-z0-9_-]{1,40}$/', $item['key']) !== 1
            || !is_string($item['label'] ?? null) || $item['label'] === '' || !isset(QUOTE_STATUS_TYPES[$item['type'] ?? '']) || isset($list[$item['key']])) {
            return null;
        }
        $list[$item['key']] = ['key' => $item['key'], 'label' => $item['label'], 'type' => $item['type'], 'default' => ($item['default'] ?? false) === true];
    }
    foreach (array_keys(QUOTE_STATUS_TYPES) as $type) {
        if (!in_array($type, array_column($list, 'type'), true)) {
            return null;
        }
    }
    return array_values($list);
}

// Statuses of the quotes to organizers, in their order: key => [label, type].
function app_quote_statuses(): array
{
    $stored = app_quote_stored_statuses();
    if ($stored === null) {
        return QUOTE_ORIGIN_STATUSES;
    }
    $statuses = [];
    foreach ($stored as $item) {
        $statuses[$item['key']] = [$item['label'], $item['type']];
    }
    return $statuses;
}

// Status given to a quote to an organizer when it takes a behaviour (created, published, link
// withdrawn…): the one marked as default for that type, otherwise the first of that type.
function app_quote_status_default(string $type): string
{
    foreach (app_quote_stored_statuses() ?? [] as $item) {
        if ($item['type'] === $type && $item['default']) {
            return $item['key'];
        }
    }
    foreach (app_quote_statuses() as $key => [, $statusType]) {
        if ($statusType === $type) {
            return $key;
        }
    }
    return $type;
}

// Statuses a quote to an organizer may take by hand: with a public link, the published ones,
// declined and validated; without, the drafts, declined and validated.
function app_quote_selectable_statuses(bool $published): array
{
    $allowed = $published ? ['published', 'declined', 'accepted'] : ['draft', 'declined', 'accepted'];
    return array_filter(app_quote_statuses(), static fn (array $status): bool => in_array($status[1], $allowed, true));
}

// Status of a quote: [key, label, type]. Quotes to organizers saved before these statuses
// ("pending", "accepted", "declined") or with a deleted status get the default of their state.
function app_quote_status(array $quote, mixed $raw): array
{
    if ($quote['kind'] === 'received') {
        $key = is_string($raw) && isset(QUOTE_STATUSES[$raw]) ? $raw : 'pending';
        return [$key, app_label(QUOTE_STATUSES[$key]), $key];
    }
    $statuses = app_quote_statuses();
    $published = $quote['share'] !== null;
    if (is_string($raw) && isset($statuses[$raw])) {
        $type = $statuses[$raw][1];
        if (($type === 'published' && !$published) || ($type === 'draft' && $published)) {
            $raw = null;
        }
    } else {
        $raw = match ($raw) {
            'accepted' => app_quote_status_default('accepted'),
            'declined' => app_quote_status_default('declined'),
            default => null,
        };
    }
    $key = $raw ?? app_quote_status_default($published ? 'published' : 'draft');
    return [$key, $statuses[$key][0], $statuses[$key][1]];
}

// Status badge of a quote, coloured by its behaviour.
function app_quote_status_badge(array $quote): string
{
    return '<span class="quote-status quote-status-' . app_escape($quote['status_type']) . '">' . app_escape($quote['status_label']) . '</span>';
}
const QUOTE_MAX_PER_EVENT = 30;
// Quotes received are grouped by need (Transport, Hébergement…): each need has its competing
// quotes and at most one accepted quote. Needs of a date, at most, and names proposed.
const LOGISTICS_MAX_NEEDS = 15;
const LOGISTICS_NEED_SUGGESTIONS = ['Transport', 'Hébergement', 'Restauration', 'Matériel', 'Sonorisation'];
const QUOTE_MAX_FILE_BYTES = 5 * 1024 * 1024;
// Attachments: [extension, MIME type]; PDF checked by its signature, images by getimagesize().
const QUOTE_FILE_TYPES = [
    'pdf' => 'application/pdf',
    'png' => 'image/png',
    'jpg' => 'image/jpeg',
    'webp' => 'image/webp',
];
// Largest amount accepted, in cents (10 million euros).
const LOGISTICS_MAX_CENTS = 1_000_000_000;
// Quotes sent to the organizers have no amount of their own: they propose options (alternative
// offers, e.g. "Bagad seul" / "Bagad et Cercle"), each with its amount; the organizer accepts one.
// Generated quotes: options made of lines (title, lines, total), and lines per option, at most.
const QUOTE_MAX_OPTIONS = 10;
const QUOTE_MAX_LINES = 20;

// Issuer of the generated quotes (data/settings.json, key "quote_issuer"), set on the
// "Paramètres des devis" page of the Logistique.
function app_quote_issuer(): array
{
    $stored = read_json_file(app_data_path('settings.json'))['quote_issuer'] ?? [];
    $stored = is_array($stored) ? $stored : [];
    $text = static fn (string $key, string $default = ''): string => is_string($stored[$key] ?? null) ? $stored[$key] : $default;
    $color = static fn (string $key, string $default): string => is_string($stored[$key] ?? null) && preg_match('/^#[0-9a-f]{6}$/', $stored[$key]) === 1 ? $stored[$key] : $default;
    $logo = $text('logo');
    $logoPath = $logo !== '' ? app_upload_path($logo) : null;
    return [
        // Issuer of the quotes: set in Logistique > Paramètres (name, address, legal mentions).
        'name' => $text('name', 'NOM DE L’ASSOCIATION'),
        'address' => $text('address'),
        'email' => $text('email'),
        'phone' => $text('phone'),
        // Remarks printed under the lines by default (one per line, "•" added when printing).
        'conditions' => $text('conditions', implode("\n", QUOTE_DEFAULT_REMARKS)),
        'due' => $text('due', 'selon date du festival'),
        'footer' => $text('footer', 'Association de loi 1901'),
        'total_label' => $text('total_label', 'Total TTC'),
        'title_color' => $color('title_color', '#2bb5b8'),
        'accent_color' => $color('accent_color', '#e2177d'),
        'logo' => $logoPath !== null && is_file($logoPath) ? $logo : null,
        'prefix' => $text('prefix', 'D'),
    ];
}

const QUOTE_DEFAULT_REMARKS = [
    'Le présent devis est valable 90 jours',
    'Le présent devis ne fait pas office de contrat',
    'Les repas, rafraîchissements et hébergements sur place sont à la charge de l’organisateur (repas chauds)',
    'Les frais de transport sont fournis à titre indicatif, un devis sera effectué avec notre transporteur sur la base de la demande finale',
];

// Total of lines, in cents.
function app_quote_lines_total(array $lines): int
{
    return array_sum(array_map(static fn (array $line): int => $line['quantity'] * $line['unit'], $lines));
}


// Next number of a generated quote ("D-2026-004"): one more than the highest number of the
// year among all the dates. Called inside the lock of data/logistics.json.
function app_next_quote_number(array $logistics, string $prefix, string $year): string
{
    $start = ($prefix !== '' ? $prefix . '-' : '') . $year . '-';
    $highest = 0;
    foreach ($logistics as $entry) {
        foreach (is_array($entry['quotes'] ?? null) ? $entry['quotes'] : [] as $quote) {
            $number = is_array($quote) && is_string($quote['number'] ?? null) ? $quote['number'] : '';
            if (str_starts_with($number, $start) && ctype_digit(substr($number, strlen($start)))) {
                $highest = max($highest, (int) substr($number, strlen($start)));
            }
        }
    }
    return $start . str_pad((string) ($highest + 1), 3, '0', STR_PAD_LEFT);
}

function app_logistics_read(): array
{
    return read_json_file(app_data_path('logistics.json'));
}

// Amounts of a date, in cents: contract (paid by the organizer) and costs borne by the group are
// entered; the fee is computed, contract − costs (costs not entered count as none). Dates saved
// before the costs were entered keep their fee: costs are then deduced (contract − fee), and a
// fee without contract stays as it was ("legacy_fee") until the contract is entered.
function app_logistics_amounts(array $raw): array
{
    $contract = is_int($raw['contract'] ?? null) ? $raw['contract'] : null;
    $storedFee = is_int($raw['fee'] ?? null) ? $raw['fee'] : null;
    $costs = is_int($raw['costs'] ?? null) ? $raw['costs'] : null;
    if ($costs === null && $contract !== null && $storedFee !== null && !array_key_exists('costs', $raw)) {
        $costs = $contract - $storedFee;
    }
    $fee = $contract !== null ? $contract - ($costs ?? 0) : $storedFee;
    return [
        'contract' => $contract,
        'costs' => $costs,
        'fee' => $fee,
        'legacy_fee' => $contract === null && $storedFee !== null,
    ];
}

// Normalized follow-up of one date: contract amount, costs and fee in cents (null when unknown), fee
// note and quotes. The contract amount is paid by the organizer, costs borne by the group
// included; the fee is what remains for the group once those costs are paid.
function app_event_logistics(array $logistics, string $eventId): array
{
    $raw = is_array($logistics[$eventId] ?? null) ? $logistics[$eventId] : [];
    $quotes = [];
    foreach (is_array($raw['quotes'] ?? null) ? $raw['quotes'] : [] as $quote) {
        if (!is_array($quote) || !is_string($quote['id'] ?? null) || !isset(QUOTE_KINDS[$quote['kind'] ?? ''])) {
            continue;
        }
        $normalized = [
            'id' => $quote['id'],
            'kind' => $quote['kind'],
            'party' => is_string($quote['party'] ?? null) ? $quote['party'] : '',
            'subject' => is_string($quote['subject'] ?? null) ? $quote['subject'] : '',
            'amount' => is_int($quote['amount'] ?? null) ? $quote['amount'] : null,
            'date' => is_string($quote['date'] ?? null) && app_parse_date($quote['date']) !== null ? $quote['date'] : '',
            'note' => is_string($quote['note'] ?? null) ? $quote['note'] : '',
            // Quotes received: need they answer ("" for the quotes entered before the needs).
            'need' => is_string($quote['need'] ?? null) ? $quote['need'] : '',
            'file' => is_string($quote['file'] ?? null) && app_quote_file_path($quote['file']) !== null ? $quote['file'] : null,
            'file_name' => is_string($quote['file_name'] ?? null) ? $quote['file_name'] : '',
            'updated_at' => is_string($quote['updated_at'] ?? null) ? $quote['updated_at'] : '',
            'updated_by' => is_string($quote['updated_by'] ?? null) ? $quote['updated_by'] : '',
            // Generated quotes only: number, lines, client address, validity and conditions.
            'number' => is_string($quote['number'] ?? null) ? $quote['number'] : '',
            'groups' => app_normalize_quote_groups($quote),
            // Quotes to organizers entered by hand: [title, amount] of each option.
            'options' => app_normalize_quote_options($quote['options'] ?? null),
            // Position of the option accepted by the organizer, among the options of the quote.
            'accepted_option' => is_int($quote['accepted_option'] ?? null) ? $quote['accepted_option'] : null,
            'client_address' => is_string($quote['client_address'] ?? null) ? $quote['client_address'] : '',
            // Free text of the "Date d'échéance" box ("selon date du festival", "30/06/2027"…).
            'due' => is_string($quote['due'] ?? null) ? $quote['due'] : '',
            'conditions' => is_string($quote['conditions'] ?? null) ? $quote['conditions'] : '',
            // Published link of a generated quote: token, publication and the document frozen
            // then (null while not published).
            'share' => app_normalize_quote_share($quote['share'] ?? null),
        ];
        [$normalized['status'], $normalized['status_label'], $normalized['status_type']] = app_quote_status($normalized, $quote['status'] ?? null);
        $quotes[] = $normalized;
    }
    return [
        // Needs of the quotes received, in their order (a need may have no quote yet).
        'needs' => array_values(array_unique(array_filter(is_array($raw['needs'] ?? null) ? $raw['needs'] : [], static fn (mixed $need): bool => is_string($need) && $need !== ''))),
        ...app_logistics_amounts($raw),
        'fee_note' => is_string($raw['fee_note'] ?? null) ? $raw['fee_note'] : '',
        // Formula of the date (key of app_logistics_formulas()), null when none or deleted.
        'formula' => is_string($raw['formula'] ?? null) && isset(app_logistics_formulas()[$raw['formula']]) ? $raw['formula'] : null,
        'quotes' => $quotes,
        // Documents annexes: [['label' => …, 'url' => http(s) address], …].
        'documents' => app_logistics_documents($raw['documents'] ?? null),
    ];
}

function app_normalize_quote_lines(mixed $lines): array
{
    $normalized = [];
    foreach (is_array($lines) ? $lines : [] as $line) {
        if (is_array($line) && is_string($line['label'] ?? null) && is_int($line['quantity'] ?? null) && is_int($line['unit'] ?? null)) {
            // "detail": smaller text printed under the line (schedule, number of artists…).
            $detail = is_string($line['detail'] ?? null) ? $line['detail'] : '';
            $normalized[] = ['label' => $line['label'], 'detail' => $detail, 'quantity' => $line['quantity'], 'unit' => $line['unit']];
        }
    }
    return $normalized;
}

// Groups of a generated quote: [title, lines]. Quotes generated before the groups had a single
// list of lines, read as one group without title.
function app_normalize_quote_groups(array $quote): array
{
    if (!is_array($quote['groups'] ?? null)) {
        $lines = app_normalize_quote_lines($quote['lines'] ?? null);
        return $lines === [] ? [] : [['title' => '', 'lines' => $lines]];
    }
    $groups = [];
    foreach ($quote['groups'] as $group) {
        $lines = is_array($group) ? app_normalize_quote_lines($group['lines'] ?? null) : [];
        if ($lines !== []) {
            $groups[] = ['title' => is_string($group['title'] ?? null) ? $group['title'] : '', 'lines' => $lines];
        }
    }
    return $groups;
}

function app_quote_is_generated(array $quote): bool
{
    return $quote['number'] !== '' && $quote['groups'] !== [];
}

// Quotes of one kind, with the total of the accepted ones.
function app_logistics_quotes(array $followUp, string $kind): array
{
    return array_values(array_filter($followUp['quotes'], static fn (array $quote): bool => $quote['kind'] === $kind));
}

function app_logistics_accepted_total(array $quotes): ?int
{
    $amounts = array_filter(array_map('app_quote_accepted_amount', $quotes), static fn (?int $amount): bool => $amount !== null);
    return $amounts === [] ? null : array_sum($amounts);
}

// Quotes received grouped by need: [need, quotes], the needs of the date in their order, then
// the needs of quotes missing from that list, then the quotes without need ("" need) if any.
function app_logistics_need_blocks(array $followUp): array
{
    $quotes = app_logistics_quotes($followUp, 'received');
    $needs = $followUp['needs'];
    foreach ($quotes as $quote) {
        if ($quote['need'] !== '' && !in_array($quote['need'], $needs, true)) {
            $needs[] = $quote['need'];
        }
    }
    $blocks = [];
    foreach ($needs as $need) {
        $blocks[] = ['need' => $need, 'quotes' => array_values(array_filter($quotes, static fn (array $quote): bool => $quote['need'] === $need))];
    }
    $withoutNeed = array_values(array_filter($quotes, static fn (array $quote): bool => $quote['need'] === ''));
    if ($withoutNeed !== []) {
        $blocks[] = ['need' => '', 'quotes' => $withoutNeed];
    }
    return $blocks;
}

function app_normalize_quote_options(mixed $options): array
{
    $normalized = [];
    foreach (is_array($options) ? $options : [] as $option) {
        if (is_array($option) && is_string($option['title'] ?? null) && is_int($option['amount'] ?? null)) {
            $normalized[] = ['title' => $option['title'], 'amount' => $option['amount']];
        }
    }
    return $normalized;
}

// Options of a quote, [title, amount] ("Option 2" when untitled): the options of a generated quote
// (total of their lines) or of a quote entered by hand; a quote received has its single amount.
function app_quote_options(array $quote): array
{
    if ($quote['kind'] === 'received') {
        return $quote['amount'] !== null ? [['title' => '', 'amount' => $quote['amount']]] : [];
    }
    $options = app_quote_is_generated($quote)
        ? array_map(static fn (array $group): array => ['title' => $group['title'], 'amount' => app_quote_lines_total($group['lines'])], $quote['groups'])
        : $quote['options'];
    // Quotes to organizers entered before the options: their amount is a single option.
    if ($options === [] && $quote['amount'] !== null) {
        $options = [['title' => '', 'amount' => $quote['amount']]];
    }
    foreach ($options as $index => &$option) {
        $option['title'] = $option['title'] !== '' ? $option['title'] : (count($options) > 1 ? __('option_n', ['n' => $index + 1]) : '');
    }
    return $options;
}

// Amount of an accepted quote: its amount, or the amount of the option accepted by the organizer
// (the only one when there is a single option); null otherwise.
function app_quote_accepted_amount(array $quote): ?int
{
    if ($quote['status_type'] !== 'accepted') {
        return null;
    }
    $options = app_quote_options($quote);
    if (count($options) === 1) {
        return $options[0]['amount'];
    }
    return $quote['accepted_option'] !== null ? ($options[$quote['accepted_option']]['amount'] ?? null) : null;
}

// "1 250,50" or "1250.5" or "1 250 €" => 125050 cents; null when empty; false when invalid.
// With $allowNegative ("-300", "− 300,50"), a negative amount is accepted: contract and fee of a
// date whose costs exceed what the organizer pays.
function app_parse_amount(mixed $value, bool $allowNegative = false): int|false|null
{
    if (!is_string($value)) {
        return false;
    }
    $value = str_replace(["\u{00A0}", "\u{202F}", ' ', '€', "\u{2212}"], ['', '', '', '', '-'], trim($value));
    if ($value === '') {
        return null;
    }
    if (preg_match('/^(-?)(\d{1,8})(?:[.,](\d{1,2}))?$/', $value, $match) !== 1 || ($match[1] === '-' && !$allowNegative)) {
        return false;
    }
    $cents = (int) $match[2] * 100 + (int) str_pad($match[3] ?? '0', 2, '0');
    return $cents <= LOGISTICS_MAX_CENTS ? ($match[1] === '-' ? -$cents : $cents) : false;
}

// 125050 => "1 250,50 €" (no-break spaces; the narrow one is missing from some bold fonts).
function app_format_amount(?int $cents): string
{
    return $cents === null ? '' : number_format($cents / 100, 2, ',', "\u{00A0}") . "\u{00A0}€";
}

// Value of an amount field: "1250,50", or "" when unknown.
function app_amount_input(?int $cents): string
{
    return $cents === null ? '' : number_format($cents / 100, 2, ',', '');
}

function app_quote_file_path(string $name): ?string
{
    return preg_match('/^quote-[0-9a-f]{32}\.(pdf|png|jpg|webp)$/', $name) === 1
        ? app_upload_directory() . '/' . $name
        : null;
}

// Validates and stores an uploaded quote (PDF or image). Returns [file name, original name],
// null when no file was sent, or throws InvalidArgumentException with a message for the user.
function app_store_quote_file(mixed $file): ?array
{
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if (($file['error'] ?? null) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) {
        throw new InvalidArgumentException(__('lenvoi_fichier_echoue_phrase'));
    }
    if (($file['size'] ?? 0) > QUOTE_MAX_FILE_BYTES) {
        throw new InvalidArgumentException(__('fichier_doit_depasser_5_mo_phrase'));
    }
    $extension = null;
    $handle = fopen($file['tmp_name'], 'rb');
    $signature = $handle !== false ? (string) fread($handle, 5) : '';
    if ($handle !== false) {
        fclose($handle);
    }
    if ($signature === '%PDF-') {
        $extension = 'pdf';
    } else {
        $info = @getimagesize($file['tmp_name']);
        $extension = match ($info[2] ?? null) {
            IMAGETYPE_PNG => 'png',
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_WEBP => 'webp',
            default => null,
        };
    }
    if ($extension === null) {
        throw new InvalidArgumentException(__('devis_doit_etre_fichier_pdf_image'));
    }
    $directory = app_upload_directory();
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException(__('impossible_creer_dossier_fichiers_phrase'));
    }
    $name = 'quote-' . bin2hex(random_bytes(16)) . '.' . $extension;
    $path = $directory . '/' . $name;
    if (!move_uploaded_file($file['tmp_name'], $path) || !chmod($path, 0600)) {
        throw new RuntimeException(__('impossible_denregistrer_fichier_phrase'));
    }
    $originalName = app_clean_line(basename(is_string($file['name'] ?? null) ? $file['name'] : ''), 120);
    return [$name, $originalName !== '' ? $originalName : 'devis.' . $extension];
}

function app_delete_quote_file(?string $name): void
{
    $path = $name !== null ? app_quote_file_path($name) : null;
    if ($path !== null && is_file($path)) {
        unlink($path);
    }
}

// Removes the follow-up of a deleted date, attachments included.
function app_logistics_delete_event(string $eventId): void
{
    $removed = null;
    update_json_file(app_data_path('logistics.json'), static function (array $logistics) use ($eventId, &$removed): array {
        if (isset($logistics[$eventId])) {
            $removed = app_event_logistics($logistics, $eventId);
            unset($logistics[$eventId]);
        }
        return $logistics;
    });
    foreach ($removed['quotes'] ?? [] as $quote) {
        app_delete_quote_file($quote['file']);
    }
}

// Main path of the workflow, by status of the date; the other statuses are exits from it.
const LOGISTICS_WORKFLOW_LANES = ['lead', 'pending', 'confirmed', 'completed'];
const LOGISTICS_WORKFLOW_EXITS = [
    'refused' => 'Demande déclinée, depuis Lead ou À valider (avant tout contrat).',
    'cancelled' => 'Prestation annulée, à tout moment avant Terminé, même après signature du contrat.',
    'information' => 'Hors circuit : date d’information, sans devis ni contrat.',
];

// Workflow of the logistics statuses (Configuration > Statuts): the main path from the lead to the
// payment in one column per status of the date, then the statuses outside that path.
function app_render_logistics_workflow(): void
{
    $badge = static fn (string $status): string => '<span class="status-badge status-badge-' . app_escape($status) . '">' . app_escape(app_statuses()[$status]) . '</span>';
    $statuses = app_logistics_statuses();
    $step = static fn (string $key): string => '<span class="workflow-code">' . app_escape($statuses[$key][0]) . '</span> ' . app_escape($statuses[$key][1]);
    $statusesOf = static fn (string $eventStatus): array => array_keys(array_filter($statuses, static fn (array $definition): bool => $definition[2] === $eventStatus));
    ?>
          <div class="workflow" role="group" aria-label="<?= app_escape(__('flux_travail_statuts_logistiques')) ?>">
            <ol class="workflow-lanes">
              <?php foreach (LOGISTICS_WORKFLOW_LANES as $eventStatus): ?>
              <li class="workflow-lane" style="--lane-cell: var(--status-<?= $eventStatus ?>-cell); --lane-accent: var(--status-<?= $eventStatus ?>-accent)">
                <p class="workflow-lane-title"><?= app_escape(__('statut_date_label')) ?> <?= $badge($eventStatus) ?></p>
                <ol class="workflow-steps">
                  <?php foreach ($statusesOf($eventStatus) as $key): ?>
                  <li class="workflow-step"><?= $step($key) ?></li>
                  <?php endforeach; ?>
                </ol>
              </li>
              <?php endforeach; ?>
            </ol>
            <ul class="workflow-exits">
              <?php foreach (LOGISTICS_WORKFLOW_EXITS as $eventStatus => $description): ?>
              <?php foreach ($statusesOf($eventStatus) as $key): ?>
              <li class="workflow-exit" style="--lane-cell: var(--status-<?= $eventStatus ?>-cell); --lane-accent: var(--status-<?= $eventStatus ?>-accent)">
                <p class="workflow-step"><?= $step($key) ?> <?= $badge($eventStatus) ?></p>
                <p class="workflow-exit-text"><?= app_escape(app_label($description)) ?></p>
              </li>
              <?php endforeach; ?>
              <?php endforeach; ?>
            </ul>
          </div>
    <?php
}

// Fields of a generated quote shown on its document, with the issuer as set at that time: what a
// published link shows, frozen until the quote is published again.
function app_quote_document(array $quote, array $event, array $issuer): array
{
    return [
        'number' => $quote['number'],
        'party' => $quote['party'],
        'client_address' => $quote['client_address'],
        'subject' => $quote['subject'],
        'date' => $quote['date'],
        'due' => $quote['due'],
        'conditions' => $quote['conditions'],
        'groups' => $quote['groups'],
        'event_title' => (string) ($event['title'] ?? ''),
        'event_date' => (string) ($event['date'] ?? ''),
        'issuer' => array_intersect_key($issuer, array_flip(['name', 'address', 'email', 'phone', 'footer', 'total_label', 'title_color', 'accent_color', 'logo'])),
    ];
}

// Document of a quote saved with its published link; null when invalid.
function app_normalize_quote_document(mixed $doc): ?array
{
    if (!is_array($doc) || !is_array($doc['issuer'] ?? null)) {
        return null;
    }
    $text = static fn (array $values, string $key): string => is_string($values[$key] ?? null) ? $values[$key] : '';
    $issuer = [];
    foreach (['name', 'address', 'email', 'phone', 'footer', 'total_label', 'title_color', 'accent_color'] as $key) {
        $issuer[$key] = $text($doc['issuer'], $key);
    }
    $issuer['logo'] = is_string($doc['issuer']['logo'] ?? null) ? $doc['issuer']['logo'] : null;
    $normalized = ['issuer' => $issuer, 'groups' => app_normalize_quote_groups(['groups' => $doc['groups'] ?? null])];
    foreach (['number', 'party', 'client_address', 'subject', 'date', 'due', 'conditions', 'event_title', 'event_date'] as $key) {
        $normalized[$key] = $text($doc, $key);
    }
    return $normalized['groups'] !== [] ? $normalized : null;
}

// The quote document (A4 sheet), from app_quote_document(); $logoUrl serves the issuer logo.
function app_render_quote_document(array $doc, ?string $logoUrl): void
{
    // A document of the association: its fixed labels in the language of the site, like its
    // content, whatever the language of the reader.
    $readerLocale = app_locale();
    app_locale(app_site_language());
    $issuer = $doc['issuer'];
    $quoteDate = app_parse_date($doc['date']);
    $eventDate = app_parse_date($doc['event_date']);
    $remarks = array_values(array_filter(array_map('trim', explode("\n", $doc['conditions'])), static fn (string $line): bool => $line !== ''));
    $printTitle = ($eventDate !== null ? $eventDate->format('Ymd') . ' - ' : '') . $doc['event_title'] . ' - ' . __('devis') . ' ' . $doc['number'];
    $options = [];
    foreach ($doc['groups'] as $index => $group) {
        $options[] = [
            'title' => $group['title'] !== '' ? $group['title'] : (count($doc['groups']) > 1 ? __('option_n', ['n' => $index + 1]) : ''),
            'amount' => app_quote_lines_total($group['lines']),
        ];
    }
    ?>
        <article class="quote-document" data-print-title="<?= app_escape($printTitle) ?>" style="--quote-title: <?= app_escape($issuer['title_color']) ?>; --quote-accent: <?= app_escape($issuer['accent_color']) ?>;">
          <header class="quote-document-header">
            <div class="quote-issuer">
              <strong><?= app_escape($issuer['name']) ?></strong>
              <?php if ($issuer['address'] !== ''): ?><span><?= nl2br(app_escape($issuer['address']), false) ?></span><?php endif; ?>
              <?php if ($issuer['email'] !== '' || $issuer['phone'] !== ''): ?><span><?= app_escape(implode(' · ', array_filter([$issuer['email'], $issuer['phone']]))) ?></span><?php endif; ?>
            </div>
            <?php if ($issuer['logo'] !== null && $logoUrl !== null): ?>
            <img class="quote-logo" src="<?= app_escape($logoUrl) ?>" alt="<?= app_escape($issuer['name']) ?>">
            <?php endif; ?>
          </header>

          <p class="quote-document-title"><?= app_escape(__('devis')) ?></p>
          <?php if ($quoteDate !== null): ?><p class="quote-sent-on"><?= app_escape(__('envoye_date', ['date' => $quoteDate->format('d/m/Y')])) ?></p><?php endif; ?>

          <div class="quote-parties">
            <div>
              <p class="quote-label"><?= app_escape(__('facturation')) ?></p>
              <p><?= app_escape($doc['party']) ?><?php if ($doc['client_address'] !== ''): ?><br><?= nl2br(app_escape($doc['client_address']), false) ?><?php endif; ?></p>
              <?php if ($doc['subject'] !== ''): ?>
              <p class="quote-label"><?= app_escape(__('projet')) ?></p>
              <p><?= app_escape($doc['subject']) ?></p>
              <?php endif; ?>
            </div>
            <div>
              <?php if ($doc['due'] !== ''): ?>
              <p class="quote-label"><?= app_escape(__('date_decheance')) ?></p>
              <p><?= app_escape($doc['due']) ?></p>
              <?php endif; ?>
              <p class="quote-label"><?= app_escape(__('numero')) ?></p>
              <p><?= app_escape($doc['number']) ?></p>
            </div>
          </div>

          <table class="quote-table">
            <thead>
              <tr>
                <th scope="col"><?= app_escape(__('description')) ?></th>
                <th scope="col" class="amount-cell"><?= app_escape(__('qte')) ?></th>
                <th scope="col" class="amount-cell"><?= app_escape(__('prix_unitaire')) ?></th>
                <th scope="col" class="amount-cell"><?= app_escape(__('prix_total')) ?></th>
              </tr>
            </thead>
            <?php foreach ($doc['groups'] as $optionIndex => $group): ?>
            <?php $group['title'] = $options[$optionIndex]['title']; ?>
            <?php if ($group['title'] !== ''): ?>
            <tbody class="quote-group-title">
              <tr><th scope="rowgroup" colspan="4"><?= app_escape($group['title']) ?></th></tr>
            </tbody>
            <?php endif; ?>
            <?php foreach ($group['lines'] as $line): ?>
            <tbody class="quote-line">
              <tr>
                <td><?= app_escape($line['label']) ?></td>
                <td class="amount-cell"><?= $line['quantity'] ?></td>
                <td class="amount-cell"><?= app_escape(app_format_amount($line['unit'])) ?></td>
                <td class="amount-cell"><?= app_escape(app_format_amount($line['quantity'] * $line['unit'])) ?></td>
              </tr>
              <?php if ($line['detail'] !== ''): ?>
              <tr class="quote-line-detail"><td colspan="4"><?= nl2br(app_escape($line['detail']), false) ?></td></tr>
              <?php endif; ?>
            </tbody>
            <?php endforeach; ?>
            <tbody class="quote-group-subtotal-row">
              <tr><td colspan="3"><?= app_escape($issuer['total_label'] . ($group['title'] !== '' ? ' — ' . $group['title'] : '')) ?></td><td class="amount-cell"><?= app_escape(app_format_amount($options[$optionIndex]['amount'])) ?></td></tr>
            </tbody>
            <?php endforeach; ?>
          </table>

          <?php if ($remarks !== []): ?>
          <div class="quote-summary">
            <div class="quote-remarks">
              <p class="quote-label"><?= app_escape(__('remarques_label')) ?></p>
              <?php foreach ($remarks as $remark): ?>
              <p>• <?= app_escape(preg_replace('/^[•*\-\s\x{00A0}]+/u', '', $remark) ?? $remark) ?></p>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endif; ?>

          <?php if ($issuer['footer'] !== ''): ?>
          <footer class="quote-footer"><?= nl2br(app_escape($issuer['footer']), false) ?></footer>
          <?php endif; ?>
        </article>

    <?php
    app_locale($readerLocale);
}

function app_normalize_quote_share(mixed $share): ?array
{
    if (!is_array($share) || !app_is_share_token($share['token'] ?? null)) {
        return null;
    }
    $document = app_normalize_quote_document($share['document'] ?? null);
    return $document === null ? null : [
        'token' => $share['token'],
        'published_at' => is_string($share['published_at'] ?? null) ? $share['published_at'] : '',
        'published_by' => is_string($share['published_by'] ?? null) ? $share['published_by'] : '',
        'document' => $document,
    ];
}

// Published quote reached through its public link: [event id, quote], or null when the link does
// not exist or was withdrawn.
function app_quote_by_public_token(mixed $token): ?array
{
    if (!app_is_share_token($token)) {
        return null;
    }
    $logistics = app_logistics_read();
    foreach (array_keys($logistics) as $eventId) {
        foreach (app_event_logistics($logistics, (string) $eventId)['quotes'] as $quote) {
            if ($quote['share'] !== null && hash_equals($quote['share']['token'], $token)) {
                return [(string) $eventId, $quote];
            }
        }
    }
    return null;
}

function app_quote_public_url(string $token): string
{
    return preg_replace('#/roadmap\.php\?t=#', '/quote-public.php?t=', app_roadmap_share_url($token)) ?? '';
}

// Logos recorded with the published quotes (kept when the logo of the quotes is replaced).
function app_published_quote_logos(): array
{
    $logos = [];
    $logistics = app_logistics_read();
    foreach (array_keys($logistics) as $eventId) {
        foreach (app_event_logistics($logistics, (string) $eventId)['quotes'] as $quote) {
            $logo = $quote['share']['document']['issuer']['logo'] ?? null;
            if (is_string($logo)) {
                $logos[] = $logo;
            }
        }
    }
    return array_values(array_unique($logos));
}

// Workflow of the statuses of the quotes to organizers (Paramètres des devis > Flux de travail):
// draft, then published, then the answer of the organizer (declined or validated).
function app_render_quote_workflow(): void
{
    $statuses = app_quote_statuses();
    $lanes = [
        'draft' => __('devis_se_prepare_modifie_phrase'),
        'published' => __('lien_public_envoye_organisateur_devis_fige'),
        'accepted' => __('organisateur_accepte_option_retenue'),
        'declined' => __('organisateur_decline_phrase'),
    ];
    ?>
          <div class="workflow quote-workflow" role="group" aria-label="<?= app_escape(__('flux_travail_devis')) ?>">
            <ol class="workflow-lanes">
              <?php foreach (['draft', 'published'] as $type): ?>
              <li class="workflow-lane quote-lane-<?= $type ?>">
                <p class="workflow-lane-title"><?= app_escape(explode(' — ', app_label(QUOTE_STATUS_TYPES[$type]))[0]) ?></p>
                <ol class="workflow-steps">
                  <?php foreach (array_keys(array_filter($statuses, static fn (array $status): bool => $status[1] === $type)) as $key): ?>
                  <li class="workflow-step"><span class="quote-status quote-status-<?= $type ?>"><?= app_escape($statuses[$key][0]) ?></span></li>
                  <?php endforeach; ?>
                </ol>
                <p class="workflow-exit-text"><?= app_escape(str_replace(' ', "\u{00A0}", $lanes[$type])) ?></p>
              </li>
              <?php endforeach; ?>
              <li class="workflow-lane quote-lane-answer">
                <p class="workflow-lane-title"><?= app_escape(__('reponse_organisateur')) ?></p>
                <ol class="workflow-steps quote-answers">
                  <?php foreach (['accepted', 'declined'] as $type): ?>
                  <?php foreach (array_keys(array_filter($statuses, static fn (array $status): bool => $status[1] === $type)) as $key): ?>
                  <li class="workflow-step"><span class="quote-status quote-status-<?= $type ?>"><?= app_escape($statuses[$key][0]) ?></span> <span class="workflow-exit-text"><?= app_escape($lanes[$type]) ?></span></li>
                  <?php endforeach; ?>
                  <?php endforeach; ?>
                </ol>
              </li>
            </ol>
            <p class="workflow-exit-text quote-workflow-back">↩ <?= app_escape(__('retirer_lien_ramene_devis_publie_brouillon')) ?></p>
          </div>
    <?php
}

// "Rapport" tab of the Logistique page: figures of one season, for the dates of that season
// (reserved dates only when $withReserved). Returns the seasons that have dates (most recent
// first) and, for the chosen one, the counts and amounts shown by the dashboard.
function app_logistics_report(array $events, array $logistics, ?string $seasonKey, bool $withReserved): array
{
    $seasonStart = app_season_start();
    $bySeason = [];
    foreach ($events as $event) {
        $date = app_parse_date($event['date'] ?? null);
        if ($date === null || (!$withReserved && app_event_is_reserved($event))) {
            continue;
        }
        $season = app_season_of($date, $seasonStart);
        $bySeason[$season['key']] ??= ['season' => $season, 'events' => []];
        $bySeason[$season['key']]['events'][] = $event;
    }
    krsort($bySeason, SORT_NUMERIC);
    $current = app_season_of(new DateTimeImmutable('today', new DateTimeZone('Europe/Paris')), $seasonStart)['key'];
    $seasonKey = $seasonKey !== null && isset($bySeason[$seasonKey]) ? $seasonKey : (isset($bySeason[$current]) ? $current : (array_key_first($bySeason) ?? null));
    $report = ['seasons' => array_column($bySeason, 'season'), 'season' => null];
    if ($seasonKey === null) {
        return $report;
    }

    $statuses = app_logistics_statuses();
    $quoteStatuses = app_quote_statuses();
    $figures = [
        'season' => $bySeason[$seasonKey]['season'],
        'dates' => 0,
        'contract' => 0,
        'fee' => 0,
        'with_contract' => 0,
        'with_fee' => 0,
        // Costs borne by the group (contract − fee), on the dates where both are known.
        'costs' => 0,
        'with_both' => 0,
        'statuses' => array_fill_keys(array_keys($statuses), 0),
        // Dates by status of the date (the status seen by the artists, managers and organizers).
        'event_statuses' => array_fill_keys(array_keys(EVENT_STATUSES), 0),
        'quote_statuses' => array_fill_keys(array_keys($quoteStatuses), 0),
        'quotes' => 0,
        'needs' => [],
        'months' => [],
        // Dates by section: section id => count, in the order of the section tree.
        'sections' => [],
        // Dates and fees by formula (one formula per date): key ("" without formula) =>
        // [dates, fee, with_fee], in the order of the formulas.
        'formulas' => [],
    ];
    foreach ($bySeason[$seasonKey]['events'] as $event) {
        $followUp = app_event_logistics($logistics, (string) $event['id']);
        $figures['dates']++;
        $figures['statuses'][app_event_logistics_status($event, $logistics)]++;
        $eventStatus = is_string($event['status'] ?? null) && isset(EVENT_STATUSES[$event['status']]) ? $event['status'] : 'pending';
        $figures['event_statuses'][$eventStatus]++;
        $formula = $followUp['formula'] ?? '';
        $figures['formulas'][$formula] ??= ['dates' => 0, 'fee' => 0, 'with_fee' => 0];
        $figures['formulas'][$formula]['dates']++;
        if ($followUp['fee'] !== null) {
            $figures['formulas'][$formula]['fee'] += $followUp['fee'];
            $figures['formulas'][$formula]['with_fee']++;
        }
        foreach (app_logistics_report_sections($event) as $section) {
            $figures['sections'][$section] = ($figures['sections'][$section] ?? 0) + 1;
        }
        $month = substr((string) $event['date'], 0, 7);
        $figures['months'][$month] ??= ['dates' => 0, 'contract' => 0, 'fee' => 0];
        $figures['months'][$month]['dates']++;
        if ($followUp['contract'] !== null) {
            $figures['contract'] += $followUp['contract'];
            $figures['with_contract']++;
            $figures['months'][$month]['contract'] += $followUp['contract'];
        }
        if ($followUp['fee'] !== null) {
            $figures['fee'] += $followUp['fee'];
            $figures['with_fee']++;
            $figures['months'][$month]['fee'] += $followUp['fee'];
        }
        if ($followUp['costs'] !== null) {
            $figures['costs'] += $followUp['costs'];
            $figures['with_both']++;
        }
        foreach (app_logistics_quotes($followUp, 'sent') as $quote) {
            $figures['quotes']++;
            $figures['quote_statuses'][$quote['status']] = ($figures['quote_statuses'][$quote['status']] ?? 0) + 1;
        }
        foreach (app_logistics_need_blocks($followUp) as $block) {
            $retained = app_logistics_accepted_total($block['quotes']);
            if ($retained !== null) {
                $need = $block['need'] !== '' ? $block['need'] : __('autres_devis');
                $figures['needs'][$need] = ($figures['needs'][$need] ?? 0) + $retained;
            }
        }
    }
    ksort($figures['months']);
    // Every formula is listed (0 when it has no date), then the dates without formula if any.
    $figures['formulas'] = array_replace(array_fill_keys(array_keys(app_logistics_formulas()), ['dates' => 0, 'fee' => 0, 'with_fee' => 0]), $figures['formulas']);
    if (isset($figures['formulas'][''])) {
        $withoutFormula = $figures['formulas'][''];
        unset($figures['formulas']['']);
        $figures['formulas'][''] = $withoutFormula;
    }
    // Sections in the order of the tree: each branch, then its sub-sections.
    $order = [];
    foreach (app_section_tree() as $branch => $definition) {
        $order[] = $branch;
        array_push($order, ...array_keys($definition['children']));
    }
    $figures['sections'] = array_replace(array_intersect_key(array_flip($order), $figures['sections']), $figures['sections']);
    arsort($figures['needs']);
    $report['season'] = $figures;
    return $report;
}

// Sections a date counts for in the report: for each branch it concerns, the branch itself, or
// only the sub-section when a single sub-section of a branch with several is concerned.
function app_logistics_report_sections(array $event): array
{
    $leaves = app_event_leaves($event);
    $sections = [];
    foreach (app_section_tree() as $branch => $definition) {
        $children = array_keys($definition['children']);
        $concerned = array_values(array_intersect($children, $leaves));
        if ($concerned === []) {
            continue;
        }
        $sections[] = count($concerned) === 1 && count($children) > 1 ? $concerned[0] : $branch;
    }
    return $sections;
}

// Formulas of the dates ("Bagad seul", "Bagad et Cercle"…): what the group performs, chosen for
// each date in its Cachet tab; the report sums the fees by formula (one formula per date, so
// nothing is counted twice). Edited in Paramètres des devis > Formules (data/settings.json,
// key "logistics_formulas": list of [key, label]).
const LOGISTICS_FORMULA_MAX = 30;

function app_logistics_formulas(): array
{
    static $cache = null;
    if ($cache === null) {
        $cache = app_logistics_parse_formulas(read_json_file(app_data_path('settings.json'))['logistics_formulas'] ?? null) ?? [];
    }
    return $cache;
}

// Formulas as key => label, or null when the stored list is invalid.
function app_logistics_parse_formulas(mixed $stored): ?array
{
    if (!is_array($stored) || count($stored) > LOGISTICS_FORMULA_MAX) {
        return null;
    }
    $formulas = [];
    foreach ($stored as $item) {
        if (!is_array($item) || !is_string($item['key'] ?? null) || preg_match('/^[a-z0-9_-]{1,40}$/', $item['key']) !== 1
            || !is_string($item['label'] ?? null) || $item['label'] === '' || isset($formulas[$item['key']])) {
            return null;
        }
        $formulas[$item['key']] = $item['label'];
    }
    return $formulas;
}

// Documents annexes of a date, as stored or as posted (rows of label and address); a row without a
// valid http(s) address is dropped, a row without label takes the address as label.
function app_logistics_documents(mixed $rows): array
{
    $documents = [];
    foreach (is_array($rows) ? $rows : [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $url = app_clean_line($row['url'] ?? '', 2000);
        if ($url === '' || !app_is_http_url($url)) {
            continue;
        }
        $label = app_clean_line($row['label'] ?? '', LOGISTICS_DOCUMENT_LABEL_MAX);
        $documents[] = ['label' => $label !== '' ? $label : $url, 'url' => $url];
    }
    return array_slice($documents, 0, LOGISTICS_DOCUMENTS_MAX);
}
