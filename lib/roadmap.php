<?php
declare(strict_types=1);

// Roadmap ("feuille de route") of a date: options, application settings,
// uploaded QR code images and rendering.

const ROADMAP_TRANSPORT = [
    'personal' => 'Transport perso / covoit',
    'bus' => 'Bus',
];
// Meal choices of earlier versions, converted to the free-text "Repas" field.
const ROADMAP_LEGACY_MEALS = [
    'local_lunch' => 'Midi (local KP)',
    'not_included' => 'Non inclus',
];
const ROADMAP_OUTFITS = [
    'civil' => 'Civil',
    'costume' => 'Costume',
];
// Sections with a repertoire before it was chosen in Configuration > Sections (their lists were
// keyed by these section identifiers).
const REPERTOIRE_DEFAULT_SECTIONS = ['bagad', 'bagadig'];
const REPERTOIRE_MAX_PIECES = 100;
// Documents of a roadmap (feuille de route de l'organisateur, plan d'accès…): a link, or a PDF or
// image file stored in data/uploads under its own names ("doc-…"), served by roadmap-document.php.
const ROADMAP_DOCUMENTS_MAX = 20;
const ROADMAP_DOCUMENT_LABEL_MAX = 120;
const ROADMAP_DOCUMENT_MAX_BYTES = 10 * 1024 * 1024;
const ROADMAP_DOCUMENT_TYPES = ['pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp'];
const ROADMAP_MAX_ROWS = 30;
const ROADMAP_MAX_UPLOAD_BYTES = 2 * 1024 * 1024;
const ROADMAP_UPLOAD_TYPES = [
    IMAGETYPE_PNG => ['png', 'image/png'],
    IMAGETYPE_JPEG => ['jpg', 'image/jpeg'],
    IMAGETYPE_GIF => ['gif', 'image/gif'],
    IMAGETYPE_WEBP => ['webp', 'image/webp'],
];

function app_default_settings(): array
{
    return [
        // No QR code until one is set in Configuration > Feuille de route (e.g. the group's chat).
        'qr' => [
            'caption' => '',
            'link' => '',
            'image' => null,
        ],
        'default_contact' => [
            'label' => '',
            'details' => '',
        ],
        'repertoires' => [
            'bagad' => [
                'Suite Bagad Saint-Brieuc 2026',
                'Andro Scénique 2026',
                'Pourlet Scénique 2026',
                'Pourlet 2001 (défilé)',
                'Tour',
                'Marche + Mélo. Lorient ’25',
            ],
            'bagadig' => [
                'Suite gavotte 2026',
                'Marche des conscrits',
                'Premier rond de Loudéac',
            ],
        ],
    ];
}

// Truncates to a number of characters without needing the mbstring extension.
function app_truncate(string $value, int $maxLength): string
{
    return preg_match('/^.{0,' . $maxLength . '}/us', $value, $match) === 1 ? $match[0] : '';
}

function app_clean_line(mixed $value, int $maxLength): string
{
    if (!is_string($value) || preg_match('//u', $value) !== 1) {
        return '';
    }
    return app_truncate(trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? ''), $maxLength);
}

function app_clean_text(mixed $value, int $maxLength): string
{
    if (!is_string($value) || preg_match('//u', $value) !== 1) {
        return '';
    }
    $value = str_replace(["\r\n", "\r"], "\n", $value);
    return trim(app_truncate(trim(preg_replace('/[\x00-\x09\x0B-\x1F\x7F]+/u', ' ', $value) ?? ''), $maxLength));
}

function app_clean_lines(mixed $value, int $maxLines, int $maxLength): array
{
    $lines = is_array($value) ? $value : explode("\n", app_clean_text($value, $maxLines * ($maxLength + 1)));
    $cleaned = [];
    foreach ($lines as $line) {
        $line = app_clean_line($line, $maxLength);
        if ($line !== '' && !in_array($line, $cleaned, true)) {
            $cleaned[] = $line;
        }
    }
    return array_slice($cleaned, 0, $maxLines);
}

