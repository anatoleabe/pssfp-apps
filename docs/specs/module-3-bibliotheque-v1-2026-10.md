# Module 3 — Bibliothèque virtuelle V1 : conception (octobre 2026)

> Statut : **proposé**, à valider par le Chef de Projet.
> Complète `docs/specs/module-3-bibliotheque.md` (spec v1.0 du 5 mai 2026) sans la remplacer : ce document fixe le **périmètre de la première mise en ligne** et les choix techniques qui en découlent. En cas de conflit, ce document l'emporte pour la V1.
> Sources : CDC biblio v2.0, `docs/data-model.md` §8 et §11, `docs/api-contract.md` §8, `docs/migration-joomla/audit-biblio-joomla.md`, `docs/specs/module-9-payment-phase2.md`, ADR-0001/0003/0005/0008.

## 1. Objectif

Remplacer l'ancienne bibliothèque Joomla 3.4 (`bibliotheque.pssfp.net`, ~3 021 ressources DOCman + Alexandria) par une bibliothèque moderne, rapide et lisible sur téléphone, **puis éteindre le Joomla**. L'architecture doit accueillir sans refonte la vente de certains ouvrages via Mobile Money (Module 9).

Critère de succès V1 : les ressources de l'ancien site sont consultables sur le nouveau, aucune ancienne URL publique ne renvoie de 404, le bibliothécaire gère le fonds depuis Filament, et l'ancien serveur peut être arrêté.

## 2. Découpage et périmètre

| # | Sous-projet | V1 | Plan |
|---|---|---|---|
| 1 | Catalogue et administration (modèle, Filament, stockage, indexation) | oui | Plan 1 |
| 2 | Bibliothèque publique (Next.js `/library`) | oui | Plan 2 |
| 3 | Migration de l'existant + bascule | oui | Plan 3 |
| 4 | Comptes auditeurs/enseignants, cours filigranés, espace personnel | non | ultérieur |
| 5 | Accès payant Mobile Money | non | ultérieur (Module 9) |

**Hors V1** : connexion des lecteurs, favoris, historique, filigrane, OCR, recherches sauvegardées, export BibTeX/RIS groupé, enrichissement DOI, vente. Chacun de ces points s'appuie sur ce que la V1 met en place sans la modifier.

## 3. Décisions

| Sujet | Décision V1 | Raison |
|---|---|---|
| Documents non publics | **Fiche visible, fichier bloqué** (« Réservé aux auditeurs — bientôt disponible ») | Validé le 2026-10-17. Le fonds reste trouvable ; le même mécanisme sert ensuite au payant. |
| Documents payants | Prix saisissable (`price_xaf`), badge « Payant », bouton « Bientôt disponible à l'achat ». Aucun prix pré-rempli à l'import. | Le paiement arrive au sous-projet 5 ; aucune promesse de vente avant. |
| Direction visuelle | **Combinaison A + B + C** des maquettes du 2026-10-17 (§6.1) | Validé le 2026-10-17. |
| Domaine | Canonique **`bibliotheque.pssfp.org`** ; à la bascule, `bibliotheque.pssfp.net` pointe sur le VPS et redirige en 301 (en une seule étape, chemins anciens résolus). Configurable par variable d'environnement. | Cohérent avec la décision du 2026-07-04 (`pssfp.org` canonique). **À confirmer** — si le Chef de Projet préfère garder `.net`, seul l'environnement change. |
| Application | App Next.js séparée `/library` (ADR-0001), API Laravel existante | Déjà scaffoldée, déploiement indépendant. |
| Recherche | Meilisearch via Scout (ADR-0003), interrogé **uniquement via l'API Laravel** ; repli PostgreSQL (`ILIKE` insensible aux accents sur titre, résumé et auteurs ; pas d'extension `pg_trgm`) si Meilisearch est indisponible | Facettes et tolérance aux fautes ; pas de dépendance dure. |
| Spécialité / promotion | `specialite` = code texte des spécialités déjà définies (`/v1/reference/specialites`) ; `promotion` = entier 1..n | Pas de tables `specialites`/`promotions` aujourd'hui ; inutile d'en créer. |
| Auteurs | Table `document_authors` simplifiée : `author_name`, `author_affiliation`, `order` | Il n'existe pas de table `enseignants` ; les liens vers des comptes viendront avec le sous-projet 4. |
| Tables Phase II `transactions`/`subscriptions` | **Non créées en V1** | Rien n'en dépend ; le sous-projet 5 les crée avec leur logique. |

