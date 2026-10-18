# Bibliothèque V1 — Plan 3 : import de l'ancienne bibliothèque Joomla et bascule

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Importer dans `library.pssfp.org` les ~3 000 ressources de l'ancienne bibliothèque Joomla (DOCman + Alexandria) avec leurs fichiers, couvertures, mots-clés et redirections des anciennes URL, puis basculer `bibliotheque.pssfp.net`.

**Architecture:** extraction du dump MySQL existant en JSON par une commande Laravel (`biblio:extract-joomla`, flux ligne à ligne, aucune base MySQL) ; correspondances déclaratives (`config/biblio_migration.php`) ; import idempotent (`biblio:import`) qui lit les PDF déjà copiés sur le VPS, les envoie sur MinIO, crée documents/auteurs/mots-clés/redirections et publie d'office. Les fichiers ne transitent jamais par le poste du Chef de Projet.

**Spec :** `docs/specs/module-3-bibliotheque-v1-2026-10.md` §7 ; audit `docs/migration-joomla/audit-biblio-joomla.md` (§11.4 URL, §12.1). **Décisions du 2026-10-18 :** publication d'office des documents publics et publiés dans Joomla ; fichiers DOCman par FTP (`/Documentations`) ; nouveau type `rapport` (« Rapport et publication »).

## Global Constraints

- Branche `feat/m3-biblio-import` depuis `main`. Commits Conventional Commits FR sans accents dans le sujet + trailer `Co-Authored-By: Claude <Modèle> 5.5 <noreply@anthropic.com>` ; `git commit` seul ; jamais `--no-verify`.
- **Aucune donnée réelle dans le dépôt** : ni export JSON, ni PDF, ni extrait du dump. Les tests utilisent un mini-dump SQL **fictif** écrit à la main (`backend/tests/Fixtures/joomla/*.sql`) et des PDF générés (dompdf).
- Jamais `Storage::exists()/size()/mimeType()` sur les disques MinIO (prod sans HeadObject) ; écrire avec `put()`/`writeStream()` et vérifier le retour (disques en `throw => false`).
- Idempotence : relancer l'import ne crée aucun doublon (clé `legacy_source` + `legacy_id`, recherche `withTrashed()`).
- Contraintes existantes à respecter : slug `^[a-z0-9]+(-[a-z0-9]+)*$` ; `library_redirects.to_path` interne, sans `.`/`..`/`\`/`//` ; `external_url` exclusif de `file_path`/prix/accès non public.
- Pas de SQL brut hors justification ; `vendor/bin/pint --dirty` ; tests Pest `cd backend && vendor/bin/pest <chemin>` (PostgreSQL de dev).
- Front : next-intl pour tout texte, WCAG AA, tests verts avec et sans backend.

## Faits mesurés (2026-10-18)

