# Spec — Module 3 — Bibliothèque virtuelle

> **Référence** : Sprint Specs A7
> **Statut** : v1.0 — proposé pour validation Chef USI
> **Date** : 2026-05-05
> **Sources** : CDC v5 §8.1 Module 3 et Annexe A ; **CDC bibliothèque v2.0 (CDC-PSSFP-BIB-2026-002)** comme source primaire

Ce document spécifie le périmètre fonctionnel et technique du Module 3 — Bibliothèque virtuelle hébergée sur `bibliotheque.pssfp.net`. Il s'aligne sur le data-model.md §8 et l'api-contract.md §8 (endpoints `/v1/library/*`). Le module de paiement multi-canal du CDC v2.0 §G est **différé Phase II** (cf. spec A9), tout le reste de la Phase I de la biblio est dans le périmètre.

## 1. Architecture

App Next.js dédiée `library/` du mono-repo, distincte de `frontend/` (cf. ADR-0001). Le partage du design system avec le site institutionnel passe par le package interne `@pssfp/ui` (header, footer, composants communs).

Domaine production : `bibliotheque.pssfp.net` (sous-domaine conservé de l'ancienne biblio pour SEO).

L'app consomme la même API Laravel `api.pssfp.net/v1` que le site principal. Les endpoints sont préfixés `/v1/library/*` et documentés dans l'api-contract.md §8.

Stratégie de rendu :

- **SSG** pour les pages statiques (accueil, à propos).
- **ISR** revalidate 5 min pour les listes de documents par type (`/theses`, `/articles`, etc.).
- **SSR** pour les pages dynamiques personnalisées (`/recherche`, `/mon-espace`, `/cours`).

## 2. Arborescence

```
library/app/
├── page.tsx                    # / : accueil avec recherche proéminente
├── theses/
│   ├── page.tsx                # liste thèses et mémoires
│   └── [uuid]/page.tsx         # détail document
├── articles/
│   ├── page.tsx                # liste articles scientifiques
│   └── [uuid]/page.tsx
├── legislation/
│   ├── page.tsx                # textes législatifs
│   └── [uuid]/page.tsx
├── cours/
│   ├── page.tsx                # supports de cours (auth requise)
│   └── [uuid]/page.tsx
├── conferences/
│   ├── page.tsx                # actes de conférences
│   └── [uuid]/page.tsx
├── recherche/page.tsx          # recherche multicritères Meilisearch
├── mon-espace/
│   ├── page.tsx                # dashboard auditeur
│   ├── favoris/page.tsx
│   └── historique/page.tsx     # téléchargements et recherches
├── login/page.tsx
└── inscription/page.tsx        # auto-inscription auditeurs si autorisée
```

## 3. Page d'accueil bibliothèque

Endpoint API : agrège plusieurs appels (statistiques globales, derniers ajouts, top téléchargements).

Structure :

1. **Hero compact** avec barre de recherche centrale proéminente — large, ombre violette, autofocus mobile pas activé pour préserver UX scroll. Placeholder : « Rechercher une thèse, un mémoire, un texte de loi… ».
2. **Compteurs animés** : nombre total de documents, nombre de thèses, nombre d'articles, nombre de promotions représentées.
3. **Accès rapides par type** : 4 cards grand format avec icônes — Thèses, Articles scientifiques, Textes législatifs, Actes de conférences. Chaque card mène à la liste filtrée.
4. **Derniers ajouts** : grille 6-8 cards des documents récemment publiés, triés par `published_at desc`.
5. **Top 5 téléchargements** du mois : pour donner une idée de ce qui intéresse la communauté.
6. **CTA `/mon-espace`** pour les auditeurs connectés (si non connecté : « Se connecter avec votre compte @pssfp.net »).
7. **Footer minimal** avec liens vers pssfp.net et contact.

Critères : recherche proéminente est l'élément central — toute autre information est secondaire.

## 4. Listes par type

Pages `/theses`, `/articles`, `/legislation`, `/cours`, `/conferences`. Toutes suivent le même pattern.

Endpoint : `GET /v1/library/documents?type=these&page=...&specialite=...&promotion=...&year_from=...&year_to=...`.

Layout :

- Sidebar gauche (desktop) ou panneau accordéon en haut (mobile) : filtres multicritères avec compteurs de résultats.
- Liste centrale : cards documents.
- Tri : pertinence (par défaut), date desc, auteur asc, titre asc.
- Pagination 20 par page.

**Filtres** par type (depuis data-model.md) :

- `specialite` (5 choix + tronc commun)
- `promotion` (1 à 13)
- `year_from` / `year_to` (range slider de 2013 à année courante)
- `language` (fr / en / autre)
- `access_level` (filter automatique selon auth utilisateur)

**Card document** : badge type avec icône couleur, miniature PDF (thumbnail première page), titre H3, auteurs, année, promotion + spécialité, badge `access_level` si != public, CTA « Lire » + « Télécharger ».

État vide : message « Aucun document ne correspond à votre recherche » + suggestion de retirer un filtre.

## 5. Détail document

Page `/theses/[uuid]`, `/articles/[uuid]`, etc.

Endpoint : `GET /v1/library/documents/{uuid}`.

Structure :

1. Breadcrumb : Bibliothèque > Thèses > Spécialité Fiscalité > Titre.
2. Header : titre + sous-titre, badges type + access_level + spécialité, métadonnées (auteurs, année, promotion, langue, pages).
3. Section « Résumé » : abstract complet.
4. Section « Mots-clés » : badges cliquables vers liste filtrée.
5. Section « Téléchargement » : bouton « Télécharger PDF » (URL signée 30 min). Si `access_level != public` et utilisateur non autorisé : message explicatif + lien login.
6. Section « Citation » : encadré avec onglets APA / MLA / Chicago / BibTeX, bouton « Copier ». Endpoint `/v1/library/citations/{uuid}?format=...`.
7. Section « Actions auditeur connecté » : bouton « Ajouter aux favoris » + « Partager le lien ».
8. Section « Documents liés » : 5 documents de la même spécialité ou même promotion.

**Prévisualisation PDF inline** : optionnel V1 — viewer PDF.js si charge serveur acceptable, sinon simple bouton download. Décision V1 : **lien download direct**, prévisualisation Phase II.

## 6. Recherche multicritères `/recherche`

**Page critique** — c'est la fonctionnalité distinctive de la nouvelle biblio.

Endpoint : `GET /v1/library/search?q=...&filters[]=...`.

Layout :

- Header : barre de recherche centrale avec autocomplétion (endpoint `/v1/library/keywords` pour suggestions de mots-clés).
- Sidebar gauche : filtres avec **facettes dynamiques** (compteurs Meilisearch par valeur).
- Liste centrale avec **highlights** sur le terme recherché (titre + extrait abstract avec balises `<mark>`).
- Footer : compteur total résultats + temps de réponse Meilisearch.

**Performance** : exigence CDC v2.0 §K.2 — résultats < 500 ms pour 500+ documents. Meilisearch sert ces volumes en typiquement 10-50 ms.

**UX recherche** :

- Soumission avec Entrée ou clic bouton.
- URL state : tous les paramètres dans l'URL (`?q=fiscalité&type=these&specialite=fiscalite&promotion=11`) — permet de partager une recherche.
- Pas de soumission si query vide ET aucun filtre actif.
- Si pas de résultat : suggestion d'élargir (retirer un filtre), suggestion de mots-clés similaires (typo correction Meilisearch).
- Sauvegarde des recherches (auditeur connecté) : enregistre dans BDD pour analytics + suggestion de recherches récentes.

**Export bibliographique** : bouton « Exporter ces résultats » génère un fichier BibTeX/RIS contenant les citations des documents listés. Limite à 100 résultats max pour éviter abus.

## 7. Espace personnel `/mon-espace`

Auth obligatoire — Sanctum + cookie httpOnly. Si non connecté : redirection vers `/login`.

Pages :

- `/mon-espace` : dashboard avec 4 widgets :
  - « Mes favoris récents » (5 derniers ajouts)
  - « Mes téléchargements récents » (10 derniers)
  - « Mes recherches récentes » (5 dernières)
  - « Suggestions personnalisées » : 5 docs de la même spécialité que l'utilisateur (si `candidate_profile` ou liaison promotion connue, on infère)
- `/mon-espace/favoris` : liste paginée de tous les favoris, possibilité de les retirer.
- `/mon-espace/historique` : historique téléchargements et recherches (consultation seule, pas de purge en V1).

## 8. Accès aux supports de cours `/cours`

Page restreinte — auth `auditor` ou `teacher` requise. Endpoint filtre automatiquement par `specialite_id` correspondant à la promotion de l'utilisateur (si liaison établie).

Affichage : organisation par UE (groupé par `specialite` puis par `unite_enseignement`).

**Filigrane sur téléchargement** : tout PDF téléchargé depuis `/cours` est filigrané dynamiquement avec le nom de l'utilisateur + email + date de téléchargement, pour décourager la redistribution. Implémentation : middleware Laravel intercepte la demande de download, génère le PDF filigrané à la volée via `setasign/fpdi` ou `mpdf`, expédie en streaming. Marqueur dans l'audit `document_downloads.signed_url_token` pour traçabilité.

## 9. Authentification

Cf. ADR-0005. Login email + mot de passe. 2FA TOTP optionnel pour les auditeurs (recommandé pour les enseignants et obligatoire pour les bibliothécaires).

Pages :

- `/login` : formulaire email + password, lien « Mot de passe oublié », lien « Pas encore de compte ? ».
- `/inscription` : auto-inscription si autorisée par paramètre admin (sinon désactivée — la création de comptes auditeurs/enseignants est faite par le bibliothécaire dans Filament). Décision V1 : **inscription désactivée**, comptes créés par admin.

## 10. Plan de migration des contenus

Cf. CDC bibliothèque v2.0 §F — quatre phases :

### Phase F.1 — Audit complet (Avril S1-S2)

Avant tout dev, audit exhaustif de l'ancienne biblio sur `bibliotheque.pssfp.net`. Tâche M. BENOH BENOH + USI :

- Recensement par type, par filière, par promotion 1-12.
- Analyse qualité PDF (natif vs scanné).
- Évaluation métadonnées existantes (titre, auteur, année, résumé) — complétude.
- Identification doublons et corrompus.
- Cartographie URLs actuelles pour redirections 301.
- Accès admin BDD ancienne biblio (dump SQL).
- Logs d'accès actuels pour identifier les documents les plus consultés.

**Livrable** : `inventaire-biblio-existante.xlsx` avec une ligne par document, statut « migrer » / « purger » / « ressaisir ».

### Phase F.2 — Préparation et nettoyage (Avril S3-S4)

- Normalisation métadonnées dans CSV de migration.
- Traitement OCR Tesseract sur scans non indexables — script artisan `php artisan biblio:ocr-scan {file}`.
- Complétion des résumés manquants (par bibliothécaire ou auteur).
- Classement rétrospectif promotions 1 à 12 selon le nouveau schéma.
- Validation par bibliothécaire avant import.
- Vérification intégrité PDF (checksums MD5).

### Phase F.3 — Migration automatisée (Mai S1-S2)

Script Laravel Artisan `biblio:migrate {csv_file}` :

1. Parse le CSV de migration.
2. Pour chaque ligne : crée le `Document`, upload du PDF vers MinIO (bucket `pssfp-documents`), crée les `document_authors` et `document_mot_cle`.
3. Lance Scout indexation Meilisearch.
4. Ajoute redirection 301 dans la table `redirects` (ancien URL → nouveau slug).
5. Log détaillé `storage/logs/biblio-migration.log` : succès, échecs, durée par document.

Validation par bibliothécaire sur **échantillon de 50 documents par filière** avant import production.

### Phase F.4 — Enrichissement post-migration (Mai S3 — Juin S1)

- Ajout résumés manquants (par auteurs ou bibliothécaire).
- Indexation mots-clés selon thésaurus.
- Ajout DOI pour articles scientifiques (via API CrossRef).
- Configuration alertes mises à jour pour thèses restreintes.
- Finalisation redirections 301 (test crawler à 0 lien cassé).

## 11. Performance et limites techniques

Volumes cibles :

- Documents : 500 à 2 000 PDF en V1 (estimation après migration).
- Recherches simultanées : ≤ 50 utilisateurs concurrents.
- Téléchargements simultanés : ≤ 20.

Performance :

- Recherche : < 500 ms (Meilisearch).
- Liste paginée : < 800 ms côté serveur, < 1,2 s côté client (TTFB + render).
- Détail document : < 600 ms côté serveur.
- Téléchargement : URL signée + streaming MinIO → débit limité par bande passante VPS Contabo.

Lighthouse cible (CDC v2.0 §K.2) : ≥ 85/100 sur les 4 dimensions (légèrement plus tolérant que le site principal vu la complexité fonctionnelle).

## 12. Filigrane PDF dynamique

Pour les documents téléchargés depuis l'espace restreint (`/cours` notamment), filigrane discret au pied de chaque page : « Téléchargé par {Prénom Nom} ({email}) le {date} — Document propriété PSSFP ».

Implémentation Laravel via `setasign/fpdi` qui ouvre le PDF source, ajoute un layer texte semi-transparent en bas de chaque page, expédie le résultat. Coût CPU : ~200-500 ms par PDF de 100 pages — acceptable pour téléchargement ponctuel. Cache 24h pour ne pas regénérer le filigrane à chaque téléchargement par le même user.

## 13. Citations bibliographiques automatiques

Endpoint `/v1/library/citations/{uuid}?format=apa|mla|chicago|bibtex`.

Implémentation : service Laravel `CitationGeneratorService` qui prend un `Document` et un format, retourne la chaîne de citation formatée selon les règles du style.

Exemples :

- **APA 7** : `MBALLA, J. (2024). La fiscalité pétrolière en zone CEMAC (Mémoire de Master). Programme Supérieur de Spécialisation en Finances Publiques.`
- **MLA 9** : `Mballa, Jean. La fiscalité pétrolière en zone CEMAC. Mémoire de Master, PSSFP, 2024.`
- **Chicago** : `Mballa, Jean. "La fiscalité pétrolière en zone CEMAC." Mémoire de Master, Programme Supérieur de Spécialisation en Finances Publiques, 2024.`
- **BibTeX** : `@mastersthesis{mballa2024fiscalite, author = {Mballa, Jean}, title = {La fiscalité pétrolière en zone CEMAC}, school = {PSSFP}, year = {2024}}`

Les règles sont implémentées en PHP, testées par tests unitaires Pest avec exemples couvrant les 4 formats × 5 types de documents (these, memoire, article, livre, texte_loi).

## 14. OCR pour scans

Documents scannés (PDF non indexables) doivent être OCRisés à l'import pour permettre la recherche full-text. Outil : Tesseract OCR avec langue française (et anglais en option).

Workflow :

1. À l'upload Filament d'un PDF, détection automatique : si le PDF a moins de N caractères extractibles (heuristique), considéré scanné.
2. Job Laravel `OcrPdfJob` planifié en queue Redis.
3. Job télécharge le PDF, lance Tesseract via `imagemagick` + `tesseract-ocr` côté shell, génère un PDF/A avec couche texte.
4. Sauvegarde résultat dans MinIO en remplaçant l'original (ou conservant l'original avec extension `_ocr`).
5. Met `documents.has_ocr = true`.
6. Réindexe Meilisearch avec le contenu OCRisé.

