<?php
declare(strict_types=1);

// Private application: search engines must not index any page (see also robots.txt and .htaccess).
header('X-Robots-Tag: noindex, nofollow, noarchive');

$isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off';
// Local development only: PHP's built-in server reached from this computer (VS Code task,
// http://localhost:8000). The site may then be shown in VS Code's Simple Browser, which displays
// it in a frame: no anti-framing header and a session cookie sent inside that frame. Never true
// behind Apache or another web server, so production keeps both protections.
define('APP_LOCAL_EMBED', PHP_SAPI === 'cli-server'
    && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true));
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_name('shared_calendar_session');
session_set_cookie_params([
    'httponly' => true,
    // Chrome-based browsers accept a "Secure" cookie on http://localhost.
    'secure' => $isHttps || APP_LOCAL_EMBED,
    'samesite' => APP_LOCAL_EMBED ? 'None' : 'Strict',
    'path' => '/',
]);
session_start();

const USER_ROLES = [
    'musician' => 'Artistes',
    'manager' => 'Manager',
    'organizer' => 'Organisateur',
    'logistician' => 'Logisticien',
    'admin' => 'Administrateur',
];
// Roles that create and edit dates and enter attendance. Managers within their management scope;
// organizers, logisticians and administrators on every section. Only administrators manage the
// application itself (Configuration, accounts, history, deletion of dates, impersonation).
const EVENT_MANAGER_ROLES = ['manager', 'organizer', 'logistician', 'admin'];
// Roles that see and edit every date and section, without a management scope.
const ALL_SECTIONS_ROLES = ['organizer', 'logistician', 'admin'];
// Roles that see the history of entries and manage accounts. Organizers and logisticians only
// create accounts and edit artists and managers (role, sections, scope); everything else stays
// with administrators.
const ACCOUNT_MANAGER_ROLES = ['organizer', 'logistician', 'admin'];
// Roles that manage the administrative follow-up of the dates: fee, quotes sent to the
// organizers of the events and quotes received from carriers, admin note.
const LOGISTICS_ROLES = ['logistician', 'admin'];

function app_can_manage_logistics(array $user): bool
{
    return in_array($user['role'] ?? null, LOGISTICS_ROLES, true);
}

// The admin note of the dates (internal follow-up) is seen and edited by organizers,
// logisticians and administrators.
function app_can_see_admin_note(array $user): bool
{
    return in_array($user['role'] ?? null, ALL_SECTIONS_ROLES, true);
}

function app_can_view_history(array $user): bool
{
    return in_array($user['role'] ?? null, ACCOUNT_MANAGER_ROLES, true);
}

// Roles $actor may give to an account.
function app_assignable_roles(array $actor): array
{
    return ($actor['role'] ?? null) === 'admin' ? array_keys(USER_ROLES) : ['musician', 'manager'];
}

// Whether $actor may edit $account: administrators edit every account, organizers and logisticians
// artists and managers.
function app_can_edit_account(array $actor, array $account): bool
{
    return ($actor['role'] ?? null) === 'admin'
        || (in_array($actor['role'] ?? null, ['organizer', 'logistician'], true) && in_array($account['role'] ?? null, ['musician', 'manager'], true));
}

// Whether $account (an artist or a manager, or the manager themselves) belongs to the management
// scope of the manager $manager: at least one of its sub-sections is managed by them.
function app_manager_scope_includes(array $manager, array $account): bool
{
    if (($manager['role'] ?? null) !== 'manager') {
        return false;
    }
    if ((string) ($manager['id'] ?? '') === (string) ($account['id'] ?? '')) {
        return true;
    }
    return in_array($account['role'] ?? null, ['musician', 'manager'], true)
        && array_intersect(app_expand_sections(app_user_sections($account)), app_user_managed_leaves($manager)) !== [];
}

// Whether $actor may change the login of $account (user.php): administrators every account,
// organizers and logisticians the accounts they edit, managers the members of their scope.
function app_can_rename_account(array $actor, array $account): bool
{
    return app_can_edit_account($actor, $account) || app_manager_scope_includes($actor, $account);
}

const EVENT_STATUSES = [
    // Prospective request, tracked before the date is submitted for validation.
    'lead' => 'Lead',
    'pending' => 'À valider',
    'confirmed' => 'Confirmé',
    'completed' => 'Terminé',
    'cancelled' => 'Annulé',
    // Request turned down: hidden from artists in the agenda, listed in the advanced views only.
    'refused' => 'Refusé',
    'information' => 'Information',
];
// Statuses of the dates whose attendance can no longer be given (agenda, detail page, attendance tables).
const ATTENDANCE_LOCKED_STATUSES = ['completed', 'cancelled', 'refused'];

function app_event_attendance_locked(array $event): bool
{
    return in_array($event['status'] ?? null, ATTENDANCE_LOCKED_STATUSES, true);
}

// Statuses of the dates artists do not see in the agenda (and its iCal feed).
const AGENDA_HIDDEN_STATUSES_FOR_ARTISTS = ['refused'];

// Statuses of the dates not listed on the home page ("Prochaines dates"); still in the full agenda,
// the advanced views and the iCal feed.
const HOME_HIDDEN_STATUSES = ['refused'];

// Colours of each status as installed, same values as the --status-* variables of styles.css:
// button (badge) background and text, accent of cards, dots and columns, planning cells.
// A colour chosen in Configuration > Statuts replaces the button background; the other colours
// are then derived from it (see app_status_palette()).
const STATUS_DEFAULT_PALETTES = [
    'lead' => ['bg' => '#e3d8f2', 'fg' => '#4e3778', 'accent' => '#9b7fc4', 'cell' => '#ece4f6'],
    'pending' => ['bg' => '#ccebd1', 'fg' => '#275a35', 'accent' => '#8fc99b', 'cell' => '#e3f3e6'],
    'confirmed' => ['bg' => '#54ed68', 'fg' => '#123d1c', 'accent' => '#24bd4a', 'cell' => '#b9ecc4'],
    'completed' => ['bg' => '#202326', 'fg' => '#ffffff', 'accent' => '#292b2d', 'cell' => '#dcdcdc'],
    'cancelled' => ['bg' => '#d4d7d8', 'fg' => '#3f4447', 'accent' => '#90969a', 'cell' => '#f1f1f0'],
    'refused' => ['bg' => '#f3d3d5', 'fg' => '#7a2028', 'accent' => '#c4646c', 'cell' => '#f8e7e8'],
    'information' => ['bg' => '#b8e3ed', 'fg' => '#07576b', 'accent' => '#087e95', 'cell' => '#cfe8ef'],
];

const ATTENDANCE_RESPONSES = [
    'present' => 'Présent',
    'absent' => 'Absent',
    'undecided' => 'Je ne sais pas',
];
// A member who has not answered yet: no answer is selected for them, and they are counted apart.
const ATTENDANCE_NO_RESPONSE = 'none';
const ATTENDANCE_NO_RESPONSE_LABEL = 'Sans réponse';
// "Mes réponses" filter of the agenda: the three answers and the dates not answered yet.
const ATTENDANCE_FILTERS = ['present' => 'Présent', 'undecided' => 'Je ne sais pas', 'absent' => 'Absent', ATTENDANCE_NO_RESPONSE => ATTENDANCE_NO_RESPONSE_LABEL];

// Answer of $userId in $attendance, or ATTENDANCE_NO_RESPONSE when they have not answered.
function app_attendance_response(mixed $attendance, string $userId): string
{
    $response = is_array($attendance) ? ($attendance[$userId] ?? null) : null;
    return is_string($response) && isset(ATTENDANCE_RESPONSES[$response]) ? $response : ATTENDANCE_NO_RESPONSE;
}

// Yes/no information tracked on accounts, set by administrators.
const ACCOUNT_FLAGS = [
    'minor' => 'Mineur',
    'vegetarian' => 'Végétarien',
];
// Optional identity of an account: first name, last name and gender ("" = not given).
const ACCOUNT_GENDERS = ['F' => 'Femme', 'M' => 'Homme', 'X' => 'Autre'];
// "Vue par défaut" of an account showing every section (stored in default_view).
const DEFAULT_VIEW_ALL = 'all';

// Identity fields posted with the account form, cleaned (empty values when not given).
// Title of a member linked to their function ("Penn-soner", "Président"…), set by administrators
// only (user.php); "" when none.
const USER_TITLE_MAX_LENGTH = 60;

function app_user_title(array $user): string
{
    return is_string($user['title'] ?? null) ? trim($user['title']) : '';
}

// What the application shows for a member's role: their title when they have one, otherwise the
// name of their role.
function app_user_role_label(array $user): string
{
    $title = app_user_title($user);
    return $title !== '' ? $title : (app_roles()[$user['role'] ?? ''] ?? 'Utilisateur');
}

// "Responsable de section": the member listed first in a sub-section (one per sub-section),
// independent of the manager role. Stored on the account ("section_leads": sub-section ids), and
// only valid for sub-sections the member belongs to.
function app_user_section_leads(array $user): array
{
    $stored = is_array($user['section_leads'] ?? null) ? array_filter($user['section_leads'], 'is_string') : [];
    return array_values(array_intersect($stored, app_expand_sections(app_user_sections($user))));
}

function app_is_section_lead(array $user, string $leaf): bool
{
    return in_array($leaf, app_user_section_leads($user), true);
}

// $members of the sub-section $leaf with its responsable first, the others in their order.
function app_sort_section_members(array $members, string $leaf): array
{
    $leads = array_filter($members, static fn (array $member): bool => app_is_section_lead($member, $leaf));
    return array_values($leads + array_diff_key($members, $leads));
}

function app_account_identity_input(array $input): array
{
    $gender = $input['gender'] ?? '';
    return [
        'first_name' => app_clean_line($input['first_name'] ?? '', 60),
        'last_name' => app_clean_line($input['last_name'] ?? '', 60),
        'gender' => is_string($gender) && isset(ACCOUNT_GENDERS[$gender]) ? $gender : '',
    ];
}

const SECTION_COLORS = [
    'red' => 'Rouge',
    'blue' => 'Bleu',
    'green' => 'Vert',
    'amber' => 'Ambre',
    'violet' => 'Violet',
    'gray' => 'Gris',
];
const SECTION_ID_PATTERN = '/^[a-z][a-z0-9_]{0,47}$/';
// Short unique code of each section, used in the annual planning (e.g. "B", "Cor").
const SECTION_CODE_MAX_LENGTH = 12;
const SECTION_CODE_PATTERN = '/^[\p{L}\p{N}][\p{L}\p{N}._-]{0,11}$/u';

const LOGIN_FAILURE_WINDOW_SECONDS = 900;
const LOGIN_MAX_FAILURES_PER_ACCOUNT = 5;
const LOGIN_MAX_FAILURES_PER_IP = 20;

// Structure used until sections are edited from the Configuration.
function app_default_section_tree(): array
{
    return [
        'bagad' => [
            'label' => 'Bagad',
            'code' => 'B',
            'color' => 'red',
            'children' => ['cornemuses' => 'Cornemuses', 'bombardes' => 'Bombardes', 'batteries' => 'Batteries'],
            'child_codes' => ['cornemuses' => 'Cor', 'bombardes' => 'Bomb', 'batteries' => 'Bat'],
        ],
        'cercle' => [
            'label' => 'Cercle',
            'code' => 'C',
            'color' => 'blue',
            'children' => ['danseurs' => 'Danseurs'],
            'child_codes' => ['danseurs' => 'Dans'],
        ],
        'bagadig' => [
            'label' => 'Bagadig',
            'code' => 'b.ig',
            'color' => 'blue',
            'children' => ['cornemuses_ig' => 'Cornemuses-ig', 'bombardes_ig' => 'Bombardes-ig', 'batterie_ig' => 'Batterie-ig'],
            'child_codes' => ['cornemuses_ig' => 'Cor.ig', 'bombardes_ig' => 'Bomb.ig', 'batterie_ig' => 'Bat.ig'],
        ],
    ];
}

