# Bibliothèque — Plan 4 : fichiers jamais catalogués et navigation par thèmes

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** publier les PDF déposés sur l'ancien serveur Joomla sans fiche, et permettre de parcourir le fonds par thème comme l'ancien menu E-books.

**Architecture:** une commande `biblio:import-orphans` réutilise l'outillage du plan 3 (mapping, titres, stockage MinIO, journal, redirections) pour créer une fiche par PDF absent du catalogue ; chaque dossier du serveur renvoie vers une catégorie DOCman/Alexandria déjà décrite, d'où type et mots-clés. Côté site, l'API des mots-clés expose des comptes de documents publiés (descendants inclus), le filtre `mot_cle` inclut les sous-thèmes, et la bibliothèque gagne `/themes`, `/themes/[slug]`, un lien « Thèmes » dans l'en-tête et des puces de thèmes sur chaque collection.

**Tech Stack:** Laravel 11 + Pest (backend), Next.js 14 App Router + next-intl + Playwright (library).

## Contexte (relevé du 2026-10-19 sur la prod)

- Import du plan 3 : 3 022 fiches Joomla lues, 2 324 documents créés, 682 doublons MD5 fusionnés, 16 sans fichier.
- Copie des fichiers de l'ancien serveur : 3 783 fichiers sous `livres/` et `Documentations/`. 656 PDF distincts n'ont ni empreinte MD5 au catalogue ni nom quasi identique à un fichier importé (498 sous `livres/`, 158 sous `Documentations/` dont 147 décrets). Ils datent de 2008 à 2025 : ce ne sont pas des ajouts postérieurs au dump.
- La catégorie E-books « Autres » (`ouvrage-numerique/autres`, 177 livres) n'a reçu aucun mot-clé.

## Global Constraints

- Tous les textes publics en français, via `next-intl` (`t('…')`), jamais en dur dans le JSX.
- WCAG 2.1 AA : contraste ≥ 4.5:1, navigation clavier, focus visible, `aria-current` sur l'élément actif.
- Charte ADR-0008 : prune `#4A2E67`, prune profonde `#3A2452`, lavande `#A592BD`, or `#D4AF6A`, ivoire `#FAF7F2`, Cormorant Garamond (titres), Source Sans 3 (corps), DM Sans (micro-texte UI). Pas d'icône `Sparkles` dans les eyebrows.
- MinIO prod : jamais `Storage::exists()/size()/mimeType()` (HeadObject refusé).
- Pas de SQL brut sans justification ; eager loading ; UUID dans les routes publiques.
- Aucune donnée réelle (PDF, export, noms de fichiers réels de l'ancien serveur) dans le dépôt : fixtures fictives uniquement.
- Fichier exclu d'office : `Documentations/ARRETES/TEST/` (liste nominative d'auditeurs, donnée personnelle).
- Commits Conventional Commits, `git commit` lancé seul (jamais chaîné).

---

### Task 1 : thème « Autres ouvrages », règles des dossiers serveur, source `serveur`

**Files:**
- Modify: `backend/config/biblio_migration.php`
- Modify: `backend/app/Services/Library/Import/LegacyMapping.php`
- Create: `backend/app/Services/Library/Import/ServerFileRule.php`
- Create: `backend/database/migrations/2026_10_19_100000_add_serveur_to_documents_legacy_source_check.php`
- Test: `backend/tests/Unit/Services/Library/LegacyMappingTest.php`, `backend/tests/Feature/Library/DocumentModelTest.php` (ou un nouveau test de migration)