Coût CPU : ~30s par page sur VPS modeste. Pour un fonds de 1000 documents x 100 pages x 30s = ~830 heures de calcul cumulé. À étaler sur plusieurs jours en queue avec rate limit pour ne pas saturer le serveur.

## 15. Accès différencié — matrice détaillée

Matrice CDC bibliothèque v2.0 §C.1 (table T2) :

| Type document | Public | Auditeur | Enseignant |
|---|---|---|---|
| Thèses et mémoires | Téléchargement libre | Oui | Oui |
| Articles scientifiques | Téléchargement libre | Oui | Oui |
| Textes législatifs | Téléchargement libre | Oui | Oui |
| Supports de cours | Non | Oui (sa filière) | Oui (toutes filières) |
| Actes de conférences | Téléchargement libre | Oui | Oui |
| Rapports de stage | Selon accord auteur | Oui | Oui |
| Ressources pédagogiques enseignants | Non | Non | Oui (sa matière) |
| Bulletins / newsletters | Téléchargement libre | Oui | Oui |

Implémentation : champ `access_level` sur `documents` + check policy `DocumentPolicy::viewDownload($user, $document)` côté Laravel. Pour les supports de cours, vérification que la `specialite_id` du document correspond à une spécialité que l'utilisateur a le droit de consulter (information dérivée de la promotion d'inscription, à figer dans `users.metadata` lors de l'inscription côté admin).

