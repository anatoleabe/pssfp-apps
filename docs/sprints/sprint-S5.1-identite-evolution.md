# Sprint S5.1 — Évolution identité visuelle + correctifs bloquants

> **Origine** : (1) Audit visuel `docs/audits/sprint-s5-visual-audit.md` a identifié 3 bugs P0 + 4 bugs P1 + 3 P2 ; (2) Anatole a partagé une proposition d'identité visuelle 2026 (« PSSFP — Identité visuelle proposée ») qui fait évoluer la charte CDC v5 §10.1 vers une palette plus institutionnelle et mature.
> **Date** : 2026-05-14
> **Durée estimée** : 2.5 à 3 jours en mode autonome Claude Code
> **PR cible** : 1 PR consolidé `feat/s5.1-identite-evolution`

---

## 1. Nouvelle identité visuelle — décisions actées

### Palette de couleurs (remplace CDC v5 §10.1)

| Token | Nom | Hex | Usage |
|---|---|---|---|
| `--pssfp-prune` | **Prune institutionnelle** | `#4A2E67` | Primary — autorité, titres, navigation, CTA principal. Remplace `#6B2FA0`. |
| `--pssfp-lavande` | **Lavande grisée** | `#A592BD` | Tertiary — fonds doux, états neutres, dividers. Remplace `#EDE7F6`. |
| `--pssfp-bleu-petrole` | **Bleu pétrole** | `#0F3A4A` | **NOUVEAU** — bandeaux institutionnels, sections sombres, contrastes forts. |
| `--pssfp-or` | **Or champagne** | `#D4AF6A` | Accent — excellence, partenariats, highlights. Remplace `#C9A227`. |
| `--pssfp-ivoire` | **Blanc ivoire** | `#FAF7F2` | Background clair. Remplace `#FAF8F5`. |
| `--pssfp-graphite` | **Gris graphite** | `#3C3C3C` | Texte principal. Remplace `#1F1A24` anthracite. |

### Couleurs dérivées (dark mode + variations)

Calculées automatiquement par Tailwind en HSL :
- `--pssfp-prune-dark` : `#3A2452` (hover, dark mode background variant)
- `--pssfp-prune-light` : `#5C3A7E` (hover light variants)
- `--pssfp-bleu-petrole-dark` : `#082A37`
- `--pssfp-or-light` : `#E5C788` (hover or)
- `--pssfp-graphite-light` : `#6B6B6B` (texte secondaire)

### Typographies (remplace CDC v5 §10.1)

| Famille | Usage | Source |
|---|---|---|
| **Cormorant Garamond** SemiBold (600), Bold (700) | Titres H1-H4, accents éditoriaux | Google Fonts |
| **Source Sans 3** Regular (400), Medium (500), SemiBold (600) | Body, paragraphes, navigation, UI | Google Fonts |
| **DM Sans** Medium (500) | À conserver pour micro-UI (badges, eyebrows letter-spaced) — optionnel | Google Fonts |

**Note** : Playfair Display et Inter (charte précédente) sortent du design system. Audit grep + remplacement nécessaire.

### Principes visuels (à intégrer dans design-system/MASTER.md)

Quatre piliers identitaires :
1. **Institutionnel** — solidité, rigueur, confiance (poids visuel : prune dominant, lignes nettes)
2. **Chic** — élégance, sobriété, raffinement (espaces blancs généreux, typo serif)
3. **Moderne** — clarté, innovation, ouverture (touches or, animations subtiles)
4. **Excellence** — performance, exigence, impact durable (data viz, chiffres clés visibles)

### Tagline officielle

> **« Former. Moderniser. Transformer les finances publiques. »**

À intégrer dans le footer + meta description site + tagline éditoriale Hero.

---

## 2. Pourquoi cette évolution est cohérente

- **Prune `#4A2E67` est plus profond** que l'ancien violet `#6B2FA0` — moins « SaaS / tech », plus « ministère / autorité ».
- **Ajout du bleu pétrole** crée un 2e accent d'autorité aligné sur les codes des institutions financières (Trésor, FMI, AFD). Donne une profondeur que le mono-violet n'offrait pas.
- **Or champagne `#D4AF6A`** plus chaud et moins agressif que `#C9A227` — touche éditoriale, pas signal d'alerte.
- **Cormorant Garamond** est la typo serif des éditions Gallimard, Le Monde Diplomatique, La République française — gravitas francophone, plus distinctive que Playfair Display (très utilisée en tech US).
- **Source Sans 3** = équivalent open-source d'Inter, optimisée pour le français.

---

## 3. Procédure d'adoption — création ADR-0008

