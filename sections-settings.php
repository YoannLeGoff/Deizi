<?php
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';
$admin = app_require_roles(['admin']);

const SECTION_LABEL_MAX_LENGTH = 60;

function sectionLabelInput(mixed $value): string
{
    return is_string($value) ? app_truncate(trim(preg_replace('/\s+/u', ' ', $value) ?? ''), SECTION_LABEL_MAX_LENGTH) : '';
}

function sectionLabelExists(string $label, string $exceptId = ''): bool
{
    foreach (app_section_labels() as $id => $existing) {
        if ($id !== $exceptId && strcasecmp(app_lowercase($existing), app_lowercase($label)) === 0) {
            return true;
        }
    }
    return false;
}

// Removes deleted sections from accounts (sections, default view, management scope) and dates.
function removeSectionReferences(array $removedIds): void
{
    update_json_file(app_data_path('users.json'), static function (array $users) use ($removedIds): array {
        foreach ($users as &$user) {
            if (!is_array($user)) {
                continue;
            }
            foreach (['sections', 'managed_sections'] as $field) {
                if (isset($user[$field]) && is_array($user[$field])) {
                    $user[$field] = array_values(array_diff($user[$field], $removedIds));
                }
            }
            if (in_array($user['default_view'] ?? null, $removedIds, true)) {
                $user['default_view'] = DEFAULT_VIEW_ALL;
            }
            if (in_array($user['primary_section'] ?? null, $removedIds, true)) {
                unset($user['primary_section']);
            }
        }
        unset($user);
        return $users;
    });
    update_json_file(app_data_path('events.json'), static function (array $events) use ($removedIds): array {
        foreach ($events as &$event) {
            if (is_array($event) && isset($event['sections']) && is_array($event['sections'])) {
                $event['sections'] = array_values(array_diff($event['sections'], $removedIds));
            }
        }
        unset($event);
        return $events;
    });
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    app_verify_csrf();
    $action = $_POST['action'] ?? '';
    $id = is_string($_POST['id'] ?? null) ? $_POST['id'] : '';
    $tree = app_section_tree();
    $branchOf = $id !== '' ? app_section_branch($id) : null;
    $isBranch = $branchOf !== null && $branchOf === $id;
    $label = sectionLabelInput($_POST['label'] ?? '');
    $color = is_string($_POST['color'] ?? null) && isset(SECTION_COLORS[$_POST['color']]) ? $_POST['color'] : 'gray';
    $code = is_string($_POST['code'] ?? null) ? trim($_POST['code']) : '';
    $childCode = is_string($_POST['child_code'] ?? null) ? trim($_POST['child_code']) : '';
    // An empty code is derived from the name when adding a section; it is required when editing one.
    $codeError = static function (string $value, string $exceptId = '') : ?string {
        if ($value === '') {
            return null;
        }
        if (preg_match(SECTION_CODE_PATTERN, $value) !== 1) {
            return 'Le code « ' . $value . ' » doit compter 1 à ' . SECTION_CODE_MAX_LENGTH . ' caractères : lettres, chiffres, point, tiret ou souligné, sans espace.';
        }
        return app_section_code_taken($value, $exceptId) ? 'Le code « ' . $value . ' » est déjà utilisé par une autre section.' : null;
    };
    $error = null;
    $message = '';

    switch ($action) {
        case 'tutti_color':
            update_json_file(app_data_path('settings.json'), static function (array $settings) use ($color): array {
                $settings['tutti_color'] = $color;
                return $settings;
            });
            $message = 'La couleur de l’étiquette Tutti a été enregistrée.';
            break;

        case 'add_branch':
            $childLabel = sectionLabelInput($_POST['child_label'] ?? '');
            if ($label === '' || $childLabel === '') {
                $error = 'Indiquez le nom de la branche et celui de sa première sous-section.';
            } elseif (sectionLabelExists($label) || sectionLabelExists($childLabel) || strcasecmp($label, $childLabel) === 0) {
                $error = 'Ce nom est déjà utilisé par une autre section.';
            } elseif (($error = $codeError($code) ?? $codeError($childCode)) === null && $code !== '' && strcasecmp($code, $childCode) === 0) {
                $error = 'La branche et sa sous-section doivent avoir des codes différents.';
            } elseif ($error === null) {
                app_update_section_tree(static function (array $tree) use ($label, $childLabel, $color, $code, $childCode): array {
                    $branchId = app_new_section_id($label, $tree);
                    $tree[$branchId] = ['label' => $label, 'code' => $code, 'color' => $color, 'children' => [], 'child_codes' => []];
                    $childId = app_new_section_id($childLabel, $tree);
                    $tree[$branchId]['children'][$childId] = $childLabel;
                    $tree[$branchId]['child_codes'][$childId] = $childCode;
                    return $tree;
                });
                $message = 'La branche « ' . $label . ' » a été créée.';
            }
            break;

        case 'add_child':
            if (!isset($tree[$id])) {
                $error = 'Cette branche n’existe plus.';
            } elseif ($label === '') {
                $error = 'Indiquez le nom de la sous-section.';
            } elseif (sectionLabelExists($label)) {
                $error = 'Ce nom est déjà utilisé par une autre section.';
            } elseif (($error = $codeError($code)) === null) {
                app_update_section_tree(static function (array $tree) use ($id, $label, $code): array {
                    $childId = app_new_section_id($label, $tree);
                    $tree[$id]['children'][$childId] = $label;
                    $tree[$id]['child_codes'][$childId] = $code;
                    return $tree;
                });
                $message = 'La sous-section « ' . $label . ' » a été ajoutée.';
            }
            break;

        case 'rename':
            if ($branchOf === null) {
                $error = 'Cette section n’existe plus.';
            } elseif ($label === '') {
                $error = 'Le nom ne peut pas être vide.';
            } elseif (sectionLabelExists($label, $id)) {
                $error = 'Ce nom est déjà utilisé par une autre section.';
            } elseif ($code === '') {
                $error = 'Le code ne peut pas être vide.';
            } elseif (($error = $codeError($code, $id)) === null) {
                app_update_section_tree(static function (array $tree) use ($id, $branchOf, $isBranch, $label, $color, $code): array {
                    if ($isBranch) {
                        $tree[$id]['label'] = $label;
                        $tree[$id]['code'] = $code;
                        $tree[$id]['color'] = $color;
                    } else {
                        $tree[$branchOf]['children'][$id] = $label;
                        $tree[$branchOf]['child_codes'][$id] = $code;
                    }
                    return $tree;
                });
                // "Répertoire": the section gets a repertoire box on the roadmaps of its dates.
                $withRepertoire = ($_POST['repertoire'] ?? '') === '1';
                $repertoireSections = array_keys(app_repertoire_sections());
                update_json_file(app_data_path('settings.json'), static function (array $settings) use ($id, $withRepertoire, $repertoireSections): array {
                    $sections = array_values(array_diff($repertoireSections, [$id]));
                    $settings['repertoire_sections'] = $withRepertoire ? [...$sections, $id] : $sections;
                    return $settings;
                });
                // "Filtres", "Trombinoscope": whether the section is shown there (unchecked: hidden).
                $shownIn = array_filter(array_keys(SECTION_DISPLAY_PLACES), static fn (string $place): bool => ($_POST['show_' . $place] ?? '') === '1');
                update_json_file(app_data_path('settings.json'), static function (array $settings) use ($id, $shownIn): array {
                    foreach (array_keys(SECTION_DISPLAY_PLACES) as $place) {
                        $hidden = is_array($settings['hidden_sections'][$place] ?? null) ? array_values(array_diff($settings['hidden_sections'][$place], [$id])) : [];
                        $settings['hidden_sections'][$place] = in_array($place, $shownIn, true) ? $hidden : [...$hidden, $id];
                    }
                    return $settings;
                });
                $message = 'La section a été mise à jour.';
            }
            break;

        case 'move':
            $direction = ($_POST['direction'] ?? '') === 'up' ? -1 : 1;
            if ($branchOf !== null) {
                app_update_section_tree(static function (array $tree) use ($id, $branchOf, $isBranch, $direction): array {
                    $move = static function (array $items, string $key) use ($direction): array {
                        $keys = array_keys($items);
                        $position = array_search($key, $keys, true);
                        $target = $position + $direction;
                        if ($position === false || $target < 0 || $target >= count($keys)) {
                            return $items;
                        }
                        [$keys[$position], $keys[$target]] = [$keys[$target], $keys[$position]];
                        return array_combine($keys, array_map(static fn (string $itemKey): mixed => $items[$itemKey], $keys));
                    };
                    if ($isBranch) {
                        return $move($tree, $id);
                    }
                    $tree[$branchOf]['children'] = $move($tree[$branchOf]['children'], $id);
                    return $tree;
                });
            }
            app_redirect('sections-settings.php#section-' . rawurlencode($branchOf ?? ''));

        case 'delete':
            if ($branchOf === null) {
                $error = 'Cette section n’existe plus.';
            } elseif ($isBranch && count($tree) === 1) {
                $error = 'Il doit rester au moins une branche.';
            } elseif (!$isBranch && count($tree[$branchOf]['children']) === 1) {
                $error = 'Une branche doit garder au moins une sous-section : supprimez plutôt la branche entière.';
            } else {
                $removedIds = $isBranch ? [$id, ...array_keys($tree[$id]['children'])] : [$id];
                $removedLabel = $isBranch ? $tree[$id]['label'] : $tree[$branchOf]['children'][$id];
                app_update_section_tree(static function (array $tree) use ($id, $branchOf, $isBranch): array {
                    if ($isBranch) {
                        unset($tree[$id]);
                    } else {
                        unset($tree[$branchOf]['children'][$id]);
                    }
                    return $tree;
                });
                removeSectionReferences($removedIds);
                update_json_file(app_data_path('settings.json'), static function (array $settings) use ($removedIds): array {
                    foreach (array_keys(SECTION_DISPLAY_PLACES) as $place) {
                        if (is_array($settings['hidden_sections'][$place] ?? null)) {
                            $settings['hidden_sections'][$place] = array_values(array_diff($settings['hidden_sections'][$place], $removedIds));
                        }
                    }
                    return $settings;
                });
                $message = '« ' . $removedLabel . ' » a été supprimée et retirée des comptes et des dates.';
            }
            break;

        default:
            http_response_code(400);
            exit('Action inconnue.');
    }

    app_flash($error ?? $message, $error === null ? 'success' : 'error');
    $anchor = $action === 'tutti_color' ? '#tutti' : ($branchOf !== null && $action !== 'delete' ? '#section-' . rawurlencode($branchOf) : '');
    app_redirect('sections-settings.php' . $anchor);
}