**Interfaces:**
- Produces: `LegacyMapping::SOURCE_SERVER = 'serveur'` ; `LegacyMapping::forServerFile(string $relativePath): ServerFileRule` ; `final readonly class ServerFileRule { public ?MappedCategory $category; public ?string $exclusionReason; public string $folderRule; }` (exactement l'un de `category` / `exclusionReason` non nul ; `folderRule` = clé de règle retenue, ou `''` si aucune).

- [ ] **Step 1 — tests** :
  - `forAlexandriaCategory('ouvrage-numerique/autres')` renvoie le type `ouvrage_numerique` avec le mot-clé `autres-ouvrages` (libellé « Autres ouvrages », parent `themes`).
  - `forServerFile('livres/Un livre.pdf')` → catégorie Alexandria `ouvrage-numerique/autres` (type `ouvrage_numerique`, mot-clé `autres-ouvrages`).
  - `forServerFile('livres/Livre/X.pdf')` → même règle (préfixe `livres`).
  - `forServerFile('Documentations/DECRETS/X.pdf')` → DOCman `textes-et-lois/decrets` (type `texte_loi`, mot-clé `decrets`).
  - `forServerFile('Documentations/REFORMES/AUDIT ET CONTROLE/X.pdf')` → DOCman `reformes/audit-et-controle` (type `rapport`, mots-clés `reformes`, `audit-et-controle`).
  - `forServerFile('Documentations/ARRETES/TEST/X.pdf')` → exclusion (raison non vide), la règle la plus spécifique l'emporte sur `Documentations/ARRETES`.
  - `forServerFile('livres/Programmer en Java.pdf')` → exclusion (fichier listé).
  - Comparaison insensible à la casse : `forServerFile('documentations/decrets/x.PDF')` → même règle que `Documentations/DECRETS`.
  - `forServerFile('Inconnu/X.pdf')` → `category` et `exclusionReason` nuls, `folderRule === ''`.
  - Configuration invalide (règle pointant vers une catégorie absente des règles DOCman, ou source inconnue) → exception au chargement de `LegacyMapping`.
  - Migration : un `Document` avec `legacy_source = 'serveur'` et `legacy_id = null` s'enregistre ; `legacy_source = 'autre'` est refusé par la contrainte.
- [ ] **Step 2 — lancer, constater l'échec.**
- [ ] **Step 3 — implémenter** :
  - `thesaurus` : ajouter `'autres-ouvrages' => ['label' => 'Autres ouvrages', 'parent' => 'themes']`.
  - Règles Alexandria : ajouter `'ouvrage-numerique/autres' => ['keywords' => ['autres-ouvrages']]`.
  - Nouvelle clé `server_folders` (chemin relatif à la racine des fichiers → règle), la plus spécifique (par segment) l'emporte :
    ```php
    'server_folders' => [
        'livres' => ['source' => 'alexandria', 'category' => 'ouvrage-numerique/autres'],
        'Documentations' => ['source' => 'docman', 'category' => 'textes-et-lois'],
        'Documentations/ARRETES' => ['source' => 'docman', 'category' => 'textes-et-lois/arretes'],
        'Documentations/ARRETES/TEST' => ['exclude' => 'données personnelles (liste nominative d\'auditeurs)'],
        'Documentations/DECRETS' => ['source' => 'docman', 'category' => 'textes-et-lois/decrets'],
        'Documentations/INSTITUTIONS/INSTITUTIONS_INTERNATIONALES/FMI' => ['source' => 'docman', 'category' => 'institutions/institutions-internationales/fonds-monetaire-internationale'],
        'Documentations/REFORMES/AUDIT ET CONTROLE' => ['source' => 'docman', 'category' => 'reformes/audit-et-controle'],
        'Documentations/REFORMES/COMPTABILITE' => ['source' => 'docman', 'category' => 'reformes/comptabilite'],
        'Documentations/TEXTES SUR LE CONTROLE' => ['source' => 'docman', 'category' => 'reformes/audit-et-controle'],
    ],
    'server_excluded_files' => [
        'livres/Programmer en Java.pdf' => 'hors sujet (programmation informatique)',
        'livres/Les cahiers du programmeur - Java EE 5.pdf' => 'hors sujet (programmation informatique)',
    ],
    ```
    Commentaire en tête : chaque dossier reprend la catégorie Joomla de la majorité de ses fichiers déjà catalogués (relevé du 2026-10-19).
  - `LegacyMapping::forServerFile` : normalise (UTF-8, NFC si `Normalizer` dispo, casse) puis applique `server_excluded_files`, sinon la règle de dossier la plus spécifique ; une règle `source/category` passe par `forDocmanPath` / `forAlexandriaCategory`. Validation au chargement.
  - Migration : `DROP CONSTRAINT documents_legacy_source_check` puis recréation avec `('docman','alexandria','serveur')` ; `down()` recrée l'ancienne contrainte (échoue s'il reste des lignes `serveur`, ce qui est voulu : pas de perte silencieuse). `DB::statement` justifié comme la migration d'origine (contrainte CHECK).
- [ ] **Step 4 — tests verts** (`vendor/bin/pest tests/Unit/Services/Library/LegacyMappingTest.php` + test migration).
- [ ] **Step 5 — commit** `feat(biblio): theme autres ouvrages et regles des dossiers du serveur`.