La charte CDC v5 §10.1 étant **gelée par ADR**, toute modification passe par une nouvelle ADR. Première tâche du sprint.

**Fichier à créer** : `docs/adr/0008-evolution-charte-2026.md`

Contenu minimal :
- Statut : Accepted
- Date : 2026-05-14
- Contexte : feedback Anatole, proposition identité visuelle 2026 partagée le 14 mai
- Décision : adopter la nouvelle palette + typo détaillée section 1 ci-dessus
- Supersedes : §10.1 du CDC v5 (couleurs et typo uniquement, le reste reste valide)
- Conséquences : refonte tokens design system, audit grep des anciennes valeurs, regénération `design-system/MASTER.md`, communication COPIL à prévoir lors de la prochaine démo (validation symbolique)

---

## 4. Bugs P0+P1 à fixer en même temps

Repris de `docs/audits/sprint-s5-visual-audit.md` — tous corrigés dans le même PR pour ne pas multiplier les cycles de review.

### P0 bloquants démo COPIL

1. **404 sur `/a-propos/mot-du-president`** — créer la page via seeder, extraire le DOCX, mention « Président du Comité de Pilotage » (pas DG).
2. **Page `/a-propos` racine quasi vide** — enrichir avec hero + grid de cards vers les sous-pages.
3. **Stats home `11 / 0 / 14+ / 0+` au lieu de `13 / 5 / 1200+ / 10+`** — bug NumberTicker en light mode initial.

### P1 souhaitables avant démo

4. **Hero light mode sans image, grosse zone vide** — problème SSR/hydration HeroCarousel.
5. **Cards Actualités = placeholders gradient** — fix résolution `featured_image_url` côté liste.
6. **Card FOAD verte hors charte** — remplacer le vert par un dégradé conforme nouvelle palette (prune→lavande ou or→bleu pétrole).
7. **Catalogue PDF marqué « (à venir) »** alors qu'il est importé en PR V.

---

## 5. Améliorations P2 inspirées de la maquette identité

La maquette « Aperçu du site web » contient 3 éléments visuels nouveaux à intégrer :

### 5.1 Bandeau « 4 piliers » bleu pétrole (nouvelle section home, sous le Hero)

Section pleine largeur, fond `var(--pssfp-bleu-petrole)`, texte blanc, 4 piliers en grid :
- **Formations qualifiantes** (icône livre/diplôme)
- **Expertise publique** (icône personnes)
- **Réseau & partenariats** (icône globe)
- **Éthique & intégrité** (icône bouclier)

Chaque pilier : icône or 32px + titre Cormorant 18px blanc + sous-titre Source Sans 14px lavande grisée.

### 5.2 Section « Nos engagements » (3 cards éditoriales)

3 cards alignées avec icônes en cercle violet :
- **Rigueur et qualité** — Des programmes alignés sur les meilleures pratiques et les besoins des administrations publiques.
- **Innovation pédagogique** — Des méthodes actives et des outils numériques pour une expérience d'apprentissage optimale.
- **Impact durable** — Des compétences renforcées pour des finances publiques performantes et au service de la société.

Chaque card : icône or 24px dans cercle prune `bg-pssfp-prune/10`, titre Cormorant 20px, body Source Sans 15px, lien « En savoir plus → » or.

### 5.3 Hero photo bâtiment néoclassique (nouvelle slide 1)

Ajouter en slide 1 du HeroCarousel une photo de **bâtiment institutionnel à colonnes** (Palais des Congrès Yaoundé, MINFI, ou Cour Suprême — disponible dans `assets-source/photos/` ?). Si pas dispo, utiliser une image générée IA respectant la maquette (façade colonnes blanche, fronton, ciel pastel).

Headline associé : *« Former aujourd'hui les acteurs de demain »* (variante du existant).
Sous-titre : *« PSSFP accompagne la modernisation des finances publiques par la formation, l'expertise et l'innovation. »*

---

## 6. Liste exhaustive des actions

### Phase 1 — Adopter l'identité (½ jour)

1. Créer `docs/adr/0008-evolution-charte-2026.md` (cf. section 3)
2. Mettre à jour `apps/frontend/design-system/CHARTE-OVERRIDE.md` avec nouvelle palette + typo (section 1)
3. Mettre à jour `apps/frontend/tailwind.config.ts` :
   - Remplacer tokens `pssfp-violet`, `pssfp-or`, `pssfp-lavande`, `pssfp-anthracite`, `pssfp-creme`
   - Ajouter tokens dérivés `pssfp-prune-dark/light`, `pssfp-bleu-petrole`, etc.
