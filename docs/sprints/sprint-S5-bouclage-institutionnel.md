# Sprint S5 — Bouclage du site institutionnel (contenu réel)

> **Origine** : insatisfaction Anatole — *« le site reste très générique, je ne vois rien sur la formation continue, pas de slider beau, je ne suis pas satisfait du rendu »*
> **Constat audit** : structure 100% OK, design 90% OK, **contenu réel intégré 15%**.
> **Objectif** : passer de « site beau mais vide » à « site institutionnel chargé et démontrable ».
> **Date** : 2026-05-08, version 1.1 (validations Anatole intégrées 2026-05-08 PM)
> **Durée estimée** : 5-6 jours répartis en 6 PRs.

## ⚠️ RÈGLE D'OR — INSPECT-FIRST (lire avant toute PR)

**Anatole a déjà commencé à charger des contenus via l'admin Filament et à intégrer des assets** (logo, certaines pages, etc.). Sprint S5 est un sprint **d'amélioration et de complément**, PAS un sprint de remise à zéro.

**Avant TOUTE création de fichier, page, asset, ou seeder, Claude Code DOIT** :

1. **Inventorier ce qui existe en BDD** :
   ```bash
   cd apps/backend
   php artisan tinker --execute="
     echo 'Pages: '; App\Models\Page::pluck('slug')->each(fn(\$s) => print(\"  - \$s\n\"));
     echo 'Articles: '; App\Models\Article::pluck('slug')->each(fn(\$s) => print(\"  - \$s\n\"));
     echo 'Media: '; \\Spatie\\MediaLibrary\\MediaCollections\\Models\\Media::select('id','collection_name','file_name')->get()->each(fn(\$m) => print(\"  - {\$m->collection_name}/{\$m->file_name}\n\"));
   "
   ```
2. **Inventorier les fichiers déjà importés** dans MinIO :
   ```bash
   php artisan storage:list minio  # ou mc ls minio/pssfp-media/
   ```
3. **Inventorier le code existant** par grep :
   ```bash
   grep -r "logo" apps/frontend/src/components/ apps/frontend/public/
   grep -r "mot-du-president\|mot-president\|président" apps/frontend/src/
   grep -r "Formation continue\|formation-continue" apps/frontend/src/
   ```
4. **Demander confirmation Anatole en commentaire de PR** si un asset/page/seeder semble déjà exister sous une forme légèrement différente. Exemple : si une page `/a-propos/mot-president` existe avec contenu déjà saisi via admin, NE PAS créer un seeder qui l'écrase ; à la place, **enrichir** le contenu manquant et **améliorer** le layout sans toucher au texte saisi.

**Règles de non-régression** :
- Pas de `migrate:fresh --seed` sans confirmation explicite Anatole.
- Pas d'overwrite d'un fichier dans `apps/frontend/public/` sans diff visuel comparé.
- Pas de seed qui écrase une `Page` ou `Article` créés via admin (vérifier `created_at` antérieur au sprint S5).
- Si conflit potentiel détecté → ouvrir issue GitHub `inspect-conflict` et continuer les autres tâches non bloquantes.

**Pattern de migration** : préférer `updateOrCreate(['slug' => $slug], [...defaults non-destructifs])` à `create()` pour les seeders qui pourraient toucher du contenu existant. Et **ne mettre que les champs vides** dans le tableau d'update, pas les champs déjà remplis.

---

## Décisions Anatole validées le 2026-05-08

1. **Le DOCX « Mot du Président.docx » est la version finale.**
2. **Pr. BASAHAG Achile Nestor = Président du Comité de Pilotage du PSSFP (PCP).** Il N'EST PAS le DG. **Il n'y a pas de DG au PSSFP.** À corriger partout dans le code et les contenus.
3. **Le catalogue Formation continue est extrait** dans `docs/sources/catalogue-formations-extracted.txt` (75 pages, 42 KB texte). Source de vérité pour la rubrique Formations.
4. **4 articles à publier en home** : (a) Lancement formations continues 2026, (b) Formation au Centre Pasteur de Yaoundé, (c) Formation à l'Assemblée Nationale, (d) Lancement appel candidature 14e promotion.
5. **Les 5 slides du carousel hero sont à proposer par Cowork** (cf. section 4 ci-dessous, sélection ci-jointe).
6. **Renommage menu** : `Le PSSFP` → **`À propos de nous`** avec sous-menus déroulants. Idem `Formations` avec sous-menus.

---

## 0. Inventaire des assets disponibles

L'audit a identifié dans `/Users/anatole-savics/Documents/PS/03_PROJETS/SITE WEB PSSFP/` (hors repo `pssfp/`) :

- **Mot du Président** — DOCX 15 KB, mai 2026 (à extraire en texte)
- **Présentation PSSFP** — DOCX 30 KB
- **Organigramme** — DOCX 38 KB (2 versions, contient photos + bios direction)
- **Catalogue formations** — PDF 49 MB (+ 2 versions compressées 5 MB)
- **Logos partenaires** : MINFI (FR + SVG), MINESUP, UY2, Expertise France, FMI, Assemblée Nationale Cameroun (11 fichiers)
- **Logo PSSFP** : SVG sans fond (9 KB) + PNG (188 KB) + JPG (83 KB)
- **125 photos** (1.2 GB) : Campus, Amphis, Salles, Bibliothèque, Direction, Enseignants, Promotions P6-P11, Soutenances P8-P11, Sortie Solennelle, Séminaires, Marches Sportives, Réunions de Coordination, Amicale, Voyages, Cours
- **Conventions** : Convention Tripartite 2013, Convention PSSFP-MINFI-MINESUP
- **Appels à candidature** : Promo 12 (6 docs FR/EN + 6 banderoles JPG 7-25 MB), Promo 13 (7 docs FR/EN)
- **PV de jury** : 3 versions (P12 + P13)
- **Fiches spécialités** : dossier `/fiches-specialites` présent