## 4. Modèle d'accès (prêt pour le paiement)

Une seule porte de décision côté backend : `App\Services\Library\DocumentAccess::decide(?User $user, Document $doc): AccessDecision`.

`AccessDecision` porte `allowed: bool` et `reason` ∈ `public`, `reserved`, `paid`, `unpublished`, `external`.

Règles V1, dans l'ordre :

1. Document non publié → `unpublished` (refus ; 404 côté public).
2. `external_url` renseignée → `external` (pas de fichier chez nous ; le bouton ouvre le lien).
3. `price_xaf` non nul → `paid` (refus en V1).
4. `access_level` ≠ `public` → `reserved` (refus en V1, aucun compte lecteur).
5. Sinon → `public` (autorisé).

Le libellé affiché (`access_mode` : `libre` / `reserve` / `payant`) est dérivé de ces champs et exposé par l'API ; il sert aussi de facette de recherche.

**Extension prévue** : le sous-projet 4 ajoute la vérification du rôle de l'utilisateur à l'étape 4 ; le sous-projet 5 ajoute à l'étape 3 la vérification d'un droit d'accès acheté (`document_entitlements`). Aucun appelant ne change : contrôleur de téléchargement, API et front lisent tous `AccessDecision`.

## 5. Sous-projet 1 — Catalogue et administration

### 5.1 Données

`documents` reprend `docs/data-model.md` §8 avec les ajouts de l'audit (§12.1) et ceux nécessaires à la migration :

- `type` : 10 valeurs — `these`, `memoire`, `article_scientifique`, `texte_loi`, `cours`, `acte_conference`, `rapport_stage`, `bulletin`, `ressource_pedagogique`, `ouvrage_numerique`.
- Ajouts : `price_xaf` (BIGINT NULL, FCFA entiers), `external_url` (TEXT NULL), `cover_path` (TEXT NULL, image publique), `legacy_source` (`docman` / `alexandria` NULL) + `legacy_id` (INT NULL) avec contrainte UNIQUE sur le couple, `specialite` (TEXT NULL), `promotion` (SMALLINT NULL).
- `title`, `subtitle`, `abstract` en JSONB traduisible (`spatie/laravel-translatable`, comme `Article`) ; `uuid` via `HasUuids` ; slug unique ; soft delete.
- Retirés par rapport au §8 : `promotion_id`, `specialite_id`, `thumbnail_id`, `keywords` JSONB (remplacé par les mots-clés), `has_ocr` (OCR hors V1).

Tables associées : `document_authors` (simplifiée, §3), `mots_cles` (thésaurus hiérarchique, `parent_id`) + pivot `document_mot_cle`, `document_downloads` (journal), `search_logs` (requêtes, utile pour repérer les recherches sans résultat), `library_redirects` (`from_path` UNIQUE, `to_path`, `status_code`, `hits`).

**Journaux et données personnelles (RGPD)** :

| Table | Finalité | Données | Conservation |
|---|---|---|---|
| `search_logs` | Repérer les recherches sans résultat pour enrichir le fonds et le thésaurus | Requête, filtres, nombre de résultats, date — **pas d'adresse IP** | 90 jours |
| `document_downloads` | Statistiques de téléchargement et repérage des abus | Document, compte éventuel, IP, navigateur, date | 180 jours |

Purge quotidienne à 02:00 par `model:prune` (modèles `Prunable`, `routes/console.php`). Les compteurs agrégés (`downloads_count`, `views_count`) ne sont pas touchés.

### 5.2 Fichiers

- PDF sur le disque privé MinIO `pssfp-documents` (déjà défini). MD5 et taille calculés à l'envoi et stockés en base.
- Téléchargement : `GET /v1/library/documents/{uuid}/download` → `DocumentAccess` → journalisation → redirection 302 vers une URL signée de 30 minutes. **Jamais de `Storage::exists()`** : la clé de prod n'autorise pas `HeadObject` (cf. mémoire projet) ; on se fie aux métadonnées en base.
- Couverture : job en file Redis qui rend la première page en JPEG 600 px (`pdftoppm` de poppler-utils, à installer sur le VPS), stockée sur le disque public média. Sans image, le front affiche une couverture typographique colorée selon le type.

