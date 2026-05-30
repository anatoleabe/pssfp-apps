# Audit visuel Sprint S5 — Site institutionnel PSSFP

> **Date** : 2026-05-14
> **Auditeur** : Cowork (Claude in Chrome — captures localhost:6001)
> **Méthode** : 10 pages capturées en light mode, dark mode et mobile 390x844 (iPhone 12 Pro). Comparatif visuel mental avec ENA / Sciences Po / HEC / Africa Finance Corporation / IMF Institute.
> **Contexte** : audit déclenché par Anatole pour valider la sortie du Sprint S5 (9 PRs mergées) avant démo Pr. BASAHAG / COPIL.

---

## 1. Verdict exécutif

**Le site n'est PAS prêt pour démo COPIL en l'état.** Trois bugs P0 fonctionnels rendent la démo embarrassante :

1. **404 sur /a-propos/mot-du-president** — le lien principal du mega menu « À propos de nous » mène à une page introuvable. Aussi 404 sur `/pssfp/mot-du-president` (slug alternatif).
2. **Page /a-propos racine quasi vide** — un eyebrow « LE PROGRAMME » centré sans contenu. Si Pr. BASAHAG clique « Voir toute la section » du mega menu À propos, il tombe sur une page blanche.
3. **Stats home incorrectes en light mode** — 11 promotions / **0** spécialités / 14+ diplômés / **0+** années d'expertise (au lieu de 13/5/1200+/10+ visibles en dark mode). Donc le 1er visiteur sur la home en mode jour voit des zéros.

**Effort estimé pour livrer une démo « propre »** : ½ à 1 journée de fixes ciblés, PR `fix/s5-audit-bloquants`. Tous les autres correctifs P1/P2 peuvent attendre Sprint S6.

**Une fois ces 3 bugs P0 corrigés**, le site est **présentable** au COPIL — peut-être pas encore au niveau visuel ENA/Sciences Po pour les pages internes (Master/Formation continue restent du Markdown brut sans bento ni photos), mais la home + hero carrousel + dark mode + Contact + page /demo-copil suffisent à porter le pitch.

---

## 2. Audit page par page

### 2.1 Home `/` — light mode desktop (1440x900)

**Captures** : 4 (hero / stats / bento spécialités / formats courts + actualités / accès rapides + footer)

**✅ Points forts** :
- Mega menus glassmorphism violet impeccables (« À propos de nous » 7 entrées, « Formations » 6 entrées) — au niveau Sciences Po
- Typographie Playfair Display 64px+ pour H1 « Former l'élite des finances publiques », rendu très institutionnel
- Bento Grid 5 spécialités : card phare (Fiscalité/Comptabilité « Spécialité phare · 04 ») + 4 cards numérotées 01-05 avec icônes — design moderne et lisible
- Section « Formats courts & à la carte » avec 3 cards (Formation continue / Certifications / Séminaires) bien équilibrée
- Section Actualités 3 cards avec badges (ÉVÉNEMENT, COOPÉRATION), dates, vraies photos (banderole P14, ANNEE ACADEMIQUE 2025-2026, remise diplômes)
- Eyebrow `MASTER PROFESSIONNEL · 5 SPÉCIALITÉS` en or letter-spaced — touche éditoriale
- Hero badge `LE PSSFP — DEPUIS 2013` en pill subtile
- Slide indicators = 5 lignes horizontales (pas dots) — conforme spec Sprint S5

**🔴 Bugs critiques observés** :
- **Hero : pas d'image en light mode au premier render**, juste fond anthracite avec H1 blanc, sous-titre, CTAs. Énorme zone vide à droite du texte (visible aussi entre hero et stats — 600+ px de fond crème sans contenu). Probable problème SSR/hydration du HeroCarousel.
- **Stats valeurs zéro** : `11 / 0 / 14+ / 0+`. Les VRAIES valeurs apparaissent uniquement quand on toggle dark mode (où on voit `13 / 5 / 1200+ / 10+`). Bug NumberTicker / IntersectionObserver.