**Manquants à créer** : Brochure marketing courte, Brand Guide, bios structurées enseignants (à extraire de l'organigramme).

---

## 1. PR V — Import assets dans MinIO + Filament Media Library (1 j)

**Branche** : `feat/s5-v-assets-import`

**Objectif** : tous les assets accessibles depuis le CMS sans manip technique.

**Tâches** :
- Script `php artisan pssfp:import-assets` qui scanne `/Users/anatole-savics/Documents/PS/03_PROJETS/SITE WEB PSSFP/` et upload dans MinIO buckets dédiés :
  - `pssfp-media/logos/` — logos officiels (PNG + SVG optimisés)
  - `pssfp-media/photos/campus/`, `/direction/`, `/promos/`, `/evenements/`, `/cours/`
  - `pssfp-media/documents/` — catalogue PDF, conventions, appels candidature, PV
- Conversion automatique en WebP pour les photos (qualité 85), conservation des originaux JPG
- Génération de 3 tailles : `thumb` (400px), `medium` (1200px), `full` (original).
- Resource Filament `Media` (déjà installée via spatie/laravel-medialibrary) configurée pour browse/upload visuel.
- Tags catégoriels : `direction`, `promo-p11`, `cerimonie`, `partenaire-minfi`, etc.
- Tableau dans `docs/asset-inventory.md` listant chaque asset importé + URL MinIO + tag.

**Critères réception** :
- Login admin → "Médias" → on voit toutes les photos triées par catégorie.
- Upload manuel d'une nouvelle photo via Filament fonctionne.
- Logos partenaires accessibles via `/storage/logos/minfi.svg` (CDN-friendly).

---

## 2. PR W — Pages institutionnelles : extraire DOCX → seeder (½ j)

**Branche** : `feat/s5-w-pages-content-real`

**Objectif** : remplacer le contenu seedé placeholder par le **vrai texte** d'Anatole, et **renommer le menu « Le PSSFP » en « À propos de nous »**.

**Tâches** :
- Script Python `scripts/extract-docx.py` qui convertit chaque DOCX en Markdown structuré :
  - `Mot du Président.docx` → `database/seeders/content/mot-president.md`
  - `Présentation PSSFP.docx` → `presentation-pssfp.md`
  - `Organigramme PSSFP.docx` → `organigramme.md` + `direction-bios.md`
- Mise à jour des seeders existants (`PssfpPagesSeeder` → renommer `AProposPagesSeeder`, `FormationsPagesSeeder`, `VieAcademiquePagesSeeder`) pour ingérer ces MD au lieu du lorem.
- Création d'un nouveau seeder `MotPresidentPageSeeder` qui crée la page `/a-propos/mot-du-president` avec :
  - Titre : « Mot du Président »
  - Sous-titre : **« Pr. BASAHAG Achile Nestor, Président du Comité de Pilotage du PSSFP »** *(PAS Directeur Général — il n'y a pas de DG. Le PSSFP est dirigé par un Comité de Pilotage présidé par le Pr. BASAHAG)*
  - Photo : `direction/Dr Basahag Achile.JPG` (déjà présente dans `pssfp/assets-source/`)
  - Corps : extrait du DOCX, formatage RichText
  - Date publication
  - SEO : meta description tirée des 160 premiers caractères
- **Renommage URL** : route `/pssfp/[...slug]` devient `/a-propos/[...slug]` (avec redirection 301 de l'ancien path pour ne pas casser le SEO si déjà indexé).
- **Menu nav refactorisé** :
  - Niveau 1 : `À propos de nous` (remplace `Le PSSFP`)
  - Sous-menus : Mot du Président • Présentation du PSSFP • Comité de Pilotage • Organigramme • Convention tripartite (MINFI + MINESUP + UY2) • Histoire (depuis 9 oct 2013) • Infrastructure (Campus Messa, 3 amphis, 5 salles)
- **Audit grep** sur tout le repo pour traquer et corriger toute mention `Directeur Général`, `DG`, `directeur du PSSFP` → remplacer par `Président du Comité de Pilotage` ou `PCP` selon contexte.

**Critères réception** :
- `php artisan migrate:fresh --seed` recharge les vrais contenus.
- Aller sur `/a-propos/mot-du-president` → on lit le texte réel d'Anatole, photo affichée, sous-titre correct (PCP, pas DG).
- Menu déroulant « À propos de nous » fonctionnel desktop + mobile.
- `grep -ri "directeur général\|\bDG\b" apps/frontend/src` retourne 0 résultat hors faux positifs (DGE/DGT/etc.).
- Redirection `/pssfp/*` → `/a-propos/*` testée.

---

## 3. PR X — Rubrique Formations refondue (catalogue intégré) (1.5 j)

**Branche** : `feat/s5-x-formations-catalogue`

**Objectif** : refondre la rubrique Formations en s'appuyant sur le catalogue PDF officiel (75 pages déjà extraites en `docs/sources/catalogue-formations-extracted.txt`). C'est la rubrique la plus stratégique du site (c'est le cœur de métier du PSSFP).

**Source de vérité** : `docs/sources/catalogue-formations-extracted.txt` — extraction texte complète du catalogue PSSFP (création UE août 2025, mise à jour février 2026).

### 3.1 Architecture menu « Formations »

Niveau 1 : **Formations** (avec sous-menus déroulants)

- **Master Professionnel en Finances Publiques** (= « Formation initiale » dans le catalogue)
  - 5 sous-pages (1 par spécialité) :
    1. Les métiers budgétaires
    2. Les métiers de la gouvernance territoriale et de la décentralisation
    3. Les métiers de la commande publique
    4. Les métiers de la fiscalité et de la comptabilité
    5. Les métiers d'audit et de contrôle
- **Formation continue** (10 modules courts — voir 3.2)
- **Certifications internationales**
- **Séminaires de formation** (Voyages d'étude Maroc + France)
- **FOAD** (lien externe vers foad.pssfp.net)
- **Catalogue PDF** (lien direct vers `/storage/documents/catalogue-pssfp-2026.pdf`)

### 3.2 Page `/formations/formation-continue` — la pièce maîtresse

**Hero** : Photo « formation au Centre Pasteur de Yaoundé » + titre « Formation continue PSSFP » + sous-titre « 10 modules courts pour les acteurs des finances publiques »

**Bloc « POUR QUI ? »** (extrait catalogue) :
- Acteurs financiers des secteurs public et privé
- Responsables des Collectivités Territoriales Décentralisées (CTD)
- Élus locaux

**Bloc « TARIFS »** (extrait catalogue, applicable à tous les modules continues) :
- Administrations : 4.995.000 FCFA pour une session de moins de 15 personnes
- Individus : 500.000 FCFA par personne
- Étudiants du PSSFP : 250.000 FCFA par personne
- Durée : 3 à 5 jours selon le module

**Bento Grid des 10 modules** (fiche par module avec : titre, description, objectifs, public cible, principales UE) :

1. **Comprendre la loi de finances et sa circulaire d'exécution** — sensibilisation aux innovations législatives, responsabilités des acteurs financiers
2. **Gestion financière des Entreprises et Établissements publics** — application des lois N°2017/010 et 011 du 12 juillet 2017
3. **Budget Programme et Gestion axée sur les Résultats (GAR)**
4. **Contrôle de Gestion et mise en place SICOGES**
5. **Les Infractions à la Réglementation Financière**
6. **Cartographie des Risques**
7. **Décentralisation et Finances Publiques Locales**
8. **Mobilisation et Gestion des Financements Extérieurs**
9. **Marchés Publics**
10. **Chaîne de planification programmation budgétaire et budgétisation**

Chaque card cliquable → page détail `/formations/formation-continue/<slug>` avec contenu complet extrait du catalogue.

**CTA principal** : « Demander un devis pour mon administration » → formulaire simple (nom organisation, contact, module(s) souhaité(s), nombre de participants) → email vers usi@pssfp.net.

**CTA secondaire** : « Télécharger le catalogue complet (PDF) » → catalogue.pdf importé en PR V.

### 3.3 Page `/formations/master` — vue d'ensemble Master

- Hero avec photo Sortie Solennelle du Palais des Congrès
- Bloc « OBJECTIF » : « Pôle d'excellence pour la formation des cadres de très haut niveau devant accompagner, aux plans stratégique et politique, la réforme budgétaire et comptable mise en œuvre depuis 2013 »
- Bloc « ADMISSION » : BAC + 3 (économie, gestion, droit ou équivalent), VAE possible avec expérience pro suffisante
- Bloc « MODALITÉS » : 2 ans, 1.185.000 FCFA/an, présentiel + distanciel, Campus de Messa
- Bento grid des 5 spécialités (réutiliser le composant existant)

### 3.4 Pages détail par spécialité (5 pages)

Chacune avec : Objectif, Objectifs spécifiques, Cibles, Coût, Durée, Type, Lieu, **Quelques unités d'enseignement dispensées** (extrait catalogue, ex. pour Métiers budgétaires : Nomenclature des pièces budgétaires, Fiscalité de la dépense publique, Cadrage macroéconomique, Acteurs de l'exécution du budget, Régime de responsabilité des ordonnateurs et comptables, Contrôle de l'exécution de la dépense publique, Fongibilité des crédits, Procédure d'exécution).

### 3.5 Page `/formations/certifications` & `/formations/seminaires`

- **Certifications internationales** : page synthèse + liste partenaires + lien contact pour info détaillée
- **Séminaires** : 2 cards Voyage d'étude Maroc + Voyage d'étude France avec descriptifs catalogue, photos disponibles, mention de leur intégration au cursus

### 3.6 Card « Formation continue » sur la home

Ajoutée sous le bento Master, dimension équivalente, lien direct vers `/formations/formation-continue`.

**Critères réception** :
- Menu Formations déroulant fonctionnel desktop + mobile, 6 entrées.
- Page `/formations/formation-continue` avec les 10 modules en bento, tarifs visibles, formulaire devis fonctionnel.
- 5 pages détail spécialités Master avec UE listées.
- 2 pages Certifications + Séminaires créées (même si contenu light).
- Catalogue PDF téléchargeable depuis chaque page formation.
- Aucun lorem ipsum résiduel dans la rubrique Formations.

---

## 4. PR Y — Hero showcase / slider premium (1 j)

**Branche** : `feat/s5-y-hero-showcase`

**Objectif** : remplacer/enrichir le hero actuel par un showcase visuel beau et institutionnel.

**Approche** : **carousel doux** (pas Y2K), 4-5 slides, autoplay 6s, transition crossfade 800ms, pause au hover, indicateurs ronds en bas, navigation clavier (← →), respect `prefers-reduced-motion`.

**Slides proposés** (validation Anatole — *« Propose »*) :

1. **Slide 1 — Identité institutionnelle** :
   - Photo : Façade du Campus de Messa (à sélectionner dans `assets-source/photos/Campus/`)
   - Titre Playfair gradient or-violet : *« Former l'élite des finances publiques »*
   - Sous-titre Inter : *« Un institut du Ministère des Finances depuis le 9 octobre 2013 »*
   - 2 CTAs : `Découvrir nos formations` (filled violet) + `Candidater à la 14e promo` (outline or)

2. **Slide 2 — Excellence des promotions** :
   - Photo : Sortie Solennelle au Palais des Congrès de Yaoundé (assets `Sortie Solennelle/`)
   - Titre Playfair : *« 13 promotions diplômées au service de l'État »*
   - Sous-titre : *« 5 spécialités du Master Professionnel en Finances Publiques, BAC+5 reconnu CAMES »*
   - CTA : `Découvrir le Master`

3. **Slide 3 — Formation continue & rayonnement national** :
   - Photo : Formation au Centre Pasteur de Yaoundé OU formation à l'Assemblée Nationale (selon disponibilité)
   - Titre Playfair : *« 10 modules de formation continue pour les acteurs publics »*
   - Sous-titre : *« Administrations, CTD, élus locaux, secteur privé — accompagner la modernisation des finances publiques »*
   - CTA : `Voir le catalogue formation continue`

4. **Slide 4 — Convention tripartite & gouvernance** :
   - Photo : Signature ou réunion Convention Tripartite MINFI + MINESUP + UY2
   - Titre Playfair : *« Trois institutions, une mission »*
   - Sous-titre : *« Convention tripartite MINFI · MINESUP · Université de Yaoundé II Soa »*
   - CTA : `À propos de nous`

5. **Slide 5 — Coopération internationale & séminaires** :
   - Photo : Voyage d'étude (Maroc ou France, selon disponibilité dans `assets-source/photos/Voyages/`) ou photo de Séminaire international
   - Titre Playfair : *« Une école ouverte sur l'international »*
   - Sous-titre : *« Coopération CEMAC, partenariats France et Maroc, certifications internationales »*
   - CTA : `Découvrir nos partenaires`

**Note sélection photos** : si certaines de ces photos précises ne sont pas dans les assets, Claude Code substitue avec la photo la plus proche (catégorie équivalente) et liste les substitutions dans le PR description pour validation Anatole.

**Tech** :
- Lib légère `embla-carousel-react` (déjà compatible shadcn/ui, ~7 KB gzip).
- Images optimisées Next/Image avec `priority` sur la 1re slide (LCP).
- Overlay gradient dark sur 40% bottom pour lisibilité texte.
- Le hero actuel (formes flottantes UX boost R) reste en option : on peut le proposer comme variante via flag CMS.

**Critères réception** :
- LCP ≤ 2.5s sur la home (Lighthouse).
- Slider fonctionne au clavier et au touch.
- `prefers-reduced-motion: reduce` → désactive autoplay, garde slide 1 statique.
- Snapshot Playwright des 5 slides.

---

## 5. PR Z — Wiring liens externes + 3 articles d'accueil réels (½ j)

**Branche** : `feat/s5-z-wiring-articles`

**Tâches** :
- Configurer `.env.example` du frontend avec :
  - `NEXT_PUBLIC_CANDIDATURE_URL=https://candidature.pssfp.net` (ou `http://localhost:6003` en dev)
  - `NEXT_PUBLIC_LIBRARY_URL=https://bibliotheque.pssfp.net` (ou `http://localhost:6002` en dev)
  - `NEXT_PUBLIC_FOAD_URL=https://foad.pssfp.net`
- Créer **4 vrais articles** via le seeder `ArticlesSeeder` (validation Anatole) :

  1. **« Formation continue PSSFP : 10 modules courts pour accompagner les réformes des finances publiques »**
     - Photo : illustration formation continue (Centre Pasteur ou Salle de cours)
     - Résumé : présentation de l'offre 2026 (10 modules continues + tarifs + cibles), lien vers `/formations/formation-continue`
     - Catégorie : Formation
     - Date : récente

  2. **« Le PSSFP forme les cadres du Centre Pasteur de Yaoundé »**
     - Photo : photo de la session au Centre Pasteur (à sélectionner dans assets, sinon placeholder en attendant)
     - Résumé : compte-rendu de la formation dispensée par le PSSFP au Centre Pasteur sur la gestion financière publique
     - Catégorie : Coopération institutionnelle

  3. **« Le PSSFP à l'Assemblée Nationale du Cameroun : formation des cadres parlementaires »**
     - Photo : session à l'Assemblée Nationale + logo Assemblée Nationale (déjà dans `assets-source/logos/`)
     - Résumé : compte-rendu de la formation dispensée à l'Assemblée Nationale, partenariat institutionnel
     - Catégorie : Coopération institutionnelle

  4. **« Lancement de l'appel à candidature 14e promotion (P14) »**
     - Photo : banderole P14 (à créer si pas dispo, sinon réutiliser banderole P12 ou P13 modifiée)
     - Résumé : ouverture des candidatures, dates clés, lien direct vers `candidature.pssfp.net`, conditions admissibilité (BAC+3 économie/gestion/droit, VAE possible), 1.185.000 FCFA/an, 5 spécialités
     - Catégorie : Admissions
     - Featured : true (mis en avant en home)
     - CTA dans l'article : « Candidater maintenant »

- Remplacer les data mock dans `HomeActualites` par fetch API `/v1/articles?featured=true&limit=3` (la 4e accessible via `/actualites`).
- Vérifier que tous les `href="#"` sont remplacés par les vraies URLs.

**Notes sur la rédaction** :
- Pour les articles 2 et 3 (Centre Pasteur, Assemblée Nationale), Claude Code rédige des canevas de 200-300 mots à partir des informations disponibles ; **Anatole valide / corrige avant publication**. Marquer `status=draft` initialement.
- Pour l'article 1 (Formation continue), rédaction directe à partir du catalogue extrait.
- Pour l'article 4 (P14), reprendre le texte officiel de l'appel candidature (dispo dans `Appel a Candidature 12eme promo/` et `Appel à candidature 13e promo/` comme template).

**Critères réception** :
- Pas de `href="#"` résiduel dans le code (audit grep).
- Home → section Actualités → 3 articles featured avec photos réelles.
- Page `/actualites` → liste les 4 articles + pagination prête.
- Cliquer sur un article ouvre `/actualites/<slug>` avec contenu complet.
- Articles 2 et 3 en `status=draft` jusqu'à validation Anatole.

---

## 6. PR AA — Polish final + démo COPIL prête (½ j)

**Branche** : `feat/s5-aa-polish-demo`

**Tâches** :
- Audit Lighthouse production : Performance ≥ 90, A11y = 100, Best Practices ≥ 95, SEO = 100.
- Audit visuel manuel sur les 5 sites de référence (ENA, Sciences Po, HEC, AFC, IMF) : screenshot comparatif.
- Tests Playwright e2e sur les 12 pages principales : pas de console error, pas de 404, dark mode toggle OK.
- Vérification i18n : aucun texte en dur résiduel (audit grep `>[A-Z][a-z].*<`).
- Création d'une page de démo `/demo-copil` (cachée du sitemap) qui regroupe les highlights du site pour la présentation Pr. BASAHAG.
- Génération d'une **brochure PDF 4 pages** depuis les contenus du site (script `php artisan pssfp:export-brochure`).

**Critères réception** :
- Anatole peut faire la démo COPIL sans bug visible.
- Brochure PDF téléchargeable.

---

## 7. Récapitulatif livrables

| PR | Sujet | Effort | Bloque démo COPIL ? |
|---|---|---|---|
| V | Import assets MinIO + Media Library | 1 j | Oui |
| W | Pages institutionnelles contenu réel + Mot du DG | ½ j | Oui |
| X | Rubrique Formation continue | 1 j | Oui (manque CDC) |
| Y | Hero showcase / slider premium | 1 j | Non (nice-to-have) |
| Z | Wiring liens + 3 articles réels | ½ j | Oui |
| AA | Polish final + démo prête | ½ j | Oui |
| **Total** | | **4-5 j** | |

---

## 8. Prompt à donner à Claude Code (copier-coller)

```
Sprint S5 — Bouclage site institutionnel (contenu réel).

⚠️ RÈGLE D'OR INSPECT-FIRST — À LIRE EN PRIORITÉ ⚠️
Anatole a déjà commencé à charger des contenus via Filament admin
et à intégrer des assets (logo, certaines pages, etc.). Sprint S5 est
un sprint d'AMÉLIORATION et de COMPLÉMENT, PAS une remise à zéro.

AVANT TOUTE création/modification, suis la procédure Inspect-First de
la section "RÈGLE D'OR" de la spec (en haut du document):
  1. Inventaire BDD via php artisan tinker (Pages, Articles, Media)
  2. Inventaire MinIO via storage:list ou mc ls
  3. Inventaire code via grep
  4. Si conflit potentiel → issue GitHub `inspect-conflict` + skip
  5. Préférer updateOrCreate(['slug'=>$slug], [champs vides uniquement])
     plutôt que create() ou seed destructif
  6. JAMAIS de migrate:fresh --seed sans confirmation explicite Anatole

Étape 0 OBLIGATOIRE de chaque PR (V→AA): commencer par produire
un inventaire de l'existant pertinent dans la PR description, et
indiquer pour chaque tâche si on CRÉE / COMPLÈTE / AMÉLIORE / SKIP.

──────────────────────────────────────────────────────────────────

Lis docs/sprints/sprint-S5-bouclage-institutionnel.md de bout en bout
(la règle Inspect-First en haut, puis les PRs V à AA, puis l'annexe
section 10bis sur ui-ux-pro-max).

Avant la PR V:
  cd backend
  Vérifie .env (déjà créé par Anatole probablement)
  Vérifie que docker compose est up (postgres + minio + redis + meilisearch + mailpit)
  php artisan migrate  (sans --fresh, sans --seed)
  → produis inventaire complet en commentaire pour validation

PR V — Import assets (Inspect-First!):
  Étape 0: lister les Media déjà présents dans Filament + MinIO
  Étape 1: identifier les assets MANQUANTS dans Documents/PS/03_PROJETS/SITE WEB PSSFP/
  Étape 2: importer UNIQUEMENT les manquants (skip si filename + checksum identique)
  Cible: MinIO buckets pssfp-media/{logos,photos,documents}
  Documente dans docs/asset-inventory.md (ajout, pas remplacement)

PR W — Pages contenu réel (Inspect-First!):
  Étape 0: php artisan tinker → liste des Pages existantes par slug
  Pour chaque page cible (mot-du-president, presentation, organigramme...):
    SI EXISTE: enrichir UNIQUEMENT champs vides (description, meta_title vides...)
              AMÉLIORER le layout sans toucher au contenu texte
    SI N'EXISTE PAS: créer via updateOrCreate
  Renommage menu "Le PSSFP" → "À propos de nous" (vérifier si déjà fait)
  Audit grep "Directeur Général|\bDG\b" → corriger en "Président du Comité de Pilotage"
    (vérifier si déjà fait avant!)

PR X — Formation continue (Inspect-First!):
  Étape 0: vérifier si menu Formations existe déjà avec sous-menus
  Source: docs/sources/catalogue-formations-extracted.txt (75 pages déjà extraites)
  Pour les 10 modules formation continue: créer chaque page UNIQUEMENT si absente
  Card "Formation continue" en home: vérifier qu'elle n'est pas déjà ajoutée

PR Y — Hero showcase (Inspect-First!):
  Étape 0: regarde le composant Hero actuel — il y a déjà un Hero avec
    AnimatedBeam + animations. Le NOUVEAU Hero carousel doit être une
    NOUVELLE variante via flag CMS (champ `hero_variant: 'static'|'carousel'`)
    sur la Page Accueil. NE PAS supprimer le Hero existant.
  npm i embla-carousel-react (vérifier qu'il n'est pas déjà installé)
  Photos: utiliser celles importées en PR V, pas dupliquer

PR Z — Wiring + articles (Inspect-First!):
  Étape 0: php artisan tinker → liste Articles existants
  Pour les 4 articles: créer UNIQUEMENT si slug absent
  .env.example frontend: vérifier si NEXT_PUBLIC_CANDIDATURE_URL existe déjà
    (peut-être déjà configuré par Anatole) — ajouter sans écraser
  HomeActualites: vérifier si déjà migré du mock vers fetch API

PR AA — Polish + démo:
  Lighthouse production, audit i18n, tests Playwright e2e
  Page /demo-copil cachée
  Script export-brochure PDF

Garde-fous PARTOUT:
- INSPECT-FIRST sur chaque PR (étape 0 obligatoire)
- Charte CDC §10.1 (Violet #6B2FA0 / Or #C9A227 / Lavande #EDE7F6)
  via apps/frontend/design-system/CHARTE-OVERRIDE.md (déjà créé en
  PR design-system bootstrap, lis-le avant tout code UI)
- A11y axe-core 0 violation critique
- Dark mode validé sur chaque PR
- prefers-reduced-motion respecté
- i18n: tout en clés next-intl, rien en dur
- Tests Playwright snapshots versionnés
- Pas de `migrate:fresh --seed` sans demander Anatole

Si une étape demande validation Anatole (rédaction articles Centre Pasteur
ou Assemblée Nationale, choix des 5 photos hero, conflit inspect-first
détecté), ouvre une issue GitHub label `validation-anatole` ou
`inspect-conflict` et continue les tâches non bloquantes.

Pour chaque PR, dans le PR description GitHub, indique :
  ## Inventaire avant intervention
  - Pages existantes touchées : [liste]
  - Articles existants touchés : [liste]
  - Assets existants touchés : [liste]
  ## Actions
  - CRÉÉ : [...]
  - COMPLÉTÉ : [...]
  - AMÉLIORÉ : [...]
  - SKIPPÉ (déjà fait) : [...]

Reporte chaque PR mergée avec lien GitHub + capture d'écran avant/après.
```

---

## 9. Checklist validation Anatole — TOUTES VALIDÉES le 2026-05-08

- [x] DOCX « Mot du Président » = version finale (mai 2026)
- [x] **Pr. BASAHAG = Président du Comité de Pilotage (PCP), pas DG, il n'y a pas de DG**
- [x] Contenu Formation continue = catalogue PDF officiel extrait dans `docs/sources/catalogue-formations-extracted.txt`
- [x] 5 slides du showcase hero proposés section 4 (validation finale dans la PR Y)
- [x] 4 articles d'accueil : Formation continue, Centre Pasteur, Assemblée Nationale, P14
- [x] Menu « **À propos de nous** » (remplace « Le PSSFP ») avec sous-menus
- [x] Menu « Formations » avec sous-menus refactorisé selon catalogue (Master + 5 spécialités, Formation continue + 10 modules, Certifications, Séminaires, FOAD, Catalogue PDF)

**Sprint S5 prêt à kickoff. Claude Code peut démarrer immédiatement.**

---

## 10bis. Annexe — Usage de `/ui-ux-pro-max` dans Sprint S5

**Décision Anatole 2026-05-10** : on utilise le skill `/ui-ux-pro-max` (https://github.com/nextlevelbuilder/ui-ux-pro-max-skill, v2.5.0, 73k★) **comme outil principal** pour le design system. Magic 21st.dev (`/ui`, `/21`) reste installé en complément optionnel, mais ui-ux-pro-max **prime** sur tout choix design.

### Pourquoi ui-ux-pro-max plutôt que Magic ?

| Critère | Magic (`/ui`) | ui-ux-pro-max (`/ui-ux-pro-max`) |
|---|---|---|
| Output | Composant unitaire ad hoc | Design System MASTER.md + overrides par page |
| Cohérence inter-composants | Non garantie | Garantie par le MASTER versionné |
| Domaine specifique | Aucun | 161 règles dont Government / Higher Education |
| Anti-patterns codifiés | Non | Oui (refuse les gradients AI purple/pink, etc.) |
| Pre-delivery checklist a11y | Non | Oui |
| Persistance | Non | Oui (`design-system/` versionné) |
| Convient à un site institutionnel | Risqué (dérives) | Idéal |

Pour PSSFP — site institutionnel public francophone à enjeu CAMES — la cohérence prime sur la rapidité. **ui-ux-pro-max gagne**.

### Étape 1 — Installation ui-ux-pro-max (une fois pour toutes)

À exécuter **avant la PR V** (sprint kickoff) :

```bash
# Option A — via marketplace Claude Code (le plus simple)
# Dans Claude Code, taper:
/plugin marketplace add nextlevelbuilder/ui-ux-pro-max-skill
/plugin install ui-ux-pro-max@ui-ux-pro-max-skill

# Option B — via CLI (équivalent)
npm install -g uipro-cli
cd /Users/anatole-savics/Documents/PS/03_PROJETS/SITE WEB PSSFP/pssfp/apps/frontend
uipro init --ai claude
```

Vérification : `python3 .claude/skills/ui-ux-pro-max/scripts/search.py --help` doit lister les commandes du skill.

### Étape 2 — Génération du Design System MASTER (5 min)

Toujours dans `apps/frontend` :

```bash
# Génère design-system/MASTER.md adapté à PSSFP
python3 .claude/skills/ui-ux-pro-max/scripts/search.py \
  "government higher education public finance institution Cameroon francophone" \
  --design-system --persist \
  -p "PSSFP Programme Supérieur de Spécialisation en Finances Publiques" \
  -f markdown
```

### Étape 3 — Override charte CDC §10.1 (CRITIQUE)

Le skill va proposer une palette par défaut. **On la remplace** par la charte officielle gelée. Créer `apps/frontend/design-system/CHARTE-OVERRIDE.md` qui prime sur le MASTER :

```markdown
# CHARTE-OVERRIDE.md — Règles non-négociables PSSFP

Ces règles **priment** sur toute suggestion de `MASTER.md` ou de tout outil tiers.

## Couleurs (CDC v5 §10.1, gelées par ADR)
- Primary : #6B2FA0 (Violet institutionnel) — JAMAIS #2D1454 ou autre
- Secondary : #C9A227 (Or excellence) — JAMAIS #C9A040 ou autre
- Tertiary : #EDE7F6 (Lavande)
- Anthracite : #1F1A24 (texte/dark bg)
- Crème : #FAF8F5 (light bg)
- États : Vert #2E7D32, Rouge #C62828

## Typographie (CDC v5 §10.1)
- Titres H1-H4 : Playfair Display (600/700/900)
- Corps : Inter (400/500/600)
- UI / Boutons : DM Sans (500)

## Anti-patterns INTERDITS
- ❌ Gradients AI purple/pink (#A855F7 → #EC4899) — cliché incompatible institutionnel
- ❌ Glow néon, brutalism, claymorphism
- ❌ Glassmorphism transparence > 60% (lisibilité d'abord)
- ❌ Emojis utilisés comme icônes (utiliser lucide-react)
- ❌ Texte en dur dans le JSX (toujours next-intl t('key'))
- ❌ Animations sans respect de prefers-reduced-motion

## Pre-delivery non négociable
- [ ] Contraste WCAG AA partout (≥4.5:1 corps, ≥3:1 UI)
- [ ] focus-visible:ring-2 sur tous éléments interactifs
- [ ] cursor-pointer sur tout cliquable
- [ ] Hover transitions 150-300ms ease-out
- [ ] Responsive testé sur 375 / 768 / 1024 / 1440
- [ ] prefers-reduced-motion respecté (pas d'autoplay, pas de Ken Burns, pas de stagger)
- [ ] Dark mode validé sur chaque composant
- [ ] axe-core 0 violation critique
```

### Étape 4 — Génération des overrides par page

```bash
# Page Accueil
python3 .claude/skills/ui-ux-pro-max/scripts/search.py \
  "institutional homepage hero data viz public finance editorial" \
  --design-system --persist -p "PSSFP" --page "accueil"

# Page Mot du Président
python3 .claude/skills/ui-ux-pro-max/scripts/search.py \
  "leader quote editorial layout institutional president" \
  --design-system --persist -p "PSSFP" --page "mot-president"

# Page Formation continue (la pièce maîtresse)
python3 .claude/skills/ui-ux-pro-max/scripts/search.py \
  "training catalog cards bento public sector education" \
  --design-system --persist -p "PSSFP" --page "formation-continue"

# Page Master + 5 spécialités
python3 .claude/skills/ui-ux-pro-max/scripts/search.py \
  "academic program presentation higher education master" \
  --design-system --persist -p "PSSFP" --page "master"

# Page Actualités
python3 .claude/skills/ui-ux-pro-max/scripts/search.py \
  "news editorial magazine timeline institutional" \
  --design-system --persist -p "PSSFP" --page "actualites"

# Page Partenaires
python3 .claude/skills/ui-ux-pro-max/scripts/search.py \
  "partners trust logos international institutional" \
  --design-system --persist -p "PSSFP" --page "partenaires"
```

Résultat attendu — arborescence design-system :

```
apps/frontend/design-system/
├── MASTER.md                     # Skill output, overridden par CHARTE
├── CHARTE-OVERRIDE.md            # Source de vérité absolue (non-négociable)
└── pages/
    ├── accueil.md
    ├── mot-president.md
    ├── formation-continue.md
    ├── master.md
    ├── actualites.md
    └── partenaires.md
```

### Étape 5 — Workflow contextuel à chaque PR du Sprint S5

Avant de coder un composant, Claude Code lit dans cet ordre :

1. `apps/frontend/design-system/CHARTE-OVERRIDE.md` (règles non-négociables)
2. `apps/frontend/design-system/MASTER.md` (style global)
3. `apps/frontend/design-system/pages/<slug>.md` si la page concernée a un override
4. La spec PR du Sprint S5 (§ correspondant)

Puis il code en respectant strictement la hiérarchie : CHARTE > pages override > MASTER > spec PR.

### Étape 6 — Mise à jour du `apps/frontend/CLAUDE.md`

Ajouter ce paragraphe en haut du `CLAUDE.md` du frontend :

```markdown
## Design System — Source de vérité

Avant tout code UI, lis dans cet ordre strict :
1. `apps/frontend/design-system/CHARTE-OVERRIDE.md` — règles non-négociables (charte CDC §10.1)
2. `apps/frontend/design-system/MASTER.md` — design system généré par /ui-ux-pro-max
3. `apps/frontend/design-system/pages/<slug>.md` — override page si présent

Ne génère JAMAIS un composant sans avoir lu la CHARTE. Toute suggestion d'outil tiers
(Magic /ui, ChatGPT, autre) qui contredit la CHARTE doit être ignorée.

Le skill /ui-ux-pro-max peut être ré-invoqué pour générer un nouvel override page
si une page nouvelle est créée. Les fichiers design-system/ sont versionnés.
```

### Étape 7 — Si tu utilises malgré tout Magic ponctuellement

OK pour des micro-composants génériques (un toast, un skeleton loader, un toggle).
Préfixe systématiquement le prompt `/ui` par :

```
Use the project's CHARTE-OVERRIDE.md design tokens (--pssfp-violet #6B2FA0,
--pssfp-or #C9A227, Playfair Display + Inter + DM Sans). All user-facing text
in next-intl t('key'). Respect prefers-reduced-motion. No emojis as icons.
Output a single shadcn/ui-compatible TypeScript component.

Now generate: [ton besoin]
```

Pour tout composant de page-niveau (hero, section, footer, page éditoriale), **n'utilise PAS Magic** — utilise ui-ux-pro-max + Claude Code direct, c'est plus cohérent.

### Référence

- Skill GitHub : https://github.com/nextlevelbuilder/ui-ux-pro-max-skill
- Catégorie matchée : Government + Higher Education + Public Finance (mix)
- Version cible : v2.5.0 ou supérieure
- Voir aussi : `docs/sprints/sprint-S4-uxboost-phase2.md` (spec UX Boost antérieure qui décrit déjà le skill — toujours valide, complétée par cette annexe).

---

## 11. Sources utilisées

- `docs/sources/catalogue-formations-extracted.txt` — extraction texte du catalogue PDF officiel (75 pages, 42 KB), source de vérité pour la rubrique Formations
- `Documentation/Mot du Président.docx` (15 KB) — texte original du Pr. BASAHAG
- `Documentation/Présentation PSSFP.docx` (30 KB)
- `Documentation/Organigramme PSSFP.docx` (38 KB) — bios direction
- `assets-source/photos/` — 1.2 GB de photos (campus, promos, cérémonies, voyages, séminaires)
- `assets-source/logos/` — 11 logos officiels (MINFI, MINESUP, UY2, Expertise France, FMI, Assemblée Nationale Cameroun, etc.)
- `Documentation/CDC_Site_Web_PSSFP_2026.docx` — CDC v5

---

**Ce document est versionné** : `pssfp/docs/sprints/sprint-S5-bouclage-institutionnel.md` (v1.4 — règle Inspect-First ajoutée 2026-05-10 PM, protège le travail manuel d'Anatole en admin Filament)