### 5.3 API publique (`/v1/library`, lecture seule, sans authentification en V1)

| Méthode | Route | Rôle |
|---|---|---|
| GET | `/library/stats` | Compteurs par type pour l'accueil |
| GET | `/library/documents` | Liste paginée (20) filtrable : type, spécialité, promotion, année (plage), langue, accès, mot-clé ; tri récent / ancien / titre / populaire (consultations de fiche, `views_count`) |
| GET | `/library/documents/{slug}` | Fiche complète + `access` (décision) + documents liés |
| GET | `/library/documents/{uuid}/download` | Téléchargement contrôlé (§5.2) |
| GET | `/library/search` | Recherche Meilisearch avec facettes et extraits surlignés ; journalise dans `search_logs` |
| GET | `/library/mots-cles` | Thésaurus pour les filtres |
| GET | `/library/redirects?path=` | Résolution d'une ancienne URL (utilisée par le middleware du front) |

Identifiants publics : `uuid` et `slug` uniquement, jamais l'`id` interne. Limitation de débit par IP (limiteurs nommés `library`, `library-search`, `library-download`, `library-view`) : 120/min sur tout le groupe, 60/min sur `search`, 30/min sur `download`, 20/min sur `view` ; plafonds réglables (`LIBRARY_RATE_LIMIT`, `LIBRARY_SEARCH_RATE_LIMIT`, `LIBRARY_DOWNLOAD_RATE_LIMIT`, `LIBRARY_VIEW_RATE_LIMIT`) et IP exemptées (`LIBRARY_RATE_LIMIT_EXEMPT_IPS`, séparées par des virgules).

### 5.4 Indexation

`Document` utilise `Searchable` (Scout, `SCOUT_QUEUE=true`). Seuls les documents publiés sont indexés. Attributs : titre, sous-titre, résumé, auteurs, mots-clés ; filtrables : type, spécialité, promotion, année, langue, `access_mode`, mots-clés ; triables : année, `published_at`, `views_count`, `downloads_count`. Commande planifiée hebdomadaire `scout:import` + contrôle de cohérence (ADR-0003). Ajout de `config/scout.php`.

### 5.5 Administration Filament

- `DocumentResource` (rôles `librarian` et admin) : envoi du PDF, métadonnées, auteurs (répéteur ordonné), mots-clés, niveau d'accès, prix (aide : « non vendu tant que le paiement en ligne n'est pas actif »), lien externe.
- Circuit `draft` → `in_review` → `published` (`approved_by`, `approved_at`), `archived`.
- Filtres « Importé de l'ancien site » et « À vérifier », action groupée « Publier ».
- `MotCleResource` (arborescence du thésaurus).
- Widget de tableau de bord : documents par type, téléchargements sur 30 jours, 10 recherches sans résultat les plus fréquentes.
- Activity Log Spatie sur `Document` et `MotCle`. Permissions ajoutées par **migration additive** (ne jamais relancer `RolePermissionSeeder` en prod).

## 6. Sous-projet 2 — Bibliothèque publique

### 6.1 Accueil (combinaison A + B + C)

1. **En-tête** : marque « PSSFP · Bibliothèque », liens Collections / Recherche avancée, lien retour vers `pssfp.org`. (« Se connecter » n'apparaît qu'avec le sous-projet 4.)
2. **Héros (A + B)** : sur-titre « {n} documents · finances publiques » en or, grand titre Cormorant, barre de recherche large, suggestions de recherches (B) et puces de types.
3. **Mosaïque des collections (C)** : grande tuile Mémoires, tuiles Thèses, Législation, Ouvrages, Cours (badge Réservé) avec compteurs réels. La tuile or **« Ouvrages premium »** n'apparaît que si au moins un document a un prix, avec la mention « bientôt disponibles ».
4. **Nouveautés (A)** : 6 derniers documents publiés, couverture + type + badge d'accès.
5. **Les plus lus (C)** : bandeau défilant de couvertures (par `views_count` : consultations de fiche, compteur historique Joomla repris à la migration).