4. Mettre à jour `apps/frontend/src/app/globals.css` :
   - Remplacer toutes les CSS variables `--pssfp-*`
   - Vérifier dark mode tokens
5. Ajouter Google Fonts dans `apps/frontend/src/app/layout.tsx` :
   - Cormorant Garamond (600, 700)
   - Source Sans 3 (400, 500, 600)
   - Retirer Playfair Display et Inter du chargement
6. Audit grep sur tout le repo des anciennes valeurs hex et noms de fonts :
   ```bash
   grep -rn "#6B2FA0\|#C9A227\|#EDE7F6\|#FAF8F5\|#1F1A24" apps/frontend/src apps/frontend/tailwind.config.ts apps/frontend/src/app/globals.css packages/ui/src
   grep -rn "Playfair Display\|font-playfair\|font-inter\b" apps/frontend/src packages/ui/src
   ```
   Remplacer toutes les occurrences. Utiliser sed avec backup ou faire à la main si risque ambigu.
7. Mettre à jour `apps/frontend/CLAUDE.md` (section charte) avec la nouvelle palette et typo.
8. Régénérer `apps/frontend/design-system/MASTER.md` via skill ui-ux-pro-max si nécessaire (avec préambule charte mise à jour). Sinon éditer manuellement.
9. Mettre à jour le tagline footer + meta description site.

### Phase 2 — Correctifs bugs P0+P1 (1 jour)

10. **P0 #1 — Page Mot du Président** :
   - Extraire `/Users/anatole-savics/Documents/PS/03_PROJETS/SITE WEB PSSFP/Documentation/Mot du Président.docx` en Markdown via `python3 -c "import docx2txt; print(docx2txt.process('...'))"` ou pandoc
   - Créer ou compléter le seeder `MotPresidentPageSeeder` avec `updateOrCreate(['slug'=>'mot-du-president', 'parent_slug'=>'a-propos'])`
   - Sous-titre : « Pr. BASAHAG Achile Nestor, Président du Comité de Pilotage du PSSFP »
   - Photo : `assets-source/photos/direction/Dr Basahag Achile.JPG` (déjà importée MinIO en PR V — vérifier media_id)
   - Layout éditorial : 2 colonnes 40/60 (photo + nom + titre / texte), citation décorative, breadcrumb visible
   - SEO meta description = 160 premiers caractères du DOCX

11. **P0 #2 — Page /a-propos racine** :
   - Créer ou compléter Page `slug='a-propos'` (root, parent_slug=null)
   - Hero avec eyebrow « LE PSSFP » + titre Cormorant « Un institut public de référence depuis 2013 »
   - Sous-titre court présentation
   - Grid 8 cards (icône + titre + 1 phrase + lien) vers chaque sous-page : Mot du Président, Présentation, Comité de Pilotage, Organigramme, Convention tripartite, Histoire, Infrastructure, Conformité CAMES, Partenaires
   - Chaque card style identique aux cards Bento spécialités existantes (cohérence)

12. **P0 #3 — Fix NumberTicker** :
   - Fichier vraisemblablement `apps/frontend/src/components/home/home-stats.tsx` ou similaire
   - Si `prefers-reduced-motion: reduce` → afficher valeurs finales directement
   - Sinon IntersectionObserver : initialiser la valeur finale dans `useState` (au lieu de 0) et n'animer que si visible
   - Vérifier que les valeurs hardcodées sont bien `13 / 5 / 1200+ / 10+`
   - Mobile et desktop : même comportement