// Branches (parent sections) and their sub-sections, as stored in data/sections.json:
// {"branches": [{"id", "label", "code", "color", "children": [{"id", "label", "code"}]}]}, in display order.
// Returns [branchId => ['label', 'code', 'color', 'children' => [childId => label], 'child_codes' => [childId => code]]].
// Every section ends up with a unique code: a missing or duplicate one is derived from its name.
function app_parse_section_tree(mixed $branches): array
{
    $tree = [];
    $seen = [];
    foreach (is_array($branches) ? $branches : [] as $branch) {
        $id = $branch['id'] ?? null;
        $label = is_string($branch['label'] ?? null) ? trim($branch['label']) : '';
        if (!is_string($id) || preg_match(SECTION_ID_PATTERN, $id) !== 1 || isset($seen[$id]) || $label === '') {
            continue;
        }
        $children = [];
        $childCodes = [];
        foreach (is_array($branch['children'] ?? null) ? $branch['children'] : [] as $child) {
            $childId = $child['id'] ?? null;
            $childLabel = is_string($child['label'] ?? null) ? trim($child['label']) : '';
            if (is_string($childId) && preg_match(SECTION_ID_PATTERN, $childId) === 1 && !isset($seen[$childId]) && $childId !== $id && $childLabel !== '') {
                $children[$childId] = $childLabel;
                $childCodes[$childId] = is_string($child['code'] ?? null) ? trim($child['code']) : '';
                $seen[$childId] = true;
            }
        }
        if ($children === []) {
            continue;
        }
        $seen[$id] = true;
        $color = $branch['color'] ?? 'gray';
        // "short" was the name of the branch code in earlier versions.
        $code = $branch['code'] ?? $branch['short'] ?? '';
        $tree[$id] = [
            'label' => $label,
            'code' => is_string($code) ? trim($code) : '',
            'color' => isset(SECTION_COLORS[$color]) ? $color : 'gray',
            'children' => $children,
            'child_codes' => $childCodes,
        ];
    }
    return app_complete_section_codes($tree);
}

// Gives every section a valid, unique code (case-insensitive). A missing code is the installation
// default for the original sections (B, Cor…), otherwise it is derived from the name.
function app_complete_section_codes(array $tree): array
{
    $defaultCodes = [];
    foreach (app_default_section_tree() as $branchId => $branch) {
        $defaultCodes[$branchId] = $branch['code'];
        $defaultCodes += $branch['child_codes'];
    }
    $used = [];
    $assign = static function (string $code, string $label, string $id) use (&$used, $defaultCodes): string {
        if ($code === '' && isset($defaultCodes[$id]) && !isset($used[app_lowercase($defaultCodes[$id])])) {
            $code = $defaultCodes[$id];
        }
        if (preg_match(SECTION_CODE_PATTERN, $code) !== 1 || isset($used[app_lowercase($code)])) {
            $base = ucfirst(substr(preg_replace('/[^a-z0-9]/', '', app_ascii($label)) ?: 'S', 0, 4));
            $code = $base;
            for ($suffix = 2; isset($used[app_lowercase($code)]); $suffix++) {
                $code = $base . $suffix;
            }
        }
        $used[app_lowercase($code)] = true;
        return $code;
    };
    foreach ($tree as $id => $branch) {
        $tree[$id]['code'] = $assign($branch['code'] ?? '', $branch['label'], $id);
        foreach ($branch['children'] as $childId => $childLabel) {
            $tree[$id]['child_codes'][$childId] = $assign($branch['child_codes'][$childId] ?? '', $childLabel, $childId);
        }
    }
    return $tree;
}

// Code of a section (branch or sub-section).
function app_section_code(string $section): string
{
    foreach (app_section_tree() as $branch => $definition) {
        if ($branch === $section) {
            return $definition['code'];
        }
        if (isset($definition['child_codes'][$section])) {
            return $definition['child_codes'][$section];
        }
    }
    return $section;
}

// Whether a code is already used by another section than $exceptId (case-insensitive).
function app_section_code_taken(string $code, string $exceptId = ''): bool
{
    foreach (app_section_tree() as $branch => $definition) {
        foreach ([$branch => $definition['code']] + $definition['child_codes'] as $id => $existing) {
            if ($id !== $exceptId && app_lowercase($existing) === app_lowercase($code)) {
                return true;
            }
        }
    }
    return false;
}

function app_section_tree(bool $refresh = false): array
{
    static $tree = null;
    if ($tree === null || $refresh) {
        $stored = read_json_file(app_data_path('sections.json'));
        $tree = app_parse_section_tree($stored['branches'] ?? null);
        if ($tree === []) {
            $tree = app_complete_section_codes(app_default_section_tree());
        }
    }
    return $tree;
}

// Applies $update to the section tree and saves it; $update receives and returns the tree.
// Places where a section can be hidden (Configuration > Sections): the section buttons of the agenda
// filters, and the Trombinoscope. Hidden sections are stored in data/settings.json, key
// "hidden_sections": {"filters": [ids], "trombinoscope": [ids]}; every section is shown by default.
const SECTION_DISPLAY_PLACES = ['filters' => 'Filtres', 'trombinoscope' => 'Trombinoscope'];

function app_hidden_sections(string $place): array
{
    $stored = read_json_file(app_data_path('settings.json'))['hidden_sections'][$place] ?? [];
    return is_array($stored) ? array_values(array_filter($stored, 'is_string')) : [];
}

// Whether $section (branch or sub-section) is shown in $place: a sub-section is hidden with its branch.
function app_section_shown(string $section, string $place): bool
{
    static $hidden = [];
    $hidden[$place] ??= array_flip(app_hidden_sections($place));
    if (isset($hidden[$place][$section])) {
        return false;
    }
    foreach (app_section_tree() as $branch => $definition) {
        if (isset($definition['children'][$section])) {
            return !isset($hidden[$place][$branch]);
        }
    }
    return true;
}

function app_update_section_tree(callable $update): array
{
    update_json_file(app_data_path('sections.json'), static function (array $data) use ($update): array {
        $current = app_parse_section_tree($data['branches'] ?? null);
        $tree = app_complete_section_codes($update($current !== [] ? $current : app_complete_section_codes(app_default_section_tree())));
        $branches = [];
        foreach ($tree as $id => $branch) {
            $branches[] = [
                'id' => $id,
                'label' => $branch['label'],
                'code' => $branch['code'],
                'color' => $branch['color'],
                'children' => array_map(
                    static fn (string $childId, string $label): array => ['id' => $childId, 'label' => $label, 'code' => $branch['child_codes'][$childId]],
                    array_keys($branch['children']),
                    array_values($branch['children'])
                ),
            ];
        }
        return ['branches' => $branches];
    });
    return app_section_tree(true);
}

// Stable identifier for a new section, derived from its label and unique in the tree.
function app_new_section_id(string $label, array $tree): string
{
    $base = trim(preg_replace('/[^a-z0-9]+/', '_', app_ascii($label)) ?? '', '_');
    $base = substr(preg_match('/^[a-z]/', $base) === 1 ? $base : 's_' . $base, 0, 40);
    $base = rtrim($base, '_') !== '' ? rtrim($base, '_') : 'section';
    $used = [];
    foreach ($tree as $branchId => $branch) {
        $used[$branchId] = true;
        foreach (array_keys($branch['children']) as $childId) {
            $used[$childId] = true;
        }
    }
    $id = $base;
    for ($suffix = 2; isset($used[$id]); $suffix++) {
        $id = $base . '_' . $suffix;
    }
    return $id;
}

// Lowercase ASCII version of a French label ("Écoles" → "ecoles").
function app_ascii(string $label): string
{
    return strtr(app_lowercase($label), [
        'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'î' => 'i', 'ï' => 'i', 'í' => 'i', 'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u',
        'ÿ' => 'y', 'ñ' => 'n', 'œ' => 'oe', 'æ' => 'ae',
    ]);
}

// Lowercase for accented French labels without requiring the mbstring extension.
function app_lowercase(string $value): string
{
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($value, 'UTF-8');
    }
    return strtr(strtolower($value), ['À' => 'à', 'Â' => 'â', 'Ç' => 'ç', 'É' => 'é', 'È' => 'è', 'Ê' => 'ê', 'Î' => 'î', 'Ô' => 'ô', 'Ù' => 'ù', 'Û' => 'û', 'Œ' => 'œ']);
}

function app_section_branch(string $section): ?string
{
    foreach (app_section_tree() as $branch => $definition) {
        if ($branch === $section || isset($definition['children'][$section])) {
            return $branch;
        }
    }
    return null;
}

function app_section_css_class(string $section): string
{
    $branch = app_section_branch($section);
    return 'tag-' . ($branch !== null ? app_section_tree()[$branch]['color'] : 'gray');
}

// Colour of the "Tutti" label, set in the sections console (data/settings.json, key "tutti_color").
// Until it is set, the label takes the colour of the Bagad branch.
function app_tutti_color(): string
{
    $color = read_json_file(app_data_path('settings.json'))['tutti_color'] ?? null;
    if (is_string($color) && isset(SECTION_COLORS[$color])) {
        return $color;
    }
    return app_section_tree()['bagad']['color'] ?? 'red';
}

// Status colours chosen in Configuration > Statuts (data/settings.json, key "status_colors"):
// only the statuses whose colour differs from their default button colour, as "#rrggbb".
function app_status_custom_colors(): array
{
    $stored = read_json_file(app_data_path('settings.json'))['status_colors'] ?? null;
    $colors = [];
    foreach (STATUS_DEFAULT_PALETTES as $status => $default) {
        $color = is_array($stored) ? app_normalize_hex_color($stored[$status] ?? null) : null;
        if ($color !== null && $color !== $default['bg']) {
            $colors[$status] = $color;
        }
    }
    return $colors;
}

// "#RGB" or "#RRGGBB" in lower case "#rrggbb", or null.
function app_normalize_hex_color(mixed $value): ?string
{
    if (!is_string($value) || preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', trim($value), $match) !== 1) {
        return null;
    }
    $hex = strtolower($match[1]);
    return '#' . (strlen($hex) === 3 ? $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2] : $hex);
}

// Colours derived from a status button colour: badge background and text (dark tint of the colour,
// or white on a dark colour), accent of cards, dots and columns (darker on a light colour) and
// light tint of the planning cells. menu.js applies the same rules to the preview of status-settings.php.
function app_status_palette(string $color): array
{
    $rgb = array_map('hexdec', str_split(substr($color, 1), 2));
    $mix = static fn (array $target, float $share): string => '#' . implode('', array_map(
        static fn (int $channel, int $other): string => sprintf('%02x', (int) round($channel * (1 - $share) + $other * $share)),
        $rgb,
        $target
    ));
    $linear = array_map(static function (int $channel): float {
        $value = $channel / 255;
        return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
    }, $rgb);
    $luminance = 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
    $isLight = $luminance > 0.18;
    return [
        'bg' => $color,
        'fg' => $isLight ? $mix([0, 0, 0], 0.7) : '#ffffff',
        'accent' => $luminance > 0.45 ? $mix([0, 0, 0], 0.3) : $color,
        'cell' => $mix([255, 255, 255], 0.65),
    ];
}