### 6.2 Autres pages

| Route | Rendu | Contenu |
|---|---|---|
| `/collections/[type]` | Dynamique (lecture de `searchParams`), appels API dans le cache de données 5 min (mots-clés 1 h) — pas d'ISR de la page | Mise en page B : filtres à gauche (tiroir sur mobile), résultats, pagination, tri ; page au-delà de la dernière → état « page inexistante » avec lien vers la page 1 ; `page` > 500 → 404 |
| `/recherche` | Coquille serveur + résultats côté navigateur | Mise en page B : état dans l'URL, facettes avec compteurs, extraits surlignés, message utile si zéro résultat ; `noindex, follow`, exclue du sitemap et interdite dans `robots.txt` |
| `/documents/[slug]` | ISR 5 min (`generateStaticParams` → `[]`) | Couverture, métadonnées, auteurs, résumé, mots-clés cliquables, bouton d'accès selon `access` (Télécharger / Réservé / Payant bientôt / Consulter le lien), citation APA copiable, documents liés, JSON-LD |
| `/a-propos` | Statique (`force-static`) | Présentation, règles d'accès (Libre / Réservé / Payant — bientôt), contact (page contact du site principal) |
| `sitemap.xml` | ISR 1 h | Accueil, à propos, collections non vides, fiches publiées (`lastModified` = `published_at`, plafond 5 000) ; sitemap réduit si l'API ne répond pas (jamais d'échec du build) |
| `robots.txt` | Statique | `Allow: /`, `Disallow: /dev/` et `/recherche`, lien du sitemap |

**Décisions du 2026-10-17** :

- **Recherche rendue côté navigateur** : `/recherche` est une coquille serveur (en-tête, formulaire, portée) ; les résultats sont demandés à `GET /v1/library/search` par le navigateur. La limite de débit par IP et les journaux de recherche portent ainsi sur le visiteur, pas sur le serveur Next. Pas d'ISR ni de frontière d'erreur sur cette page : l'échec de l'API s'affiche dans la page (`role="alert"`, Réessayer ; message dédié sur 429).
- **Consultations par balise** : la fiche (ISR) ne compte rien côté serveur ; un composant client envoie `POST /v1/library/documents/{uuid}/view` (`navigator.sendBeacon`, repli `fetch keepalive`) à l'affichage.

- **`/collections/[type]` n'est pas ISR** : la page lit `searchParams`, Next la rend donc à la demande (`ƒ` au build) ; un export `revalidate` y serait sans effet (retiré). La charge sur l'API est contenue par le cache de données de Next (`revalidate` 300 s sur la liste, 3600 s sur les mots-clés), par URL d'API.