**🟠 Polish manquant** :
- Card FOAD verte (#1F8E5C visible) → palette CDC §10.1 ne prévoit ni vert ni clash de couleurs entre les 3 cards Accès rapides (vert / violet / or). À uniformiser.

### 2.2 Home `/` — dark mode desktop

**Captures** : 2 (hero après toggle / stats correctes)

**✅ Tout marche correctement** :
- Hero affiche une vraie photo de cérémonie de remise de diplômes (slide 2 « 10 modules pour les acteurs publics »)
- Stats `13 / 5 / 1200+ / 10+` correctes — donc les valeurs SONT dans le code, juste pas affichées en light mode initial
- Glassmorphism du header sticky avec gradient violet-anthracite très premium
- Hero photo en pleine largeur viewport — impact maximal

**⚠️ Observation** :
- Slide hero semble superposer 2 images (cérémonie à gauche + bannière P14 à droite) — possible transition crossfade en cours OU 2 slides rendues en même temps. À vérifier.

### 2.3 Mega menu « À propos de nous » (hover)

**✅ Excellent** :
- Layout 2 colonnes desktop, 7 entrées avec icônes + description, glassmorphism violet semi-transparent, CTA « VOIR TOUTE LA SECTION » en bas
- Entrées : Mot du Président · Présentation du PSSFP · Comité de Pilotage · Organigramme · Convention tripartite · Histoire · Infrastructure · Conformité CAMES · Partenaires
- Animation hover smooth, pas de flash

**🔴 PROBLÈME GRAVE** :
- **L'entrée « Mot du Président » mène à une 404** (`/a-propos/mot-du-president` n'existe pas, ni `/pssfp/mot-du-president`)
- **« VOIR TOUTE LA SECTION » mène à `/a-propos` qui est quasi vide**
- C'est la 1re entrée du 1er menu de la barre principale. Bloquant.

### 2.4 Mega menu « Formations » (hover)

**✅ Excellent** :
- 6 entrées : Master Professionnel · Formation continue · Certifications · Séminaires & voyages d'étude · Admission · Frais de scolarité
- Même design glassmorphism cohérent avec « À propos de nous »
- Icônes et descriptions claires

### 2.5 Page Master `/formations/master`

**Captures** : 2

**✅ Contenu correct** :
- Hero lavande clair, titre Playfair violet « Master Professionnel en Finances Publiques »
- Sous-titre : « Cursus BAC+5 sur 2 ans (4 semestres) — 1 185 000 FCFA/an. 5 spécialités. Reconnu CAMES. »
- Sections : Architecture (M1 tronc commun / M2 spécialisation + mémoire + stage 3 mois), Conditions d'admission (BAC+3, VAE), Modalités (Présentiel Campus Messa / Distanciel FOAD / Hybride), Les 5 spécialités (liste), Reconnaissance CAMES
- Breadcrumb visible : Accueil / Formations / Master Professionnel
- Tout le texte est conforme au catalogue PSSFP officiel

**🟡 Visuel pauvre** :
- Layout = Markdown brut rendu en HTML. Pas de bento, pas de cards, pas de photos, pas de tableau pour modalités, pas d'image hero, juste H2 violet + paragraphes
- Les liens « Voir les conditions d'admission complètes → » ne sont pas cliquables visiblement (lien interne mais sans style hover marqué)
- Aucune illustration, aucune photo de Campus Messa, aucune infographie BAC+3 → Master → débouchés
- Niveau visuel : **70%** du niveau attendu pour démo. Acceptable mais pas wahou.

### 2.6 Page Formation continue `/formations/formation-continue`

**Captures** : 3 (hero / catalogue 10 modules / footer)

**✅ Contenu correct** :
- Hero gradient lavande, titre Playfair violet « Formation continue PSSFP »
- Sous-titre : « 10 modules courts (3 à 5 jours) pour les administrations, élus locaux et cadres en activité. »
- Section « Pour qui ? » : Acteurs financiers public/privé, Responsables CTD, Élus locaux, Cadres et professionnels
- Section « Tarifs » avec tableau Public / Tarif : Administrations 4 995 000 FCFA · Individus 500 000 FCFA · Étudiants PSSFP 250 000 FCFA
- Durée 3 à 5 jours, qualifiantes ou certifiantes
- **Liste correcte des 10 modules du catalogue 2026** : Loi de Finances + Circulaire d'exécution / Gestion Financière EE publics / Budget Programme GAR / Contrôle de Gestion SICOGES / Infractions Réglementation Financière + Faute de Gestion / Cartographie des Risques / Décentralisation et Finances Publiques Locales / Mobilisation et Gestion des Financements Extérieurs / Marchés Publics / Chaîne PPBS