// CSS variables overriding styles.css for the customised status colours ("" when none).
function app_status_colors_css(): string
{
    $rules = '';
    foreach (app_status_custom_colors() as $status => $color) {
        foreach (app_status_palette($color) as $part => $value) {
            $rules .= '--status-' . $status . '-' . $part . ': ' . $value . '; ';
        }
    }
    return $rules === '' ? '' : ':root { ' . trim($rules) . ' }';
}

function app_normalize_sections(mixed $sections): array
{
    if (!is_array($sections)) {
        return [];
    }
    $labels = app_section_labels();
    $legacy = [
        'Cornemuses' => 'cornemuses',
        'Bombardes' => 'bombardes',
        'Batteurs' => 'batteries',
        'Danseurs' => 'danseurs',
    ];
    $normalized = [];
    foreach ($sections as $section) {
        if (!is_string($section)) {
            continue;
        }
        $section = $legacy[$section] ?? $section;
        if (isset($labels[$section])) {
            $normalized[] = $section;
            continue;
        }
        $id = array_search($section, $labels, true);
        if ($id !== false) {
            $normalized[] = $id;
        }
    }
    return array_values(array_unique($normalized));
}

function app_section_labels(): array
{
    $labels = [];
    foreach (app_section_tree() as $branch => $definition) {
        $labels[$branch] = $definition['label'];
        $labels = array_merge($labels, $definition['children']);
    }
    return $labels;
}

function app_section_leaves(): array
{
    $leaves = [];
    foreach (app_section_tree() as $definition) {
        $leaves = array_merge($leaves, array_keys($definition['children']));
    }
    return $leaves;
}

function app_expand_sections(array $sections): array
{
    $expanded = [];
    foreach (app_normalize_sections($sections) as $section) {
        if (isset(app_section_tree()[$section])) {
            $expanded = array_merge($expanded, array_keys(app_section_tree()[$section]['children']));
        } else {
            $expanded[] = $section;
        }
    }
    return array_values(array_unique($expanded));
}

function app_section_display_items(array $sections): array
{
    $leaves = app_expand_sections($sections);
    $displayItems = [];
    foreach (app_section_tree() as $branch => $definition) {
        $children = array_keys($definition['children']);
        $selectedChildren = array_values(array_intersect($children, $leaves));
        if (count($selectedChildren) === count($children)) {
            $displayItems[] = $branch;
        } else {
            $displayItems = array_merge($displayItems, $selectedChildren);
        }
    }

    return $displayItems;
}

function app_user_sections(array $user): array
{
    return app_normalize_sections($user['sections'] ?? null);
}

// Management scope, stored as whole branches (parent sections) and/or individual
// sub-sections. A branch selected as a whole also covers sub-sections added to it later.
function app_normalize_managed_sections(mixed $sections): array
{
    return app_section_display_items(app_normalize_sections($sections));
}

function app_user_managed_sections(array $user): array
{
    if (in_array($user['role'] ?? null, ALL_SECTIONS_ROLES, true)) {
        return array_keys(app_section_tree());
    }
    if (($user['role'] ?? null) !== 'manager') {
        return [];
    }
    $managed = app_normalize_managed_sections($user['managed_sections'] ?? null);
    // Managers created before the management scope existed manage their own sections.
    return $managed !== [] ? $managed : app_normalize_managed_sections(app_user_sections($user));
}

function app_user_managed_leaves(array $user): array
{
    return app_expand_sections(app_user_managed_sections($user));
}

// Reads users.json, converting legacy section labels on the way.
function app_read_users_with_migration(): array
{
    $usersPath = app_data_path('users.json');
    $storedUsers = read_json_file($usersPath);
    $needsSectionMigration = false;
    foreach ($storedUsers as $storedUser) {
        if (is_array($storedUser) && ($storedUser['sections'] ?? null) !== app_normalize_sections($storedUser['sections'] ?? null)) {
            $needsSectionMigration = true;
            break;
        }
    }
    if (!$needsSectionMigration) {
        return $storedUsers;
    }

    return update_json_file($usersPath, static function (array $users): array {
        foreach ($users as &$storedUser) {
            if (is_array($storedUser)) {
                $storedUser['sections'] = app_user_sections($storedUser);
            }
        }
        unset($storedUser);
        return $users;
    });
}

function app_describe_sections(array $sections): string
{
    $labels = app_section_labels();
    return implode(', ', array_map(
        static fn (string $section): string => isset(app_section_tree()[$section])
            ? $labels[$section] . ' (toute la branche)'
            : $labels[$section],
        $sections
    ));
}

// Stored value of the "Vue par défaut" field: a branch or sub-section id, or DEFAULT_VIEW_ALL
// ("" when invalid).
function app_normalize_default_view(mixed $value): string
{
    if ($value === DEFAULT_VIEW_ALL) {
        return DEFAULT_VIEW_ALL;
    }
    return is_string($value) && isset(app_section_labels()[$value]) ? $value : '';
}

// Default agenda filter of a user, independent of their sections: a branch or sub-section id,
// or "Toutes". Accounts saved before the field existed use their former primary section.
function app_user_default_view(array $user): string
{
    if (array_key_exists('default_view', $user)) {
        $view = app_normalize_default_view($user['default_view']);
        return $view === '' || $view === DEFAULT_VIEW_ALL ? 'Toutes' : $view;
    }
    return app_normalize_sections([$user['primary_section'] ?? null])[0] ?? (app_user_sections($user)[0] ?? 'Toutes');
}

function app_config(): array
{
    static $config;
    if (isset($config)) {
        return $config;
    }

    $path = dirname(__DIR__) . '/config.json';
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException('Impossible de lire config.json.');
    }

    $config = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($config) || !isset($config['site_title']) || !is_string($config['site_title']) || trim($config['site_title']) === '') {
        throw new RuntimeException('config.json doit définir un titre de site non vide.');
    }

    $config['site_title'] = trim($config['site_title']);
    return $config;
}

const DEFAULT_SITE_TAGLINE = 'L’agenda Kerlenn Pondi';
// Deizi is free software under the GNU AGPL v3: every page links to the licence and to the source
// code, as the licence requires for a network service. "source_url" in config.json points to the
// code of this installation (a modified version must publish its own).
const DEIZI_SOURCE_URL = 'https://github.com/YoannLeGoff/Deizi';
// Version of the application, shown on the About page; its release notes are in releases/ and on
// the releases page of the source code.
const DEIZI_VERSION = '1.1.0';
const DEIZI_LICENSE_URL = 'https://www.gnu.org/licenses/agpl-3.0.html';

function app_source_url(): string
{
    $url = app_config()['source_url'] ?? null;
    return is_string($url) && preg_match('#^https?://\S+$#', trim($url)) === 1 ? trim($url) : DEIZI_SOURCE_URL;
}

// Release notes of the versions: the releases page of the source code (GitHub).
function app_releases_url(): string
{
    return rtrim(app_source_url(), '/') . '/releases';
}
// Configurable logos: key in the "site" settings => label used by the console.
const SITE_LOGOS = ['logo' => 'Logo de l’en-tête', 'login_logo' => 'Logo de la page de connexion', 'app_icon' => 'Icône de l’application (téléphone)'];

// Version of the application icon, in its URLs: changes when the header logo or the app icon does,
// so that phones fetch the new icon.
function app_icon_version(): string
{
    $identity = app_site_identity();
    return substr(sha1((string) $identity['logo'] . '|' . (string) $identity['app_icon']), 0, 8);
}

// Site identity set from the Configuration (data/settings.json, key "site"):
// title (defaults to config.json), nickname (short name of the application, defaults to the title),
// tagline shown under the title, header logo and login page logo.
function app_site_identity(bool $refresh = false): array
{
    static $identity = null;
    if ($identity !== null && !$refresh) {
        return $identity;
    }
    $site = read_json_file(app_data_path('settings.json'))['site'] ?? [];
    $site = is_array($site) ? $site : [];
    $title = is_string($site['title'] ?? null) ? trim($site['title']) : '';
    $identity = [
        'title' => $title !== '' ? $title : app_config()['site_title'],
        'nickname' => is_string($site['nickname'] ?? null) ? trim($site['nickname']) : '',
        // Name of the association ("Kerlenn Pondi"), in the title of the full agenda.
        'association' => is_string($site['association'] ?? null) ? trim($site['association']) : '',
        'tagline' => is_string($site['tagline'] ?? null) ? trim($site['tagline']) : DEFAULT_SITE_TAGLINE,
        // Presentation of the application on the "À propos" page (updates.php); null until saved.
        'about_text' => is_string($site['about_text'] ?? null) ? trim($site['about_text']) : null,
    ];
    foreach (array_keys(SITE_LOGOS) as $key) {
        $logo = $site[$key] ?? null;
        $logoPath = is_string($logo) ? app_upload_path($logo) : null;
        $identity[$key] = $logoPath !== null && is_file($logoPath) ? $logo : null;
    }
    return $identity;
}

// Presentation of the application ("À propos" page): the tagline in bold, then the text set in
// Configuration > Identité du site, or by default the original wording with the nickname of the
// application.
function app_site_about(): array
{
    $identity = app_site_identity();
    // A full stop closes the tagline when it has no final punctuation ("L’agenda Kerlenn Pondi.").
    $tagline = $identity['tagline'];
    if ($tagline !== '' && preg_match('/[.!?…:]$/u', $tagline) !== 1) {
        $tagline .= '.';
    }
    return [
        'title' => $tagline,
        'text' => $identity['about_text'] ?? app_site_nickname() . ' réunit au même endroit les dates du groupe, la présence de chacun et les informations pratiques des sorties. Chacun y voit les dates qui le concernent, indique s’il sera là, et retrouve tout ce qu’il faut savoir avant de partir.',
    ];
}

// Address of a static file of the application with its modification time ("styles.css?v=…"), so
// that browsers and proxies load the new version after an update instead of a cached one.
function app_asset_url(string $file): string
{
    $time = @filemtime(dirname(__DIR__) . '/' . $file);
    return $time !== false ? $file . '?v=' . $time : $file;
}

function app_site_logo_url(string $key): ?string
{
    $logo = app_site_identity()[$key] ?? null;
    return $logo === null ? null : 'logo.php?' . http_build_query(['kind' => $key, 'v' => $logo]);
}

function app_site_title(): string
{
    return app_site_identity()['title'];
}

// Short name of the application ("Deizi"), used where the full title would be too long.
function app_site_nickname(): string
{
    $identity = app_site_identity();
    return $identity['nickname'] !== '' ? $identity['nickname'] : $identity['title'];
}

function app_data_path(string $filename): string
{
    // Texts corrected in Configuration > Langues: one file per language.
    $isTranslations = preg_match('/^translations-[a-z]{2,3}(-[a-z0-9]{2,8})?\.json$/', $filename) === 1;
    if (!$isTranslations && !in_array($filename, ['events.json', 'users.json', 'login_attempts.json', 'attendance_log.jsonl', 'settings.json', 'sections.json', 'calendar.json', 'seen.json', 'logistics.json', 'remember.json', 'push.json', 'announcements.json'], true)) {
        throw new InvalidArgumentException('Nom de fichier de données non autorisé.');
    }

    return dirname(__DIR__) . '/data/' . $filename;
}