## 16. Critères d'acceptation Module 3

- Catalogue complet : toutes les thèses et mémoires existants migrés, indexés, accessibles (CDC v2.0 §K.1).
- Recherche multicritères < 500 ms confirmé sur 500+ documents.
- Accès différencié opérationnel : test bout-en-bout par chaque rôle (public, auditeur fiscalité, enseignant, bibliothécaire).
- Téléchargement PDF sécurisé via URL signée fonctionnel pour documents restreints.
- Filigrane dynamique appliqué et lisible sur PDF téléchargés depuis `/cours`.
- CMS Filament : ajout/modification/suppression/publication de document fonctionnel sans intervention dev (cf. spec module 6).
- Citation auto correcte sur 4 formats × 5 types (testé Pest).
- Lighthouse ≥ 85 sur les 4 dimensions, sur l'accueil biblio + une fiche document + la page de recherche.
- HTTPS actif sur `bibliotheque.pssfp.net`.
- Sauvegarde quotidienne fonctionnelle et testée (cf. spec A10).
- Redirections 301 depuis anciennes URLs configurées et testées (zéro 404 sur ancien lien actif).
- Conformité WCAG 2.1 AA niveau A confirmée par axe-core sur les pages clés.

## 17. Composants Next.js library/

Liste indicative, à compléter au dev :