**🟡 Visuel pauvre + erreur** :
- Aucun bento grid des 10 modules (la spec Sprint S5 §3.2 le promettait explicitement) — c'est juste une liste à puces
- Section « Demander un devis » = paragraphe texte avec adresse `usi@pssfp.net` + liste à puces des éléments à préciser. Pas de formulaire interactif comme spec'é
- **« Télécharger le catalogue complet (PDF) (à venir) »** ← le PDF est dans assets-source/Catalogues/ depuis PR V, et même importé dans MinIO. Le lien doit être câblé. Marqué « à venir » alors qu'il est dispo. Bloquant pour démo (Pr. BASAHAG voudra peut-être le télécharger live).
- Niveau visuel : **65%** du niveau attendu. La pièce maîtresse du site est sous-investie en présentation.

### 2.7 Page Mot du Président `/a-propos/mot-du-president` (et `/pssfp/mot-du-president`)

**Capture** : 404

**🔴🔴🔴 BLOQUANT** : Page absente sur les 2 slugs testés.

C'est la PROMESSE numéro 1 du Sprint S5 PR W. Page absente = audit fail catastrophique.

### 2.8 Page À propos `/a-propos`

**Capture** : 1

**🔴🔴 BLOQUANT** : Page presque vide. Juste l'eyebrow « LE PROGRAMME » centré sur fond lavande, sans hero, sans contenu, sans cards vers les sous-pages. C'est la cible du « VOIR TOUTE LA SECTION » du mega menu.

### 2.9 Page Actualités `/actualites`

**Capture** : 1

**✅ Structure correcte** :
- Titre H1 « Actualités » + intro
- 2 cards visibles (Formation continue + Lancement P14) avec dates, badges (ÉVÉNEMENT, À la une), tags, lien « Lire la suite → »
- Layout 2-col : grille articles à gauche + sidebar « Sur Facebook » à droite
- Bonne hiérarchie typo

**🟠 Bugs visibles** :
- **Cards = placeholders gradient lavande/crème** au lieu des vraies photos. Pourtant les MÊMES articles ont leurs photos sur la home. À fixer (probable problème de fetch côté liste paginée).
- Sidebar « Sur Facebook » = card blanche vide. En local sans Page Plugin configuré c'est attendu, mais en démo COPIL ça fera vide. Soit on configure (compte FB), soit on cache la sidebar en attendant.

### 2.10 Page Contact `/contact`

**Capture** : 1

