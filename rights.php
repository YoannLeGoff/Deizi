<?php
declare(strict_types=1);

// "Matrice des droits": what each role can do, for managers, organizers, logisticians and administrators.
// Keep this table in line with the access checks when rights change.
require __DIR__ . '/lib/bootstrap.php';
$user = app_require_roles(EVENT_MANAGER_ROLES);

// Each right: [label, [musician, manager, organizer, logistician, admin]]; true = yes, false = no, string = yes with a limit.
const RIGHTS_MATRIX = [
    'Agenda' => [
        ['Consulter l’agenda (prochaines dates et agenda complet)', ['Ses sections, sauf dates refusées', 'Sauf dates réservées hors de ses sections', true, true, true]],
        ['Répondre à ses présences', ['Ses sections', 'Ses sections', 'Ses sections', 'Ses sections', 'Ses sections']],
        ['Consulter le détail d’une date et son commentaire', ['Ses sections', 'Sauf dates réservées hors de ses sections', true, true, true]],
        ['Voir les présents par section', [false, true, true, true, true]],
        ['Voir les réponses par sous-section sur la page d’une date (toutes les sous-sections de la date)', true],
        ['S’abonner au calendrier (iCal)', true],
        ['Consulter le trombinoscope', true],
        ['Ajouter ou changer sa photo (Mon compte)', [true, true, false, false, true]],
        ['Changer ou retirer la photo d’un autre membre (noté dans le journal d’audit)', [false, 'Artistes et managers de son périmètre', false, false, true]],
        ['Changer l’identifiant d’un autre membre (noté dans le journal d’audit)', [false, 'Artistes et managers de son périmètre', 'Artistes et managers', 'Artistes et managers', true]],
        ['Voir le journal d’audit d’un membre (onglet de sa fiche)', [false, false, false, false, true]],
        ['Attribuer un titre à un membre (affiché à la place du rôle)', [false, false, false, false, true]],
        ['Être averti des nouveautés (cloche, notifications) : nouvelles dates de ses sections, modifications des dates consultées', ['Ses sections, sauf dates refusées', 'Sauf dates réservées hors de ses sections', true, true, true]],
        ['Recevoir dans l’iCal les notes et le lien de la feuille de route', ['Date confirmée', true, true, true, true]],
        ['Consulter les vues avancées (liste et planning annuel)', ['Toutes les sections, sauf dates réservées', 'Sauf dates réservées hors de ses sections', true, true, true]],
        ['Consulter la page « Mises à jour »', true],
        ['Consulter la présentation de l’application (diaporama et PDF)', true],
        ['Installer l’application sur son téléphone', true],
    ],
    'Dates' => [
        ['Créer et modifier des dates (y compris « Feuille de route non disponible »)', [false, 'Son périmètre uniquement', true, true, true]],
        ['Indiquer qu’une date nécessite un suivi logistique', [false, 'Ses nouvelles dates n’en ont pas', true, true, true]],
        ['Choisir si une date est « réservée » à ses sous-sections', [false, 'Ses nouvelles dates le sont toujours', true, true, true]],
        ['Ouvrir une date réservée à des sous-sections hors de son périmètre', [false, true, true, true, true]],
        ['Supprimer des dates (console dédiée)', [false, false, false, false, true]],
        ['Voir et modifier la note admin (fiche, formulaire et Logistique)', [false, false, true, true, true]],
        ['Consulter et imprimer la feuille de route', ['Date confirmée', true, true, true, true]],
        ['Copier le lien public de la feuille de route', ['Date confirmée', true, true, true, true]],
        ['Créer, renouveler ou désactiver le lien public', [false, true, true, true, true]],
    ],
    'Annonces' => [
        ['Voir les annonces sur la page d’accueil (et les masquer)', ['Ses sections', 'Ses sections', 'Ses sections', 'Ses sections', 'Ses sections']],
        ['Publier, modifier et supprimer des annonces (texte, lien, image, notification)', [false, 'Son périmètre uniquement', true, true, true]],
        ['Publier une annonce pour tout le monde', [false, false, true, true, true]],
        ['Voir toutes les annonces, de tous les auteurs (onglet Administrateur)', [false, false, false, false, true]],
    ],
    'Sondages' => [
        ['Répondre aux sondages', ['Dates concernées', 'Dates concernées', 'Dates concernées', 'Dates concernées', 'Dates concernées']],
        ['Créer, modifier, clore, masquer, supprimer et exporter les sondages', [false, true, true, true, true]],
        ['Répondre à un sondage pour un membre', [false, 'Son périmètre', true, true, true]],
        ['Voir le tableau détaillé (identifiant, prénom, nom, réponses, informations)', [false, false, true, true, true]],
    ],
    'Logistique' => [
        ['Accéder à la page Logistique (menu)', [false, false, false, true, true]],
        ['Choisir le statut logistique d’une date (il fixe le statut de la date)', [false, false, false, true, true]],
        ['Saisir le montant du contrat et le cachet d’une date', [false, false, false, true, true]],
        ['Gérer les devis envoyés aux organisateurs (ajout, modification, fichier, statut)', [false, false, false, true, true]],
        ['Gérer les devis annexes reçus — transport, hébergement… (ajout, modification, fichier, statut)', [false, false, false, true, true]],
        ['Générer, imprimer et modifier les devis destinés aux organisateurs', [false, false, false, true, true]],
        ['Régler les paramètres des devis (émetteur, mentions, numérotation)', [false, false, false, true, true]],
    ],
    'Présences et suivi' => [
        ['Saisir les présences (tableau de présence, onglet Présences d’une date)', [false, 'Son périmètre', true, true, true]],
        ['Consulter le journal d’audit (connexions, saisies, dates, identifiants ; page et onglet des dates)', [false, false, true, true, true]],
        ['Voir qui a saisi chaque réponse dans le tableau de présence', [false, false, true, true, true]],
    ],
    'Configuration' => [
        ['Accéder à la page Configuration', [false, 'Matrice des droits', 'Comptes, calendrier, feuille de route, historique', 'Comptes, calendrier, feuille de route, historique', true]],
        ['Consulter la matrice des droits', [false, true, true, true, true]],
        ['Consulter la liste des comptes', [false, false, true, true, true]],
        ['Créer des comptes', [false, false, 'Artistes et managers', 'Artistes et managers', true]],
        ['Importer des comptes en masse (CSV)', [false, false, false, false, true]],
        ['Désigner le responsable de section d’une sous-section (affiché en premier dans les listes)', [false, false, 'Comptes d’artistes et de managers', 'Comptes d’artistes et de managers', true]],
        ['Modifier un compte : rôle, sections, vue par défaut, périmètre, prénom, nom, sexe, Mineur / Végétarien', [false, false, 'Artistes et managers', 'Artistes et managers', true]],
        ['Changer son propre identifiant et son mot de passe (Mon compte ; le changement d’identifiant est tracé dans le journal d’audit)', true],
        ['Désactiver un compte, changer le mot de passe d’un autre compte, renouveler un lien iCal', [false, false, false, false, true]],
        ['Créer ou modifier un compte organisateur, logisticien ou administrateur', [false, false, false, false, true]],
        ['Se connecter en tant qu’un autre membre', [false, false, false, false, true]],
        ['Gérer les vacances scolaires et les jours fériés', [false, false, true, true, true]],
        ['Régler la feuille de route par défaut', [false, false, true, true, true]],
        ['Gérer les sections (codes, couleurs, couleur de l’étiquette Tutti, affichage dans les filtres et le trombinoscope)', [false, false, false, false, true]],
        ['Régler le début des saisons', [false, false, false, false, true]],
        ['Choisir la couleur des statuts', [false, false, false, false, true]],
        ['Modifier l’identité du site (titre, pseudo, phrase d’accroche, logos, icône de l’application)', [false, false, false, false, true]],
    ],
];
$roleOrder = ['musician', 'manager', 'organizer', 'logistician', 'admin'];