function app_clean_choices(mixed $value, array $allowed): array
{
    return is_array($value)
        ? array_values(array_intersect(array_keys($allowed), array_filter($value, 'is_string')))
        : [];
}

function app_is_http_url(string $url): bool
{
    return filter_var($url, FILTER_VALIDATE_URL) !== false
        && in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true);
}

function app_clean_url(mixed $value): string
{
    $url = app_clean_line($value, 2000);
    return $url !== '' && app_is_http_url($url) ? $url : '';
}

function app_clean_rows(mixed $rows, array $fields, string $requiredField): array
{
    $cleaned = [];
    foreach (is_array($rows) ? $rows : [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $item = [];
        foreach ($fields as $field => $maxLength) {
            $item[$field] = $field === 'details' ? app_clean_text($row[$field] ?? '', $maxLength) : app_clean_line($row[$field] ?? '', $maxLength);
        }
        if ($item[$requiredField] !== '') {
            $cleaned[] = $item;
        }
    }
    return array_slice($cleaned, 0, ROADMAP_MAX_ROWS);
}

// Free text, one item per line. Rows stored by earlier versions are converted to lines.
function app_rows_to_text(mixed $value, callable $formatRow): string
{
    if (is_array($value)) {
        $lines = [];
        foreach ($value as $row) {
            $line = is_array($row) ? trim($formatRow(array_map(static fn (mixed $cell): string => is_string($cell) ? trim($cell) : '', $row))) : '';
            if ($line !== '') {
                $lines[] = $line;
            }
        }
        $value = implode("\n", $lines);
    }
    return app_clean_text($value, 3000);
}

function app_text_lines(string $text): array
{
    return array_values(array_filter(array_map('trim', explode("\n", $text)), static fn (string $line): bool => $line !== ''));
}

function app_normalize_qr(mixed $qr): array
{
    $qr = is_array($qr) ? $qr : [];
    $image = $qr['image'] ?? null;
    return [
        'caption' => app_clean_line($qr['caption'] ?? '', 80),
        'link' => app_clean_url($qr['link'] ?? ''),
        'image' => is_string($image) && app_upload_path($image) !== null ? $image : null,
    ];
}

function app_settings(): array
{
    $defaults = app_default_settings();
    $stored = read_json_file(app_data_path('settings.json'));
    $qr = app_normalize_qr($stored['qr'] ?? $defaults['qr']);
    $repertoires = [];
    foreach (app_repertoire_sections() as $key => $label) {
        $repertoires[$key] = isset($stored['repertoires'][$key])
            ? app_clean_lines($stored['repertoires'][$key], REPERTOIRE_MAX_PIECES, 120)
            : $defaults['repertoires'][$key] ?? [];
    }
    $contact = is_array($stored['default_contact'] ?? null) ? $stored['default_contact'] : $defaults['default_contact'];
    return [
        'qr' => $qr,
        'default_contact' => [
            'label' => app_clean_line($contact['label'] ?? '', 80),
            'details' => app_clean_text($contact['details'] ?? '', 600),
        ],
        'repertoires' => $repertoires,
    ];
}

// Sections (branches or sub-sections) that have a repertoire, checked in Configuration > Sections
// (data/settings.json, key "repertoire_sections"): identifier => name, in the order of the sections.
function app_repertoire_sections(): array
{
    $stored = read_json_file(app_data_path('settings.json'))['repertoire_sections'] ?? null;
    $chosen = is_array($stored) ? array_filter($stored, 'is_string') : REPERTOIRE_DEFAULT_SECTIONS;
    $sections = [];
    foreach (app_section_tree() as $branch => $definition) {
        foreach ([$branch => $definition['label']] + $definition['children'] as $id => $label) {
            if (in_array($id, $chosen, true)) {
                $sections[$id] = $label;
            }
        }
    }
    return $sections;
}

// Repertoires of a date with the sub-sections $leaves: a branch's repertoire when one of its
// sub-sections takes part, a sub-section's repertoire when it takes part. Identifier => name.
function app_event_repertoire_sections(array $leaves): array
{
    $tree = app_section_tree();
    return array_filter(app_repertoire_sections(), static fn (string $id): bool => isset($tree[$id])
        ? array_intersect(array_keys($tree[$id]['children']), $leaves) !== []
        : in_array($id, $leaves, true), ARRAY_FILTER_USE_KEY);
}

// Sections (identifiers) whose checkbox in the date form makes the repertoire of $id appear: the
// section itself, its sub-sections for a branch, its branch for a sub-section.
function app_repertoire_triggers(string $id): array
{
    $tree = app_section_tree();
    if (isset($tree[$id])) {
        return [$id, ...array_keys($tree[$id]['children'])];
    }
    return array_values(array_filter([$id, app_section_branch($id)]));
}

// Normalizes a roadmap, either as stored in events.json or as posted by the date form. The
// repertoire is keyed by section identifier (see app_repertoire_sections()).
function app_normalize_roadmap(mixed $raw): array
{
    $raw = is_array($raw) ? $raw : [];
    $repertoire = [];
    $keys = array_unique(array_merge(
        is_array($raw['repertoire'] ?? null) ? array_keys($raw['repertoire']) : [],
        is_array($raw['repertoire_extra'] ?? null) ? array_keys($raw['repertoire_extra']) : []
    ));
    foreach ($keys as $key) {
        if (!is_string($key) || preg_match(SECTION_ID_PATTERN, $key) !== 1) {
            continue;
        }
        $pieces = app_clean_lines($raw['repertoire'][$key] ?? [], REPERTOIRE_MAX_PIECES, 120);
        $extra = app_clean_lines($raw['repertoire_extra'][$key] ?? '', 50, 120);
        $merged = array_values(array_unique(array_merge($pieces, $extra)));
        if ($merged !== []) {
            $repertoire[$key] = $merged;
        }
    }
    return [
        'location_note' => app_clean_line($raw['location_note'] ?? '', 300),
        'location_url' => app_clean_url($raw['location_url'] ?? ''),
        'contacts' => app_clean_rows($raw['contacts'] ?? [], ['label' => 80, 'details' => 600], 'details'),
        'transport' => app_clean_choices($raw['transport'] ?? [], ROADMAP_TRANSPORT),
        'meals' => is_array($raw['meals'] ?? null)
            ? implode(', ', array_map(static fn (string $choice): string => ROADMAP_LEGACY_MEALS[$choice], app_clean_choices($raw['meals'], ROADMAP_LEGACY_MEALS)))
            : app_clean_text($raw['meals'] ?? '', 500),
        'outfit' => app_clean_choices($raw['outfit'] ?? [], ROADMAP_OUTFITS),
        'repertoire' => $repertoire,
        'schedule' => app_rows_to_text(
            $raw['schedule'] ?? '',
            static fn (array $row): string => ($row['label'] ?? '') === '' ? '' : (($row['time'] ?? '') !== '' ? $row['time'] . ' : ' : '') . $row['label']
        ),
        // "Note des Penn" replaces the former running order, whose content it takes over.
        'penn_notes' => app_rows_to_text(
            $raw['penn_notes'] ?? $raw['running_order'] ?? '',
            static fn (array $row): string => ($row['label'] ?? '') === '' ? '' : implode(' ', array_filter([$row['start'] ?? '', $row['duration'] ?? '', $row['label']], static fn (string $cell): bool => $cell !== ''))
        ),
        'notes' => app_clean_text($raw['notes'] ?? '', 3000),
        'qr' => app_normalize_qr($raw['qr'] ?? []),
        'documents' => app_roadmap_documents($raw['documents'] ?? null),
    ];
}

function app_event_roadmap(array $event): array
{
    return app_normalize_roadmap($event['roadmap'] ?? []);
}

function app_roadmap_is_empty(array $roadmap): bool
{
    foreach ($roadmap as $key => $value) {
        if ($key !== 'qr' && $value !== [] && $value !== '') {
            return false;
        }
    }
    return true;
}

function app_upload_directory(): string
{
    return dirname(__DIR__) . '/data/uploads';
}

function app_upload_path(string $name): ?string
{
    return preg_match('/^(qr|logo|annonce)-[0-9a-f]{32}\.(png|jpg|gif|webp)$/', $name) === 1
        ? app_upload_directory() . '/' . $name
        : null;
}

// Validates and stores an uploaded image. Returns its file name, null when no file
// was sent, or throws InvalidArgumentException with a message for the user.
function app_store_uploaded_image(mixed $file, string $prefix = 'qr'): ?string
{
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if (($file['error'] ?? null) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) {
        throw new InvalidArgumentException(__('lenvoi_limage_echoue_phrase'));
    }
    if (($file['size'] ?? 0) > ROADMAP_MAX_UPLOAD_BYTES) {
        throw new InvalidArgumentException(__('limage_doit_depasser_2_mo_phrase'));
    }
    $info = getimagesize($file['tmp_name']);
    if ($info === false || !isset(ROADMAP_UPLOAD_TYPES[$info[2]])) {
        throw new InvalidArgumentException(__('limage_doit_etre_format_png_jpeg'));
    }
    $directory = app_upload_directory();
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException(__('impossible_creer_dossier_images_phrase'));
    }
    $name = $prefix . '-' . bin2hex(random_bytes(16)) . '.' . ROADMAP_UPLOAD_TYPES[$info[2]][0];
    $path = $directory . '/' . $name;
    if (!move_uploaded_file($file['tmp_name'], $path) || !chmod($path, 0600)) {
        throw new RuntimeException('Impossible d’enregistrer l’image.');
    }
    return $name;
}

