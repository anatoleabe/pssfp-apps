# Bibliothèque — Plan 6 : administration (rôles, validation, outils)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** faire de l'écran « Module 3 — Bibliothèque » du back-office Filament (`api.pssfp.org/admin`) un outil complet pour l'équipe de la bibliothèque : rôles dédiés, dépôts de contributeurs, file de validation, actions en lot, thésaurus, anciennes adresses, tableau de bord.

**Architecture:** tout reste dans le panneau Filament existant et dans le système de permissions maison (`App\Support\ModulePermissionMap` : niveaux lecture/gestion par module + actions sensibles cochées à part, écran Rôles). Les nouveaux rôles et permissions arrivent par **migrations additives** (le `RolePermissionSeeder` n'est jamais relancé en prod) ; le seeder est mis à jour en parallèle pour les installations neuves.

**Tech Stack:** Laravel 11, Filament 3, spatie/laravel-permission, spatie/laravel-activitylog, Pest.

## Décisions de l'utilisateur (2026-10-19)

- Rôles retenus : **Responsable de la bibliothèque**, **Bibliothécaire**, **Contributeur**, **Consultation et statistiques**.
- **Séparer l'encaissement** des frais de dossier du rôle Bibliothécaire : nouveau rôle « Caisse — frais de dossier ».
- **Contributeurs** : comptes créés par un administrateur ; accès limité à leurs propres dépôts.

## Global Constraints

- Ne jamais relancer `RolePermissionSeeder` en prod : rôles et permissions par migrations additives au motif de `2026_10_17_110000_grant_library_permissions.php` (listes figées dans la migration, `forgetCachedPermissions()` avant/après, `down()` réversible).
- Aucun utilisateur existant ne perd un droit qu'il exerce : quiconque a le rôle `librarian` reçoit le nouveau rôle `caisse` **avant** que `candidature.mark_paid` soit retiré de `librarian`.
- `super_admin` n'a pas de contournement de Gate : il doit recevoir explicitement chaque nouvelle permission.
- 2FA TOTP obligatoire pour tout accès au panneau (inchangé, `config('pssfp.filament.require_2fa')`).
- MinIO prod : jamais `Storage::exists()/size()/mimeType()` ; tout `FileUpload` sur un disque MinIO a `->fetchFileInformation(false)`.
- Libellés d'interface en français ; Activity Log (`LogsActivity`) conservé sur les modèles sensibles.
- Mails par `App\Jobs\SendThrottledMail` (plafond hébergeur 150/h).
- Tests Pest : un utilisateur par rôle via `User::factory()->create()->assignRole(...)`, `config()->set('pssfp.filament.require_2fa', false)`, `Bus::fake([ProcessDocumentFile::class])` ; noms de fonctions de test uniques par fichier.
- Commits Conventional Commits, `git commit` lancé seul.

## Nouvelles permissions (module `bibliotheque`, actions sensibles)

| Permission | Libellé | Sens |
|---|---|---|
| `document.publish` (existe) | Publier ou dépublier un document | inchangé |
| `library.thesaurus` | Organiser le thésaurus (thèmes) | modifier un parent, renommer ou supprimer un mot-clé (créer reste dans « gestion ») |
| `library.redirects` | Gérer les anciennes adresses | écran Redirections |
| `library.stats_export` | Exporter les statistiques | export CSV du tableau de bord |
| `document.contribute` | Déposer des documents (contributeur) | créer, modifier et soumettre **ses** brouillons ; ne voit que ses dépôts |

`document.contribute` est une action « autonome » : elle doit pouvoir être cochée dans l'écran Rôles même quand le niveau du module est « aucun ».

## Rôles

| Nom technique | Libellé | Bibliothèque | Actions sensibles | Autres modules |
|---|---|---|---|---|
| `library_manager` | Responsable de la bibliothèque | gestion | `document.publish`, `library.thesaurus`, `library.redirects`, `library.stats_export` | — |
| `librarian` (existe) | Bibliothécaire | gestion | `document.publish` | **retirés** : `candidature.mark_paid` et lecture admissions |
| `library_contributor` | Contributeur bibliothèque | aucun | `document.contribute` | — |
| `library_viewer` | Consultation bibliothèque | lecture | `library.stats_export` | — |
| `caisse` | Caisse — frais de dossier | — | `candidature.mark_paid` | admissions : lecture |

`super_admin` et `admin` reçoivent les quatre nouvelles permissions bibliothèque.

---

### Task 1 : rôles et permissions

**Files:** `backend/app/Support/ModulePermissionMap.php`, `backend/app/Filament/Resources/RoleResource.php` (+ pages si besoin), `backend/database/seeders/RolePermissionSeeder.php`, nouvelle migration `backend/database/migrations/2026_10_19_200000_library_admin_roles.php`, tests `backend/tests/Feature/Library/LibraryAdminRolesMigrationTest.php`, `backend/tests/Feature/Filament/RoleResourceTest.php`.

- [ ] Tests d'abord :
  - la migration crée les 4 permissions et les rôles `library_manager`, `library_contributor`, `library_viewer`, `caisse` avec libellés, descriptions et permissions du tableau ;
  - un utilisateur `librarian` existant reçoit `caisse` et garde donc `candidature.mark_paid` ; `librarian` n'a plus `candidature.mark_paid` ni la lecture admissions ;
  - `super_admin` et `admin` ont les nouvelles permissions ;
  - relancer la migration ne duplique rien ; `down()` retire ce qu'elle a ajouté et rend `mark_paid` à `librarian` ;
  - écran Rôles : l'action autonome `document.contribute` est visible et enregistrable avec le niveau « aucun » ;
  - le seeder (installation neuve) produit le même état que la migration.
- [ ] Implémenter (`ModulePermissionMap` : nouvelles actions sensibles + marqueur « autonome » ; `toLevels`/`sensitiveActionsByModule` cohérents).
- [ ] `vendor/bin/pest --group=filament` et le test de migration verts ; commit `feat(biblio-admin): roles de la bibliotheque et caisse separee`.

### Task 2 : contributeurs

**Files:** `backend/app/Policies/DocumentPolicy.php`, `backend/app/Filament/Resources/DocumentResource.php` (+ Pages), tests `backend/tests/Feature/Filament/LibraryContributorTest.php`.

- [ ] Tests d'abord, avec un `library_contributor` :
  - accède au panneau (2FA désactivée en test), voit l'écran Documents mais **uniquement ses documents** (`uploaded_by`) ;
  - crée un document : statut brouillon, `uploaded_by` = lui ; champs `access_level` et `price_xaf` absents du formulaire (valeur `public`, pas de prix) ;
  - modifie son brouillon ; ne peut pas modifier un document « en revue », publié ou archivé, ni celui d'un autre (403) ;
  - « Soumettre en revue » sur son brouillon ; aucune action Publier/Archiver/lot ; ne peut supprimer que ses brouillons ;
  - n'accède pas aux mots-clés en écriture, ni aux autres modules ;
  - un `librarian` voit toujours tous les documents (non-régression).
- [ ] Implémenter : `DocumentPolicy` (viewAny/view/create/update/delete acceptent `document.contribute` avec propriété et statut brouillon) ; `DocumentResource::getEloquentQuery()` filtré sur `uploaded_by` quand l'utilisateur n'a pas `view_any_document` ; onglet/filtre « Mes dépôts » pour tous.
- [ ] Commit `feat(biblio-admin): depots des contributeurs`.

### Task 3 : file de validation et notifications

**Files:** `backend/app/Filament/Resources/DocumentResource/Pages/ListDocuments.php`, `DocumentResource.php`, `backend/app/Mail/LibraryDocumentSubmittedMail.php`, `backend/app/Mail/LibraryDocumentPublishedMail.php` (+ vues), tests `backend/tests/Feature/Filament/LibraryReviewQueueTest.php`.

- [ ] Tests d'abord :
  - onglets de la liste : « À valider » (statut en revue, badge avec le nombre), « Brouillons », « Publiés », « Archivés », « Tous » ; « À valider » est l'onglet par défaut quand il n'est pas vide et que l'utilisateur peut publier ;
  - soumettre en revue envoie (via `SendThrottledMail`, `Bus::fake`) un mail aux utilisateurs actifs ayant `document.publish`, sauf l'auteur de l'action ; aucun envoi si personne ;
  - publier un document déposé par un contributeur lui envoie un mail ; pas de mail quand l'auteur est le compte d'import système ;
  - nouvelle action « Renvoyer en brouillon » (en revue → brouillon, motif obligatoire, motif dans le mail au déposant et dans l'Activity Log), réservée à `document.publish`.
- [ ] Implémenter (mails en français, lien vers la fiche admin, sans donnée personnelle superflue).
- [ ] Commit `feat(biblio-admin): file de validation et notifications`.

### Task 4 : actions en lot et outils de fiche

**Files:** `DocumentResource.php`, `DocumentResource/Pages/EditDocument.php`, `backend/app/Jobs/ProcessDocumentFile.php` (option de régénération forcée), relation manager ou section « Historique » et « Anciennes adresses », tests `backend/tests/Feature/Filament/LibraryBulkAndToolsTest.php`.

- [ ] Tests d'abord :
  - lot « Changer le type », « Ajouter des thèmes », « Retirer des thèmes » (gestion requise ; documents publiés touchés seulement avec `document.publish`, sinon ignorés et comptés dans la notification) ; « Archiver la sélection » (`document.publish`) ; « Soumettre la sélection » ; index de recherche resynchronisé ;
  - action de fiche « Régénérer la couverture » (relance `ProcessDocumentFile` en mode forcé) et champ « Couverture personnalisée » (image sur le disque des couvertures, `fetchFileInformation(false)`, remplace `cover_path`) ;
  - section « Historique » sur la fiche : entrées Activity Log (date, auteur, champs modifiés), lecture seule ;
  - section « Anciennes adresses » : redirections dont la cible est `/documents/{slug}`, lecture seule, avec le nombre de visites.
- [ ] Implémenter ; commit `feat(biblio-admin): actions en lot et outils de fiche`.

### Task 5 : thésaurus

**Files:** `backend/app/Filament/Resources/MotCleResource.php` (+ page), `backend/app/Policies/MotClePolicy.php`, tests `backend/tests/Feature/Filament/MotCleResourceTest.php`.

- [ ] Tests d'abord : colonne « Chemin » (ex. « Institutions › Institutions internationales › FMI ») triable, filtre par racine (Thèmes / Institutions / Types de textes), nombre de documents publiés ; un `librarian` peut créer un mot-clé mais ni renommer, ni changer le parent, ni supprimer (403/action absente) ; `library_manager` le peut ; supprimer un mot-clé rattaché à des documents demande une confirmation qui annonce le nombre de documents.
- [ ] Implémenter ; commit `feat(biblio-admin): thesaurus en arborescence`.

### Task 6 : anciennes adresses

**Files:** `backend/app/Filament/Resources/LibraryRedirectResource.php` (+ pages), `backend/app/Policies/LibraryRedirectPolicy.php`, tests `backend/tests/Feature/Filament/LibraryRedirectResourceTest.php`.

- [ ] Tests d'abord : écran « Anciennes adresses » visible seulement avec `library.redirects` ; recherche sur l'ancienne et la nouvelle adresse, tri par visites ; création et modification de la cible (validation `LibraryRedirect::isSafeTarget`, message clair si refus) ; suppression ; Activity Log.
- [ ] Implémenter ; commit `feat(biblio-admin): ecran des anciennes adresses`.

### Task 7 : tableau de bord et export

**Files:** `backend/app/Filament/Widgets/LibraryOverview.php`, nouveaux widgets `LibraryTopDocuments.php` (plus consultés / plus téléchargés sur 30 jours), action d'export, tests `backend/tests/Feature/Filament/LibraryWidgetsTest.php`.

- [ ] Tests d'abord : compteurs par statut (brouillons, à valider, publiés, archivés) ; top 10 consultés (`views_count`) et top 10 téléchargés sur 30 jours (`document_downloads`) avec lien vers la fiche ; export CSV (titre, type, statut, vues, téléchargements 30 j et total, date de publication) réservé à `library.stats_export`, en flux, sans donnée personnelle (ni IP ni user agent) ; les widgets restent visibles avec `view_any_document`.
- [ ] Implémenter ; commit `feat(biblio-admin): tableau de bord et export`.

### Task 8 : mise en production (opérations)

- [ ] PR → CI (faux rouge connu `actualites-images`) → fusion.
- [ ] VPS : `git merge --ff-only`, `php artisan migrate --force`, `php artisan optimize`, redémarrage `pssfp-queue`, sauvegarde BD avant migration.
- [ ] Vérifier en prod : liste des rôles, permissions de `librarian` et `caisse`, que les titulaires actuels de `librarian` ont bien `caisse`.
- [ ] Indiquer à l'utilisateur quels comptes rattacher à quels rôles (sans créer de compte nominatif à sa place).