### Task 2 : commande `biblio:import-orphans`

**Files:**
- Create: `backend/app/Services/Library/Import/OrphanFileImporter.php`, `backend/app/Services/Library/Import/OrphanImportReport.php`
- Create: `backend/app/Services/Library/Import/ImportedFileStore.php` (extraction sans changement de comportement de `LegacyImporter::storeFile/upload` et de la génération de couverture)
- Modify: `backend/app/Services/Library/Import/LegacyImporter.php` (utilise `ImportedFileStore`)
- Modify: `backend/app/Services/Library/Import/RedirectPlanner.php` (`encodePath` rendu `public static`, sans autre changement)
- Create: `backend/app/Console/Commands/ImportOrphanFiles.php`
- Test: `backend/tests/Feature/Library/OrphanImportTest.php` ; `LegacyImportTest.php` doit passer **sans modification**.

**Interfaces:**
- Consumes: `LegacyMapping::forServerFile`, `LegacyMapping::SOURCE_SERVER`, `LegacyText::title/slug/year/language`, `SlugAllocator`, `RedirectWriter::ensure`, `LegacyPath`, `ImportPreflight::check`, `ImportJournal`, `ImportSources::fromDirectory`, `FileLocator::locate/key/skeleton`, `ProcessDocumentFile`.
- Produces: `biblio:import-orphans {export} {--files=} {--dry-run} {--limit=}` ; rapport JSON `storage/logs/biblio-orphelins-{YmdHis}[-dry-run].json` (0600).

Règles de sélection (dans cet ordre, chaque fichier reçoit une seule issue, consignée dans le rapport avec son chemin relatif UTF-8) :
1. Parcourir `livres/` et `Documentations/` (pas `images/`), en ignorant tout dossier ou fichier dont un segment commence par `.` (ex. `Documentations/.tmp`). Tri par chemin pour un ordre déterministe.
2. Fichier référencé par une fiche de l'export (DOCman `storage_path` sous `Documentations/`, Alexandria `file` sous `livres/`, résolu par `ImportSources`/`FileLocator::locate` comme l'import du plan 3) → ignoré, non compté comme orphelin.
3. Extension ≠ `pdf` (insensible à la casse) ou contenu ne commençant pas par `%PDF-` → `non_pdf`.
4. `forServerFile` : exclusion → `excluded` (avec raison) ; aucune règle → `unmapped`.
5. MD5 déjà présent sur un `Document` (`withTrashed`, `file_checksum_md5`) → `known` : crée seulement la redirection du chemin direct vers ce document (non supprimé).
6. Nom quasi identique à un fichier référencé (clé `FileLocator::skeleton` du nom sans extension, après retrait des suffixes ` (n)` et `-n`) → `variant` (pas de fiche ; le fichier référencé correspondant est noté).
7. MD5 identique à un orphelin déjà traité dans ce lancement → `duplicate` : redirection vers la fiche du premier.
8. Sinon → `created`.

Fiche créée (`created`) :
- `title` `['fr' => LegacyText::title(nom sans extension converti en UTF-8)]` ; `slug` via `SlugAllocator::allocate(LegacyText::slug('', $title))` ;
- `type`, `access_level`, mots-clés : depuis la `MappedCategory` ; `year` `LegacyText::year(null, $title)` ; `language` `LegacyText::language($title)` ;
- `status` publié, `published_at` = date de modification du fichier, `approved_by`/`uploaded_by` = utilisateur système de l'import Joomla (`LegacyImporter::SYSTEM_USER_EMAIL`, créé s'il manque) ; `approved_at` = maintenant ; `views_count` 0 ;
- `legacy_source` `serveur`, `legacy_id` null, `legacy_url` `'/'.RedirectPlanner::encodePath($relatif)` ;
- fichier + couverture via `ImportedFileStore` (couverture générée par `ProcessDocumentFile`, échec consigné sans faire échouer la fiche) ;
- redirection `new LegacyPath('/'.encodePath($relatif), 'serveur.fichier')` → `/documents/{slug}` ; `syncSearchIndex()`.
- Une transaction par fichier ; en cas d'échec : rollback, libération du slug, suppression des objets MinIO envoyés (comme `LegacyImporter::importItem`), issue `error` avec message.

