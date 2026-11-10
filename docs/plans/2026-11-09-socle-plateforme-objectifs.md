# Socle de la plateforme — objectifs et plan (2026-11-09)

**Décideur** : M. ABE ETOUMOU Anatole, Chef USI. **État** : validé le 09/11/2026 (socle d'abord, 9 groupes de menu, nettoyage des rôles après vérification en prod).

## 1. État des lieux (09/11/2026)

**En production** : site institutionnel (M1), actualités (M2), bibliothèque (M3, 2 324 documents importés), candidatures (M5), scolarité lots 1 à 4 (paiements, auditeurs, espace, reçus), LMD lots 4 à 13b-2 (PR #72 à #87).

**Reste au programme LMD** : 13c (rapprochement, contrôle de gestion), 14 (supervision, messagerie interne), 15 (archivage, reprise d'ACMA).

**Constats sur l'organisation** :

| Sujet | Constat |
|---|---|
| Navigation admin | 42 ressources et 6 pages. Le groupe « Parcours LMD » compte 24 entrées. Les libellés divergent : menu « Vie académique » mais permission « Module 4 — Scolarité » ; menu « Module 5 — Admissions » mais « Parcours LMD » sans numéro. |
| Charte admin | Couleur `#6B2FA0` et police Inter : antérieures à l'ADR-0008 (prune `#4A2E67`, Source Sans 3, DM Sans). Aucun thème Filament dédié. |
| Tableau de bord | Le compte, plus des widgets bibliothèque et campagne. Rien pour la scolarité, le LMD ou la paie. Pas de vue par rôle. |
| Rôles | 22 rôles. Doublons : `teacher` (rôle d'API, inutilisé) et `enseignant` portent tous deux le libellé « Enseignant » ; `auditor` (rôle d'API, inutilisé) ; `librarian` et `library_manager` ont le même périmètre. Noms mêlant anglais et français. Les permissions arrivent par migrations additives, lot par lot : bon principe, mais aucune matrice rôles × droits n'est publiée. |
| Journaux d'activité | 9 journaux (`candidatures`, `lmd`, `scolarite`, `documents`, `connexions`, `envois`, `roles`, `parametres`, `admin`). Pas de journal `paie` ni `bibliotheque` ; la paie écrit dans `lmd`. 28 modèles sur 85 portent `LogsActivity`, les autres sont journalisés à la main ou pas du tout. |
| Journal applicatif | Canal `single`, sans identifiant de requête ni utilisateur en contexte, sans rotation. |
| Taille des fichiers | `CandidatureResource.php` : 1 349 lignes (plafond 800). |
| Hors ligne | Rien encore. Les identifiants publics sont en UUID (ADR), ce qui aide. |

## 2. Objectifs

1. **Un back-office rangé par métier**, où chaque acteur trouve ses écrans en deux clics.
2. **Une interface à la charte**, moderne et accessible (WCAG 2.1 AA).
3. **Des rôles lisibles** : un rôle par fonction réelle, une matrice publiée, aucun doublon.
4. **Des journaux exploitables** : un journal par domaine, des libellés en français, l'avant/après des modifications, un journal applicatif horodaté et rattaché à la requête.
5. **Préparer le hors-ligne** sans le construire : décisions d'architecture prises tôt pour ne pas tout reprendre plus tard.
6. **Finir le LMD** (13c, 14, 15) sur ce socle.

## 3. Plan — chantier « Socle » (8 étapes, une PR par étape)

| # | Étape | Contenu | Risque |
|---|---|---|---|
| 1 | **Navigation par domaine** | Groupes : Admissions · Scolarité · Référentiel pédagogique · Planification · Évaluations et jurys · Enseignants et paie · Bibliothèque · Site · Administration. Libellés identiques au menu et à l'écran Rôles (`ModulePermissionMap`). Ordre, menu repliable. | Faible : aucun droit changé |
| 2 | **Thème admin à la charte** | Thème Filament (Vite) : prune `#4A2E67`, or `#D4AF6A`, ivoire, Source Sans 3 et DM Sans ; contraste vérifié. | Faible |
| 3 | **Tableau de bord par rôle** | Widgets affichés selon les droits, et badges « à traiter » dans le menu : candidatures à instruire, versements et reçus du jour, notes à saisir, pointages à valider, états de paie dans le circuit, requêtes ouvertes. | Faible, lecture seule |
| 4 | **Rôles** | Matrice rôles × droits générée depuis `ModulePermissionMap` (`docs/ops/roles.md`, et export depuis l'écran Rôles). Retrait de `teacher` et `auditor` s'ils n'ont aucun utilisateur, fusion `librarian` → `library_manager`. Migration additive, jamais de reseed. | Moyen : vérifier les comptes en prod avant |
| 5 | **Journaux d'activité** | Journaux `paie` et `bibliotheque` ; convention écrite (un journal par domaine, événement en français, propriétés avant/après) ; couverture des modèles sensibles restants ; filtres du Journal d'activité par domaine. | Faible |
| 6 | **Journal applicatif** | Canal `daily` (30 jours), contexte `request_id` et `user_id` ajouté à chaque ligne par un middleware. | Faible |
| 7 | **Découpage de `CandidatureResource`** | Formulaire, table et actions dans des classes dédiées, sur le modèle d'`AuditeurResource`. Aucun changement de comportement. | Faible, tests existants |
| 8 | **ADR-0012 hors-ligne** | Périmètre (saisie des notes, émargement, pointage), stratégie (PWA, file d'écritures locale, clés d'idempotence, résolution des conflits), conventions à appliquer dès maintenant aux nouvelles API. Aucun code. | Nul |

Ensuite : LMD 13c → 14 → 15, chacun sur sa spec.

### Avancement

| # | État |
|---|---|
| 1 | Fait — PR #88, en production le 10/11 |
| 2 | Fait — PR #90, en production le 10/11 |
| 3 | Fait — PR #93, en production le 10/11. Encart « À traiter » et pastilles ; statuts des candidatures en clair |
| 4 | Fait — PR #91, en production le 10/11. `teacher` et `auditor` retirés (aucun titulaire). **`librarian` conservé** : son périmètre est plus étroit que celui de `library_manager` (ni thésaurus, ni anciennes adresses, ni statistiques), contrairement au constat initial. Matrice réelle : `docs/ops/roles.md` |
| 5 | Fait — PR #94, en production le 10/11. Journaux `paie`, `bibliotheque`, `comptes` ; filtre par domaine (la scolarité et le LMD y manquaient) ; convention `backend/docs/journaux.md` |
| 6 | Fait — PR #94, en production le 10/11. Journal quotidien (30 jours, 0664), `X-Request-Id`, `user_id` |
| 8 | Fait — ADR-0012 (proposée) : PWA de terrain, conventions d'API à appliquer dès maintenant |
| 7 | Reste à faire : découpage de `CandidatureResource` |

**Constat de la matrice (10/11)** : le visa des états de paie (« contrôleur interne », `lmd.paie_viser`) n'est porté par aucun rôle hors `super_admin`. Sans effet aujourd'hui : le visa est désactivé en prod (vérifié le 10/11). À décider avant de l'activer : quel agent est contrôleur interne, et faut-il un rôle dédié.

## 4. Vérification

Chaque étape : tests Pest verts, revue (`filament-reviewer`, `security-reviewer` pour les rôles, `a11y-reviewer` pour le thème), déploiement seul.

## 5. Décisions à prendre

1. Validation des groupes de navigation de l'étape 1.
2. Retrait de `teacher` et `auditor`, fusion `librarian` → `library_manager`.
3. Ordre : socle d'abord (proposé), ou LMD 13c d'abord.