13. **P1 #4 — Fix Hero light mode image** :
   - Vérifier `apps/frontend/src/components/home/home-hero.tsx` ou `hero-carousel.tsx`
   - L'image de slide 1 doit être servie au SSR (Next/Image avec `priority`)
   - Pas de conditionnement par theme (les images sont identiques light/dark, juste l'overlay diffère)
   - Tester en mode reload sans cache (Cmd+Shift+R)

14. **P1 #5 — Fix Actualités cards images** :
   - Le composant liste `/actualites` probablement dans `apps/frontend/src/app/[locale]/actualites/page.tsx`
   - Le fetch côté liste retourne `articles[]` — chaque article doit avoir `featured_image_url` résolu
   - Comparer avec `HomeActualites` qui fonctionne ; appliquer la même résolution
   - Si problème de URL signée MinIO qui expire : vérifier la durée (`signed_url_ttl`) et passer à 1h minimum

15. **P1 #6 — Card FOAD** :
   - Fichier vraisemblablement `apps/frontend/src/components/home/home-acces-rapides.tsx`
   - Remplacer le gradient vert par : `bg-gradient-to-br from-pssfp-prune via-pssfp-prune-light to-pssfp-lavande`
   - Ou : `bg-gradient-to-br from-pssfp-bleu-petrole to-pssfp-prune` (variante plus distinctive)

16. **P1 #7 — Lien catalogue PDF** :
   - Sur `/formations/formation-continue`, remplacer le texte « Télécharger le catalogue complet (PDF) (à venir) » par un vrai lien
   - URL probable : `/storage/documents/catalogue-pssfp-2026.pdf` ou URL MinIO signée
   - Vérifier dans Filament Media Library le nom du fichier importé en PR V
   - Ajouter icône download Lucide

### Phase 3 — Améliorations P2 maquette identité (1 jour)

17. **Bandeau 4 piliers** (cf. 5.1) sur la home, ajouté entre Hero et Stats
18. **Section « Nos engagements »** (cf. 5.2) sur la home, ajoutée entre Bento spécialités et Actualités
19. **Slide 1 hero** photo bâtiment néoclassique (cf. 5.3) — si photo dispo, sinon noter en TODO
20. **Footer** : ajouter 4 icônes réseaux sociaux (Facebook, LinkedIn, YouTube, Twitter/X) en circles or, séparateur gradient prune→or→prune, tagline « Former. Moderniser. Transformer les finances publiques. »
21. **Pages Master & Formation continue** : enrichir le layout (au minimum ajouter photo hero, formatter le tableau tarifs en card stylée, mettre les 10 modules en grid 2-col avec icônes)

### Phase 4 — Vérification + démo prête (½ jour)

22. Tests Playwright snapshot updated pour les 10 pages auditées (`apps/frontend/tests/`)
23. Lighthouse production local : a11y et best practices ≥ 90
24. Manual QA : toggle dark mode aller-retour x3, scroll up/down, hover mega menus, click chaque entrée mega menu
25. Mettre à jour `docs/audits/sprint-s5-visual-audit.md` avec section « Résolution » au bas (snapshots after/before)
26. PR description avec :
    - Liste des 7 bugs corrigés
    - Comparaison palette avant/après (table)
    - Captures before/after pour bugs visuels
    - Closes #35, #36, #37, #38, #39, #40, #42
    - Mention ADR-0008 et lien

---

## 7. Garde-fous Inspect-First (rappel)

**Avant toute création/modification BDD** (Pages, Articles, Media) :
```bash
cd apps/backend
php artisan tinker --execute="
  echo 'Pages a-propos*: ';
  App\Models\Page::where('slug', 'like', 'a-propos%')->orWhere('slug', 'like', '%mot-du-president%')->get()->each(fn(\$p) => print('  - ' . \$p->slug . ' (' . \$p->id . ')' . PHP_EOL));
"
```

Si une page existe déjà : **updateOrCreate avec champs vides uniquement**, ne pas écraser le contenu saisi.

**Pas de `migrate:fresh --seed`**. Pas de `php artisan db:wipe`.

---

## 8. Prompt prêt à coller dans Claude Code

```
Sprint S5.1 — Évolution identité visuelle + correctifs P0/P1 audit.

Lis docs/sprints/sprint-S5.1-identite-evolution.md de bout en bout.
Lis aussi docs/audits/sprint-s5-visual-audit.md pour les détails des bugs.

⚠️ RÈGLE INSPECT-FIRST OBLIGATOIRE (cf section 7):
- php artisan tinker pour inventorier l'existant AVANT toute création
- updateOrCreate(['slug'=>...], [...champs vides uniquement])
- PAS de migrate:fresh, PAS de db:wipe

Branche: feat/s5.1-identite-evolution
PR final consolidé (tout en un seul PR, pas 4 séparés)

Phase 1 — Adopter l'identité visuelle 2026:
  - Créer docs/adr/0008-evolution-charte-2026.md (cf section 3 de la spec)
  - Update apps/frontend/design-system/CHARTE-OVERRIDE.md avec NOUVELLE palette:
      --pssfp-prune #4A2E67 (remplace ancien violet #6B2FA0)
      --pssfp-lavande #A592BD (remplace ancienne lavande #EDE7F6)
      --pssfp-bleu-petrole #0F3A4A (NOUVEAU token, 2e accent autorité)
      --pssfp-or #D4AF6A (remplace ancien or #C9A227)
      --pssfp-ivoire #FAF7F2 (remplace ancien crème #FAF8F5)
      --pssfp-graphite #3C3C3C (remplace ancien anthracite #1F1A24)
  - Update tailwind.config.ts + globals.css avec les nouveaux tokens
  - Remplacer Playfair Display par Cormorant Garamond (600/700)
  - Remplacer Inter par Source Sans 3 (400/500/600)
  - Garder DM Sans pour micro-UI letter-spaced
  - Audit grep des anciennes valeurs hex et noms de fonts, remplacer partout
  - Update apps/frontend/CLAUDE.md section charte
  - Régénérer ou éditer design-system/MASTER.md pour cohérence

Phase 2 — Correctifs bugs (cf docs/audits/sprint-s5-visual-audit.md section 3):
  P0 #1 Page Mot du Président:
    - Extraire /Users/anatole-savics/Documents/PS/03_PROJETS/SITE WEB PSSFP/Documentation/Mot du Président.docx en Markdown
    - Seeder MotPresidentPageSeeder updateOrCreate slug=mot-du-president parent=a-propos
    - Sous-titre: "Pr. BASAHAG Achile Nestor, Président du Comité de Pilotage du PSSFP"
    - Photo: Dr Basahag Achile.JPG (importée MinIO en PR V)
    - Layout éditorial 40/60 photo + texte, citation décorative
  P0 #2 Page /a-propos racine:
    - Hero "LE PSSFP" + Cormorant titre
    - Grid 8 cards vers sous-pages
  P0 #3 Fix NumberTicker stats:
    - Valeurs finales 13/5/1200+/10+ au render initial
    - prefers-reduced-motion respecté
  P1 #4 Fix Hero light mode SSR image
  P1 #5 Fix Actualités featured_image_url côté liste
  P1 #6 Card FOAD: gradient prune→lavande (plus de vert)
  P1 #7 Câbler lien catalogue PDF /storage/documents/catalogue-pssfp-2026.pdf

Phase 3 — Améliorations maquette identité (P2):
  - Bandeau 4 piliers bleu pétrole (Formations qualifiantes/Expertise publique/
    Réseau & partenariats/Éthique & intégrité) entre Hero et Stats
  - Section "Nos engagements" 3 cards (Rigueur/Innovation/Impact)
    entre Bento spécialités et Actualités
  - Slide 1 hero photo bâtiment néoclassique si dispo (sinon TODO)
  - Footer: 4 icônes réseaux sociaux + séparateur gradient prune→or→prune
    + tagline "Former. Moderniser. Transformer les finances publiques."
  - Pages Master + Formation continue: photo hero + tableau tarifs en card
    stylée + grid 2-col 10 modules

Phase 4 — Vérification:
  - Tests Playwright snapshot updated (10 pages)
  - Lighthouse production: a11y ≥ 90, best practices ≥ 90
  - Manual QA: dark mode toggle x3, scroll, hover mega menus,
    test chaque entrée mega menus À propos + Formations
  - Update docs/audits/sprint-s5-visual-audit.md avec section "Résolution"

PR description format:
  ## Résumé
  Évolution charte CDC §10.1 → ADR-0008 + correctifs audit P0+P1

  ## Palette avant/après
  | Token | Avant | Après |
  |---|---|---|
  | Primary | #6B2FA0 | #4A2E67 prune |
  ... (table complète)

  ## Bugs fixés
  - [x] #35 404 Mot du Président
  - [x] #36 Stats NumberTicker
  - [x] #37 /a-propos vide
  - [x] #38 Hero light mode
  - [x] #39 Actualités photos
  - [x] #40 Card FOAD verte
  - [x] #42 Catalogue PDF lien

  ## Captures
  [10 captures before/after]

  ## ADR
  - ADR-0008 créée et reviewée

Reporte le PR avec lien GitHub.
Si quelque chose bloque (ex: photo bâtiment néoclassique introuvable,
texte DOCX Mot du Président mal extrait), ouvre une issue label
`validation-anatole` et continue les autres tâches non bloquantes.
```

---

## 9. Communication COPIL

À ajouter au brief démo COPIL (à rédiger ensuite) :

> « L'identité visuelle du site a été affinée le 14 mai 2026 pour renforcer son caractère institutionnel : passage du violet `#6B2FA0` au prune `#4A2E67` (plus proche des codes ENA / Cour des Comptes), ajout du bleu pétrole `#0F3A4A` comme 2e accent d'autorité (référence aux codes des institutions financières internationales), typographie Cormorant Garamond plus distinctive (utilisée par les éditions francophones de référence). L'évolution est actée par ADR-0008 et remplace la palette §10.1 du CDC v5. »

---

**Document versionné** : `pssfp/docs/sprints/sprint-S5.1-identite-evolution.md` (v1.0)
**Tâches associées** : #35-#42 + nouvelles tâches charte
