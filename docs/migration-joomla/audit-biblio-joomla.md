# Audit de l'ancienne bibliothèque Joomla — bibliotheque.pssfp.net

> **Date de l'audit** : 2026-05-05
> **Auditeur** : M. ABE ETOUMOU Anatole (USI) avec assistance Claude in Chrome
> **Méthode** : navigation interactive du panneau d'administration Joomla en session admin
> **Référence** : décision Anatole 5 mai 2026 — bascule franche au nouveau système, pas de migration des candidatures Joomla

Ce document remplace les hypothèses du CDC bibliothèque v2.0 §F.1 (audit). Les volumes réels diffèrent **significativement** des estimations initiales et imposent un ajustement de la stratégie de migration.

## 1. Synthèse exécutive

L'ancienne bibliothèque tourne sur **Joomla 3.4.3** (sortie 2015, en fin de vie depuis Joomla 3.x EOL août 2023). Elle utilise **deux composants distincts** pour gérer le contenu : DOCman (textes officiels et documents administratifs) et Alexandria Book Library (ouvrages académiques et numériques).

**Inventaire global** :

| Source | Items | Catégories | Statut |
|---|---|---|---|
| DOCman | **1 752 documents** | 84 catégories | tous publiés (à confirmer) |
| Alexandria Book Library | **1 269 ouvrages** (1 256 publiés + 13 brouillons) | 26 catégories (18 publiées + 8 brouillons) | partiellement actif |
| **Total** | **≈ 3 021 ressources** | 110 catégories | — |

L'estimation du CDC v2.0 (« 500+ documents ») était sous-évaluée d'un facteur **6**. La migration sera donc plus lourde que prévu, avec une marge sur le calendrier Phase 5/6 à anticiper.

**Verdict général** : la biblio actuelle est **fonctionnelle et active** (dernière modification de contenu en 2025), mais techniquement obsolète. Le contenu mérite d'être migré dans son intégralité. La structure de catégories est riche (110 catégories au total) et largement réutilisable comme base du nouveau thésaurus.

## 2. Identifiants techniques

Informations critiques pour la migration, extraites des erreurs SQL et URLs visibles :