function app_delete_upload(?string $name): void
{
    $path = $name !== null ? app_upload_path($name) : null;
    if ($path !== null && is_file($path)) {
        unlink($path);
    }
}

// Address of an uploaded QR code image, or null when there is none.
function app_upload_url(?string $name): ?string
{
    return $name !== null ? 'qr-image.php?file=' . rawurlencode($name) : null;
}

// Effective QR code of a roadmap: the date's own values, falling back on the settings.
// On the public page, uploaded images are served against the share token instead of a session.
function app_roadmap_qr(array $roadmap, array $settings, ?string $shareToken = null): array
{
    $image = $roadmap['qr']['image'] ?? $settings['qr']['image'];
    $imageUrl = app_upload_url($image);
    if ($imageUrl !== null && $shareToken !== null) {
        $imageUrl .= '&share=' . rawurlencode($shareToken);
    }
    return [
        'caption' => $roadmap['qr']['caption'] !== '' ? $roadmap['qr']['caption'] : $settings['qr']['caption'],
        'link' => $roadmap['qr']['link'] !== '' ? $roadmap['qr']['link'] : $settings['qr']['link'],
        'image_url' => $imageUrl,
        'image' => $image,
        'custom' => $roadmap['qr']['image'] !== null || $roadmap['qr']['link'] !== '' || $roadmap['qr']['caption'] !== '',
    ];
}

