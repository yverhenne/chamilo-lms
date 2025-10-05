Fiche Utilisateur (user_profile)
===============================

Présentation
------------

Le plugin « Fiche utilisateur » ajoute une fiche synthétique et configurable pour chaque utilisateur, ainsi que des outils de suivi pédagogique/administratif, d’affectation des enseignants, de gestion d’entreprise d’accueil et d’évaluation mensuelle. Il s’intègre au système de tickets de Chamilo pour faciliter les relances et le suivi.

Fonctionnalités clés
--------------------

- Champs personnalisés par catégories
  - Création de champs de type « texte » ou « date » regroupés par catégories.
  - Ordonnancement par glisser‑déposer, édition en masse, suppression en cascade d’une catégorie (supprime aussi ses champs et leurs valeurs).
  - Option « Afficher dans le suivi » par champ (`include_tracking`) pour afficher le champ dans la vue « suivi pédagogique » ou « suivi administratif » selon le cas.
  - Apparition des champs personnalisés dans les formulaires de création/édition d’utilisateur (via hooks), avec stockage des valeurs par utilisateur.

- Suivi pédagogique et administratif
  - « Suivi pédagogique » (`tracking.php`) : liste des utilisateurs avec indicateurs d’agenda (cette/semaine prochaine), dernier accès, temps passé la semaine dernière, progression moyenne par session, enseignants affectés, entreprise d’accueil, et champs « suivis » avec cases « complété » (persistées dans la table des valeurs).
  - « Suivi administratif » (`tracking_untracked.php`) : mêmes informations mais affiche les champs non marqués « suivis » (c.-à-d. `include_tracking = 0`).
  - Actions rapides par utilisateur :
    - « Relancer » l’agenda (envoi d’un message interne à l’utilisateur).
    - « Avertir » les enseignants (création d’un ticket par enseignant ou envoi de messages si la création de ticket échoue ou n’est pas configurée).
    - « Synthèse des suivis » (vue + export PDF des tickets liés à l’utilisateur).
    - Accès direct à la fiche utilisateur.

- Affectation des enseignants
  - Écran dédié pour affecter/désaffecter un ou plusieurs enseignants par utilisateur (`teachers.php`).
  - Stockage normalisé dans `plugin_user_profile_user_teacher` (pivot). Migration automatique depuis l’ancienne colonne CSV `plugin_user_profile_teachers.teacher_ids` si présente.

- Gestion « Fiche entreprise » et liaison utilisateur → entreprise
  - Gestion d’une fiche entreprise par portail (`company.php`, `company_list.php`) avec nom commercial, raison sociale, adresse et contacts (tuteur, directeur, autre contact).
  - Liaison 1→1 utilisateur/entreprise (`plugin_user_profile_user_company`) et affichage du nom commercial dans les vues de suivi.
  - Compatibilité ascendante: prise en charge des anciens noms de colonnes en français (ex. `nom_commercial`, `mail_tuteur`, etc.).

- Évaluation mensuelle
  - Saisie/édition par mois/année et commentaire (`monthly_evaluation.php`) par administrateur de session, enseignant ou plateforme (validation réservée à l’admin/session admin dans `admin_monthly_evaluation.php`).
  - Validation d’une évaluation: envoi d’un e‑mail au tuteur avec PDF en pièce jointe et création d’un ticket (si configuré) dans le projet/catégorie de suivi apprenant.
  - Prévisualisation web et export PDF de l’évaluation (`preview_monthly_evaluation.php` et `preview_monthly_evaluation_pdf.php`).

- Contrôle rapide
  - « Speed check » (`speed_check.php`) liste les utilisateurs:
    - sans événement d’agenda la semaine prochaine,
    - sans ticket de suivi (projet/catégorie configurés) la semaine précédente,
    - ou sans commentaire mensuel validé le mois dernier,
    avec actions contextuelles (ex. relance agenda).

- Exports
  - Fiche utilisateur PDF (`pdf.php`) et Excel (`xls.php`).
  - Rapport de temps Excel XLSX (`time_report_xls.php`) basé sur la date d’inscription → aujourd’hui.
  - Export ZIP global (`export_zip.php`) qui regroupe : rapport de temps PDF, fiche utilisateur PDF, synthèse des suivis PDF et export MyStudents XLS (et, si disponible, le PDF « student_follow_export »).

Pages et accès
--------------

- Administration des champs/catégories: `plugin/user_profile/admin.php` (plateforme/session admin). Édition en masse: `plugin/user_profile/fields_edit.php`.
- Affectation des enseignants: `plugin/user_profile/teachers.php` (plateforme/session admin).
- Suivi pédagogique: `plugin/user_profile/tracking.php` (plateforme/session admin).
- Suivi administratif: `plugin/user_profile/tracking_untracked.php` (plateforme/session admin).
- Contrôle rapide: `plugin/user_profile/speed_check.php` (plateforme/session admin).
- Synthèse des suivis: `plugin/user_profile/resume_tracking.php` + export PDF `resume_tracking_pdf.php` (accès de suivi standard: administrateurs, responsables, enseignants, etc.).
- Fiche utilisateur: `plugin/user_profile/view.php` (utilisateur connecté; `id` optionnel pour afficher un autre utilisateur si autorisé).
- Exports fiche: `plugin/user_profile/pdf.php`, `plugin/user_profile/xls.php`.
- Export temps: `plugin/user_profile/time_report_xls.php`.
- Export global ZIP: `plugin/user_profile/export_zip.php`.
- Entreprise: liste/fiche `plugin/user_profile/company_list.php` (lecture pour enseignants; édition pour plateforme/session admin/DRH), édition `plugin/user_profile/company.php`.
- Évaluation mensuelle: saisie/édition `plugin/user_profile/monthly_evaluation.php` (plateforme/session admin/enseignant), gestion/validation `plugin/user_profile/admin_monthly_evaluation.php` (plateforme/session admin).
- API AJAX: `plugin/user_profile/ajax.php` (CSRF obligatoire, restreint aux administrateurs de plateforme/session) pour: coche « complété », relance agenda, avertissement enseignants (tickets/messages), sauvegarde des enseignants.