**✅ Excellente** :
- Hero gradient lavande, eyebrow `CONTACTEZ-NOUS` en or letter-spaced, titre Playfair grand avec gradient or-violet sur le mot « écoute »
- Sous-titre clair (« Une question, un projet de partenariat, une demande d'information ? Le secrétariat du PSSFP vous répond... »)
- Badge « ⏱ Réponse sous 48h ouvrées » en pill subtile
- Section « NOUS ÉCRIRE » (formulaire) + « COORDONNÉES » (3 canaux : adresse, téléphone, email)
- Niveau visuel : **90%** du niveau attendu. Probablement la plus belle page interne.

### 2.11 Page /demo-copil (interne, non indexée)

**Capture** : 1

**✅ Parfait pour le pitch** :
- Eyebrow `DÉMONSTRATION INTERNE — SPRINT S5`
- Titre H1 « Bouclage du site institutionnel »
- Mention correcte : « **Dr. BASAHAG Achile Nestor** » comme « Président du Comité de Pilotage » ✅ (pas DG)
- Stats Sprint S5 (6 cards lavande/violet) :
  - 9 Pages institutionnelles seedées
  - 10 Modules formation continue
  - 5 Spécialités Master catalogue
  - 4 Articles d'accueil
  - 5 Slides carrousel hero
  - 67 Assets MinIO importés
- Notation « Page interne — non indexée, non listée dans le sitemap. »

Page idéale pour ouvrir la démo COPIL. Aucun changement nécessaire.

### 2.12 Mobile 390x844 (iPhone 12 Pro)

**Captures** : 2 (hero / stats)

**✅ Responsive OK** :
- Header mobile compact : logo + theme toggle + hamburger menu
- Hero photo cérémonie plein écran, H1 Playfair lisible, sous-titre, 2 CTAs empilés
- Card FOAD floating bottom-right encore visible

**🔴 Même bug stats** : `4 / 1 / 82+` à un moment du scroll puis `12 / 4 / 1655+ / 8+` (valeurs intermédiaires de l'animation). Encore le problème NumberTicker — n'arrive jamais aux vraies valeurs `13 / 5 / 1200+ / 10+`.

---

## 3. Synthèse des correctifs nécessaires

### P0 — Bloquants démo COPIL (TOUS à fixer avant démo)

| # | Bug | Effort | PR |
|---|---|---|---|
| 1 | 404 `/a-propos/mot-du-president` (page principale absente) | 1-2h | `fix/s5-audit-p0-mot-president` |
| 2 | `/a-propos` racine page quasi vide (cible « Voir toute la section ») | 1h | idem PR ci-dessus |
| 3 | Stats home valeurs zéro en light mode initial (NumberTicker bug) | 1-2h | `fix/s5-audit-p0-stats` |

### P1 — Souhaitables avant démo (gros impact visuel, fixables en ½ j)

| # | Bug | Effort | PR |
|---|---|---|---|
| 4 | Hero light mode : image absente, grosse zone vide | 2h | `fix/s5-audit-p1-hero-light` |
| 5 | Actualités cards : placeholders gradient au lieu des vraies photos | 1h | `fix/s5-audit-p1-news-images` |
| 6 | Card FOAD verte hors charte CDC §10.1 | 30 min | idem PR ci-dessus |
| 7 | Lien « Catalogue PDF (à venir) » à câbler vers le PDF importé | 30 min | idem |

### P2 — Polish post-démo

| # | Bug | Effort |
|---|---|---|
| 8 | Pages Master & Formation continue : enrichir layout (bento 10 modules, tableau tarifs stylé, photos hero, formulaire devis) | 1 j |
| 9 | Footer manque réseaux sociaux + séparateur visuel décoratif | 2h |
| 10 | Sidebar « Sur Facebook » sur /actualites : soit configurer le Page Plugin (compte FB + consentement RGPD), soit cacher la sidebar en V1 | ½ j |

### Bonus — vérifier en plus

- Convention `Directeur Général` vs `Président du Comité de Pilotage` : déjà OK sur /demo-copil, vérifier sur les autres pages (page Mot du Président une fois créée, page Comité de Pilotage, page Présentation).
- Le menu À propos de nous > Conformité CAMES, Infrastructure, Convention tripartite, Histoire, Partenaires — chacune existe-t-elle ? Tester chaque entrée du mega menu.
- Le menu Formations > Certifications, Séminaires & voyages d'étude, Admission, Frais de scolarité — idem.

---

## 4. Comparatif vs sites institutionnels de référence

**Niveau actuel par rubrique** (vs ENA / Sciences Po / HEC / AFC / IMF Institute) :

| Élément | PSSFP actuel | Référence visée | Écart |
|---|---|---|---|
| Header sticky + mega menus | 90% | ✓ | Faible |
| Hero éditorial typo Playfair | 85% (light mode brisé) | ✓ | Moyen |
| Stats data-viz | 50% (bug + valeurs incorrectes) | ✓ | Fort |
| Bento Grid programmes | 90% | ✓ | Faible |
| Section éditoriale actualités | 70% (photos manquantes en liste) | ✓ | Moyen |
| Pages détail Master & Formation continue | 65% (Markdown brut) | ✓ (riches photos + infographies) | Fort |
| Page Mot du Président | 0% (404) | ✓ | Critique |
| Page Contact | 90% | ✓ | Faible |
| Footer | 70% (pas de réseaux, séparateurs) | ✓ | Moyen |
| Dark mode premium | 85% | ✓ (Sciences Po, IMF) | Faible |
| Mobile responsive | 80% (stats bug encore) | ✓ | Moyen |

**Verdict** : structure et identité visuelle à 80% du niveau visé. Reste les bugs P0/P1 et 2 pages internes à enrichir (Master + Formation continue) pour atteindre 95%. Le visuel « ENA Paris » est à portée d'1 sprint S6.

---

## 5. Points forts à valoriser en démo COPIL

Lors de la démo, mettre en avant ces 7 succès Sprint S5 :

1. **Mega menus glassmorphism violet** avec icônes + descriptions — niveau Sciences Po
2. **Typographie Playfair Display + Inter** — institutionnel et lisible, conforme CDC §10.1
3. **Bento Grid 5 spécialités** avec card phare + 4 numérotées — UX moderne
4. **Hero carrousel Embla 5 slides** (en dark mode où ça fonctionne) avec slide indicators en lignes
5. **Contenu catalogue 2026 intégré** : 10 modules Formation continue, 5 spécialités Master, tarifs, durées — fidèle au catalogue officiel
6. **Page Contact** avec gradient or-violet et badge « 48h ouvrées » — exemplaire
7. **Page interne /demo-copil** pour structurer le pitch — 6 stats Sprint S5 clairement présentées, mention correcte du PCP

---

## 6. Plan de correctifs recommandé

### Option recommandée — PR « fix/s5-audit-p0-p1 » (1 jour effort)

Faire **un seul PR** consolidé qui corrige les 7 bugs P0+P1 listés ci-dessus. Cela permet :
- Une démo COPIL « propre » en moins de 24h
- Un seul cycle de review au lieu de 4 PRs séparées
- Tests Playwright snapshot updated en une seule fois
- Capture before/after dans le PR description pour Anatole

**Branche** : `fix/s5-audit-p0-p1`
**Contenu** :
1. Créer page `/a-propos/mot-du-president` via seeder (lire `/Users/.../Documentation/Mot du Président.docx`, extraire en markdown, créer la page avec photo Pr. BASAHAG)
2. Créer contenu pour `/a-propos` racine (hero + cards vers sous-pages : Mot du Président, Présentation, Comité de Pilotage, Organigramme, Histoire, Convention tripartite, etc.)
3. Fix NumberTicker : forcer la valeur finale au render initial si `prefers-reduced-motion` OU déclencher l'animation au mount si IntersectionObserver ne trigger pas
4. Fix Hero light mode : vérifier que l'image de slide 1 charge au SSR (pas conditionné par theme)
5. Fix Actualités : adapter le fetch côté liste pour résoudre `featured_image_url` comme sur la home
6. Card FOAD : remplacer le vert par un dégradé violet→lavande ou or→ambre
7. Câbler le lien catalogue PDF (probablement `/storage/documents/catalogue-pssfp-2026.pdf` ou URL signée MinIO)

### Option moins ambitieuse — P0 only (½ jour)

Si timeline serrée pour démo COPIL (cette semaine), réduire au strict minimum :
- Bug 1 + 2 + 3 (les 3 bloquants)
- Reporter P1 en Sprint S6

**Mais alors** : le hero light mode restera vide, les actualités liste resteront avec placeholders, et la card FOAD verte sera visible à l'écran. Démo « passable » mais pas wahou.

---

## 7. Prompt prêt à l'emploi pour Claude Code

```
Sprint S5.1 — Correctifs bloquants audit visuel.

Lis docs/audits/sprint-s5-visual-audit.md de bout en bout.

Cible: PR fix/s5-audit-p0-p1 qui corrige les 7 bugs P0+P1 listés section 3.

⚠️ Inspect-First obligatoire (cf docs/sprints/sprint-S5-bouclage-institutionnel.md):
- Avant toute création/modification, vérifier l'existant via tinker:
    php artisan tinker --execute="App\Models\Page::pluck('slug')->each(fn(\$s)=>print(\$s.PHP_EOL));"
- updateOrCreate(['slug' => ...], [...champs vides uniquement])
- Pas de migrate:fresh

Tâches:
1. Créer/seeder la Page slug=mot-du-president sous parent a-propos
   Source contenu: /Users/anatole-savics/Documents/PS/03_PROJETS/SITE WEB PSSFP/Documentation/Mot du Président.docx
   Sous-titre: "Pr. BASAHAG Achile Nestor, Président du Comité de Pilotage du PSSFP"
   Photo: assets-source/photos/direction/Dr Basahag Achile.JPG (déjà importée MinIO en PR V)

2. Créer/enrichir la Page slug=a-propos (root) avec hero + grid 8 cards vers les sous-pages
   (Mot du Président, Présentation, Comité de Pilotage, Organigramme, Convention tripartite,
    Histoire, Infrastructure, Conformité CAMES, Partenaires)

3. Fix HomeStats NumberTicker:
   - Si prefers-reduced-motion ou si IntersectionObserver n'a pas trigger, afficher la valeur finale directement
   - Vérifier que les valeurs 13/5/1200+/10+ sont bien les valeurs finales (pas 11/0/14+/0+)
   - Mobile inclus (le bug se reproduit en mobile)

4. Fix HeroCarousel light mode:
   - Vérifier que la slide 1 affiche bien sa photo au SSR, pas seulement après hydratation
   - Tester en light mode au reload (Cmd+Shift+R)

5. Fix /actualites cards images:
   - Le composant liste utilise probablement un autre champ que HomeActualites
   - Réutiliser la même résolution `featured_image_url` que sur la home

6. Card FOAD Accès rapides:
   - Remplacer le vert (#1F8E5C ou similaire) par un gradient violet→lavande
   - Charte CDC §10.1: aucun vert autorisé (palette Violet/Or/Lavande/Anthracite/Crème uniquement)

7. Page /formations/formation-continue:
   - Câbler le lien "Télécharger le catalogue complet (PDF)" vers l'URL réelle du PDF importé en PR V
   - Retirer la mention "(à venir)"

PR description:
- Liste les 7 bugs corrigés avec coche
- Avant/après captures pour bugs 3, 4, 5, 6
- Tests Playwright snapshot updated
- Mention "Closes #35, #36, #37, #38, #39, #40, #42"

Tests:
- Playwright snapshot des 10 pages auditées avant push
- Lighthouse run local: a11y et best practices >= 90
- Manual test: toggle dark mode aller-retour, scroll up/down, hover mega menus

Reporte le PR + captures.
```

---

## 8. Captures de référence

[10 captures haute résolution disponibles dans la session Cowork — à attacher à la PR description après commit]

- ss_4304tyblo : Home light mode hero
- ss_5711zde87 : Home light mode stats (bug: 11/0/14+/0+)
- ss_2085uhgd5 : Home dark mode hero slide 3
- ss_3727yuxju : Home dark mode stats (correct: 13/5/1200+/10+)
- ss_74059cocv : Mega menu À propos de nous ouvert
- ss_9738h653o : Mega menu Formations ouvert
- ss_2919bibo2 : 404 sur /a-propos/mot-du-president
- ss_8187qqz7z : /a-propos page quasi vide
- ss_504201eg8 : /actualites cards sans photos
- ss_7063dbyqr : /contact (bonne page de référence)
- ss_3730tvr9k : /demo-copil
- ss_7092q8s9d : Mobile home hero
- ss_8713mgpqx : Mobile stats (encore bug)

---

---

## 9. Résolution — Sprint S5.1 (2026-05-14)

Toutes les corrections listées section 3 (P0+P1) ont été appliquées dans le PR **`feat/s5.1-identite-evolution`** (consolidé avec l'évolution de la charte graphique 2026 — cf. `docs/sprints/sprint-S5.1-identite-evolution.md` et **ADR-0008**).

### 9.1 P0 — bloquants démo COPIL — corrigés

| # | Bug | Statut | Détail technique |
|---|---|---|---|
| 1 | 404 `/a-propos/mot-du-president` | ✅ **Résolu** | Le slug canonique est `mot-president` (sans "du") et est seedé `published`. Ajout d'un **redirect 308** `/a-propos/mot-du-president` → `/a-propos/mot-president` (+ alias `/pssfp/mot-du-president`) dans `frontend/next.config.js` pour absorber la variante avec "du" qui circule dans certains documents pré-démo. |
| 2 | `/a-propos` page racine quasi vide | ✅ **Résolu** | La page racine `a-propos` (parent_slug=null) n'existait pas en BDD : le menu API exposait donc les enfants à plat. Ajout dans `AProposPagesSeeder` d'une entrée racine. Page enrichie côté frontend (grid 3-col responsive avec icônes lucide + descriptions courtes par sous-page). |
| 3 | Stats home valeurs zéro en light mode initial | ✅ **Résolu** | Refonte `components/magic-ui/number-ticker.tsx` : valeur finale rendue au SSR + 1er render client, animation au mount avec reset à 0, **watchdog 1.8 s** qui force la valeur finale si le spring ne l'atteint pas, `prefers-reduced-motion` respecté. Le visiteur ne voit plus jamais « 0 ». |

### 9.2 P1 — souhaitables — corrigés

| # | Bug | Statut | Détail |
|---|---|---|---|
| 4 | Hero light mode : image absente | ✅ | `HomeShowcase` en import statique (above-the-fold). **Gradient ink-deep de secours** sur chaque slide (visible tant que MinIO charge). `loading="eager"` explicite sur slide 1. |
| 5 | Actualités cards : placeholders | ✅ | `frontend/app/(public)/actualites/page.tsx` rend `Image src={mediaUrl(article.featured_image_path)}` avec fallback gradient. Même pattern que `HomeActualites`. |
| 6 | Card FOAD verte hors charte | ✅ | Sed bulk-replace de la phase 1.5 : `#1F8E5C` + `bg-gradient-forest` → `bg-gradient-petrole-prune`. Plus aucun vert décoratif sur la home. |
| 7 | Lien catalogue PDF « (à venir) » | ✅ | Route `GET /documents/catalogue-pssfp-2026.pdf` (Laravel streamDownload depuis MinIO `pssfp-documents`). Rewrite Next.js pour URL propre. Markdown du seeder pointe désormais vers ce lien. Vérifié `200 OK`. |

### 9.3 Améliorations P2 maquette identité 2026 — implémentées

Inspirées de la proposition « PSSFP — Identité visuelle 2026 » du 14 mai 2026 :

- **Bandeau institutionnel bleu pétrole — 4 piliers** (`HomePiliers`) entre Hero et Stats.
- **Section "Nos engagements" — 3 cards éditoriales** (`HomeEngagements`) entre Bento spécialités et Actualités.
- **Footer enrichi** : 4 icônes réseaux sociaux en cercles or, séparateur `pssfp-hairline-prune-or`, tagline officielle « Former. Moderniser. Transformer les finances publiques. » en Cormorant Garamond italic.

### 9.4 Bonus — Évolution charte 2026 (ADR-0008)

| Token | Avant (CDC §10.1) | Après (ADR-0008) |
|---|---|---|
| Primary | `#6B2FA0` Violet | **`#4A2E67`** Prune institutionnelle |
| Secondary | — | **`#0F3A4A`** Bleu pétrole (nouveau) |
| Tertiary | `#EDE7F6` Lavande pâle | **`#A592BD`** Lavande grisée |
| Accent | `#C9A227` Or | **`#D4AF6A`** Or champagne |
| Background | `#FAF8F5` Crème | **`#FAF7F2`** Ivoire |
| Texte | `#1F1A24` Anthracite | **`#3C3C3C`** Graphite |
| Heading | Playfair Display 600/700 | **Cormorant Garamond** 600/700 |
| Body | Inter 400/500/600 | **Source Sans 3** 400/500/600 |

Audit grep + sed sur 130 fichiers, 412 occurrences hex remplacées. Tokens partagés (`packages/ui/src/tokens/*`), tailwind config partagé, globals.css frontend/library/candidature tous mis à jour. Type-check, lint, build production tous verts.

### 9.5 TODO post-démo (non bloquants)

- Photo bâtiment néoclassique pour slide 1 hero — pas dispo dans MinIO, slide actuelle (cérémonie graduation P6) reste institutionnelle.
- Sidebar « Sur Facebook » sur `/actualites` — configurer Page Plugin ou cacher en V1.
- Bento 10 modules détaillé sur `/formations/formation-continue` — refonte au Sprint S6.
- Photo hero sur `/formations/master` + `/formations/formation-continue` — nécessite import MinIO.

---

**Document versionné** : `pssfp/docs/audits/sprint-s5-visual-audit.md` (v1.1 — Sprint S5.1)
**Tâches associées** : #35, #36, #37, #38, #39, #40, #41, #42 — toutes closes par PR `feat/s5.1-identite-evolution`