// Escapes text and turns phone numbers and e-mail addresses into links.
function app_linkify(string $text): string
{
    $pattern = '/([A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,})|((?:\+\d{2,3}|0)\d?(?:[ .]?\d{2,3}){3,5})/i';
    $html = '';
    $offset = 0;
    if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) > 0) {
        foreach ($matches as $match) {
            [$value, $position] = $match[0];
            $html .= app_escape(substr($text, $offset, $position - $offset));
            $href = ($match[1][0] ?? '') !== '' ? 'mailto:' . $value : 'tel:' . preg_replace('/[^\d+]/', '', $value);
            $html .= '<a href="' . app_escape($href) . '">' . app_escape($value) . '</a>';
            $offset = $position + strlen($value);
        }
    }
    return $html . app_escape(substr($text, $offset));
}

function app_render_checklist(array $options, array $checked): void
{
    ?>
              <ul class="roadmap-checklist">
                <?php foreach ($options as $value => $label): ?>
                <?php $isChecked = in_array($value, $checked, true); ?>
                <li class="<?= $isChecked ? 'is-checked' : '' ?>"><span class="roadmap-box" aria-hidden="true"></span><?= app_escape($label) ?><span class="sr-only"><?= app_escape($isChecked ? __('suffixe_oui') : __('suffixe_non')) ?></span></li>
                <?php endforeach; ?>
              </ul>
<?php
}