**Anciennes URL** : `library/middleware.ts` soumet à `/v1/library/redirects` tout chemin que l'app ne sert pas (y compris `/index.php/...`, `/index.php?…`, `.../file`, `....html`) — les routes de l'app et les fichiers statiques usuels en sont exclus — et répond en 301 (302 si demandé) vers un chemin interne du même site uniquement (`library/lib/redirects.ts` : commence par `/`, ni `//` ni `/\`, aucun caractère de contrôle, puis même origine une fois l'URL résolue ; le backend refuse aussi un tel `to_path` à l'écriture). Réponses mémorisées 10 min par processus (introuvables compris) ; API en échec ou délai dépassé (2 s) → suite normale (404), jamais d'erreur.

**Décision du 2026-10-17 — anciennes URL Joomla** :

- Les anciennes URL de téléchargement (`…/file`) redirigent vers la fiche `/documents/{slug}`, **pas** vers l'API de téléchargement : le mode d'accès s'affiche sur la fiche, et aucune URL signée n'est figée dans une redirection.
- Les URL à query string `/index.php?option=…` sont stockées dans `library_redirects.from_path` **avec leur query string**, telle que reçue (le middleware envoie `pathname + search` pour `/index.php` seulement ; ailleurs la query string est ignorée). L'import du Plan 3 génère ces entrées sous la même forme.

### 6.3 Exigences

Mobile d'abord (320 px sans défilement horizontal), charte ADR-0008, composants partagés `@pssfp/ui`, textes via `next-intl` (aucun texte en dur), WCAG 2.1 AA, Lighthouse ≥ 85 sur les quatre axes, liste < 800 ms et recherche < 500 ms côté API.

- **Pages ISR et API indisponible** : une page ISR ne met jamais en cache un état d'erreur. Toute donnée indispensable passe par `unwrapOrThrow` (`library/lib/api/unwrap.ts`) : 404 → `notFound()`, tout autre échec → exception. Pendant une revalidation, Next conserve alors la dernière page valide ; au premier rendu, `app/error.tsx` affiche une page « bibliothèque momentanément indisponible » utile (recherche, Réessayer, liens vers le site principal). Seule exception : le prérendu de `next build` sans API (CI) rend une page de repli au lieu d'échouer, remplacée à la première revalidation réussie. Un état vide (`role="status"`) reste distinct d'une erreur.

## 7. Sous-projet 3 — Migration et bascule

1. **Extraction (hors prod)** : chargement du dump MySQL `assets-source/BD bibliotheque PSSFP …sql` dans un MySQL Docker jetable ; script d'export vers des fichiers JSON (catégories et documents DOCman, ouvrages et catégories Alexandria). Fichiers d'export non versionnés (données réelles).
2. **Récupération des PDF** : rsync depuis l'ancien serveur (accès SSH/FTP confirmé ; identifiants transmis hors dépôt) vers un dossier de transit sur le VPS ; relevé des chemins exacts dans l'audit §11.5.
3. **Correspondances** : `config/biblio_migration.php` associe chaque catégorie Joomla à un `type`, des mots-clés et un niveau d'accès (catégories de supports de cours → `auditor`, le reste → `public`). Relu par le bibliothécaire avant l'import.
4. **Import** : `php artisan biblio:import {export} --files=` idempotent sur (`legacy_source`, `legacy_id`), par lots de 100 ; envoi des PDF vers MinIO, MD5, détection des doublons DOCman/Alexandria (un seul document, redirections pour les deux) ; reprise des vues ; documents créés en `in_review` avec l'indicateur « importé » ; génération des redirections (fiche, `/file`, pages catégorie) ; rapport final (créés, doublons, fichiers manquants).
5. **Validation** : le bibliothécaire contrôle un échantillon par catégorie dans Filament puis publie par lots.
6. **Bascule** : DNS de `bibliotheque.pssfp.net` vers le VPS, vhost nginx servant les deux noms, contrôle automatique d'un échantillon d'anciennes URL (attendu : 301 vers une page 200). Ancien serveur conservé en lecture seule 30 jours, puis arrêt.
7. **Sécurité** : désactiver dès maintenant le compte Joomla de l'ancien administrateur technique signalé dans l'audit (§11.6).

## 8. Déploiement

Nouveau processus pm2 `pssfp-library` (port 6002, à ajouter dans la copie locale de prod de `ecosystem.config.js`), vhost nginx + certificat, Meilisearch sur le VPS (service local, clé maître en `.env`), bucket `pssfp-documents`, `poppler-utils`. Séquence manuelle habituelle, sauvegarde de la base avant toute migration.

**Liste de contrôle avant la mise en ligne** (et à chaque déploiement touchant la bibliothèque) :

1. `php artisan migrate --force` (sauvegarde de la base avant) — les permissions arrivent par migration additive ; **ne jamais relancer `RolePermissionSeeder` en prod**.
2. `php artisan scout:sync-index-settings` avant la mise en ligne, puis après tout changement des réglages d'index (`config/scout.php`) ; `php artisan scout:import "App\Models\Document"` pour le premier remplissage.
3. Worker de file actif (Redis) sous pm2, utilisateur `pssfp` : il traite l'indexation Scout (`SCOUT_QUEUE=true`) et `ProcessDocumentFile` (taille, MD5, couverture). `ProcessDocumentFile` s'arrête à 85 s, sous le `retry_after` Redis de 90 s : si on allonge l'un, allonger l'autre (`REDIS_QUEUE_RETRY_AFTER`).
4. Cron `* * * * * php artisan schedule:run` pour l'utilisateur `pssfp` : réindexation hebdomadaire, contrôle `library:check-index`, purge `model:prune` quotidienne (§5.1).
5. `poppler-utils` installé (`pdftoppm -v`) ; sans lui, pas de couverture (avertissement dans les journaux).
6. Limites d'envoi alignées (ci-dessous).
7. `LIBRARY_RATE_LIMIT_EXEMPT_IPS` = IP publique du VPS (les appels SSR/ISR du serveur Next.js partent tous de cette IP ; sans exemption, ils épuiseraient la limite de 120/min). Avant de la renseigner, vérifier une fois en prod qu'un appel SSR du serveur Next.js arrive à PHP avec l'adresse attendue (journaliser `$request->ip()`), car un proxy local ou des `TrustProxies` mal réglés feraient voir `127.0.0.1` ou l'IP de nginx.

**Bibliothèque publique (`library/`, à faire au Plan 3)** :

1. **Appels serveur → API** : les rendus SSR/ISR de Next appellent l'API depuis le VPS. Les faire passer en local — `127.0.0.1 api.pssfp.org` dans `/etc/hosts` du VPS, ou une variable serveur dédiée `API_INTERNAL_URL` (non `NEXT_PUBLIC_*`) si on préfère ne pas toucher `/etc/hosts` — puis `LIBRARY_RATE_LIMIT_EXEMPT_IPS=127.0.0.1`. Vérifier IPv4 **et** IPv6 : si `api.pssfp.org` se résout aussi en `::1` ou en AAAA publique, l'appel peut sortir en IPv6 et ne pas être exempté (contrôler avec le journal de `$request->ip()`).
2. **`MINIO_MEDIA_PUBLIC_URL`** = `https://media.pssfp.org` : cette variable change aussi les URL publiques des modèles `Asset` et `CampagneCandidature` (site principal, candidature) ; vérifier ces pages après le déploiement.
3. **pm2** : entrée `pssfp-library` (port 6002, utilisateur `pssfp`) dans la copie locale de prod de `ecosystem.config.js` ; ajouter `library` à `deploy.sh` (installation, build, redémarrage).
4. **nginx** : vhost `bibliotheque.pssfp.org` + certificat, avec une CSP adaptée — au minimum `connect-src 'self' https://api.pssfp.org` (recherche et balise de consultation depuis le navigateur), `img-src 'self' data: https://media.pssfp.org`, `form-action 'self'`, `frame-ancestors 'none'`.
5. **DNS Cloudflare** : enregistrement `bibliotheque` vers le VPS.
6. **Build** : `NEXT_PUBLIC_API_URL=https://api.pssfp.org/v1` obligatoire (le build de production échoue sans elle, hors CI). Construire **avec l'API joignable**, puis vérifier l'absence de `page-error` dans `.next/server/app/index.html` (sinon l'accueil prérendu serait la page de repli jusqu'à la première revalidation).

**Limites d'envoi (PDF jusqu'à 200 Mo)** : Livewire dépose les envois temporaires sur le disque local (`config/livewire.php`, règle `max:204800`, 15 minutes d'envoi au plus). Aligner sur le VPS : PHP-FPM `upload_max_filesize = 200M` et `post_max_size = 210M`, nginx `client_max_body_size 210m;` dans le vhost `api` (sinon erreur 413 avant Laravel).

## 9. Tests

- **Pest** : matrice `DocumentAccess` (5 raisons, dont `paid`), contrôleur de téléchargement (refus, journalisation, URL signée), filtres et tri de la liste, proxy de recherche (driver Scout `collection`), résolution des redirections, commande d'import sur un jeu d'essai fictif (idempotence, doublons, fichier manquant), permissions Filament.
- **Playwright** : accueil, recherche avec facettes, fiche avec chacun des trois états d'accès, ancienne URL → 301, contrôles axe ; captures 320 / 768 / 1440.
- **Lighthouse** sur accueil, recherche et une fiche.
- Revue par les agents `biblio-reviewer`, `a11y-reviewer`, `i18n-reviewer`, `filament-reviewer`, `security-reviewer`.

## 10. Points à confirmer (valeurs par défaut appliquées en attendant)

1. Domaine canonique : `.org` (défaut) ou `.net`.
2. Catégories Joomla à classer « Réservé » : par défaut, uniquement les supports de cours.
3. Disponibilité du bibliothécaire pour la relecture des correspondances et l'échantillonnage.
4. Identifiants d'accès à l'ancien serveur : à transmettre au moment du Plan 3.