function read_json_file(string $path, array $default = []): array
{
    if (!is_file($path)) {
        return $default;
    }

    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException('Impossible de lire le fichier de données.');
    }

    $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data)) {
        throw new RuntimeException('Le fichier de données doit contenir une liste JSON.');
    }

    return $data;
}

function update_json_file(string $path, callable $update, array $default = []): array
{
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Impossible de créer le dossier de données.');
    }
    if (!chmod($directory, 0700)) {
        throw new RuntimeException('Impossible de protéger le dossier de données.');
    }

    $lock = fopen($path . '.lock', 'c');
    if ($lock === false) {
        throw new RuntimeException('Impossible de verrouiller le fichier de données.');
    }
    if (!chmod($path . '.lock', 0600)) {
        fclose($lock);
        throw new RuntimeException('Impossible de protéger le verrou des données.');
    }

    $temporary = null;
    try {
        if (!flock($lock, LOCK_EX)) {
            throw new RuntimeException('Impossible de verrouiller le fichier de données.');
        }
        $data = read_json_file($path, $default);
        $updated = $update($data);
        if (!is_array($updated)) {
            throw new RuntimeException('La mise à jour des données doit retourner un tableau.');
        }
        $temporary = tempnam($directory, '.shared-calendar-');
        if ($temporary === false) {
            throw new RuntimeException('Impossible de préparer la sauvegarde des données.');
        }
        if (!chmod($temporary, 0600)) {
            throw new RuntimeException('Impossible de protéger les données temporaires.');
        }
        $json = json_encode($updated, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
        if (file_put_contents($temporary, $json, LOCK_EX) === false || !rename($temporary, $path)) {
            throw new RuntimeException('Impossible d’enregistrer les données.');
        }
        $temporary = null;
        return $updated;
    } finally {
        if ($temporary !== null && is_file($temporary)) {
            unlink($temporary);
        }
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

const ATTENDANCE_LOG_SOURCES = [
    'self' => 'Réponse du membre',
    'matrix' => 'Saisie manager',
    'event' => 'Gestion des dates',
    'account' => 'Profil (identifiant, nom, titre, photo)',
    'login' => 'Connexions',
];
// Changes of login made by members ("Mon compte") are recorded in the same journal ("kind": "account").
const ACCOUNT_LOG_ACTIONS = [
    'rename' => 'Identifiant modifié',
    'first_name' => 'Prénom modifié',
    'last_name' => 'Nom modifié',
    'title' => 'Titre modifié',
    'photo_add' => 'Photo ajoutée',
    'photo_change' => 'Photo changée',
    'photo_remove' => 'Photo retirée',
];
// Logins to the application ("kind": "login"). A failure is only recorded for an existing account,
// so that a password typed in the login field never reaches the journal.
const LOGIN_LOG_ACTIONS = [
    'login' => 'Connexion',
    'remember' => 'Reconnexion automatique',
    'failed' => 'Échec de connexion',
    'throttled' => 'Connexion bloquée',
];

// Changes made to the dates themselves are recorded in the same journal ("kind": "event").
const EVENT_LOG_ACTIONS = ['create' => 'Création de la date', 'update' => 'Modification de la date', 'delete' => 'Suppression de la date'];
// Fields compared on a modification; long texts are only reported as modified.
const EVENT_LOG_FIELDS = [
    'title' => 'Titre',
    'status' => 'Statut',
    'date' => 'Date',
    'end_date' => 'Date de fin',
    'all_day' => 'Journée entière',
    'time' => 'Heure de début',
    'end_time' => 'Heure de fin',
    'location' => 'Lieu',
    'url' => 'Lien',
    'sections' => 'Sections',
    'tutti' => 'Tutti',
    'reserved' => 'Date réservée',
    'comment' => 'Commentaire',
    'admin_note' => 'Note admin',
    'roadmap' => 'Feuille de route',
    'roadmap_disabled' => 'Feuille de route non disponible',
];
const EVENT_LOG_TEXT_FIELDS = ['comment', 'admin_note', 'roadmap'];

// Comparable value of a date field, as shown in the journal.
function app_event_log_value(array $event, string $field): string
{
    $value = $event[$field] ?? null;
    return match ($field) {
        // The journal records the status code (shown with its current label) and yes/no in English.
        'status' => is_string($value) && isset(EVENT_STATUSES[$value]) ? $value : 'pending',
        'date', 'end_date' => ($date = app_parse_date($value)) !== null ? $date->format('d/m/Y') : '',
        'all_day', 'tutti', 'reserved', 'roadmap_disabled' => app_in_english(LOG_YES_NO[$value === true ? 1 : 0]),
        'sections' => implode(', ', array_map(
            static fn (string $section): string => app_section_labels()[$section] ?? $section,
            app_section_display_items(is_array($value) ? array_filter($value, 'is_string') : [])
        )),
        'end_time' => app_event_end_time($event),
        'roadmap' => hash('sha256', json_encode(app_event_roadmap($event), JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR)),
        default => is_string($value) ? trim($value) : '',
    };
}

// Journal entry for the creation ($before null), modification or deletion ($after null) of a date,
// or null when a modification changes nothing.
function app_event_log_entry(?array $before, ?array $after, array $author, ?array $impersonator = null): ?array
{
    $event = $after ?? $before ?? [];
    $action = $before === null ? 'create' : ($after === null ? 'delete' : 'update');
    $changes = [];
    if ($action === 'update') {
        foreach (EVENT_LOG_FIELDS as $field => $label) {
            $old = app_event_log_value($before, $field);
            $new = app_event_log_value($after, $field);
            if ($old !== $new) {
                $changes[] = in_array($field, EVENT_LOG_TEXT_FIELDS, true)
                    ? ['field' => $field]
                    : ['field' => $field, 'from' => $old, 'to' => $new];
            }
        }
        if ($changes === []) {
            return null;
        }
    }
    $entry = [
        'kind' => 'event',
        'at' => (new DateTimeImmutable('now', new DateTimeZone('Europe/Paris')))->format(DATE_ATOM),
        'event_id' => (string) ($event['id'] ?? ''),
        'event_title' => is_string($event['title'] ?? null) ? $event['title'] : '',
        'event_date' => is_string($event['date'] ?? null) ? $event['date'] : '',
        'action' => $action,
        'changes' => $changes,
        'by_id' => (string) $author['id'],
        'by' => (string) $author['username'],
        'source' => 'event',
    ];
    if ($impersonator !== null) {
        $entry['by'] .= '-i-' . (string) $impersonator['username'];
        $entry['impersonator_id'] = (string) $impersonator['id'];
        $entry['impersonator'] = (string) $impersonator['username'];
    }
    return $entry;
}

function app_is_event_log_entry(array $entry): bool
{
    return ($entry['kind'] ?? null) === 'event';
}

function app_is_account_log_entry(array $entry): bool
{
    return ($entry['kind'] ?? null) === 'account';
}

function app_is_login_log_entry(array $entry): bool
{
    return ($entry['kind'] ?? null) === 'login';
}

// Records a login of $account (an action of LOGIN_LOG_ACTIONS) in the audit journal. A journal
// that cannot be written never prevents the login.
function app_login_log_record(array $account, string $action): void
{
    $name = (string) ($account['username'] ?? '');
    try {
        app_attendance_log_append([[
            'kind' => 'login',
            'at' => (new DateTimeImmutable('now', new DateTimeZone('Europe/Paris')))->format(DATE_ATOM),
            'event_id' => '',
            'action' => $action,
            'member_id' => (string) ($account['id'] ?? ''),
            'member' => $name,
            'by_id' => (string) ($account['id'] ?? ''),
            'by' => $name,
            'source' => 'login',
        ]]);
    } catch (Throwable) {
    }
}

// A change to the profile of $account (an action of ACCOUNT_LOG_ACTIONS: login, first name, last
// name, title, photo): the old and new values ("" for a photo), who did it and when.
function app_account_log_entry(array $account, string $action, string $from, string $to, array $author): array
{
    $member = $action === 'rename' ? $to : (string) ($account['username'] ?? '');
    $authorIsMember = (string) $author['id'] === (string) $account['id'];
    return [
        'kind' => 'account',
        'at' => (new DateTimeImmutable('now', new DateTimeZone('Europe/Paris')))->format(DATE_ATOM),
        'event_id' => '',
        'action' => $action,
        'member_id' => (string) $account['id'],
        'member' => $member,
        'from' => $from,
        'to' => $to,
        'by_id' => (string) $author['id'],
        'by' => $authorIsMember ? $member : (string) ($author['username'] ?? ''),
        'source' => 'account',
    ];
}

// "ancien → nouveau" of a profile change for the journal ("" for a photo).
function app_account_log_change_text(array $entry): string
{
    if (str_starts_with((string) ($entry['action'] ?? ''), 'photo_')) {
        return '';
    }
    $value = static fn (mixed $text): string => is_string($text) && $text !== '' ? $text : __('vide_parentheses');
    return $value($entry['from'] ?? '') . ' → ' . $value($entry['to'] ?? '');
}

// "Statut : À valider → Confirmé ; Lieu ; Commentaire modifié", for the journal and its CSV export.
function app_event_log_changes_text(array $entry): string
{
    $parts = [];
    foreach (is_array($entry['changes'] ?? null) ? $entry['changes'] : [] as $change) {
        $field = (string) ($change['field'] ?? '');
        if (!isset(EVENT_LOG_FIELDS[$field])) {
            continue;
        }
        $label = app_label(EVENT_LOG_FIELDS[$field]);
        $parts[] = array_key_exists('from', $change)
            ? __('field_from_to', [
                'field' => $label,
                'from' => ($change['from'] ?? '') !== '' ? app_journal_value($field, (string) $change['from']) : '—',
                'to' => ($change['to'] ?? '') !== '' ? app_journal_value($field, (string) $change['to']) : '—',
            ])
            : ($field === 'roadmap' ? __('field_modifiee', ['field' => $label]) : __('field_modifie', ['field' => $label]));
    }
    return implode(__('separateur_liste'), $parts);
}

function app_attendance_log_entry(array $event, array $member, mixed $previous, string $response, array $author, string $source, ?array $impersonator = null): array
{
    $entry = [
        'at' => (new DateTimeImmutable('now', new DateTimeZone('Europe/Paris')))->format(DATE_ATOM),
        'event_id' => (string) ($event['id'] ?? ''),
        'event_title' => is_string($event['title'] ?? null) ? $event['title'] : '',
        'event_date' => is_string($event['date'] ?? null) ? $event['date'] : '',
        'member_id' => (string) $member['id'],
        'member' => (string) $member['username'],
        'previous' => is_string($previous) && isset(ATTENDANCE_RESPONSES[$previous]) ? $previous : null,
        'response' => $response,
        'by_id' => (string) $author['id'],
        'by' => (string) $author['username'],
        'source' => $source,
    ];
    // Entered by an administrator acting as the author: recorded as "author-i-admin".
    if ($impersonator !== null) {
        $entry['by'] .= '-i-' . (string) $impersonator['username'];
        $entry['impersonator_id'] = (string) $impersonator['id'];
        $entry['impersonator'] = (string) $impersonator['username'];
    }
    return $entry;
}

// Append-only journal. Call it inside the events.json update so that an entry is
// written under the same lock as the change, and a failed write aborts the change.
function app_attendance_log_append(array $entries): void
{
    if ($entries === []) {
        return;
    }
    $path = app_data_path('attendance_log.jsonl');
    $isNew = !is_file($path);
    $lines = '';
    foreach ($entries as $entry) {
        $lines .= json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }
    if (file_put_contents($path, $lines, FILE_APPEND | LOCK_EX) === false) {
        throw new RuntimeException('Impossible d’enregistrer le journal d’audit.');
    }
    if ($isNew && !chmod($path, 0600)) {
        throw new RuntimeException('Impossible de protéger le journal d’audit.');
    }
}

// Attendance answers of the journal; with $withEventChanges, also the changes made to the dates.
function app_attendance_log_read(bool $withEventChanges = false): array
{
    $path = app_data_path('attendance_log.jsonl');
    if (!is_file($path)) {
        return [];
    }
    $handle = fopen($path, 'r');
    if ($handle === false) {
        throw new RuntimeException('Impossible de lire le journal d’audit.');
    }
    $entries = [];
    try {
        flock($handle, LOCK_SH);
        while (($line = fgets($handle)) !== false) {
            try {
                $entry = json_decode($line, true, 4, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                continue;
            }
            if (
                $withEventChanges
                && is_array($entry)
                && app_is_event_log_entry($entry)
                && is_string($entry['at'] ?? null)
                && is_string($entry['event_id'] ?? null)
                && is_string($entry['by_id'] ?? null)
                && isset(EVENT_LOG_ACTIONS[$entry['action'] ?? ''])
            ) {
                $entries[] = $entry;
            } elseif (
                $withEventChanges
                && is_array($entry)
                && app_is_account_log_entry($entry)
                && is_string($entry['at'] ?? null)
                && is_string($entry['member_id'] ?? null)
                && is_string($entry['by_id'] ?? null)
                && isset(ACCOUNT_LOG_ACTIONS[$entry['action'] ?? ''])
            ) {
                $entry['event_id'] = '';
                $entries[] = $entry;
            } elseif (
                $withEventChanges
                && is_array($entry)
                && app_is_login_log_entry($entry)
                && is_string($entry['at'] ?? null)
                && is_string($entry['member_id'] ?? null)
                && isset(LOGIN_LOG_ACTIONS[$entry['action'] ?? ''])
            ) {
                $entry['event_id'] = '';
                $entries[] = $entry;
            } elseif (
                is_array($entry)
                && !app_is_event_log_entry($entry)
                && !app_is_account_log_entry($entry)
                && !app_is_login_log_entry($entry)
                && is_string($entry['at'] ?? null)
                && is_string($entry['event_id'] ?? null)
                && is_string($entry['member_id'] ?? null)
                && is_string($entry['by_id'] ?? null)
                && (isset(ATTENDANCE_RESPONSES[$entry['response'] ?? '']) || ($entry['response'] ?? null) === ATTENDANCE_NO_RESPONSE)
            ) {
                $entries[] = $entry;
            }
        }
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
    return $entries;
}

function app_format_log_time(string $at): string
{
    try {
        $time = (new DateTimeImmutable($at))->setTimezone(new DateTimeZone('Europe/Paris'));
        return __('date_time', ['date' => $time->format('d/m/Y'), 'time' => $time->format('H:i')]);
    } catch (Exception) {
        return $at;
    }
}

function app_parse_date(mixed $value): ?DateTimeImmutable
{
    if (!is_string($value)) {
        return null;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('Europe/Paris'));
    return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
}

// Reads a free-text time such as "20h", "20h30", "20 h 30" or "20:30" as [hour, minute].
function app_parse_time(mixed $value): ?array
{
    if (!is_string($value) || preg_match('/^\s*(\d{1,2})\s*(?:h\s*(\d{1,2})?|:(\d{2}))\s*$/i', $value, $matches) !== 1) {
        return null;
    }
    $hour = (int) $matches[1];
    $minute = (int) (($matches[2] ?? '') !== '' ? $matches[2] : ($matches[3] ?? 0));
    return $hour <= 23 && $minute <= 59 ? [$hour, $minute] : null;
}

// Sort key of a date in listings: day, then start time, then title. On the same day,
// all-day dates come first and dates without a readable start time come last.
function app_event_sort_key(mixed $event): array
{
    if (!is_array($event)) {
        return ['', 0, ''];
    }
    $allDay = ($event['all_day'] ?? false) === true;
    $time = $allDay ? null : app_parse_time($event['time'] ?? null);
    return [
        is_string($event['date'] ?? null) ? $event['date'] : '',
        $allDay ? -1 : ($time === null ? 1440 : $time[0] * 60 + $time[1]),
        is_string($event['title'] ?? null) ? mb_strtolower($event['title']) : '',
    ];
}

function app_compare_events(mixed $first, mixed $second): int
{
    return app_event_sort_key($first) <=> app_event_sort_key($second);
}

// End time of a date; dates of earlier versions had a duration instead, converted here.
function app_event_end_time(array $event): string
{
    if (is_string($event['end_time'] ?? null) && trim($event['end_time']) !== '') {
        return trim($event['end_time']);
    }
    $start = app_parse_time($event['time'] ?? null);
    $duration = $event['duration_minutes'] ?? null;
    if ($start === null || !is_int($duration) || $duration <= 0) {
        return '';
    }
    $end = ($start[0] * 60 + $start[1] + $duration) % 1440;
    return sprintf('%dh%02d', intdiv($end, 60), $end % 60);
}

// Comment of a date, shown to everyone who sees it.
function app_event_comment(array $event): string
{
    return is_string($event['comment'] ?? null) ? trim($event['comment']) : '';
}

// Sends a CSV download readable by Excel (UTF-8 with BOM, ";" separator) and stops.
// Cells starting like a formula are prefixed so spreadsheets do not evaluate them.
function app_send_csv(string $filename, array $header, iterable $rows): never
{
    $cell = static fn (mixed $value): string => preg_match('/^[=+\-@\t\r]/', (string) $value) === 1 ? "'" . $value : (string) $value;
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
    header('Cache-Control: no-store');
    $output = fopen('php://output', 'w');
    fwrite($output, "\u{FEFF}");
    fputcsv($output, $header, ';');
    foreach ($rows as $row) {
        fputcsv($output, array_map($cell, $row), ';');
    }
    fclose($output);
    exit;
}

// Internal follow-up note of a date, for administrators only.
function app_event_admin_note(array $event): string
{
    return is_string($event['admin_note'] ?? null) ? trim($event['admin_note']) : '';
}

// "20h00 – 22h40", "20h00" or "" for the times of a date.
function app_event_time_range(array $event): string
{
    $start = is_string($event['time'] ?? null) ? trim($event['time']) : '';
    $end = app_event_end_time($event);
    if ($start === '') {
        return $end !== '' ? __('jusqua_time', ['time' => $end]) : '';
    }
    return $end !== '' ? $start . ' – ' . $end : $start;
}

function app_attendance_response_label(mixed $response): string
{
    if ($response === ATTENDANCE_NO_RESPONSE) {
        // Answer withdrawn by a manager in the attendance matrix.
        return app_no_response_label();
    }
    return is_string($response) && isset(ATTENDANCE_RESPONSES[$response]) ? app_attendance_responses()[$response] : __('aucune_reponse');
}

function app_render_attendance_log_table(array $entries, bool $withEvent, array $existingEventIds = []): void
{
    ?>
          <div class="log-scroll">
            <table class="log-table">
              <thead>
                <tr>
                  <th scope="col"><?= app_escape(__('le')) ?></th>
                  <?php if ($withEvent): ?><th scope="col"><?= app_escape(__('date_concernee')) ?></th><?php endif; ?>
                  <th scope="col"><?= app_escape(__('membre')) ?></th>
                  <th scope="col"><?= app_escape(__('action')) ?></th>
                  <th scope="col"><?= app_escape(__('par')) ?></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($entries as $entry): ?>
                <?php
                    $previous = is_string($entry['previous'] ?? null) && isset(ATTENDANCE_RESPONSES[$entry['previous']]) ? $entry['previous'] : 'none';
                    $eventDate = app_parse_date($entry['event_date'] ?? null);
                    $eventExists = isset($existingEventIds[$entry['event_id']]);
                ?>
                <tr>
                  <td class="log-time"><?= app_escape(app_format_log_time($entry['at'])) ?></td>
                  <?php if ($withEvent && (app_is_account_log_entry($entry) || app_is_login_log_entry($entry))): ?>
                  <td><span class="log-sub">—</span></td>
                  <?php elseif ($withEvent): ?>
                  <td>
                    <?php if ($eventExists): ?>
                    <a class="log-event-link" href="event.php?id=<?= rawurlencode($entry['event_id']) ?>&amp;tab=historique"><?= app_escape((string) ($entry['event_title'] ?? '')) ?></a>
                    <?php else: ?>
                    <strong><?= app_escape((string) ($entry['event_title'] ?? '')) ?></strong>
                    <?php endif; ?>
                    <span class="log-sub"><?= $eventDate !== null ? app_escape($eventDate->format('d/m/Y')) : '' ?><?= $eventExists ? '' : ' · ' . app_escape(__('date_supprimee')) ?></span>
                  </td>
                  <?php endif; ?>
                  <?php if (app_is_login_log_entry($entry)): ?>
                  <td><?= app_escape((string) ($entry['member'] ?? '')) ?></td>
                  <td class="log-change log-event-change">
                    <span class="log-action log-action-<?= app_escape($entry['action']) ?>"><?= app_escape(app_label(LOGIN_LOG_ACTIONS[$entry['action']])) ?></span>
                  </td>
                  <?php elseif (app_is_account_log_entry($entry)): ?>
                  <td><?= app_escape((string) ($entry['member'] ?? '')) ?></td>
                  <td class="log-change log-event-change">
                    <span class="log-action log-action-rename"><?= app_escape(app_label(ACCOUNT_LOG_ACTIONS[$entry['action']])) ?></span>
                    <?php if (app_account_log_change_text($entry) !== ''): ?>
                    <span class="log-sub"><?= app_escape(app_account_log_change_text($entry)) ?></span>
                    <?php endif; ?>
                    <?php if (($entry['by_id'] ?? '') !== ($entry['member_id'] ?? '')): ?>
                    <span class="log-sub"><?= app_escape(__('par_name', ['name' => (string) ($entry['by'] ?? '')])) ?></span>
                    <?php endif; ?>
                  </td>
                  <?php elseif (app_is_event_log_entry($entry)): ?>
                  <td><span class="log-sub">—</span></td>
                  <td class="log-change log-event-change">
                    <span class="log-action log-action-<?= app_escape($entry['action']) ?>"><?= app_escape(app_label(EVENT_LOG_ACTIONS[$entry['action']])) ?></span>
                    <?php if (app_event_log_changes_text($entry) !== ''): ?>
                    <span class="log-sub"><?= app_escape(app_event_log_changes_text($entry)) ?></span>
                    <?php endif; ?>
                  </td>
                  <?php else: ?>
                  <td><?= app_escape((string) ($entry['member'] ?? '')) ?></td>
                  <td class="log-change">
                    <span class="log-response log-response-<?= app_escape($previous) ?>"><?= app_escape(app_attendance_response_label($entry['previous'] ?? null)) ?></span>
                    <span aria-label="<?= app_escape(__('devient')) ?>">→</span>
                    <span class="log-response log-response-<?= app_escape($entry['response']) ?>"><?= app_escape(app_attendance_response_label($entry['response'])) ?></span>
                  </td>
                  <?php endif; ?>
                  <td>
                    <?= app_escape((string) ($entry['by'] ?? '')) ?>
                    <span class="log-sub"><?= app_escape(app_label(ATTENDANCE_LOG_SOURCES[$entry['source'] ?? ''] ?? '')) ?></span>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
<?php
}

function app_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function app_csrf_token(): string
{
    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function app_verify_csrf(): void
{
    $token = $_POST['csrf_token'] ?? null;
    if (!is_string($token) || !hash_equals(app_csrf_token(), $token)) {
        http_response_code(403);
        exit('La vérification de sécurité a échoué. Rechargez la page et réessayez.');
    }
}

// $link: optional ['url' => relative URL, 'label' => text] shown after the message.
function app_flash(string $message, string $kind = 'success', ?array $link = null): void
{
    $_SESSION['flash'] = ['message' => $message, 'kind' => $kind, 'link' => $link];
}

function app_take_flash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return is_array($flash) ? $flash : null;
}

function app_redirect(string $location): never
{
    header('Location: ' . $location, true, 303);
    exit;
}

function app_is_active_user(mixed $user): bool
{
    return is_array($user)
        && is_string($user['id'] ?? null)
        && ($user['active'] ?? false) === true
        && isset($user['role'], $user['username'])
        && is_string($user['role'])
        && in_array($user['role'], array_keys(USER_ROLES), true)
        && is_string($user['username']);
}

// Administrator acting as another account ("impersonation"), or null.
function app_impersonator(): ?array
{
    $impersonatorId = $_SESSION['impersonator_id'] ?? null;
    if (!is_string($impersonatorId)) {
        return null;
    }
    foreach (read_json_file(app_data_path('users.json')) as $user) {
        if (app_is_active_user($user) && $user['id'] === $impersonatorId && $user['role'] === 'admin') {
            return $user;
        }
    }
    return null;
}

function app_can_be_impersonated(array $account): bool
{
    return app_is_active_user($account) && in_array($account['role'], ['musician', 'manager', 'organizer', 'logistician'], true);
}

function app_current_user(): ?array
{
    $userId = $_SESSION['user_id'] ?? null;
    if (!is_string($userId)) {
        // Session ended (browser or installed application closed): "Rester connecté" logs the
        // member in again from the token of this device, if any.
        $userId = app_remember_login();
        if ($userId === null) {
            return null;
        }
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
        $rememberedLogin = true;
    }
    // An impersonation whose administrator was deactivated or demoted ends the session.
    if (isset($_SESSION['impersonator_id']) && app_impersonator() === null) {
        unset($_SESSION['user_id'], $_SESSION['impersonator_id']);
        return null;
    }

    foreach (read_json_file(app_data_path('users.json')) as $user) {
        if (app_is_active_user($user) && $user['id'] === $userId) {
            if (isset($rememberedLogin)) {
                app_login_log_record($user, 'remember');
            }
            $user['sections'] = app_user_sections($user);
            return $user;
        }
    }

    unset($_SESSION['user_id']);
    return null;
}

function app_is_calendar_token(mixed $token): bool
{
    return is_string($token) && preg_match('/^[0-9a-f]{64}$/', $token) === 1;
}

function app_user_by_calendar_token(string $token): ?array
{
    if (!app_is_calendar_token($token)) {
        return null;
    }

    foreach (read_json_file(app_data_path('users.json')) as $user) {
        if (
            app_is_active_user($user)
            && app_is_calendar_token($user['calendar_token'] ?? null)
            && hash_equals($user['calendar_token'], $token)
        ) {
            $user['sections'] = app_user_sections($user);
            return $user;
        }
    }

    return null;
}

function app_calendar_token(array $user, bool $renew = false): string
{
    if (!$renew && app_is_calendar_token($user['calendar_token'] ?? null)) {
        return $user['calendar_token'];
    }

    $token = bin2hex(random_bytes(32));
    update_json_file(app_data_path('users.json'), static function (array $users) use ($user, $renew, &$token): array {
        foreach ($users as &$storedUser) {
            if (!is_array($storedUser) || ($storedUser['id'] ?? null) !== $user['id']) {
                continue;
            }
            if (!$renew && app_is_calendar_token($storedUser['calendar_token'] ?? null)) {
                $token = $storedUser['calendar_token'];
            } else {
                $storedUser['calendar_token'] = $token;
            }
            break;
        }
        unset($storedUser);
        return $users;
    });

    return $token;
}

// Logins: 3 to 32 letters (accents, tilde and cedilla included: é, ñ, ç…), digits, dot, hyphen or underscore.
const USERNAME_PATTERN = '/^[\p{L}\p{N}_.-]{3,32}$/u';
const USERNAME_RULE = '3 à 32 caractères : lettres (accents, tilde et cédille acceptés, comme é, ñ ou ç), chiffres, point, tiret ou souligné, sans espace';
// Letter + combining accent (grave, acute, circumflex, tilde, diaeresis, cedilla) and its single
// character, to compose logins when the intl extension (Normalizer) is not available.
const USERNAME_COMPOSITIONS = ["a\u{0300}" => 'à', "a\u{0301}" => 'á', "a\u{0302}" => 'â', "a\u{0303}" => 'ã', "a\u{0308}" => 'ä', "e\u{0300}" => 'è', "e\u{0301}" => 'é', "e\u{0302}" => 'ê', "e\u{0303}" => 'ẽ', "e\u{0308}" => 'ë', "e\u{0327}" => 'ȩ', "i\u{0300}" => 'ì', "i\u{0301}" => 'í', "i\u{0302}" => 'î', "i\u{0303}" => 'ĩ', "i\u{0308}" => 'ï', "o\u{0300}" => 'ò', "o\u{0301}" => 'ó', "o\u{0302}" => 'ô', "o\u{0303}" => 'õ', "o\u{0308}" => 'ö', "u\u{0300}" => 'ù', "u\u{0301}" => 'ú', "u\u{0302}" => 'û', "u\u{0303}" => 'ũ', "u\u{0308}" => 'ü', "y\u{0300}" => 'ỳ', "y\u{0301}" => 'ý', "y\u{0302}" => 'ŷ', "y\u{0303}" => 'ỹ', "y\u{0308}" => 'ÿ', "c\u{0301}" => 'ć', "c\u{0302}" => 'ĉ', "c\u{0327}" => 'ç', "n\u{0300}" => 'ǹ', "n\u{0301}" => 'ń', "n\u{0303}" => 'ñ', "n\u{0327}" => 'ņ', "A\u{0300}" => 'À', "A\u{0301}" => 'Á', "A\u{0302}" => 'Â', "A\u{0303}" => 'Ã', "A\u{0308}" => 'Ä', "E\u{0300}" => 'È', "E\u{0301}" => 'É', "E\u{0302}" => 'Ê', "E\u{0303}" => 'Ẽ', "E\u{0308}" => 'Ë', "E\u{0327}" => 'Ȩ', "I\u{0300}" => 'Ì', "I\u{0301}" => 'Í', "I\u{0302}" => 'Î', "I\u{0303}" => 'Ĩ', "I\u{0308}" => 'Ï', "O\u{0300}" => 'Ò', "O\u{0301}" => 'Ó', "O\u{0302}" => 'Ô', "O\u{0303}" => 'Õ', "O\u{0308}" => 'Ö', "U\u{0300}" => 'Ù', "U\u{0301}" => 'Ú', "U\u{0302}" => 'Û', "U\u{0303}" => 'Ũ', "U\u{0308}" => 'Ü', "Y\u{0300}" => 'Ỳ', "Y\u{0301}" => 'Ý', "Y\u{0302}" => 'Ŷ', "Y\u{0303}" => 'Ỹ', "Y\u{0308}" => 'Ÿ', "C\u{0301}" => 'Ć', "C\u{0302}" => 'Ĉ', "C\u{0327}" => 'Ç', "N\u{0300}" => 'Ǹ', "N\u{0301}" => 'Ń', "N\u{0303}" => 'Ñ', "N\u{0327}" => 'Ņ'];

// Login as typed, trimmed and in composed Unicode form: "ñ" typed or pasted as "n" + combining
// tilde becomes the single letter "ñ", so that it is accepted and recognised at login.
function app_normalize_username(string $username): string
{
    $username = trim($username);
    if (class_exists('Normalizer')) {
        return Normalizer::normalize($username, Normalizer::FORM_C) ?: $username;
    }
    return strtr($username, USERNAME_COMPOSITIONS);
}

function app_is_valid_username(string $username): bool
{
    return preg_match(USERNAME_PATTERN, $username) === 1;
}

// Comparison key of a login: logins are unique and recognised regardless of case and Unicode form.
function app_username_key(string $username): string
{
    return app_lowercase(app_normalize_username($username));
}

function app_login_attempt_keys(string $username): array
{
    $ip = is_string($_SERVER['REMOTE_ADDR'] ?? null) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
    return [
        'ip' => hash('sha256', 'ip:' . $ip),
        'account' => hash('sha256', 'account:' . $ip . "\0" . app_username_key($username)),
    ];
}

function app_recent_login_failures(array $attempts, string $key): array
{
    $threshold = time() - LOGIN_FAILURE_WINDOW_SECONDS;
    $failures = isset($attempts[$key]) && is_array($attempts[$key]) ? $attempts[$key] : [];
    return array_values(array_filter($failures, static fn (mixed $time): bool => is_int($time) && $time > $threshold));
}

function app_login_is_throttled(string $username): bool
{
    $attempts = read_json_file(app_data_path('login_attempts.json'));
    $keys = app_login_attempt_keys($username);
    return count(app_recent_login_failures($attempts, $keys['account'])) >= LOGIN_MAX_FAILURES_PER_ACCOUNT
        || count(app_recent_login_failures($attempts, $keys['ip'])) >= LOGIN_MAX_FAILURES_PER_IP;
}

function app_login_record_attempt(string $username, bool $success): void
{
    $keys = app_login_attempt_keys($username);
    update_json_file(app_data_path('login_attempts.json'), static function (array $attempts) use ($keys, $success): array {
        $pruned = [];
        foreach (array_keys($attempts) as $key) {
            $failures = app_recent_login_failures($attempts, (string) $key);
            if ($failures !== []) {
                $pruned[(string) $key] = $failures;
            }
        }
        if ($success) {
            unset($pruned[$keys['account']]);
        } else {
            $pruned[$keys['account']][] = time();
            $pruned[$keys['ip']][] = time();
        }
        return $pruned;
    });
}

// Temporary closing of the application (Configuration > Maintenance): while it lasts, only
// administrators (and an administrator acting as a member) use the application; everyone else
// sees its message. Stored under "maintenance" in data/settings.json.
const MAINTENANCE_MESSAGE_MAX_LENGTH = 600;

// ['message' => …, 'since' => ATOM time, 'by' => login] while the application is closed, or null.
function app_maintenance(): ?array
{
    $stored = read_json_file(app_data_path('settings.json'))['maintenance'] ?? null;
    if (!is_array($stored) || ($stored['active'] ?? false) !== true) {
        return null;
    }
    return [
        'message' => is_string($stored['message'] ?? null) ? $stored['message'] : '',
        'since' => is_string($stored['since'] ?? null) ? $stored['since'] : '',
        'by' => is_string($stored['by'] ?? null) ? $stored['by'] : '',
    ];
}

// Whether the closing keeps $user out: everyone but the administrators.
function app_maintenance_excludes(?array $user): bool
{
    return app_maintenance() !== null && ($user['role'] ?? null) !== 'admin' && app_impersonator() === null;
}

// Page shown instead of the application while it is closed (503, to come back later).
function app_render_maintenance_page(): never
{
    $maintenance = app_maintenance() ?? ['message' => '', 'since' => '', 'by' => ''];
    http_response_code(503);
    header('Retry-After: 3600');
    header('Cache-Control: no-store');
    app_header(__('maintenance_titre'));
    ?>
      <section class="login-panel maintenance-panel" aria-labelledby="maintenance-title">
        <p class="eyebrow"><span class="eyebrow-line"></span> <?= app_escape(mb_strtoupper(app_site_title())) ?></p>
        <h1 id="maintenance-title"><?= app_escape(__('maintenance_titre')) ?><span class="heading-dot">.</span></h1>
        <?php if (trim($maintenance['message']) !== ''): ?>
        <p class="maintenance-message"><?= nl2br(app_escape($maintenance['message']), false) ?></p>
        <?php else: ?>
        <p class="maintenance-message"><?= app_escape(__('maintenance_message_defaut')) ?></p>
        <?php endif; ?>
        <p class="muted-copy"><?= app_escape(__('maintenance_revenez_plus_tard')) ?></p>
      </section>
<?php
    app_footer();
    exit;
}

function app_require_login(): array
{
    $user = app_current_user();
    if ($user !== null && app_maintenance_excludes($user)) {
        app_render_maintenance_page();
    }
    if ($user === null) {
        // Page asked for (e.g. a date opened from the iCal calendar), shown again after logging in.
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
            $requested = basename((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH));
            $query = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_QUERY);
            $_SESSION['after_login'] = app_safe_return_path($requested . ($query !== '' ? '?' . $query : ''));
        }
        app_redirect('login.php');
    }
    return $user;
}

// A page of the site to come back to: "event.php?id=…" (a PHP page of the site with its query
// string, nothing else: no other site, no path), or null.
function app_safe_return_path(mixed $path): ?string
{
    return is_string($path)
        && preg_match('#^[a-z0-9-]+\.php(\?[^\s\\\\/:]*)?$#i', $path) === 1
        && !preg_match('/^(login|logout)\.php/i', $path)
        ? $path
        : null;
}

function app_require_roles(array $roles): array
{
    $user = app_require_login();
    if (!in_array($user['role'] ?? null, $roles, true)) {
        http_response_code(403);
        exit(__('vous_navez_pas_droits_necessaires_page'));
    }
    return $user;
}

function app_header(string $title, ?array $user = null): void
{
    header('X-Content-Type-Options: nosniff');
    if (!APP_LOCAL_EMBED) {
        header('X-Frame-Options: DENY');
    }
    header('Referrer-Policy: same-origin');
    $identity = app_site_identity();
    $siteTitle = $identity['title'];
    $pageTitle = $title === '' ? $siteTitle : app_label($title) . ' — ' . $siteTitle;
    $flash = app_take_flash();
    $impersonator = $user !== null ? app_impersonator() : null;
    ?>
<!doctype html>
<html lang="<?= app_escape(app_locale()) ?>">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <?php // Favicon: the header logo of "Identité du site", or the red ♫ mark when none is set. ?>
    <?php if ($identity['logo'] !== null): ?>
    <link rel="icon" href="<?= app_escape(app_site_logo_url('logo')) ?>">
    <?php else: ?>
    <link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
    <?php endif; ?>
    <?php // Installable application (home screen of phones): manifest, square icon, app name. ?>
    <link rel="manifest" href="manifest.php">
    <link rel="apple-touch-icon" href="icon.php?size=180&amp;v=<?= app_escape(app_icon_version()) ?>">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="<?= app_escape(app_site_nickname()) ?>">
    <meta name="theme-color" content="#f5f1e8">
    <title><?= app_escape($pageTitle) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,400..800&family=Instrument+Sans:ital,wght@0,400..700;1,400..700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= app_escape(app_asset_url('styles.css')) ?>">
    <?php if (($statusColorsCss = app_status_colors_css()) !== ''): ?>
    <style><?= $statusColorsCss ?></style>
    <?php endif; ?>
    <?php // Texts of the scripts in the language of the page (French when absent). ?>
    <?php if (($jsTranslations = app_js_translations()) !== []): ?>
    <script type="application/json" id="deizi-i18n"><?= json_encode($jsTranslations, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
    <?php endif; ?>
    <script src="<?= app_escape(app_asset_url('menu.js')) ?>" defer></script>
  </head>
  <body>
    <?php if ($impersonator !== null): ?>
    <div class="impersonation-banner" role="status">
      <p>
        <?= __('admin_agit_tant_member_role_phrase', ['admin' => '<strong>' . app_escape((string) $impersonator['username']) . '</strong>', 'member' => '<strong>' . app_escape((string) $user['username']) . '</strong>', 'role' => app_escape(__(app_roles()[$user['role']] ?? ''))]) ?>
        <?= app_escape(__('reponses_saisies_sont_enregistrees_nom_name', ['name' => $user['username'] . '-i-' . $impersonator['username']])) ?>
      </p>
      <form method="post" action="impersonate-action.php">
        <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
        <input type="hidden" name="action" value="stop">
        <button class="impersonation-stop" type="submit"><?= app_escape(__('revenir_mon_compte')) ?></button>
      </form>
    </div>
    <?php endif; ?>
    <?php // Administrators are reminded on every page that the application is closed to the others. ?>
    <?php if ($user !== null && ($user['role'] ?? null) === 'admin' && $impersonator === null && ($maintenance = app_maintenance()) !== null): ?>
    <div class="maintenance-banner" role="status">
      <p><?= app_escape(__('maintenance_bandeau_admin')) ?></p>
      <a class="maintenance-banner-link" href="maintenance-settings.php"><?= app_escape(__('maintenance_rouvrir_lien')) ?></a>
    </div>
    <?php endif; ?>
    <main class="app-main">
      <?php // Logged-out visitors (login page, shared roadmaps) do not see the site banner. ?>
      <?php if (app_current_user() !== null): ?>
      <?php if ($user !== null): ?>
      <?php // Large screens: the connected account sits in a band above the menu, aligned right. ?>
      <div class="session-bar"><a href="account.php" title="<?= app_escape(__('mon_compte')) ?>"><?= app_escape((string) $user['username']) ?></a> · <?= app_escape(app_user_role_label($user)) ?></div>
      <?php endif; ?>
      <div class="app-brand">
        <a class="brand" href="index.php" aria-label="<?= app_escape($siteTitle) ?>">
          <?php if ($identity['logo'] !== null): ?>
          <img class="brand-logo" src="<?= app_escape(app_site_logo_url('logo')) ?>" alt="">
          <?php else: ?>
          <span class="brand-mark" aria-hidden="true">♫</span>
          <?php endif; ?>
          <span class="brand-copy">
            <span class="brand-title"><?= app_escape($siteTitle) ?></span>
            <?php if ($identity['tagline'] !== ''): ?>
            <span class="brand-subtitle"><?= app_escape($identity['tagline']) ?></span>
            <?php endif; ?>
          </span>
        </a>
        <?php if ($user !== null): ?>
        <?php
        // Bell: new dates of the member's sections and changes of dates they have opened (the
        // dates the push notifications announce), for members who have not turned them on.
        $unreadEvents = app_unread_events($user);
        $unreadCount = count($unreadEvents);
        $noticeReturn = app_safe_return_path(basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')) . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : ''));
        ?>
        <details class="notice-menu" data-unread-count="<?= $unreadCount ?>">
          <summary class="notice-toggle" aria-label="<?= app_escape($unreadCount === 0 ? __('aucune_nouveaute') : __n('date_nouvelle_modifiee', 'dates_nouvelles_modifiees', $unreadCount)) ?>">
            <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path d="M12 3a6 6 0 0 0-6 6v3.6L4.3 15.4A1 1 0 0 0 5.2 17h13.6a1 1 0 0 0 .9-1.6L18 12.6V9a6 6 0 0 0-6-6Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M9.5 19.5a2.6 2.6 0 0 0 5 0" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
            <?php if ($unreadCount > 0): ?><span class="notice-count" aria-hidden="true"><?= $unreadCount > 99 ? '99+' : $unreadCount ?></span><?php endif; ?>
          </summary>
          <div class="notice-panel">
            <p class="notice-heading"><?= app_escape(__('nouveautes')) ?></p>
            <?php if ($unreadEvents === []): ?>
            <p class="notice-empty"><?= app_escape(__('aucune_date_nouvelle_modifiee_depuis_votre')) ?></p>
            <?php else: ?>
            <ul class="notice-list">
              <?php foreach (array_slice($unreadEvents, 0, 20) as $item): ?>
              <?php $noticeDate = app_parse_date($item['event']['date'] ?? null); ?>
              <li>
                <a href="event.php?id=<?= rawurlencode((string) $item['event']['id']) ?>">
                  <span class="notice-kind <?= $item['notice']['type'] === 'new' ? 'is-new' : 'is-changed' ?>"><?= app_escape($item['notice']['type'] === 'new' ? __('nouveau') : __('modifie')) ?></span>
                  <strong><?= app_escape((string) ($item['event']['title'] ?? '')) ?></strong>
                  <span class="notice-meta"><?= $noticeDate !== null ? app_escape($noticeDate->format('d/m/Y')) : '' ?><?= $item['notice']['type'] === 'changed' ? ' · ' . app_escape(implode(', ', array_map('app_label', $item['notice']['fields']))) : '' ?></span>
                </a>
              </li>
              <?php endforeach; ?>
            </ul>
            <form method="post" action="notifications-action.php">
              <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
              <input type="hidden" name="action" value="mark_all_seen">
              <?php if ($noticeReturn !== null): ?><input type="hidden" name="return" value="<?= app_escape($noticeReturn) ?>"><?php endif; ?>
              <button class="text-button notice-clear" type="submit"><?= app_escape(__('tout_marquer_comme_lu')) ?></button>
            </form>
            <?php endif; ?>
            <a class="notice-settings" href="updates.php#notifications"><?= app_escape(__('notifications_cet_appareil')) ?></a>
          </div>
        </details>
        <div class="account-menu">
          <button class="account-menu-toggle" type="button" aria-expanded="false" aria-controls="account-navigation" aria-label="<?= app_escape(__('ouvrir_menu')) ?>">
            <span></span><span></span><span></span>
          </button>
          <?php // Large screens: icons only, the text of each entry being its alternative text and tooltip; phones: icon and text. ?>
          <nav class="account-menu-links" id="account-navigation" aria-label="<?= app_escape(__('menu_compte')) ?>">
            <span class="account-menu-user"><?= app_escape((string) $user['username']) ?> · <?= app_escape(app_user_role_label($user)) ?></span>
            <?php if (in_array($user['role'], EVENT_MANAGER_ROLES, true)): ?>
            <?= app_menu_link('manage-events.php', 'calendar-pen', __('gerer_dates')) ?>
            <?= app_menu_link(app_matrix_tab_url(), 'grid-check', __('tableau_presence')) ?>
            <?php endif; ?>
            <?= app_menu_link('reporting.php', 'planning', __('vues')) ?>
            <?= app_menu_link('trombinoscope.php', 'users', __('trombinoscope')) ?>
            <?php if (app_can_publish_announcements($user)): ?>
            <?= app_menu_link('announcements.php', 'megaphone', __('annonces')) ?>
            <?php endif; ?>
            <?php if (app_can_manage_logistics($user)): ?>
            <?= app_menu_link('logistics.php', 'receipt-euro', __('logistique')) ?>
            <?php endif; ?>
            <span class="menu-separator" aria-hidden="true"></span>
            <?= app_menu_link('updates.php#installer', 'phone-install', __('installer_lapplication'), 'install-menu-link', ' data-install-link') ?>
            <?php if (in_array($user['role'], EVENT_MANAGER_ROLES, true)): ?>
            <?= app_menu_link('settings.php', 'gear', __('configuration')) ?>
            <?php endif; ?>
            <?= app_menu_link('account.php', 'user', __('mon_compte')) ?>
            <form method="post" action="logout.php">
              <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
              <button class="text-button menu-icon-link menu-logout" type="submit" data-label="<?= app_escape(__('deconnecter')) ?>"><?= app_menu_icon('logout') ?><span class="menu-label"><?= app_escape(__('deconnecter')) ?></span></button>
            </form>
          </nav>
        </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>
      <?php if ($flash !== null): ?>
      <?php $flashLink = is_array($flash['link'] ?? null) && is_string($flash['link']['url'] ?? null) && is_string($flash['link']['label'] ?? null) ? $flash['link'] : null; ?>
      <p class="flash-message <?= app_escape((string) ($flash['kind'] ?? 'success')) ?>" role="status"><?= app_escape(app_label((string) ($flash['message'] ?? ''))) ?><?php if ($flashLink !== null): ?> <a class="flash-link" href="<?= app_escape($flashLink['url']) ?>"><?= app_escape(app_label($flashLink['label'])) ?> <span aria-hidden="true">→</span></a><?php endif; ?></p>
      <?php endif; ?>
<?php
}

// Pictograms of the menu (24×24, stroked like the bell): decorative, the text of the entry goes with them.
const MENU_ICONS = [
    'calendar-pen' => '<path d="M21 11V6a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h6"/><path d="M16 2v4M8 2v4M3 10h18"/><path d="M18.4 13.6a1.9 1.9 0 0 1 2.7 2.7L16.5 21l-2.5.5.5-2.5Z"/>',
    'grid-check' => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 3v18"/><path d="m12.5 15 2 2 4-4"/>',
    'planning' => '<path d="M3 3v16a2 2 0 0 0 2 2h16"/><path d="M8 7h6M11 12h8M7 17h5"/>',
    'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8"/>',
    'megaphone' => '<path d="M3 10v4a1 1 0 0 0 1 1h3l7 4V5L7 9H4a1 1 0 0 0-1 1Z"/><path d="M17.5 8.5a5 5 0 0 1 0 7"/><path d="M20 6a8.5 8.5 0 0 1 0 12"/><path d="M7 15l1.5 5h2.5L9.8 15.6"/>',
    'receipt-euro' => '<path d="M5 2v20l2.3-1.5L9.7 22 12 20.5l2.3 1.5 2.4-1.5L19 22V2l-2.3 1.5L14.3 2 12 3.5 9.7 2 7.3 3.5Z"/><path d="M15 8.6a3.2 3.2 0 1 0 0 5"/><path d="M8.5 10.3h4.5M8.5 12.3h4.5"/>',
    'phone-install' => '<rect x="6" y="2" width="12" height="20" rx="2"/><path d="M12 6.5v7M9 10.5l3 3 3-3M11 18h2"/>',
    'gear' => '<path d="M12.2 2h-.4a2 2 0 0 0-2 2v.2a2 2 0 0 1-1 1.7l-.4.3a2 2 0 0 1-2 0l-.2-.1a2 2 0 0 0-2.7.7l-.2.4a2 2 0 0 0 .7 2.7l.2.1a2 2 0 0 1 1 1.7v.5a2 2 0 0 1-1 1.7l-.2.1a2 2 0 0 0-.7 2.7l.2.4a2 2 0 0 0 2.7.7l.2-.1a2 2 0 0 1 2 0l.4.3a2 2 0 0 1 1 1.7v.2a2 2 0 0 0 2 2h.4a2 2 0 0 0 2-2v-.2a2 2 0 0 1 1-1.7l.4-.3a2 2 0 0 1 2 0l.2.1a2 2 0 0 0 2.7-.7l.2-.4a2 2 0 0 0-.7-2.7l-.2-.1a2 2 0 0 1-1-1.7v-.5a2 2 0 0 1 1-1.7l.2-.1a2 2 0 0 0 .7-2.7l-.2-.4a2 2 0 0 0-2.7-.7l-.2.1a2 2 0 0 1-2 0l-.4-.3a2 2 0 0 1-1-1.7V4a2 2 0 0 0-2-2Z"/><circle cx="12" cy="12" r="3"/>',
    'user' => '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="10" r="3"/><path d="M6.8 19a6 6 0 0 1 10.4 0"/>',
    'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5M21 12H9"/>',
];

function app_menu_icon(string $name): string
{
    return '<svg class="menu-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . (MENU_ICONS[$name] ?? '') . '</svg>';
}

// Entry of the menu: its icon, and its text (hidden on large screens, where it is read by screen
// readers and shown as a tooltip).
function app_menu_link(string $url, string $icon, string $label, string $class = '', string $attributes = ''): string
{
    return '<a class="menu-icon-link' . ($class !== '' ? ' ' . $class : '') . '" href="' . app_escape($url) . '" data-label="' . app_escape($label) . '"' . $attributes . '>'
        . app_menu_icon($icon) . '<span class="menu-label">' . app_escape($label) . '</span></a>';
}

// Configuration, grouped by theme: each group lists its pages (file, name, roles that open it).
// The Configuration page shows the groups; each page of a group shows the tabs of its group.
const SETTINGS_GROUPS = [
    'membres' => ['Membres', 'Les comptes et ce que chaque rôle peut faire.', [
        ['users.php', 'Comptes', ACCOUNT_MANAGER_ROLES],
        ['users-import.php', 'Import de comptes', ['admin']],
        ['rights.php', 'Matrice des droits', EVENT_MANAGER_ROLES],
    ]],
    'documents' => ['Documents', 'Les modèles des documents partagés avec les membres et les organisateurs.', [
        ['roadmap-settings.php', 'Feuille de route', ACCOUNT_MANAGER_ROLES],
        ['logistics-settings.php', 'Paramètres des devis', LOGISTICS_ROLES],
    ]],
    'calendrier' => ['Calendrier', 'Les repères du calendrier des dates et du planning annuel.', [
        ['season-settings.php', 'Saisons', ['admin']],
        ['school-holidays-settings.php', 'Vacances scolaires', ACCOUNT_MANAGER_ROLES],
        ['holidays-settings.php', 'Jours fériés', ACCOUNT_MANAGER_ROLES],
    ]],
    'organisation' => ['Configuration du site', 'L’identité du site, les sections du groupe et les statuts des dates.', [
        ['site-settings.php', 'Identité du site', ['admin']],
        ['sections-settings.php', 'Sections', ['admin']],
        ['status-settings.php', 'Statuts', ['admin']],
        ['languages-settings.php', 'Langues', ['admin']],
    ]],
    'suivi' => ['Suivi et sécurité', 'Ce qui a été fait, par qui et quand, et les opérations définitives.', [
        ['attendance-log.php', 'Journal d’audit', ACCOUNT_MANAGER_ROLES],
        ['delete-events.php', 'Suppression des dates', ['admin']],
        ['maintenance-settings.php', 'Fermeture temporaire', ['admin']],
    ]],
];

// Pages of a group that $user may open: [file, name] pairs.
function app_settings_group_pages(string $group, array $user): array
{
    return array_values(array_map(
        static fn (array $page): array => [$page[0], $page[1]],
        array_filter(SETTINGS_GROUPS[$group][2] ?? [], static fn (array $page): bool => in_array($user['role'] ?? '', $page[2], true))
    ));
}

// Tabs of the group of the current page, under its title (nothing when the group has one page).
function app_settings_nav(string $group, string $current, array $user): void
{
    $pages = app_settings_group_pages($group, $user);
    if (count($pages) < 2) {
        return;
    }
    ?>
        <nav class="settings-subnav" aria-label="<?= app_escape(app_label(SETTINGS_GROUPS[$group][0])) ?>">
          <span class="settings-subnav-group"><?= app_escape(app_label(SETTINGS_GROUPS[$group][0])) ?></span>
          <?php foreach ($pages as [$file, $name]): ?>
          <a href="<?= app_escape($file) ?>"<?= $file === $current ? ' aria-current="page"' : '' ?>><?= app_escape(app_label($name)) ?></a>
          <?php endforeach; ?>
        </nav>
<?php
}

// Navigation links shown on the right of the eyebrow line of a page, the same way on every page.
// $links: [label, url] pairs, labels in French (translated here); null entries (links reserved to
// some roles) are skipped.
function app_page_links(array $links): void
{
    $links = array_values(array_filter($links));
    if ($links === []) {
        return;
    }
    ?>
          <nav class="eyebrow eyebrow-links" aria-label="<?= app_escape(__('navigation')) ?>">
            <?php foreach ($links as $index => [$label, $url]): ?>
            <?php if ($index > 0): ?><span class="eyebrow-sep" aria-hidden="true">/</span><?php endif; ?>
            <a href="<?= app_escape($url) ?>"><?= app_escape(mb_strtoupper(app_label($label))) ?></a>
            <?php endforeach; ?>
          </nav>
<?php
}

// Date of the last update of the application: the most recent change among its own files
// (code, styles, scripts, images), i.e. the last deployment. Data files are not counted.
function app_files_updated_at(): DateTimeImmutable
{
    $root = dirname(__DIR__);
    $files = array_merge(
        glob($root . '/*.php') ?: [],
        glob($root . '/*.js') ?: [],
        glob($root . '/*.css') ?: [],
        glob($root . '/lib/*.php') ?: [],
        glob($root . '/assets/*') ?: []
    );
    $latest = max(array_map(static fn (string $file): int => (int) filemtime($file), $files) ?: [time()]);
    return (new DateTimeImmutable('@' . $latest))->setTimezone(new DateTimeZone('Europe/Paris'));
}

function app_footer(): void
{
    ?>
    </main>
    <footer class="site-footer">
      <?php // Title and last update: logged-in users only, like the site banner (not on the login page). ?>
      <?php if (app_current_user() !== null): ?>
      <?= app_escape(app_site_title()) ?> — <?= app_escape(__('derniere_mise_jour')) ?> <a href="updates.php" title="<?= app_escape(__('voir_nouveautes')) ?>"><?= app_files_updated_at()->format('d/m/Y') ?></a> ·
      <?php endif; ?>
      <?= app_escape(__('deizi_logiciel_libre_sous')) ?> <a href="<?= app_escape(DEIZI_LICENSE_URL) ?>" target="_blank" rel="noopener license"><?= app_escape(__('licence_agpl')) ?></a> · <a href="<?= app_escape(app_source_url()) ?>" target="_blank" rel="noopener"><?= app_escape(__('code_source')) ?></a>
    </footer>
  </body>
</html>
<?php
}

require __DIR__ . '/i18n.php';
require __DIR__ . '/roadmap.php';
require __DIR__ . '/polls.php';
require __DIR__ . '/changes.php';
require __DIR__ . '/calendar.php';
require __DIR__ . '/attendance-matrix.php';
require __DIR__ . '/seasons.php';
require __DIR__ . '/remember.php';
require __DIR__ . '/push.php';
require __DIR__ . '/announcements.php';
require __DIR__ . '/photos.php';
require __DIR__ . '/logistics.php';