- **Base de données** : `epolepssfp_bib3` (MySQL/MariaDB sur l'hébergement actuel)
- **Préfixe des tables** : `oe179_`
- **Hébergeur courriel + DB** : creawebhosting (probablement même hébergeur pour Joomla)
- **Joomla** : version 3.4.3
- **DOCman** : version inconnue (à confirmer dans Système → Information système)
- **Alexandria Book Library** : version 3.1.1
- **Autres composants installés notables** : K2, JCE Editor, EXTman, FILEman, CoalaWeb Traffic, LOGman, Engage Box, Banniers, Fiches de contact, Fils d'actualité, Messagerie privée, Recherche avancée

## 3. Comptes utilisateurs Joomla

Le système actuel ne gère **pas d'utilisateurs auditeurs ou enseignants** — seulement quelques comptes administrateurs. La couche d'accès différencié (`auditor`, `teacher`) du nouveau système est entièrement nouvelle.

| ID | Nom | Identifiant | Email | Groupes | Dernière connexion | Créé |
|---|---|---|---|---|---|---|
| 439 | Super User | jp | info@pfinancespubliques.org | Super Users | jamais | 2015-08-20 |
| 440 | ABE ANTOLE ERIC | anatole | anatoleabe@gmail.com | Registered | jamais | 2015-08-20 |
| 441 | PSSFP | romuald | pogoromuald@gmail.com | Super Users | 2026-05-05 (actif) | 2015-08-21 |
| 442 | BENOH BENOH | tanguy | benohpt@yahoo.fr | Multiple groupes | 2025-07-21 | 2016-03-04 |

**Implications migration** :

- **Pas de migration de comptes utilisateurs** — il n'y en a pas à migrer. Les nouveaux comptes auditeurs/enseignants seront créés à neuf par le bibliothécaire dans Filament.
- L'admin actif est `pogoromuald@gmail.com` — il faut probablement lui créer un compte avec rôle `admin` dans le nouveau système.
- M. BENOH BENOH (`benohpt@yahoo.fr`) est l'admin opérationnel quotidien — compte `librarian` à créer en priorité. À noter : son adresse Yahoo, pas une adresse `@pssfp.net`. À aligner avec la politique d'auth `@pssfp.net` du nouveau système (créer un compte `tanguy@pssfp.net` ou aligner sur sa boîte habituelle).

## 4. DOCman — détails

### 4.1 Activité récente

L'activité visible sur le tableau de bord Joomla montre des ajouts récents par M. BENOH BENOH (« il y a 1 an » et « il y a 2 ans », datant donc de 2024-2025). Documents typés « législation 2024-2025 » :

- DECRET N 2025/076 portant application du système comptable et de déclaration statistique et fiscale
- CIRCULAIRE N 13995/C/MINFI du 31 DEC 2024 (versions française et anglaise)
- LOI N 2024/013 du 23 DEC 2024 portant loi de finances pour l'exercice 2025
- CONSTRUCTION DECONSTRUCTION DE LA COMPTABILITE PUBLIQUE
- EXPLIQUER DEUX SIECLES DE LA COMPTABILITE PUBLIQUE

La biblio est donc **active** et tenue à jour — elle ne sera pas figée pendant la migration, ce qui impose une **fenêtre de migration courte** (idéalement < 7 jours) pour éviter les divergences entre l'ancienne et la nouvelle base.

### 4.2 Hiérarchie des catégories DOCman (84 au total)

Structure principale extraite via inspection DOM :

```
TEXTES ET LOIS (id=1)
├── DECRETS (id=2)
│   ├── DECRETS MARCHES PUBLICS (id=67)
│   ├── DECRETS CTD (id=68)
│   ├── DECRETS FISCALITE (id=69)
│   ├── DECRETS COMPTABILITE (id=70)
│   └── DECRETS GENERALITES (id=71)
├── LOIS (id=3)
│   ├── Lois de règlement et ordonnances (id=62)
│   ├── Lois des marchés publics (id=63)
│   ├── Lois des CTD (id=64)
│   ├── Lois fiscales (id=65)
│   ├── LOIS GENERALE (id=66)
│   └── LOIS COMPTABILITE (id=88)
├── ORDONNANCES (id=30)
├── CONSTITUTION (id=32)
├── INSTRUCTIONS (id=47)
├── CIRCULAIRES (id=48)
│   ├── Circulaires lois de règlement (id=77)
│   ├── Circulaires CTD (id=78)
│   ├── Circulaires fiscalité (id=79)
│   ├── Circulaires généralité (id=80)
│   ├── Circulaires comptabilité (id=81)
│   └── Circulaires marchés publics (id=82)
├── DECISIONS (id=5)
│   ├── Décisions marché publics (id=83)
│   ├── Décisions CTD (id=84)
│   ├── Décisions fiscalité (id=85)
│   ├── Décisions généralités (id=86)
│   └── Décisions comptabilité (id=87)
├── DIRECTIVES (id=6)
└── ARRETES (id=7)
    ├── ARRETES MARCHES PUBLIC (id=72)
    ├── ARRETES CTD (id=73)
    ├── ARRETE FISCALITE (id=74)
    ├── ARRETES GENERALITE (id=75)
    └── ARRETE COMPTABILITE (id=76)

REFORMES (id=22)
├── AUDIT ET CONTROLE (id=23)
├── BUDGET PROGRAMME (id=24)
├── COMPTABILITE (id=25)
├── EXEMPLES DE REFORMES (id=26)
├── FINANCE PUBLIQUE (id=27)
└── SEMINAIRES ET FORMATIONS (id=28)

RAPPORTS ET PUBLICATIONS (id=29) [doublon avec id=49 ? à vérifier]
AUTRES TEXTES (id=31)
FISCALITE (id=33)
GUIDES (id=34)
INSTRUCTIONS (id=47)
LES COLLECTIVITES TERRITORIALES
MANUELS
MEMENTOS
ARTICLES
ECONOMIE
INSTITUTIONS
```

Cette structure se mappe directement sur le nouveau modèle :

- Tout document `DOCman` devient un `Document` avec :
  - `type = 'texte_loi'` pour la branche TEXTES ET LOIS, ARRETES, CIRCULAIRES, DECISIONS, ORDONNANCES, CONSTITUTION, INSTRUCTIONS.
  - `type = 'these'` ou `type = 'memoire'` à déterminer pour RAPPORTS ET PUBLICATIONS.
  - `type = 'article_scientifique'` pour ARTICLES.
- Les catégories DOCman deviennent des entrées dans `mots_cles` (thésaurus PSSFP) avec hiérarchie préservée (`parent_id`).
- L'attribut `specialite_id` peut être inféré pour les sous-catégories thématisées (Marchés Publics → `specialite_id` = marches-publics, Fiscalité → fiscalite, CTD → gouvernance-territoriale, Comptabilité → fiscalite-finance-comptabilite, etc.).

### 4.3 Module Export DOCman cassé

L'URL `?option=com_docman&view=export` retourne une erreur SQL :

```
Table 'epolepssfp_bib3.oe179_docman_exports' doesn't exist of the following query :
SHOW INDEX FROM `oe179_docman_exports`
```

**Conséquence** : on ne peut pas s'appuyer sur l'export natif DOCman. La migration devra se faire via :

1. **Demande de mysqldump à l'hébergeur** (méthode primaire) — voir tâche dédiée.
2. **Crawl public via curl/script Laravel** comme fallback partiel (lent, métadonnées limitées au visible public).

## 5. Alexandria Book Library — détails

### 5.1 Statistiques globales

- **1 269 livres** : 1 256 publiés + 13 non publiés.
- **26 catégories** : 18 publiées + 8 non publiées.
- **6 auteurs** dans le système (faible — beaucoup d'ouvrages sans attribution propre).
- **0 éditeurs**.
- **1 bibliothèque** + **1 location**.

### 5.2 Hiérarchie des catégories Alexandria

Beaucoup de catégories sont **dépubliées** (héritage initial qui faisait double avec DOCman, abandonné au profit de DOCman pour les textes officiels). Les catégories Alexandria désormais utilisées pour les ouvrages numériques sont :

```
TEXTES ET LOIS (id=2, NON publié — historique)
├── LOIS, DECRETS, CIRCULAIRES, ARRETES, DECISIONS, DIRECTIVES, AUTRES TEXTES (tous NON publiés — historique)

REFORMES (id=10, publié)
├── BUDGET PROGRAMME (id=11)
├── AUDIT ET CONTROLE (id=12, accès restreint configuré)
├── COMPTABILITE (id=13)
└── MARCHES PUBLICS (id=25)

RAPPORTS ET PUBLICATIONS (id=14, publié)

OUVRAGES NUMERIQUES (id=15, publié)
├── COMPTABILITE PUBLIQUES (id=16)
├── GESTION BUDGETAIRE (id=17)
├── AUDIT ET CONTROLE (id=18)
├── FISCALITE (id=19)
└── FINANCES PUBLIQUES LOCALES (id=20)
```

### 5.3 Top 10 ouvrages Alexandria (par vues)

Forte indication de ce qui intéresse réellement les utilisateurs :

| # | Titre | Vues |
|---|---|---|
| 1 | EXERCICES CORRIGES FINANCES PUBLIQUES LMD | 53 765 |
| 2 | CAHIER DE RECHERCHE | 26 646 |
| 3 | MANUEL DE PILOTAGE ET D'EXECUTION DU BUDGET PROGRAMME | 24 020 |
| 4 | DROIT DES CONTRATS SPECIAUX CIVILS ET COMMERCIAUX | 11 522 |
| 5 | GESTION DES RISQUES FINANCIERS | 7 678 |
| 6 | CARTE COMMUNALE CAMEROUN | 5 715 |
| 7 | REVUE DE L'OCDE SUR LA GESTION BUDGETAIRE | 5 486 |
| 8 | CODE GENERAL DES IMPOTS 2023 | 4 992 |
| 9 | GESTION ET PILOTAGE DE LA MASSE SALARIALE | 4 862 |
| 10 | CODE DES DOUANES CEMAC | 4 437 |

Total cumul des 10 : **149 123 vues** — le contenu est très consulté. Il faut **préserver les URLs** ou faire des redirections 301 robustes pour ne pas casser le SEO.

### 5.4 Mapping Alexandria → nouveau modèle

- Tout livre Alexandria devient un `Document` avec `type = 'these'`, `type = 'memoire'`, `type = 'article_scientifique'`, ou un nouveau type `ouvrage_numerique` à ajouter au modèle.
- À considérer : ajouter `ouvrage_numerique` comme 10e valeur de l'enum `documents.type` (cf. data-model.md §8). Décision recommandée : OUI — c'est une catégorie distincte des thèses et articles.
- Les vues `views_count` Alexandria peuvent être migrées dans `documents.views_count` pour préserver la valeur historique.
- L'auteur (champ Alexandria) → `document_authors.author_name`.

## 6. Stockage des fichiers PDF

Non vérifié dans cet audit (nécessite accès SSH ou FTP). Hypothèse : `/images/docman/` ou `/components/com_docman/files/` pour DOCman, `/images/abook/` ou `/components/com_abook/files/` pour Alexandria. Volume estimé : **5-30 Go** au total compte tenu de ~3000 PDFs avec une moyenne de 1,5-10 Mo par fichier.

À demander à l'hébergeur ou à constater lors du B8 (audit VPS si même serveur) :

- Chemin disque exact des fichiers DOCman et Alexandria.
- Volume total occupé.
- Permissions de lecture pour le user qui fera le rsync de migration.

## 7. Activité et trafic

- **CoalaWeb Traffic** est installé — pourrait fournir des stats détaillées si on l'inspecte (visiteurs, top pages, géolocalisation, période). Non explorée dans cet audit pour gain de temps.
- **0 visiteur, 0 admin actif, 0 messages** dans le compteur en bas du dashboard au moment de l'audit — instantané d'inactivité. Mais les vues Alexandria (top 10 cumulé > 150K) montrent un trafic historique substantiel.

## 8. Décisions de migration mises à jour

Suite à cet audit, les décisions du CDC v2.0 §F sont ajustées :

### 8.1 Volume réel à migrer

**3 021 ressources** au lieu de 500+ estimées. Impact :

- **Phase F.1 — Audit** : terminée à ce jour (présent document).
- **Phase F.2 — Préparation et nettoyage** : à dimensionner sur 2-3 semaines au lieu de 1-2 (volume × 6).
- **Phase F.3 — Migration automatisée** : script `biblio:migrate` à concevoir avec gestion de batch (ex: 100 docs par run pour ne pas saturer Meilisearch + MinIO).
- **Phase F.4 — Enrichissement post-migration** : OCR à étaler sur plusieurs jours en queue Redis (impossible de traiter 3000 PDFs scannés en une nuit, environ 30 s/page Tesseract).

### 8.2 Stratégie de migration recommandée

Trois étapes :

1. **Migration des contenus massifs (DOCman + Alexandria) en mode batch** depuis le mysqldump, avec mapping vers le nouveau modèle `documents` + `mots_cles` + `document_authors`. Préservation des `views_count` Alexandria.

2. **Crawl public en complément** pour récupérer les URLs publiques avec leur structure exacte → table `redirects` pour les 301. Permet aussi de capturer ce qui ne serait pas dans le dump (par exemple, fichiers attachés sur un host externe).

3. **Validation par échantillonnage** : le bibliothécaire (M. BENOH BENOH) valide 50 documents par catégorie principale avant publication massive. Cela représente ~600 documents validés (~20% du fonds), suffisant pour qualifier la migration.

### 8.3 Préservation SEO critique

Les URLs Alexandria comme `/index.php/ouvrages-numeriques/comptabilite-publiques/exercices-corriges-finances-publiques-lmd` ont accumulé du « jus » SEO (53 765 vues). **Toutes les URLs publiques actuelles** doivent être redirigées 301 vers les nouvelles URLs `/theses/{slug}` ou `/ouvrages/{slug}` selon le type. Sans cela, perte massive de trafic SEO post-migration.

À automatiser : capturer toutes les URLs publiques actuelles via un sitemap.xml ou un crawl, générer la table `redirects` avec mapping ancien → nouveau slug.

### 8.4 Catégories à conserver, fusionner ou archiver

Les catégories DOCman et Alexandria se chevauchent fortement (TEXTES ET LOIS, REFORMES, AUDIT ET CONTROLE, COMPTABILITE, MARCHES PUBLICS). Recommandation : **fusionner** en une taxonomie unique côté `mots_cles` du nouveau modèle, en gardant les catégories Alexandria désactivées (elles étaient déjà des doublons abandonnés).

Mapping fusionné cible (`mots_cles` hiérarchiques) :

```
textes-et-lois
├── decrets
│   ├── marches-publics
│   ├── ctd
│   ├── fiscalite
│   ├── comptabilite
│   └── generalites
├── lois (mêmes sous-thèmes)
├── ordonnances
├── constitution
├── instructions
├── circulaires (mêmes sous-thèmes)
├── decisions (mêmes sous-thèmes)
├── directives
└── arretes (mêmes sous-thèmes)

reformes
├── audit-et-controle
├── budget-programme
├── comptabilite
├── exemples-de-reformes
├── finances-publiques
├── seminaires-et-formations
└── marches-publics

ouvrages-numeriques
├── comptabilite-publique
├── gestion-budgetaire
├── audit-et-controle
├── fiscalite
└── finances-publiques-locales

rapports-et-publications
articles
guides
manuels
mementos
institutions
collectivites-territoriales
economie
autres-textes
```

Total cible après fusion : **environ 50 mots-clés hiérarchiques** au lieu de 110 catégories disparates.

## 9. Actions à mener

| # | Action | Responsable | Délai |
|---|---|---|---|
| 1 | Demander mysqldump complet de `epolepssfp_bib3` à creawebhosting | M. ABE ETOUMOU | Avant 2026-05-15 |
| 2 | Demander accès FTP/SFTP ou archive zip des fichiers PDF | M. ABE ETOUMOU | Avant 2026-05-15 |
| 3 | Communiquer à M. BENOH BENOH la stratégie de migration et planifier la fenêtre de bascule | USI + Centre Documentation | Avant 2026-05-22 |
| 4 | Aligner l'email `tanguy@pssfp.net` ou conserver `benohpt@yahoo.fr` pour son compte futur | USI + Centre Documentation | Avant 2026-05-22 |
| 5 | Ajuster la spec module 3 — ajouter type `ouvrage_numerique`, valider mapping catégories | USI | Cette semaine |
| 6 | Réécrire script `biblio:migrate` en mode batch avec rate-limit Meilisearch + MinIO | USI | Phase F.3 |
| 7 | Provisionner espace MinIO suffisant : viser 50 Go pour le bucket `pssfp-documents` | USI | Phase B7/B8 |
| 8 | Prévoir 7-10 jours OCR Tesseract en background pour PDFs scannés | USI | Phase F.4 |

## 10. Questions ouvertes — résolues le 5 mai 2026

- **Compte Joomla `pogoromuald@gmail.com`** (Super User actif) : ancien employé, **plus besoin de migrer ce compte**. À supprimer dans le mysqldump avant import ou ne pas créer dans le nouveau système.
- **Ouvrages Numériques Alexandria** : **mix accès libre + payant** selon les ouvrages. Le module paiement Phase II est donc effectivement nécessaire mais sélectif (pas tous les ouvrages).
- **« EXERCICES CORRIGES FINANCES PUBLIQUES LMD »** : **accès libre conservé**, ne bascule pas en restreint.
- **Compte de M. BENOH BENOH** : créer `tanguy@pssfp.net` dans le nouveau système. La boîte `benohpt@yahoo.fr` n'est plus utilisée pour l'admin biblio.

## 11. Phase 2 d'audit — informations techniques détaillées

### 11.1 Environnement d'hébergement actuel

| Paramètre | Valeur |
|---|---|
| Joomla | 3.4.3 (sortie 2015, EOL Joomla 3.x août 2023) |
| PHP | **5.6.40** (EOL depuis janvier 2019 — 7 ans en non-support !) |
| OS | Linux `web30.us.cloudlogin.co` (hébergement mutualisé revendeur creawebhosting) |
| Base de données | `epolepssfp_bib3` (préfixe tables `oe179_`) |
| memory_limit PHP | 128 Mo |
| upload_max_filesize | 100 Mo |
| post_max_size | 100 Mo |
| Path PHP config | /home/sys/php5.6 |

**Verdict sécurité** : PHP 5.6 sans patches depuis 7 ans = surface d'attaque énorme. Migration **urgente** au-delà du seul intérêt fonctionnel. À mettre dans la note au COPIL pour justifier l'accélération si besoin.

### 11.2 Schéma DOCman — champs détaillés

Capturés depuis le formulaire d'édition du document id=311 (ARRETE N_058 MINAGRI) :

| Champ Joomla | Type | Notes migration |
|---|---|---|
| id | int | → `documents.id` interne (BIGSERIAL) |
| title | string | → `documents.title.fr` |
| alias | slug | → composante du `documents.slug` |
| file_path | string (chemin Local) | Format observé : `<CATEGORIE>/<NOM_FICHIER>.pdf` (ex: `ARRETES/ARRETE N_ 058_MINAGRI...pdf`). Pointe vers stockage DOCman. |
| catid | int | → `documents.specialite_id` (mapping catégorie Joomla → spécialité) ou `mots_cles` |
| description | HTML | → `documents.abstract.fr` (après sanitisation `mews/purifier`) |
| status | int (0/1) | → `documents.status` (`published` si 1, `draft` sinon) |
| created | timestamp | → `documents.created_at` |
| created_by | user_id | → `documents.uploaded_by` (NULL pour comptes Joomla supprimés comme romuald) |
| publish_up / publish_down | timestamp | → `documents.published_at` (uniquement publish_up utile) |
| modified | timestamp | → `documents.updated_at` |
| modified_by | user_id | → ignoré côté nouveau modèle (Activity Log Spatie reprend ce rôle) |
| access | int (Joomla ACL level) | → `documents.access_level` mapping : 1 (Public) → `public`, autres → `auditor` |
| owner_id | user_id | → ignoré (Spatie permissions remplace) |
| hits | int | → `documents.views_count` |
| download_count | int | → `documents.downloads_count` |
| image | string (path) | → `documents.thumbnail_id` via Spatie Media Library |

**Champs absents de DOCman** (à enrichir manuellement après migration) :
- Pas de champ `auteur` explicite — l'auteur est implicite dans le titre (« MINAGRI », « MINFI », « MINEFI » = institutions). Le bibliothécaire pourra ajouter via `document_authors.author_name = 'Ministère des Finances du Cameroun'` lors de l'enrichissement Phase F.4.
- Pas de champ `année` — extractible du titre par regex (`/(19|20)\d{2}/`).
- Pas de mots-clés/tags — à déduire de la catégorie Joomla.
- Pas d'ISBN, DOI, journal — sans objet pour les textes officiels.

### 11.3 Schéma Alexandria — champs détaillés

Capturés depuis le formulaire d'édition du book id=1148 (BUDGET CITOYEN MINFI 2023) :

| Champ Alexandria | Type | Notes migration |
|---|---|---|
| id | int | → `documents.id` interne |
| title | string | → `documents.title.fr` |
| subtitle | string | → préfixé au champ title ou nouveau champ `subtitle` |
| catid | int | → `mots_cles` (mapping vers la nouvelle taxonomie) |
| editor_id | int | → ignoré (0 éditeurs actuellement) |
| edited_by_self | bool | → ignoré |
| author (multi) | array of author_id | → `document_authors.author_name` ou `enseignant_id` si match |
| tags (multi) | array | → `document_mot_cle` (les tags Alexandria deviennent des mots-clés) |
| note | text | → ajouté à `documents.abstract` |
| description | HTML | → `documents.abstract.fr` (après sanitisation) |
| cover_image | string (path) | → `documents.thumbnail_id` (Spatie Media) — chemin format `images/<NOM>` |
| year | smallint | → `documents.year` |
| total_copies | int | → ignoré V1 (gestion physique pas migrée) |
| **price** | decimal | → `documents.price_xaf` (NOUVEAU CHAMP À AJOUTER) — pour les ouvrages premium Phase II. À conserver à la migration même si le module paiement n'est pas activé. |
| pages | int | → `documents.pages_count` |
| num_page_index | int | → ignoré (numérotation interne Alexandria) |
| isbn | string | → `documents.isbn` |
| file (PDF) | string | → `documents.file_path` |
| url_label, external_url | string | → si `external_url` présent, `documents.external_url` (NOUVEAU CHAMP) — pour ouvrages hébergés ailleurs |
| state | int (0/1) | → `documents.status` |
| hits | int | → `documents.views_count` (préserve les compteurs de vues) |

**Implications data-model** : ajouter trois colonnes au modèle `documents` :

- `price_xaf` BIGINT NULL — prix en FCFA centimes pour Phase II, NULL = gratuit.
- `external_url` TEXT NULL — pour ouvrages hébergés en externe.
- `subtitle` JSONB NULL — TR, sous-titre.

### 11.4 Pattern URLs publiques — pour redirections 301

#### Pages catégorie

Format actuel :

```
/index.php/<top-cat-alias>
/index.php/<top-cat-alias>/<sub-cat-alias>
/index.php/<top-cat-alias>/<sub-cat-alias>/<sub-sub-cat-alias>
```

Exemples :

- `/index.php/textes-et-lois`
- `/index.php/textes-et-lois/lois`
- `/index.php/textes-et-lois/lois/lois-fiscalite`
- `/index.php/textes-et-lois/lois/lois-de-reglement-et-ordonnances`
- `/index.php/textes-et-lois/decrets/decrets-marches-publics`

Le préfixe `/index.php/` peut être présent ou absent selon la config SEF Joomla — à confirmer côté config Apache / .htaccess.

Mapping cible :

```
Old → New
/index.php/textes-et-lois              → /legislation
/index.php/textes-et-lois/lois         → /legislation?theme=lois
/index.php/textes-et-lois/lois/lois-fiscalite → /legislation?theme=lois-fiscalite
```

#### Pages document DOCman

Format actuel : `/index.php/<path>/<id>-<alias>`

Exemples :

- `/index.php/textes-et-lois/lois/lois-fiscalite/1992-code-general-des-impots-2023`
- `/index.php/textes-et-lois/lois/lois-fiscalite/527-law-n-15-december-2009-local-fiscal-system`

Mapping cible :

```
Old → New (préserver le slug, perdre l'ID numérique)
/index.php/textes-et-lois/lois/lois-fiscalite/1992-code-general-des-impots-2023
→ /legislation/code-general-des-impots-2023
```

#### URLs de téléchargement

Format actuel : `/index.php/<path>/<id>-<alias>/file`

Exemple : `/index.php/textes-et-lois/lois/lois-fiscalite/1992-code-general-des-impots-2023/file`

Mapping cible : tous les anciens `.../file` redirigent vers `/api/v1/library/documents/{uuid}/download` qui re-signe une URL temporelle.

#### Stratégie de génération des redirections

Lors du script `biblio:migrate` Laravel, pour chaque document migré :

1. Récupérer le path complet de la catégorie Joomla (en remontant la hiérarchie via `oe179_docman_categories.parent_id`).
2. Calculer l'URL ancienne canonique : `/index.php/{cat_path}/{id}-{alias}`.
3. Insérer dans `redirects` : `{from_path: ancien, to_path: nouveau, status_code: 301}`.
4. Insérer la variante `/index.php/{cat_path}/{id}-{alias}/file` → `/api/v1/library/documents/{uuid}/download`.

Estimation : ~6 000 lignes dans `redirects` (3 021 docs × 2 URLs : page + download).

### 11.5 Stockage des fichiers PDF

Non vérifié directement (pas d'accès SSH ou FTP dans cette session). Hypothèses à valider quand l'audit FTP sera fait :

- **DOCman** : probablement `/home/<user>/public_html/components/com_docman/files/` ou `/home/<user>/public_html/images/docman/`. Le format de chemin observé sur les documents (`ARRETES/ARRETE N_ 058...pdf`) suggère que la racine du stockage est segmentée par catégorie, avec un point d'entrée probable `/.../files/`.
- **Alexandria** : probablement `/home/<user>/public_html/images/abook/` ou similaire. Cover images dans `/images/<NOM>` (vu dans le formulaire).

Volume estimé total : 5-30 Go selon densité moyenne des PDFs.

**Action FTP** quand tu te connectes :

1. Naviguer vers `/public_html/` (ou racine site Joomla).
2. Identifier les dossiers : `components/com_docman/files/`, `images/abook/`, `images/`.
3. Télécharger en archive zip ou rsync vers le NAS USI ou un dossier temporaire.
4. Notes les chemins exacts dans une mise à jour de ce document section 11.5.

### 11.6 Activité par utilisateur

Confirmation après examen ciblé :

- **`pogoromuald@gmail.com`** : ancien employé — connexion encore active (2026-05-05 ce matin) car **probablement des accès non révoqués**. À auditer côté sécurité : révoquer toutes les sessions et mots de passe avant la migration finale, sinon possibilité d'accès non maîtrisé.
- **`tanguy` (BENOH)** : compte multi-groupes, dernière connexion 2025-07-21. À recréer dans le nouveau système avec email `tanguy@pssfp.net`.
- **`anatole`** : Registered, jamais connecté. Sans intérêt opérationnel.
- **`jp`** (Super User original) : info@pfinancespubliques.org, jamais connecté. À ignorer.

**Recommandation** : avant la migration finale, **désactiver le compte `pogoromuald`** dans le Joomla actuel (Utilisateurs → tanguy → Désactiver) pour fermer l'exposition.

## 12. Mises à jour à apporter aux specs Sprint A

À synchroniser dans les fichiers documentaires existants :

### 12.1 `docs/data-model.md` — table `documents`

Ajouter trois colonnes :

| Colonne | Type | Notes |
|---|---|---|
| `subtitle` | JSONB NULL | TR — sous-titre Alexandria |
| `price_xaf` | BIGINT NULL | Prix FCFA centimes — préparation Phase II monétisation |
| `external_url` | TEXT NULL | Ouvrages hébergés ailleurs (Alexandria external_url) |

Étendre l'enum `documents.type` pour inclure :

- `ouvrage_numerique` — manuels et exercices Alexandria.

Soit 10 valeurs au total : `these`, `memoire`, `article_scientifique`, `texte_loi`, `cours`, `acte_conference`, `rapport_stage`, `bulletin`, `ressource_pedagogique`, **`ouvrage_numerique`**.

### 12.2 `docs/specs/module-3-bibliotheque.md`

Ajouter section sur :

- Le type `ouvrage_numerique` avec sous-pages frontend `/ouvrages` (publique gratuite) et `/ouvrages-premium` (payant Phase II).
- Le mapping de la taxonomie Joomla fusionnée vers ~50 mots-clés hiérarchiques.

### 12.3 `docs/api-contract.md`

Ajouter un endpoint `GET /v1/library/redirects-check?path=<old_path>` que Nginx peut interroger pour les redirections dynamiques (alternative au stockage redirect statique en BDD si le volume devient trop gros).

Ou simpler : générer un fichier `nginx-redirects.conf` à partir de la table `redirects` au déploiement, et l'inclure dans la config Nginx du vhost biblio.

## 13. Questions ouvertes — itération 2

- **L'ancien admin technique `pogoromuald`** : doit-on garder l'accès actif jusqu'à la fin de la migration (pour éventuel debug) ou le couper immédiatement ? Recommandation : couper immédiatement après obtention du dump.
- **Ouvrages premium** : as-tu une liste préliminaire des ouvrages qui passeront en payant ? Cela permettra de pré-marquer `price_xaf` à la migration plutôt que de revenir manuellement.
- **Subtitle Alexandria** : observé vide sur l'ouvrage testé (BUDGET CITOYEN MINFI 2023). Combien d'ouvrages ont effectivement un subtitle non vide ? Si < 5%, on peut le concaténer au title et abandonner la colonne.


## Annexe — Captures d'écran

Captures d'écran de la session d'audit conservées séparément. Disponibles sur demande.

## Annexe — Commande mysqldump à demander à l'hébergeur

```bash
mysqldump --single-transaction --skip-lock-tables \
  --default-character-set=utf8mb4 \
  -u <user> -p<password> \
  epolepssfp_bib3 \
  | gzip > epolepssfp_bib3_$(date +%Y%m%d).sql.gz
```

À déposer sur le NAS USI ou un cloud sécurisé temporaire (jamais dans le repo Git — la base contient potentiellement des PII utilisateurs et doit rester confidentielle).

Le dump est à protéger et à supprimer après import dans la nouvelle base.