// Public share link of a roadmap: an unguessable token stored on the date.
function app_is_share_token(mixed $token): bool
{
    return is_string($token) && preg_match('/^[0-9a-f]{48}$/', $token) === 1;
}

function app_roadmap_share_token(array $event): ?string
{
    $token = $event['roadmap_share_token'] ?? null;
    return app_is_share_token($token) ? $token : null;
}

// A date can be marked "roadmap not available": its tab, agenda link and public link are then hidden.
function app_roadmap_available(array $event): bool
{
    return ($event['roadmap_disabled'] ?? false) !== true;
}

function app_event_by_share_token(string $token): ?array
{
    if (!app_is_share_token($token)) {
        return null;
    }
    foreach (read_json_file(app_data_path('events.json')) as $event) {
        $eventToken = is_array($event) ? app_roadmap_share_token($event) : null;
        if ($eventToken !== null && hash_equals($eventToken, $token)) {
            // The token is kept, so the link works again if the roadmap is made available again.
            return app_roadmap_available($event) ? $event : null;
        }
    }
    return null;
}

// Full address of a page of the site ("event.php?id=…"), for links sent outside it (iCal, shares).
function app_absolute_url(string $path): string
{
    $isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off';
    $host = is_string($_SERVER['HTTP_HOST'] ?? null) ? $_SERVER['HTTP_HOST'] : 'localhost';
    $directory = rtrim(str_replace('\\', '/', dirname(is_string($_SERVER['SCRIPT_NAME'] ?? null) ? $_SERVER['SCRIPT_NAME'] : '/')), '/');
    return ($isHttps ? 'https' : 'http') . '://' . $host . $directory . '/' . $path;
}

function app_roadmap_share_url(string $token): string
{
    return app_absolute_url('roadmap.php?t=' . $token);
}

