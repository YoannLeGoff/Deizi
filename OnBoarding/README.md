# OnBoarding — présentation de Deizi

Support de présentation de Deizi aux futurs utilisateurs, organisé par niveau : tous les membres, managers, organisateurs, logisticiens, administrateurs. Il existe en **français** et en **anglais**.

## Contenu

| Fichier | Usage |
|---|---|
| `presentation.html` | Présentation à projeter : l’ouvrir dans un navigateur (double-clic). Flèches ← → ou espace pour avancer, **F** plein écran, **P** imprimer. L’adresse garde le numéro de la diapositive (`#7`). |
| `presentation-en.html` | La même présentation en anglais. |
| `Deizi-presentation.pdf`, `Deizi-presentation-en.pdf` | Les deux présentations en PDF (34 pages), pour les envoyer ou les projeter sans navigateur ; importables dans Keynote. |
| `script-video.md` | Script de lecture pour une vidéo avec avatar : un texte par diapositive, avec la durée estimée (22 minutes environ au total) et une version courte pour les artistes. |
| `visuels/` | Les copies d’écran en français, en PNG (1440 px de large), réutilisables ailleurs ; `visuels/en/` les mêmes en anglais. L’agenda sur téléphone existe dans les quatre langues (`34-telephone-fr.png`, `-en`, `-br`, `-es`). |

## Déroulé (34 diapositives, environ 37 minutes)

1. Couverture, pourquoi Deizi, les cinq rôles, le déroulé
2. **Tous les membres** : connexion, choix de la langue (seuls les libellés sont traduits), agenda (ordinateur et téléphone, annonce en haut, filtres repliés), présence (Présent, Absent, Je ne sais pas ; « Sans réponse » ; qui vient, par sous-section) et réponse aux sondages, abonnement iCal et liens partagés, application à installer sur le téléphone, trombinoscope (photo, portrait, titres, responsable en tête), planning annuel (vues avancées)
3. **Managers** : les rendez-vous de section hors logistique (stages de pupitre, répétitions de sous-section), les annonces à leur section (accueil et notification), photos et identifiants des membres de leur périmètre, tableau de présence
4. **Organisateurs** : sondages (création, résultats, tableau détaillé), feuille de route et son lien public, comptes et journal d’audit (connexions et changements de profil compris)
5. **Logisticiens** : liste par saison et statut logistique, contrat / frais / cachet, devis et lien public, rapport
6. **Administrateurs** : configuration et statuts, langues et traductions (corriger à l’écran, déléguer en CSV, créer une variante), fiche d’un membre (titre, responsable de section, onglet journal d’audit), matrice des droits
7. Pour démarrer (quatre gestes) et questions

Pour un public d’artistes seulement, s’arrêter après la partie « Tous les membres » et passer à « Pour démarrer » (diapositive 33).

## À savoir

- Les copies d’écran viennent d’une **installation de démonstration** : les dates et les lieux sont ceux de Kerlenn Pondi, mais les membres (noms, prénoms, identifiants), leurs photos (avatars dessinés), les téléphones, les liens et les montants sont **fictifs**. La présentation peut donc être diffusée librement.
- Dans l’application, la présentation est consultable par les **personnes connectées** via `onboarding.php` (bouton « Voir la présentation » de la page **Mises à jour**) : en français pour les membres qui utilisent le français, une de ses variantes ou le breton, en anglais pour les autres ; le bouton « PDF » (`onboarding.php?f=pdf`) suit la même règle.
- Dans la version anglaise, les pages de gestion (managers, organisateurs, logisticiens, administrateurs) apparaissent encore en français, le temps de leur traduction ; une note le signale. Les refaire en anglais une fois traduites.
- Le script vidéo (`script-video.md`) n’existe qu’en français. Le dossier n’est jamais servi directement (bloqué par `router.php` et `.htaccess`) : le publier avec l’application, avec son `.htaccess`.
- Les polices viennent de Google Fonts ; sans connexion Internet, la présentation HTML s’affiche avec Arial (le PDF garde les bonnes polices).
- Pour refaire les captures après des changements dans l’application, demander à Claude de « régénérer les visuels OnBoarding ».