| Composant | Type | Rôle |
|---|---|---|
| `LibraryHeader` | RSC | Header spécifique biblio (avec recherche centrale) |
| `SearchBar` | Client | Barre de recherche avec autocomplétion |
| `DocumentCard` | RSC | Card document liste |
| `DocumentBadgeType` | RSC | Badge couleur par type |
| `DocumentBadgeAccess` | RSC | Badge access_level |
| `FilterSidebar` | Client | Filtres multicritères avec facettes |
| `KeywordChip` | RSC | Chip mot-clé cliquable |
| `CitationBox` | Client | Encadré citation avec onglets et copy |
| `RelatedDocuments` | RSC | 5 documents liés |
| `FavoriteButton` | Client | Toggle favori |
| `DownloadButton` | Client | Bouton download avec gestion access |
| `MyDashboardWidgets` | RSC | Widgets espace personnel |
| `ResultsHighlight` | RSC | Highlights `<mark>` sur résultats |

## Annexe — Sous-agent Claude Code à créer

`.claude/agents/biblio-reviewer.md` — sous-agent qui relit les diffs touchant la biblio et vérifie :

- Toutes les règles d'accès `access_level` sont appliquées dans les controllers/policies.
- Les URLs signées MinIO ne fuient pas dans les réponses non auth.
- Les filigranes PDF sont bien activés sur les documents restreints.
- Aucune query Meilisearch ne renvoie un document `status != 'published'` côté public.
- Les tests Pest couvrent les 4 niveaux d'accès × les types de documents critiques.