// Everything shown on the roadmap of a date: its own content and the date fields it displays.
// When this changes on save, the "last update" date of the roadmap is renewed.
function app_roadmap_fingerprint(array $event): string
{
    $fields = [];
    foreach (['date', 'end_date', 'title', 'location', 'time', 'end_time', 'all_day', 'sections'] as $field) {
        $fields[$field] = $event[$field] ?? null;
    }
    $fields['roadmap'] = app_event_roadmap($event);
    return hash('sha256', json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
}

function app_render_roadmap(array $event, array $roadmap, array $settings, ?string $shareToken = null): void
{
    $format = static fn (DateTimeImmutable $date): string => app_long_date($date, true, false);
    $date = app_parse_date($event['date'] ?? null);
    $endDate = app_parse_date($event['end_date'] ?? null);
    $qr = app_roadmap_qr($roadmap, $settings, $shareToken);
    $location = is_string($event['location'] ?? null) ? $event['location'] : '';
    $notes = $roadmap['notes'] !== '' ? explode("\n", $roadmap['notes']) : [];
    $schedule = app_text_lines($roadmap['schedule']);
    $pennNotes = app_text_lines($roadmap['penn_notes']);
    // "Type sortie": the parent sections (branches) with at least one sub-section concerned by the date.
    $eventLeaves = app_expand_sections(is_array($event['sections'] ?? null) ? array_filter($event['sections'], 'is_string') : []);
    $branchLabels = [];
    $concernedBranches = [];
    foreach (app_section_tree() as $branch => $definition) {
        $branchLabels[$branch] = $definition['label'];
        if (array_intersect(array_keys($definition['children']), $eventLeaves) !== []) {
            $concernedBranches[] = $branch;
        }
    }
    // "Répertoire": one box per repertoire of the sections of the date that has pieces.
    $repertoireBoxes = array_filter(
        array_intersect_key(app_event_repertoire_sections($eventLeaves), $roadmap['repertoire']),
        static fn (string $id): bool => $roadmap['repertoire'][$id] !== [],
        ARRAY_FILTER_USE_KEY
    );
    $updatedAt = null;
    if (is_string($event['roadmap_updated_at'] ?? null)) {
        try {
            $updatedAt = (new DateTimeImmutable($event['roadmap_updated_at']))->setTimezone(new DateTimeZone('Europe/Paris'));
        } catch (Exception) {
            $updatedAt = null;
        }
    }
    ?>
        <?php
            // Default name of the PDF when printing: "YYYYMMDD-Title" (browsers use the page title).
            $printTitle = ($date !== null ? $date->format('Ymd') . '-' : '') . trim(preg_replace('/\s+/', ' ', preg_replace('#[\\\\/:*?"<>|]+#', ' ', (string) ($event['title'] ?? ''))));
        ?>
        <article class="roadmap" data-print-title="<?= app_escape($printTitle) ?>">
          <p class="roadmap-updated">
            <?php if ($updatedAt !== null): ?>
            <?= __('informations_mises_jour_date', ['date' => '<strong>' . app_escape(__('date_time', ['date' => app_long_date($updatedAt, true, true, false), 'time' => app_time($updatedAt)])) . '</strong>']) ?>
            <?php else: ?>
            <?= app_escape(__('date_derniere_mise_jour_inconnue_feuille')) ?>
            <?php endif; ?>
          </p>
          <header class="roadmap-header">
            <img class="roadmap-logo" src="assets/kerlenn-pondi-logo.png" alt="Kerlenn Pondi" width="512" height="145">
            <div class="roadmap-types">
              <h3><?= app_escape(__('type_sortie')) ?></h3>
              <?php app_render_checklist($branchLabels, $concernedBranches); ?>
            </div>
          </header>

          <h2 class="roadmap-title">
            <?php if ($date !== null): ?><span class="roadmap-date"><?= app_escape($format($date)) ?><?= $endDate !== null && $endDate > $date ? ' → ' . app_escape($format($endDate)) : '' ?></span><?php endif; ?>
            <span class="roadmap-name"><?= app_escape((string) ($event['title'] ?? '')) ?></span>
          </h2>

          <section class="roadmap-block">
            <h3><?= app_escape(__('contacts')) ?></h3>
            <div class="roadmap-contacts">
              <table>
                <tbody>
                  <?php foreach ($roadmap['contacts'] as $contact): ?>
                  <tr>
                    <th scope="row"><?= app_escape($contact['label']) ?></th>
                    <td>
                      <?php foreach (explode("\n", $contact['details']) as $line): ?>
                      <?php $line = trim($line); ?>
                      <?php if ($line === ''): ?><span class="roadmap-gap"></span><?php elseif (str_ends_with($line, ':')): ?><strong><?= app_escape($line) ?></strong><br><?php else: ?><?= app_linkify($line) ?><br><?php endif; ?>
                      <?php endforeach; ?>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                  <?php if ($roadmap['contacts'] === []): ?>
                  <tr><td class="roadmap-empty"><?= app_escape(__('aucun_contact_renseigne_phrase')) ?></td></tr>
                  <?php endif; ?>
                </tbody>
              </table>
              <?php // QR code: only when an image or a link is set. ?>
              <?php if ($qr['image_url'] !== null || $qr['link'] !== ''): ?>
              <div class="roadmap-qr">
                <?php if ($qr['link'] !== ''): ?>
                <a href="<?= app_escape($qr['link']) ?>" target="_blank" rel="noopener noreferrer"><?= app_escape($qr['caption'] !== '' ? $qr['caption'] : $qr['link']) ?></a>
                <?php else: ?>
                <span><?= app_escape($qr['caption']) ?></span>
                <?php endif; ?>
                <?php if ($qr['image_url'] !== null): ?>
                <img src="<?= app_escape($qr['image_url']) ?>" alt="<?= app_escape(__('qr_code_caption', ['caption' => $qr['caption']])) ?>" width="150" height="150">
                <?php endif; ?>
              </div>
              <?php endif; ?>
            </div>
          </section>

          <?php if ($location !== '' || $roadmap['location_note'] !== ''): ?>
          <section class="roadmap-block">
            <h3><?= app_escape(__('lieu_levenement')) ?></h3>
            <p class="roadmap-location">
              <?php if ($roadmap['location_url'] !== '' && $location !== ''): ?>
              <a href="<?= app_escape($roadmap['location_url']) ?>" target="_blank" rel="noopener noreferrer"><?= app_escape($location) ?></a>
              <?php else: ?>
              <?= app_escape($location) ?>
              <?php endif; ?>
              <?php if ($roadmap['location_note'] !== ''): ?><span>(<?= app_escape($roadmap['location_note']) ?>)</span><?php endif; ?>
            </p>
          </section>
          <?php endif; ?>

          <section class="roadmap-block">
            <h3><?= app_escape(__('informations_generales')) ?></h3>
            <div class="roadmap-grid roadmap-general">
              <div><h4><?= app_escape(__('transport')) ?></h4><?php app_render_checklist(app_translated(ROADMAP_TRANSPORT), $roadmap['transport']); ?></div>
              <div>
                <h4><?= app_escape(__('repas')) ?></h4>
                <?php if ($roadmap['meals'] === ''): ?>
                <p class="roadmap-text roadmap-empty"><?= app_escape(__('non_precise')) ?></p>
                <?php else: ?>
                <p class="roadmap-text"><?= implode('<br>', array_map('app_linkify', app_text_lines($roadmap['meals']))) ?></p>
                <?php endif; ?>
              </div>
              <div><h4><?= app_escape(__('tenue')) ?></h4><?php app_render_checklist(app_translated(ROADMAP_OUTFITS), $roadmap['outfit']); ?></div>
            </div>
          </section>

          <?php if ($repertoireBoxes !== []): ?>
          <section class="roadmap-block">
            <h3><?= app_escape(__('repertoire')) ?></h3>
            <div class="roadmap-grid roadmap-repertoires">
              <?php foreach ($repertoireBoxes as $key => $label): ?>
              <div>
                <h4><?= app_escape($label) ?></h4>
                <?php app_render_checklist(array_combine($roadmap['repertoire'][$key], $roadmap['repertoire'][$key]), $roadmap['repertoire'][$key]); ?>
              </div>
              <?php endforeach; ?>
            </div>
          </section>
          <?php endif; ?>

          <?php if ($roadmap['documents'] !== []): ?>
          <section class="roadmap-block">
            <h3><?= app_escape(__('documents')) ?></h3>
            <ul class="roadmap-documents">
              <?php foreach ($roadmap['documents'] as $document): ?>
              <li>
                <a href="<?= app_escape(app_roadmap_document_url($event, $document, $shareToken)) ?>" target="_blank" rel="noopener"><?= app_escape($document['label']) ?></a>
                <span class="roadmap-document-kind"><?= $document['file'] !== null ? app_escape(strtoupper(pathinfo($document['file'], PATHINFO_EXTENSION))) : app_escape((string) parse_url($document['url'], PHP_URL_HOST)) ?></span>
              </li>
              <?php endforeach; ?>
            </ul>
          </section>
          <?php endif; ?>

          <?php if ($schedule !== [] || $pennNotes !== [] || $notes !== []): ?>
          <section class="roadmap-block">
            <h3><?= app_escape(__('deroule_journee_prestation')) ?></h3>
            <div class="roadmap-program">
              <div>
                <?php if ($schedule !== []): ?>
                <ul class="roadmap-schedule">
                  <?php foreach ($schedule as $step): ?>
                  <?php if (preg_match('/^(\d{1,2}\s?[h:]\s?\d{0,2})\s*(?:[:\-–]\s*|\s+)(.+)$/u', $step, $match) === 1): ?>
                  <li><strong><?= app_escape($match[1]) ?></strong> : <?= app_linkify($match[2]) ?></li>
                  <?php else: ?>
                  <li><?= app_linkify($step) ?></li>
                  <?php endif; ?>
                  <?php endforeach; ?>
                </ul>
                <?php endif; ?>
                <?php if ($notes !== []): ?>
                <h4><?= app_escape(__('note_label')) ?></h4>
                <ul class="roadmap-notes">
                  <?php foreach ($notes as $note): ?>
                  <?php if (trim($note) !== ''): ?><li><?= app_linkify(trim($note)) ?></li><?php endif; ?>
                  <?php endforeach; ?>
                </ul>
                <?php endif; ?>
              </div>
              <?php if ($pennNotes !== []): ?>
              <div>
                <h4><?= app_escape(__('note_penn_label')) ?></h4>
                <ul class="roadmap-notes">
                  <?php foreach ($pennNotes as $pennNote): ?>
                  <li><?= app_linkify($pennNote) ?></li>
                  <?php endforeach; ?>
                </ul>
              </div>
              <?php endif; ?>
            </div>
          </section>
          <?php endif; ?>
        </article>
<?php
}

function app_roadmap_document_path(string $name): ?string
{
    return preg_match('/^doc-[0-9a-f]{32}\.(pdf|png|jpg|webp)$/', $name) === 1 ? app_upload_directory() . '/' . $name : null;
}

// Documents of a roadmap as stored: [['label' => …, 'url' => http(s) address or '', 'file' => stored
// file name or null], …], each with a link or a file.
function app_roadmap_documents(mixed $rows): array
{
    $documents = [];
    foreach (is_array($rows) ? $rows : [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $file = is_string($row['file'] ?? null) && app_roadmap_document_path($row['file']) !== null ? $row['file'] : null;
        $url = $file === null ? app_clean_url($row['url'] ?? '') : '';
        if ($file === null && $url === '') {
            continue;
        }
        $label = app_clean_line($row['label'] ?? '', ROADMAP_DOCUMENT_LABEL_MAX);
        $documents[] = ['label' => $label !== '' ? $label : ($file !== null ? __('document') : $url), 'url' => $url, 'file' => $file];
    }
    return array_slice($documents, 0, ROADMAP_DOCUMENTS_MAX);
}

// Stores an uploaded document (PDF, PNG, JPEG or WebP, checked from its content). Returns its file
// name, null when no file was sent, or throws InvalidArgumentException with a message for the user.
function app_store_roadmap_document(mixed $file): ?string
{
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if (in_array($file['error'] ?? null, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || ($file['size'] ?? 0) > ROADMAP_DOCUMENT_MAX_BYTES) {
        throw new InvalidArgumentException(__('document_doit_depasser_10_mo_phrase'));
    }
    if (($file['error'] ?? null) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) {
        throw new InvalidArgumentException(__('lenvoi_dun_document_echoue_phrase'));
    }
    $head = (string) file_get_contents($file['tmp_name'], false, null, 0, 5);
    $info = @getimagesize($file['tmp_name']);
    $extension = match (true) {
        $head === '%PDF-' => 'pdf',
        $info !== false && $info[2] === IMAGETYPE_PNG => 'png',
        $info !== false && $info[2] === IMAGETYPE_JPEG => 'jpg',
        $info !== false && $info[2] === IMAGETYPE_WEBP => 'webp',
        default => null,
    };
    if ($extension === null) {
        throw new InvalidArgumentException(__('document_doit_etre_pdf_image_png'));
    }
    $directory = app_upload_directory();
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException(__('impossible_creer_dossier_documents_phrase'));
    }
    $name = 'doc-' . bin2hex(random_bytes(16)) . '.' . $extension;
    $path = $directory . '/' . $name;
    if (!move_uploaded_file($file['tmp_name'], $path) || !chmod($path, 0600)) {
        throw new RuntimeException(__('impossible_denregistrer_document_phrase'));
    }
    return $name;
}

function app_delete_roadmap_document(?string $name): void
{
    $path = $name !== null ? app_roadmap_document_path($name) : null;
    if ($path !== null && is_file($path)) {
        unlink($path);
    }
}

// Address of a document: its link, or roadmap-document.php (with the share token on a public roadmap).
function app_roadmap_document_url(array $event, array $document, ?string $shareToken = null): string
{
    if ($document['file'] === null) {
        return $document['url'];
    }
    return 'roadmap-document.php?' . http_build_query(['event' => (string) ($event['id'] ?? ''), 'f' => $document['file']] + ($shareToken !== null ? ['share' => $shareToken] : []));
}