Installation
------------

1) Activer le plugin dans `configuration.php`:

```
$_configuration['plugin_user_profile_enabled'] = true;
```

2) Créer le schéma (une fois par portail) :
- Via l’activation du plugin dans l’interface d’administration des plugins, ou
- En exécutant `plugin/user_profile/install.php` connecté en administrateur plateforme.

3) Optionnel: affecter le plugin à la zone `pre_footer` pour proposer un lien vers la fiche (`view.php`) dans les pages de la plateforme.

Configuration (tickets et options)
----------------------------------

Le formulaire de réglages (Administration → Plugins → user_profile) enregistre une configuration par portail (`plugin_user_profile_configuration`) :

- `email` (facultatif) : e‑mail de contact générique.
- `id_ticket_communication` et `category1` : projet et catégorie utilisés par l’action « Avertir » (création d’un ticket par enseignant affecté). Si non configuré ou en cas d’échec, envoi de messages internes aux enseignants.
- `id_ticket_learner_tracking` et `category2` : projet et catégorie de « suivi apprenant » utilisés pour :
  - colorer l’indicateur « Suivi » dans `tracking.php` (verts si un ticket du projet/catégorie a été créé la semaine dernière),
  - alimenter les listes du « contrôle rapide » mode « suivi ».

Modèle de données (par portail)
------------------------------

- `plugin_user_profile_category` : catégories (nom, ordre).
- `plugin_user_profile_field` : champs (nom, type `text|date`, catégorie, ordre, `include_tracking`).
- `plugin_user_profile_value` : valeurs par utilisateur + indicateur `checked` (complété).
- `plugin_user_profile_teachers` : stockage historique (CSV) des enseignants par utilisateur.
- `plugin_user_profile_user_teacher` : table pivot normalisée (utilisateur ↔ enseignant).
- `plugin_user_profile_comment` : commentaires libres (auteur, date, visibilité) liés à un utilisateur.
- `plugin_user_profile_entreprise` : fiche entreprise (nom commercial, raison sociale, adresse, contacts).
- `plugin_user_profile_user_company` : liaison utilisateur → entreprise.
- `plugin_user_profile_configuration` : réglages décrits ci‑dessus.
- `relation_student_ticket` : liaisons utilisateur ↔ ticket (filtrées par `access_url_id`).
- `user_profile_monthly_evaluation` : évaluation mensuelle (étudiant, auteur, mois, année, commentaire, validation).

Multi‑URL
---------

Toutes les requêtes filtrent par `access_url_id` : chaque portail (multi‑URL) dispose de ses propres catégories, champs, valeurs, entreprises, liaisons, évaluations et configuration. Aucune donnée n’est partagée entre portails.

Hooks et cycle de vie
---------------------

- `HookCreateUser` / `HookUpdateUser` : persistance des valeurs de champs depuis les formulaires utilisateur.
- Nettoyage à la suppression d’un utilisateur : purge des valeurs, commentaires, liaisons enseignants, relation tickets, évaluations mensuelles, entreprise liée.
- `install.php` / `uninstall.php` : création/suppression idempotente du schéma. L’uninstall supprime toutes les tables du plugin (opération destructive).
- Migrations automatiques : ajout des colonnes manquantes (`include_tracking`, `checked`, `author_id`, etc.), création d’index utiles, migration CSV → pivot pour les enseignants, compatibilité des colonnes « entreprise » en français.

Sécurité et i18n
----------------

- Toutes les actions d’écriture utilisent des jetons CSRF et les pages sensibles requièrent les droits admin (plateforme/session) ou enseignants selon les cas.
- Échappement systématique des sorties HTML pour éviter les injections XSS ; les messages de tickets se limitent à un sous‑ensemble sûr.
- Traductions disponibles dans `plugin/user_profile/lang/`. Les libellés de catégories/champs tentent d’être traduits (retour au texte saisi si la clé est absente).

Liens utiles
------------

- Administration des champs: `plugin/user_profile/admin.php`
- Édition en masse: `plugin/user_profile/fields_edit.php`
- Affectation enseignants: `plugin/user_profile/teachers.php`
- Suivi pédagogique: `plugin/user_profile/tracking.php`
- Suivi administratif: `plugin/user_profile/tracking_untracked.php`
- Contrôle rapide: `plugin/user_profile/speed_check.php`
- Fiche utilisateur: `plugin/user_profile/view.php`

