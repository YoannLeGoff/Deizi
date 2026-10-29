<?php
declare(strict_types=1);

// "Matrice des droits": what each role can do, for managers, organizers, logisticians and administrators.
// The table itself is RIGHTS_MATRIX (lib/rights-matrix.php), to keep in line with the access checks.
require __DIR__ . '/lib/bootstrap.php';
$user = app_require_roles(EVENT_MANAGER_ROLES);

require_once __DIR__ . '/lib/rights-matrix.php';
$roleOrder = ['musician', 'manager', 'organizer', 'logistician', 'admin'];

app_header('Matrice des droits', $user);
?>
      <section class="management-section">
        <div class="eyebrow-row">
          <p class="eyebrow"><span class="eyebrow-line"></span> <?= app_escape(__('configuration_membres_majuscules')) ?></p>
          <?php app_page_links([['Configuration', 'settings.php']]); ?>
        </div>
        <div class="section-heading">
          <div>
            <h1><?= app_escape(__('matrice_droits')) ?><span class="heading-dot">.</span></h1>
          </div>
        </div>
        <?php app_settings_nav('membres', 'rights.php', $user); ?>

        <p class="accounts-help rights-help"><?= app_escape(__('ce_chaque_role_peut_faire_dans')) ?> <strong><?= app_escape(__('perimetre_minuscule')) ?></strong> <?= app_escape(__('dun_manager_correspond_sections_quil_gere_label')) ?> <strong><?= app_escape(app_roles()[$user['role']] ?? $user['role']) ?></strong>.</p>

        <section class="management-panel">
          <div class="log-scroll">
            <table class="rights-table">
              <thead>
                <tr>
                  <th scope="col"><?= app_escape(__('action')) ?></th>
                  <?php foreach ($roleOrder as $role): ?>
                  <th scope="col"<?= $role === $user['role'] ? ' class="is-current"' : '' ?>><?= app_escape(app_roles()[$role]) ?></th>
                  <?php endforeach; ?>
                </tr>
              </thead>
              <?php foreach (RIGHTS_MATRIX as $group => $rights): ?>
              <tbody>
                <tr class="rights-group">
                  <th scope="rowgroup" colspan="<?= count($roleOrder) + 1 ?>"><?= app_escape(app_label($group)) ?></th>
                </tr>
                <?php foreach ($rights as [$label, $values]): ?>
                <?php $values = is_array($values) ? $values : array_fill(0, count($roleOrder), $values); ?>
                <tr>
                  <th scope="row"><?= app_escape(app_label($label)) ?></th>
                  <?php foreach ($roleOrder as $index => $role): ?>
                  <?php $value = $values[$index]; ?>
                  <td class="<?= $value === false ? 'rights-no' : 'rights-yes' ?><?= $role === $user['role'] ? ' is-current' : '' ?>">
                    <?php if ($value === true): ?>
                    <span aria-hidden="true">✓</span><span class="sr-only"><?= app_escape(__('oui')) ?></span>
                    <?php elseif ($value === false): ?>
                    <span aria-hidden="true">—</span><span class="sr-only"><?= app_escape(__('non')) ?></span>
                    <?php else: ?>
                    <span aria-hidden="true">✓</span> <span class="rights-limit"><?= app_escape(app_label($value)) ?></span>
                    <?php endif; ?>
                  </td>
                  <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
              </tbody>
              <?php endforeach; ?>
            </table>
          </div>
        </section>
      </section>
<?php app_footer(); ?>