Commande : même verrou que `biblio:import` (`storage/logs/biblio-import.lock`, flock exclusif non bloquant → message clair et code ≠ 0 si occupé), mêmes contrôles préalables (`ImportPreflight`), journal dans `storage/logs`, `--dry-run` n'écrit rien (ni BD, ni MinIO, ni redirection) mais produit le rapport, `--limit=N` borne le nombre de fiches créées. Résumé en tableau : créés, déjà au catalogue, variantes, doublons, exclus, sans règle, non-PDF, erreurs. Relance idempotente (les fichiers créés deviennent `known`).

- [ ] **Step 1 — tests** (arborescence temporaire avec faux PDF `%PDF-1.4 …` fictifs, export minimal fictif) : création publiée avec titre, type, mots-clés, `published_at` = mtime, `legacy_source` `serveur`, fichier sur le disque documents, redirection `/livres/...` ; chacune des issues 2 à 7 ; dossier caché ignoré ; dry-run sans écriture ; relance → 0 créé ; échec d'upload simulé → aucune fiche, aucun objet restant ; verrou occupé → refus.
- [ ] **Step 2 — échec constaté.**
- [ ] **Step 3 — extraire `ImportedFileStore`**, relancer `LegacyImportTest` (vert, inchangé).
- [ ] **Step 4 — implémenter `OrphanFileImporter`, le rapport et la commande.**
- [ ] **Step 5 — suite bibliothèque verte** : `vendor/bin/pest --group=library`.
- [ ] **Step 6 — commits** `refactor(biblio): stockage des fichiers importes extrait` puis `feat(biblio): import des fichiers jamais catalogues`.

### Task 3 : API — comptes par thème et filtre incluant les sous-thèmes

**Files:**
- Modify: `backend/app/Models/MotCle.php`, `backend/app/Http/Controllers/Api/Library/CatalogueController.php`, `backend/app/Http/Controllers/Api/Library/DocumentController.php`
- Test: `backend/tests/Feature/Library/CatalogueApiTest.php`

**Interfaces:**
- Produces: `GET /v1/library/mots-cles[?type=<type>]` → chaque entrée garde `slug`, `label`, `parent_slug` et ajoute `documents_count` (int) = nombre de documents **publiés distincts** rattachés au mot-clé ou à l'un de ses descendants (filtrés par `type` si fourni ; `type` validé contre `Document::TYPES`, 422 sinon). `MotCle::selfAndDescendantIds(): list<int>` (ou statique par slug).
- Produces: `GET /v1/library/documents?mot_cle=<slug>` inclut les documents rattachés aux descendants.

- [ ] **Step 1 — tests** : un document rattaché à un parent et à son enfant n'est compté qu'une fois pour le parent ; un brouillon n'est pas compté ; `?type=texte_loi` restreint ; `?type=inconnu` → 422 ; `mot_cle=institutions` renvoie un document rattaché seulement à `fmi` (petit-enfant) ; le test existant `data.0.slug` reste vrai ; pas de requête N+1 (nombre de requêtes borné, indépendant du nombre de mots-clés).
- [ ] **Step 2 — échec constaté.**
- [ ] **Step 3 — implémenter** : une requête des paires (mot-clé, document) pour les documents publiés (Eloquent / query builder sur le pivot, pas de SQL brut), agrégation des ancêtres en PHP ; descendants par parcours itératif de `parent_id`.
- [ ] **Step 4 — vert** (`vendor/bin/pest tests/Feature/Library/CatalogueApiTest.php`).
- [ ] **Step 5 — commit** `feat(biblio): comptes par theme et filtre par sous-themes`.

### Task 4 : bibliothèque — pages Thèmes, en-tête et sitemap

**Files:**
- Modify: `library/lib/api/types.ts` (`MotCle.documents_count: number`), `library/lib/api/library.ts` (`getMotsCles(options?: { type?: DocumentType })`), `library/lib/routes.ts` (`themeHref(slug, page?)`, `themesHref = '/themes'`), `library/components/layout/nav-config.ts` (+ `{ key: 'themes', href: '/themes' }` entre `collections` et `search`), `library/messages/fr.json`, `library/app/sitemap.ts`
- Create: `library/app/themes/page.tsx`, `library/app/themes/[slug]/page.tsx`, composants nécessaires sous `library/components/themes/`
- Test: `library/tests/playwright/themes.spec.ts`, `layout.spec.ts`, `seo.spec.ts` (sitemap)