// Usage of each sub-section: active accounts (members or managers) and dates.
$tree = app_section_tree();
$memberCounts = [];
$eventCounts = [];
foreach (read_json_file(app_data_path('users.json')) as $account) {
    if (app_is_active_user($account)) {
        $leaves = array_unique(array_merge(
            app_expand_sections(app_user_sections($account)),
            app_expand_sections(app_normalize_sections($account['managed_sections'] ?? []))
        ));
        foreach ($leaves as $leaf) {
            $memberCounts[$leaf][$account['id']] = true;
        }
    }
}
foreach (read_json_file(app_data_path('events.json')) as $index => $event) {
    if (is_array($event) && is_array($event['sections'] ?? null)) {
        foreach (app_expand_sections(array_filter($event['sections'], 'is_string')) as $leaf) {
            $eventCounts[$leaf][$index] = true;
        }
    }
}
// Distinct accounts and dates: a date concerning several sub-sections of a branch counts once.
$usage = static function (array $leaves) use ($memberCounts, $eventCounts): array {
    $members = [];
    $events = [];
    foreach ($leaves as $leaf) {
        $members += $memberCounts[$leaf] ?? [];
        $events += $eventCounts[$leaf] ?? [];
    }
    return [count($members), count($events)];
};
$repertoireSections = app_repertoire_sections();
$hiddenSections = array_map(static fn (string $place): array => array_flip(app_hidden_sections($place)), array_combine(array_keys(SECTION_DISPLAY_PLACES), array_keys(SECTION_DISPLAY_PLACES)));
$usageText = static fn (array $counts): string => $counts[0] . ' compte' . ($counts[0] > 1 ? 's' : '') . ' · ' . $counts[1] . ' date' . ($counts[1] > 1 ? 's' : '');