app_header('Matrice des droits', $user);
?>
      <section class="management-section">
        <div class="eyebrow-row">
          <p class="eyebrow"><span class="eyebrow-line"></span> CONFIGURATION · MEMBRES</p>
          <?php app_page_links([['Configuration', 'settings.php']]); ?>
        </div>
        <div class="section-heading">
          <div>
            <h1>Matrice des droits<span class="heading-dot">.</span></h1>
          </div>
        </div>
        <?php app_settings_nav('membres', 'rights.php', $user); ?>

        <p class="accounts-help rights-help">Ce que chaque rôle peut faire dans l’application. Le <strong>périmètre</strong> d’un manager correspond aux sections qu’il gère, définies sur sa fiche de compte. Votre rôle : <strong><?= app_escape(app_roles()[$user['role']] ?? $user['role']) ?></strong>.</p>

        <section class="management-panel">
          <div class="log-scroll">
            <table class="rights-table">
              <thead>
                <tr>
                  <th scope="col">Action</th>
                  <?php foreach ($roleOrder as $role): ?>
                  <th scope="col"<?= $role === $user['role'] ? ' class="is-current"' : '' ?>><?= app_escape(app_roles()[$role]) ?></th>
                  <?php endforeach; ?>
                </tr>
              </thead>
              <?php foreach (RIGHTS_MATRIX as $group => $rights): ?>
              <tbody>
                <tr class="rights-group">
                  <th scope="rowgroup" colspan="<?= count($roleOrder) + 1 ?>"><?= app_escape($group) ?></th>
                </tr>
                <?php foreach ($rights as [$label, $values]): ?>
                <?php $values = is_array($values) ? $values : array_fill(0, count($roleOrder), $values); ?>
                <tr>
                  <th scope="row"><?= app_escape($label) ?></th>
                  <?php foreach ($roleOrder as $index => $role): ?>
                  <?php $value = $values[$index]; ?>
                  <td class="<?= $value === false ? 'rights-no' : 'rights-yes' ?><?= $role === $user['role'] ? ' is-current' : '' ?>">
                    <?php if ($value === true): ?>
                    <span aria-hidden="true">✓</span><span class="sr-only">Oui</span>
                    <?php elseif ($value === false): ?>
                    <span aria-hidden="true">—</span><span class="sr-only">Non</span>
                    <?php else: ?>
                    <span aria-hidden="true">✓</span> <span class="rights-limit"><?= app_escape($value) ?></span>
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