Spécification :
- `/themes` (ISR `revalidate = 300`) : titre « Explorer par thème », trois sections dans l'ordre Thèmes, Institutions, Types de textes (racines du thésaurus). Chaque mot-clé enfant avec `documents_count > 0` apparaît avec son nombre de documents (format `fr`), lien vers `/themes/{slug}` ; les institutions gardent leur niveau intermédiaire (Institutions internationales → BAD, FMI…). Les mots-clés à 0 sont masqués. Mots-clés indisponibles (API en panne) → `StateMessage` d'erreur, pas d'exception.
- `/themes/[slug]` (dynamique, comme la page collection) : fil d'Ariane Accueil › Thèmes › {libellé}, titre = libellé, nombre de documents, liste paginée (`DocumentCard layout="row"`, 12 par page, `Pagination`), sous-thèmes éventuels en puces. Slug inconnu → 404. `canonical` sans paramètre ; `robots` index seulement page 1.
- En-tête (desktop + tiroir mobile) et pied de page : lien « Thèmes », actif (`aria-current="page"`) sur `/themes` et ses sous-pages.
- Sitemap : `/themes` et `/themes/{slug}` des mots-clés publiés (count > 0), avec la même tolérance aux pannes que les collections.
- Tous les textes dans `messages/fr.json` (`nav.themes`, bloc `themes.*`).

- [ ] **Step 1 — tests Playwright** (même mode que les specs existantes : routes de l'API simulées ou tests ignorés sans backend, selon le motif de `collections.spec.ts`) : lien Thèmes dans l'en-tête et le tiroir mobile ; `/themes` liste les trois groupes avec comptes ; clic sur un thème → page du thème avec documents et pagination ; slug inconnu → 404 ; sitemap contient `/themes`.
- [ ] **Step 2 — échec constaté.**
- [ ] **Step 3 — implémenter.**
- [ ] **Step 4 — `pnpm lint && pnpm typecheck && pnpm test`** (library) verts.
- [ ] **Step 5 — commit** `feat(biblio-front): navigation par themes`.

### Task 5 : bibliothèque — thèmes de chaque collection

**Files:**
- Modify: `library/app/collections/[type]/page.tsx`, `library/messages/fr.json`
- Create: `library/components/catalogue/CollectionThemes.tsx`
- Test: `library/tests/playwright/collections.spec.ts`

Spécification : au-dessus des résultats, « Thèmes de la collection » : puces des mots-clés ayant `documents_count > 0` pour ce type (`getMotsCles({ type })`), triées par nombre décroissant, 12 au plus, chacune avec son nombre ; lien `collectionHref(type, { ...filtres, mot_cle: slug, page retirée })` ; puce active (`aria-current="true"`) si `mot_cle` correspond, avec lien de retrait ; lien « Tous les thèmes » vers `/themes`. Masqué si aucun thème ou API indisponible (comme le filtre actuel).

- [ ] **Step 1 — tests Playwright** : puces visibles avec comptes sur une collection ; clic → URL avec `mot_cle` et puce active ; navigation clavier (Tab atteint les puces, focus visible).
- [ ] **Step 2 — échec constaté.**
- [ ] **Step 3 — implémenter.**
- [ ] **Step 4 — `pnpm lint && pnpm typecheck && pnpm test`** verts.
- [ ] **Step 5 — commit** `feat(biblio-front): themes de chaque collection`.

### Task 6 : mise en production (opérations, pas de code)

- [ ] PR vers `main`, CI verte (faux rouge connu : `actualites-images` du site principal), fusion.
- [ ] VPS (`sudo -u pssfp`) : `git merge --ff-only`, `php artisan migrate --force`, `php artisan optimize`, redémarrage `pssfp-queue`.
- [ ] Relancer `biblio:import` (idempotent) pour rattacher « Autres ouvrages » aux 177 livres de la catégorie Autres ; vérifier « 0 créé ».
- [ ] `biblio:import-orphans … --dry-run` sous `nohup` ; relire le rapport (créés ≈ 650, exclus = liste d'auditeurs + 2 livres Java, sans règle = 0, erreurs = 0) ; puis import réel.
- [ ] Rebuild `library` (`rm -rf .next/cache && pnpm build`) + `pm2 reload pssfp-library`.
- [ ] Vérifier : `/themes`, un thème, une collection avec puces, une fiche importée depuis le serveur (titre, couverture, téléchargement), une URL directe `/livres/<fichier>.pdf` → fiche, sitemap.