function sectionToolForms(string $id, bool $first, bool $last, string $deleteConfirm, bool $canDelete): void
{
    ?>
                <div class="sections-tools">
                  <?php foreach (['up' => ['↑', 'Monter', $first], 'down' => ['↓', 'Descendre', $last]] as $direction => [$symbol, $title, $disabled]): ?>
                  <form method="post" action="sections-settings.php">
                    <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
                    <input type="hidden" name="action" value="move">
                    <input type="hidden" name="id" value="<?= app_escape($id) ?>">
                    <input type="hidden" name="direction" value="<?= $direction ?>">
                    <button class="sections-icon-button" type="submit" title="<?= $title ?>" aria-label="<?= $title ?>"<?= $disabled ? ' disabled' : '' ?>><?= $symbol ?></button>
                  </form>
                  <?php endforeach; ?>
                  <form method="post" action="sections-settings.php" onsubmit="return confirm(<?= app_escape(json_encode($deleteConfirm, JSON_UNESCAPED_UNICODE)) ?>);">
                    <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= app_escape($id) ?>">
                    <button class="text-button sections-delete" type="submit"<?= $canDelete ? '' : ' disabled title="Une branche doit garder au moins une sous-section"' ?>>Supprimer</button>
                  </form>
                </div>
<?php
}