| Source | Fiches (dump) | Fichiers sur le FTP | Dossier FTP |
|---|---|---|---|
| DOCman `oe179_docman_documents` | 1 752 (`storage_type=file`, `storage_path` = chemin relatif, ex. `ARRETES/….pdf`) | ~1 676 retrouvés (96 %, écarts d'encodage ’ ° Œ) | `/Documentations` (1 877 fichiers, 3,21 Go) |
| Alexandria `oe179_abbook` | 1 270 (`file` = nom, `image` = couverture, `year`, `hits`, `catid`, `state`, `alias`) | 1 248 | `/livres` (1 906 fichiers, 4,95 Go) |

Catégories : DOCman 84 (`oe179_docman_categories` + `oe179_docman_category_relations` niveau 1 = parent) ; Alexandria 27 (`oe179_abcategories`, `parent_id`, `published`). Menus (`oe179_menu.path` ↔ `link` `com_docman … slug=` / `com_abook … id=`) donnent les chemins SEF des anciennes URL. Auteurs Alexandria : `oe179_abauthor` + `oe179_abbookauth`. Copie des PDF vers `/var/lib/pssfp-biblio-import/files/{livres,Documentations}` (VPS) lancée le 2026-10-18.

---

### Task 1 : type « Rapport et publication »

- Migration additive : remplacer la contrainte `documents_type_check` pour ajouter `rapport` (down : la remettre sans, refus si des lignes `rapport` existent → les passer d'abord en `bulletin` dans `down()`).
- `Document::TYPES` : `'rapport' => 'Rapport et publication'` ; front : `library/lib/api/types.ts` (`DOCUMENT_TYPES`), `messages/fr.json` (`types.rapport.one/other`), couleur de couverture (bleu pétrole clair ou graphite, contraste ≥ 4.5:1), mosaïque de l'accueil (tuile si > 0), sitemap/robots inchangés.
- Tests : Pest (contrainte accepte `rapport`, refuse un type inconnu), Playwright (`composants` : couverture et libellé du type), routes (`rapport` reconnu par `searchParamsToFilters`).

### Task 2 : `php artisan biblio:extract-joomla {dump} {sortie.json}`

- `App\Console\Commands\ExtractJoomlaLibrary` + `App\Services\Library\Import\MysqlDumpReader` (lecture ligne à ligne, parse des `INSERT INTO \`table\` (...) VALUES (...),(...);` multi-lignes, chaînes échappées MySQL `\'` `\\` `\n`, `NULL`, nombres ; encodage UTF-8 avec repli) — **jamais** tout le fichier en mémoire (933 Mo).
- Tables lues : docman_documents, docman_categories, docman_category_relations, abbook, abcategories, abauthor, abbookauth, menu (seulement `published=1` et liens `com_docman`/`com_abook`). Préfixe de table en option (`--prefix=oe179_`).
- Sortie JSON : `{ "generated_at", "docman": {"categories":[{id, parent_id, slug, title, path}], "documents":[{id, category_id, title, slug, description, storage_path, hits, enabled, access, publish_on, created_on}]}, "alexandria": {"categories":[…], "books":[{id, catid, title, subtitle, alias, description, file, image, year, hits, state, approved, access, url, isbn, pag, authors:[…]}]}, "menus":[{path, component, category_key}] }`.
- Tests : mini-dump fictif (2 catégories DOCman hiérarchiques, 3 documents dont une apostrophe échappée et un retour à la ligne, 2 livres avec 2 auteurs, 3 menus) → JSON attendu exact ; mémoire : test qu'un dump de ~20 Mo généré en boucle est lu avec `memory_get_peak_usage` < 64 Mo.

### Task 3 : correspondances (`config/biblio_migration.php` + `App\Services\Library\Import\LegacyMapping`)

Règles (premier préfixe de chemin qui correspond, du plus spécifique au plus général) :

| Chemin DOCman / catégorie Alexandria | type | mots-clés |
|---|---|---|
| `textes-et-lois/**`, `les-collectivites-territoriales-decentralisees/**`, `autres-textes`, `instructions` | `texte_loi` | sous-thème : comptabilité, fiscalité, marchés publics, décentralisation (CTD), lois de règlement, généralités |
| `articles/**` | `article_scientifique` | thème de la sous-catégorie (comptabilité, CTD/décentralisation, économie, fiscalité, gestion budgétaire, management, marchés publics) |
| `manuels`, `mementos`, `guides`, toutes les catégories Alexandria `ouvrage-numerique/**` | `ouvrage_numerique` | thème de la catégorie (comptabilité publique, gestion budgétaire, audit et contrôle, fiscalité, finances publiques locales, recueils de textes, politiques publiques, marchés publics, économie publique, finances) |
| `reformes/seminaires-et-formations` | `ressource_pedagogique` | réformes |
| `institutions/**`, `rapports-et-publications*`, `reformes/**` (autres), `economie-1`, `fiscalite`, catégories Alexandria `reformes/**` et `rapports-et-publications` | `rapport` | institution (Banque mondiale, FMI, BEAC, BAD, CEMAC, MINFI, MINEPAT, Assemblée nationale, Chambre des comptes…) ou thème de réforme (budget-programme, audit et contrôle, comptabilité, finance publique) |

- Thésaurus : mots-clés hiérarchiques créés par l'import (`firstOrCreate` par slug), parents « Thèmes » / « Institutions » / « Types de textes » ; libellés en français propres (accents, casse phrase).
- Accès : `access_level = public` partout sauf catégorie explicitement réservée (aucune en V1) ; aucun prix.
- Année : Alexandria `year` si 1900–2100 ; sinon année à 4 chiffres trouvée dans le titre (dernière occurrence plausible) ; sinon `null` (jamais la date d'import).
- Langue : `en` si le titre contient ≥ 2 mots anglais courants (the, of, and, for, in, on, public, financial…) et aucun mot français courant ; sinon `fr`.
- Titres : décoder les entités HTML, retirer les extensions `.pdf`, normaliser les espaces ; titres TOUT EN MAJUSCULES → casse phrase en conservant sigles (MINFI, CEMAC, CTD, OHADA, BEAC, FMI, PRC, TVA, LMD…).
- Résumé : description Joomla HTML → texte (balises retirées, paragraphes conservés), vide → `null`.
- Tests unitaires de chaque fonction pure (chemin → type/mots-clés, année, langue, titre, résumé).

### Task 4 : `php artisan biblio:import {export.json} --files={dossier} [--dry-run] [--limit=N] [--only=docman|alexandria]`

- `App\Services\Library\Import\LegacyImporter` (logique) + commande mince. Par élément :
  1. ignorer si non publié dans Joomla (`enabled/state=0`, `publish_on` futur, `access` non public) → compté « ignoré » ;
  2. retrouver le fichier : chemin exact sous `--files` (`Documentations/{storage_path}` ou `livres/{file}`), sinon correspondance tolérante (NFKD sans accents, minuscules, `’'°Œ` normalisés, Latin-1 ↔ UTF-8) dans un index construit une fois ; absent → document **non créé**, consigné dans le rapport ;
  3. MD5 du fichier ; si un document existe déjà avec ce MD5 (autre source) → pas de doublon : on rattache seulement les redirections et on cumule les vues ;
  4. `Document::updateOrCreate(['legacy_source','legacy_id'], …)` (recherche `withTrashed`), slug unique dérivé de l'alias Joomla (conforme à la contrainte), `status=published`, `published_at` = date Joomla de publication/création, `approved_by` = utilisateur système « Import Joomla » (créé sans rôle de connexion), `views_count` = hits, auteurs (Alexandria) dans l'ordre ;
  5. fichier → `minio_documents` `documents/{uuid}.pdf` par `writeStream` (vérifier le retour) puis `ProcessDocumentFile` (taille, MD5, couverture) en file **sync** pendant l'import (pas de dépendance au worker) ; couverture Alexandria `image` existante sur le FTP → copiée en priorité (`/images/…` copiés à part), sinon couverture générée ;
  6. indexation Scout via `syncSearchIndex()` (sans effet avec le driver `database`).
- `--dry-run` : aucune écriture (base, MinIO), rapport identique.
- Rapport final (console + `storage/logs/biblio-import-{date}.json`) : créés, mis à jour, doublons fusionnés, ignorés (raison), fichiers introuvables (liste), erreurs.
- Lots de 100 dans des transactions ; reprise possible (`--only`, idempotence).
- Tests Pest (mini-dump fictif + PDF générés, disques simulés) : création ; relance sans doublon ; fichier introuvable consigné ; correspondance tolérante (nom avec ’ et accents Latin-1) ; doublon MD5 fusionné ; non publié ignoré ; dry-run sans écriture ; mots-clés et auteurs ; slug conforme.

### Task 5 : redirections des anciennes URL

- Générées par l'import (même transaction que le document) via `LibraryRedirect::firstOrCreate` (garde `isSafeTarget`) :
  - DOCman document : `/index.php/{chemin_menu}/{id}-{slug}` → `/documents/{nouveau_slug}` ; variante `…/file` → même fiche ; variante sans `/index.php` si l'ancien site l'accepte (à vérifier sur `bibliotheque.pssfp.net` par 3 URL réelles `curl -sI`) ;
  - `chemin_menu` : chemin du menu dont le lien vise la catégorie, sinon chemin du menu de l'ancêtre le plus proche + slugs des sous-catégories ;
  - catégorie DOCman : `/index.php/{chemin_menu}` → `/collections/{type}?mot_cle={slug}` (ou `/collections/{type}`) ;
  - Alexandria livre : `/index.php/e-books/{alias_catégorie}/{id}-{alias}` et `/index.php/e-books/{alias_catégorie}/{alias}` (les deux formes SEF existent) → fiche ; catégorie `/index.php/e-books/{alias}` → `/collections/ouvrage_numerique?mot_cle=…` ;
  - formes à query string `/index.php?option=com_docman&view=document&slug={slug}` et `…&view=book&id={id}` → fiche (forme sérialisée WHATWG).
- Tests : chaque forme générée pour le mini-dump ; aucune cible refusée par `isSafeTarget` ; relance sans doublon ; échantillon de 20 vraies anciennes URL vérifié à l'étape 7 (opérations).

### Task 6 : front — catalogue vide et couvertures

- Accueil : si `stats.total === 0`, la mosaïque est remplacée par un `StateMessage` « Le catalogue est en cours de constitution » (pas d'espace vide) ; test sans backend (stats vides simulées impossibles côté serveur → test de rendu du composant via `/dev/composants`).
- `next.config.js` : `remotePatterns` accepte `https://media.pssfp.org/pssfp-media/**`.
- Rien d'autre (le reste est déjà livré).

### Task 7 : opérations (contrôleur, pas de sous-agent)

1. Attendre la fin de la copie (`copie.done`), vérifier volumes ; copier aussi les couvertures Alexandria référencées (`/images/…`) dans `files/images`.
2. Lancer `biblio:extract-joomla` sur le poste (le dump y est déjà ; la sortie fait quelques Mo) puis envoyer le JSON au VPS (`/var/lib/pssfp-biblio-import/export.json`, 600) — le JSON ne va jamais dans le dépôt.
3. Déployer `main` (migration du type `rapport`), `MINIO_MEDIA_PUBLIC_URL=https://media.pssfp.org/pssfp-media` (vérifier qu'`Asset` et le communiqué restent corrects), `config:cache`.
4. Sauvegarde base → `biblio:import --dry-run` → lecture du rapport → `biblio:import` → vérifications (comptes, 20 fiches au hasard, téléchargements, couvertures, recherche, 20 anciennes URL contre `library.pssfp.org`).
5. Retirer `X-Robots-Tag noindex` du vhost ; vérifier `sitemap.xml`.

### Task 8 : bascule (contrôleur, après validation visuelle du Chef de Projet)

- `NEXT_PUBLIC_LIBRARY_URL=https://library.pssfp.org` dans `frontend/.env.production` + rebuild du site principal.
- `bibliotheque.pssfp.net` : DNS vers le VPS (zone `.net` — accès Cloudflare à confirmer) + vhost : 301 vers `library.pssfp.org` **en conservant le chemin**, le middleware résolvant les anciennes URL.
- Ancien serveur : lecture seule 30 jours puis arrêt ; désactivation immédiate du compte Joomla de l'ancien administrateur signalé (audit §11.6) ; suppression de `/var/lib/pssfp-biblio-import/ftp-open.lftp` et des copies locales une fois l'import validé.