function colorSelect(string $name, string $selected, string $id): void
{
    ?>
                  <select id="<?= app_escape($id) ?>" name="<?= $name ?>" class="sections-color-select" aria-label="Couleur des étiquettes">
                    <?php foreach (SECTION_COLORS as $value => $label): ?>
                    <option value="<?= $value ?>"<?= $selected === $value ? ' selected' : '' ?>><?= app_escape($label) ?></option>
                    <?php endforeach; ?>
                  </select>
<?php
}

app_header('Sections', $admin);
?>
      <section class="management-section">
        <div class="eyebrow-row">
          <p class="eyebrow"><span class="eyebrow-line"></span> CONFIGURATION DU SITE</p>
          <?php app_page_links([['Configuration', 'settings.php']]); ?>
        </div>
        <div class="section-heading">
          <div>
            <h1>Sections<span class="heading-dot">.</span></h1>
          </div>
        </div>
        <?php app_settings_nav('organisation', 'sections-settings.php', $admin); ?>

        <p class="accounts-help">Chaque section a un <strong>code</strong> court et unique (par exemple « B », « Cor »), utilisé dans le planning annuel ; laissé vide à la création, il est déduit du nom. Les branches (sections mères) regroupent des sous-sections. Les comptes et les dates sont rattachés aux sous-sections ; renommer une section ne change rien à ces rattachements. Supprimer une section la retire des comptes et des dates concernés. Cochez « Répertoire » pour qu’une branche ou une sous-section ait sa liste de morceaux sur la feuille de route (listes à remplir dans <a href="roadmap-settings.php">Configuration › Feuille de route</a>) ; une sous-section qui joue un répertoire particulier peut avoir le sien. Décochez « Filtres » ou « Trombinoscope » pour ne plus afficher une section dans les filtres de l’agenda ou dans le trombinoscope (les comptes et les dates restent rattachés) ; une branche masquée masque aussi toutes ses sous-sections.</p>

        <?php $branchIds = array_keys($tree); ?>
        <?php foreach ($tree as $branchId => $branch): ?>
        <?php
            $branchIndex = array_search($branchId, $branchIds, true);
            $branchUsage = $usage(array_keys($branch['children']));
            $childIds = array_keys($branch['children']);
        ?>
        <section class="management-panel sections-branch" id="section-<?= app_escape($branchId) ?>" aria-label="Branche <?= app_escape($branch['label']) ?>">
          <div class="sections-row sections-branch-row">
            <form class="sections-edit" method="post" action="sections-settings.php">
              <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
              <input type="hidden" name="action" value="rename">
              <input type="hidden" name="id" value="<?= app_escape($branchId) ?>">
              <span class="section-tag tag-<?= app_escape($branch['color']) ?> sections-preview" aria-hidden="true"><?= app_escape($branch['label']) ?></span>
              <label class="sr-only" for="branch-label-<?= app_escape($branchId) ?>">Nom de la branche</label>
              <input id="branch-label-<?= app_escape($branchId) ?>" name="label" maxlength="<?= SECTION_LABEL_MAX_LENGTH ?>" value="<?= app_escape($branch['label']) ?>" required>
              <label class="sr-only" for="branch-code-<?= app_escape($branchId) ?>">Code de la branche</label>
              <input class="sections-code" id="branch-code-<?= app_escape($branchId) ?>" name="code" maxlength="<?= SECTION_CODE_MAX_LENGTH ?>" required value="<?= app_escape($branch['code']) ?>" placeholder="Code" title="Code unique, utilisé dans le planning annuel">
              <?php colorSelect('color', $branch['color'], 'branch-color-' . $branchId); ?>
              <label class="sections-repertoire" title="Une boîte « Répertoire » sur la feuille de route des dates de la branche"><input type="checkbox" name="repertoire" value="1"<?= isset($repertoireSections[$branchId]) ? ' checked' : '' ?>> Répertoire</label>
              <?php foreach (SECTION_DISPLAY_PLACES as $place => $placeLabel): ?>
              <label class="sections-repertoire" title="<?= $place === 'filters' ? 'Afficher la branche dans les filtres de l’agenda ; décochée, ses sous-sections n’y sont pas non plus' : 'Afficher la branche dans le trombinoscope ; décochée, ses sous-sections n’y sont pas non plus' ?>"><input type="checkbox" name="show_<?= $place ?>" value="1"<?= isset($hiddenSections[$place][$branchId]) ? '' : ' checked' ?>> <?= $placeLabel ?></label>
              <?php endforeach; ?>
              <button class="secondary-button compact-button" type="submit">Enregistrer</button>
            </form>
            <span class="sections-usage"><?= app_escape($usageText($branchUsage)) ?></span>
            <?php sectionToolForms($branchId, $branchIndex === 0, $branchIndex === count($branchIds) - 1, 'Supprimer la branche « ' . $branch['label'] . ' » et ses ' . count($childIds) . ' sous-sections ? Elles seront retirées de ' . $usageText($branchUsage) . '.', count($tree) > 1); ?>
          </div>

          <ul class="sections-children">
            <?php foreach ($branch['children'] as $childId => $childLabel): ?>
            <?php $childIndex = array_search($childId, $childIds, true); $childUsage = $usage([$childId]); ?>
            <li class="sections-row">
              <form class="sections-edit" method="post" action="sections-settings.php">
                <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
                <input type="hidden" name="action" value="rename">
                <input type="hidden" name="id" value="<?= app_escape($childId) ?>">
                <label class="sr-only" for="child-label-<?= app_escape($childId) ?>">Nom de la sous-section</label>
                <input id="child-label-<?= app_escape($childId) ?>" name="label" maxlength="<?= SECTION_LABEL_MAX_LENGTH ?>" value="<?= app_escape($childLabel) ?>" required>
                <label class="sr-only" for="child-code-<?= app_escape($childId) ?>">Code de la sous-section</label>
                <input class="sections-code" id="child-code-<?= app_escape($childId) ?>" name="code" maxlength="<?= SECTION_CODE_MAX_LENGTH ?>" required value="<?= app_escape($branch['child_codes'][$childId]) ?>" placeholder="Code" title="Code unique, utilisé dans le planning annuel">
                <label class="sections-repertoire" title="Une boîte « Répertoire » propre à cette sous-section sur la feuille de route de ses dates"><input type="checkbox" name="repertoire" value="1"<?= isset($repertoireSections[$childId]) ? ' checked' : '' ?>> Répertoire</label>
                <?php foreach (SECTION_DISPLAY_PLACES as $place => $placeLabel): ?>
                <?php $branchHidden = isset($hiddenSections[$place][$branchId]); ?>
                <label class="sections-repertoire<?= $branchHidden ? ' is-overridden' : '' ?>" title="<?= $branchHidden ? 'Masquée avec sa branche « ' . app_escape($branch['label']) . ' »' : ($place === 'filters' ? 'Afficher la sous-section dans les filtres de l’agenda' : 'Afficher la sous-section dans le trombinoscope') ?>"><input type="checkbox" name="show_<?= $place ?>" value="1"<?= isset($hiddenSections[$place][$childId]) ? '' : ' checked' ?>> <?= $placeLabel ?></label>
                <?php endforeach; ?>
                <button class="secondary-button compact-button" type="submit">Enregistrer</button>
              </form>
              <span class="sections-usage"><?= app_escape($usageText($childUsage)) ?></span>
              <?php sectionToolForms($childId, $childIndex === 0, $childIndex === count($childIds) - 1, 'Supprimer la sous-section « ' . $childLabel . ' » ? Elle sera retirée de ' . $usageText($childUsage) . '.', count($childIds) > 1); ?>
            </li>
            <?php endforeach; ?>
          </ul>

          <form class="sections-add" method="post" action="sections-settings.php">
            <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
            <input type="hidden" name="action" value="add_child">
            <input type="hidden" name="id" value="<?= app_escape($branchId) ?>">
            <label class="sr-only" for="new-child-<?= app_escape($branchId) ?>">Nouvelle sous-section de <?= app_escape($branch['label']) ?></label>
            <input id="new-child-<?= app_escape($branchId) ?>" name="label" maxlength="<?= SECTION_LABEL_MAX_LENGTH ?>" placeholder="Nouvelle sous-section" required>
            <label class="sr-only" for="new-child-code-<?= app_escape($branchId) ?>">Code (facultatif)</label>
            <input class="sections-code" id="new-child-code-<?= app_escape($branchId) ?>" name="code" maxlength="<?= SECTION_CODE_MAX_LENGTH ?>" placeholder="Code" title="Code unique ; laissé vide, il est déduit du nom">
            <button class="secondary-button compact-button" type="submit">Ajouter</button>
          </form>
        </section>
        <?php endforeach; ?>

        <section class="management-panel" id="tutti" aria-labelledby="tutti-title">
          <h2 class="detail-heading" id="tutti-title">Étiquette Tutti</h2>
          <p class="muted-copy">Affichée à la place des sections pour les dates cochées « Tutti » dans les vues avancées.</p>
          <div class="sections-row">
            <form class="sections-edit" method="post" action="sections-settings.php">
              <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
              <input type="hidden" name="action" value="tutti_color">
              <span class="section-tag tag-tutti tag-<?= app_escape(app_tutti_color()) ?> sections-preview" aria-hidden="true">Tutti</span>
              <?php colorSelect('color', app_tutti_color(), 'tutti-color'); ?>
              <button class="secondary-button compact-button" type="submit">Enregistrer</button>
            </form>
          </div>
        </section>

        <section class="management-panel" aria-labelledby="new-branch-title">
          <h2 class="detail-heading" id="new-branch-title">Nouvelle branche</h2>
          <form class="sections-new-branch" method="post" action="sections-settings.php">
            <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
            <input type="hidden" name="action" value="add_branch">
            <div>
              <label for="new-branch-label">Nom de la branche</label>
              <input id="new-branch-label" name="label" maxlength="<?= SECTION_LABEL_MAX_LENGTH ?>" required>
            </div>
            <div>
              <label for="new-branch-code">Code</label>
              <input class="sections-code" id="new-branch-code" name="code" maxlength="<?= SECTION_CODE_MAX_LENGTH ?>" placeholder="Auto">
            </div>
            <div>
              <label for="new-branch-child">Première sous-section</label>
              <input id="new-branch-child" name="child_label" maxlength="<?= SECTION_LABEL_MAX_LENGTH ?>" required>
            </div>
            <div>
              <label for="new-branch-child-code">Code</label>
              <input class="sections-code" id="new-branch-child-code" name="child_code" maxlength="<?= SECTION_CODE_MAX_LENGTH ?>" placeholder="Auto">
            </div>
            <div>
              <label for="new-branch-color">Couleur</label>
              <?php colorSelect('color', 'gray', 'new-branch-color'); ?>
            </div>
            <button class="primary-button compact-button" type="submit">Créer la branche</button>
          </form>
        </section>
      </section>
<?php app_footer(); ?>
