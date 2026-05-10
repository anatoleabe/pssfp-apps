# Sprint S4 — UX Boost Phase 2 (Skill ui-ux-pro-max + thème Finances Publiques)

> **Origine** : insatisfaction Anatole sur le rendu actuel — *« c'est beau mais pas encore ça, surtout pour le site institutionnel comparé à des sites de haute facture des universités et autres »*
> **Référence skill** : https://github.com/nextlevelbuilder/ui-ux-pro-max-skill (v2.5.0, 73k★)
> **Inspirations design** : kokonutd/shape-landing-hero (21st.dev) + bundled/1960 (21st.dev dark)
> **Date** : 2026-05-08
> **Prérequis** : Module 1 site institutionnel V1 mergé, design tokens charte v5 §10.1 en place (Violet #6B2FA0 / Or #C9A227 / Lavande #EDE7F6, Playfair Display + Inter + DM Sans).

---

## 1. Brief design — pourquoi cette phase

### Le problème actuel

Module 1 a livré un site fonctionnel, accessible (WCAG AA), responsive, charté. Mais le rendu reste **« propre sans être impressionnant »** comparé aux sites institutionnels de référence qu'Anatole vise :

- ENA France (ena.fr / inp.gov.fr)
- Sciences Po (sciencespo.fr)
- HEC Paris
- Africa Finance Corporation
- Banque mondiale / FMI sections institutionnelles
- IMF Institute for Capacity Development

Ces sites partagent un vocabulaire visuel : **gravitas institutionnelle + modernité contenue + data-viz éditoriale**. Pas de neon, pas d'animations gratuites, mais une **maîtrise des micro-détails** (typo display, gradients subtils, photographie en hero, motifs géométriques évoquant rigueur et chiffres, transitions millimétrées).

### La cible

Faire passer pssfp.net du statut de *« site école »* au statut de *« institution publique de référence francophone en finances publiques »*. L'audience cible inclut :

1. **Ministres et hauts fonctionnaires** (Cameroun + CEMAC + Afrique francophone) — exigent gravitas et signaux d'autorité.
2. **Partenaires bilatéraux et multilatéraux** (Banque mondiale, FMI, AFD, BAD, GIZ) — exigent professionnalisme international.
3. **Candidats à la 14e promo** (cibles locales hautes potentialités) — exigent attractivité moderne.
4. **Auditeurs en activité** (cadres MINFI, MINEFOP, etc.) — exigent ergonomie et lisibilité.

Le défi : **une seule esthétique qui parle à ces 4 publics**. La réponse design : *« sobriété éditoriale enrichie de touches data-viz et micro-animations »*.

---

## 2. Skill ui-ux-pro-max — installation & activation

### Étape 1 — Installer le skill dans Claude Code (local au repo)

```bash
cd /Users/anatole-savics/Documents/PS/03_PROJETS/SITE WEB PSSFP/pssfp/apps/frontend

# Installer le CLI uipro
npm install -g uipro-cli

# Initialiser le skill pour Claude Code (au niveau du repo apps/frontend)
uipro init --ai claude

# Vérifier l'installation
ls -la .claude/skills/ui-ux-pro-max
python3 .claude/skills/ui-ux-pro-max/scripts/search.py --help
```

### Étape 2 — Générer le design-system MASTER pour PSSFP

```bash
# Génération design-system avec persistance
python3 .claude/skills/ui-ux-pro-max/scripts/search.py \
  "government higher education public finance institution Cameroon francophone" \
  --design-system \
  --persist \
  -p "PSSFP Programme Supérieur de Spécialisation en Finances Publiques" \
  -f markdown

# Cela crée design-system/MASTER.md
```

### Étape 3 — Générer les overrides par page

```bash
# Page d'accueil (la plus critique)
python3 .claude/skills/ui-ux-pro-max/scripts/search.py \
  "institutional homepage hero data viz public finance" \
  --design-system --persist -p "PSSFP" --page "accueil"

# Page Le Programme (présentation académique)
python3 .claude/skills/ui-ux-pro-max/scripts/search.py \
  "academic program presentation higher education" \
  --design-system --persist -p "PSSFP" --page "programme"

# Page Spécialités (5 cards parcours)
python3 .claude/skills/ui-ux-pro-max/scripts/search.py \
  "specialization cards bento grid academic" \
  --design-system --persist -p "PSSFP" --page "specialites"

# Page Actualités (timeline éditoriale)
python3 .claude/skills/ui-ux-pro-max/scripts/search.py \
  "news editorial magazine timeline institutional" \
  --design-system --persist -p "PSSFP" --page "actualites"

# Page Partenaires (logos + cartographie internationale)
python3 .claude/skills/ui-ux-pro-max/scripts/search.py \
  "partners trust logos international institutional" \
  --design-system --persist -p "PSSFP" --page "partenaires"
```

### Étape 4 — Vérifier la conformité de la palette

Le skill va probablement proposer une palette par défaut. **Il faut la contraindre à la charte CDC v5 §10.1** :

- Primary : Violet #6B2FA0 (institutionnel)
- Secondary : Or #C9A227 (excellence)
- Tertiary : Lavande #EDE7F6 (douceur, espace)
- Neutrals : Anthracite #1F1A24, Crème #FAF8F5
- États : Vert succès #2E7D32, Rouge erreur #C62828

Si le skill propose autre chose, **éditer manuellement design-system/MASTER.md** pour forcer ces couleurs et conserver le reste (typo, effets, anti-patterns, pre-delivery checklist).

---

## 3. Inspirations 21st.dev — comment les exploiter

### Référence 1 — kokonutd/shape-landing-hero

URL : https://21st.dev/community/components/kokonutd/shape-landing-hero/default

**Lecture du composant** : hero plein écran sombre (option), formes géométriques flottantes en arrière-plan (rectangles arrondis aux bords nets, légère rotation, parallax doux), gradient indigo/violet/rose en background, badge centré en haut, titre H1 en deux lignes (la 2e ligne avec gradient text), sous-titre, CTA principal + secondaire.

**Adaptation PSSFP « finance publique »** :

- Garder le pattern : badge → H1 deux lignes → sous-titre → CTAs
- Remplacer formes géométriques par **glyphes financiers stylisés** : courbes type graphique, pictogrammes XAF/€/$ très subtils, lignes parallèles évoquant un graphique d'indices, voire silhouettes architecturales sobres (colonnes, frontons).
- Couleurs : **passer du violet/rose Y2K au Violet #6B2FA0 + Or #C9A227** sur fond Crème ou Anthracite.
- Animations : **plus lentes** (8-12s au lieu de 3-5s), **plus subtiles** (translation 20px max au lieu de 80px). Respecter `prefers-reduced-motion`.
- Le H1 institutionnel : *« Former l'élite des finances publiques / au service de l'Afrique »* (la 2e ligne en gradient or → violet).

### Référence 2 — bundled/1960 (21st.dev)

URL : https://cdn.21st.dev/bundled/1960.html?theme=dark&dark=true

À explorer manuellement pour identifier les composants/effets retenus. Vraisemblablement un dashboard ou une landing avec effets premium (glassmorphism, charts inline). Si Claude Code peut extraire le source via Playwright, intégrer les patterns dans `design-system/MASTER.md`.

---

## 4. Découpage en PRs séquentielles

### PR Q — Skill bootstrap + Design System MASTER (½ jour)

**Branche** : `feat/uxboost-q-design-system`

- Installer `uipro-cli` au niveau monorepo (devDep optionnel) ET dans `apps/frontend`.
- Générer `apps/frontend/design-system/MASTER.md` + 5 pages overrides via le skill.
- Forcer la palette charte CDC v5 §10.1 dans MASTER.md (override post-génération).
- Ajouter `apps/frontend/design-system/` au `.gitignore` ? **Non** — versionner pour traçabilité.
- Documenter dans `apps/frontend/CLAUDE.md` la règle : *« Avant de coder un composant UI, lire `design-system/MASTER.md` et le `pages/<slug>.md` correspondant si présent. »*
- Créer un fichier `apps/frontend/design-system/CHARTE-OVERRIDE.md` qui liste les 5-10 règles non-négociables PSSFP qui priment sur le skill (couleurs, polices, ton institutionnel, anti-patterns interdits comme « AI purple/pink gradients » et « neon »).

**Critères réception** :
- `MASTER.md` versionné, contient bien Violet/Or/Lavande, Playfair/Inter/DM Sans.
- 5 fichiers `pages/*.md` présents.
- README skill ajouté à la racine `apps/frontend/`.

### PR R — Hero refonte (formes géométriques finances publiques) (1 jour)

**Branche** : `feat/uxboost-r-hero-finances`

**Objectif** : remplacer le hero actuel de la home par un nouveau composant `HeroInstitutionnel` inspiré kokonutd/shape-landing-hero, thème finances publiques.

**Specs techniques** :

- Nouveau composant `apps/frontend/src/components/hero/hero-institutionnel.tsx`
- Background animé en SVG inline (pas Lottie, pas GIF) : 6 à 8 formes (rectangles arrondis, courbes type sparkline, glyphes XAF subtils) en absolute positioning, animation `translateY` + `rotate` très lente (8-12s ease-in-out infinite).
- Gradient mesh subtil en background : Lavande → Crème (light mode) ou Violet 950 → Anthracite (dark mode futur).
- Badge en haut : « Programme Supérieur de Spécialisation • Cameroun • Depuis 2010 » en pill `bg-white/60 backdrop-blur` avec ring violet.
- H1 sur 2 lignes :
  - L1 : *« Former l'élite des finances publiques »* (Playfair Display, 64-80px desktop, anthracite)
  - L2 : *« au service de l'Afrique »* (Playfair Display italic, gradient text Or → Violet)
- Sous-titre : 1 phrase 18px DM Sans gris 600 sur 2 lignes max.
- 2 CTAs : « Découvrir le programme » (filled violet), « Candidater à la 14e promo » (outline or).
- Mini-stats en bas du hero (3 chiffres) : `13 promotions • 412 diplômés • 5 spécialités` — typo Inter tabular-nums, séparateurs ·.
- Respect `prefers-reduced-motion: reduce` → désactiver toutes les translations, garder le gradient statique.
- Lighthouse Performance ≥ 90, CLS ≤ 0.05, LCP ≤ 2.5s.

**Tests** :
- Playwright snapshot du hero (light mode) sur 3 viewports (375/768/1440).
- Test a11y axe-core sur la home : 0 violation critique.
- Test `prefers-reduced-motion` : vérifier que les animations sont supprimées.

**Critères réception** :
- Demo locale `:6001/` sans console error.
- Capture d'écran avant/après partagée dans la PR description.

### PR S — Sections « 5 spécialités » + « Chiffres clés » (Bento Grid + data viz) (1 jour)

**Branche** : `feat/uxboost-s-bento-stats`

**Objectif** : moderniser les deux sections les plus institutionnelles via Bento Grid et data viz éditoriale.

**Section A — Bento Grid 5 spécialités** :
- Grille 12 colonnes desktop, 1-2 colonnes mobile.
- Card maître (col-span-7 row-span-2) : Spécialité phare (ex. *« Audit et Contrôle des Finances Publiques »*) avec illustration SVG abstraite (pyramide d'audit, courbe de contrôle), description, CTA « En savoir plus ».
- 4 cards secondaires (col-span-5 puis col-span-3 col-span-3 col-span-3 col-span-3) : Budget, Fiscalité, Trésorerie, Marchés Publics.
- Effet hover : élévation `translateY(-4px)`, `box-shadow` violet diffus, gradient border qui s'allume.
- Glassmorphism léger : `bg-white/70 backdrop-blur-sm border border-violet-100`.

**Section B — Chiffres clés institutionnels (data viz éditoriale)** :
- 4-6 chiffres clés présentés comme un dashboard éditorial : `412` diplômés, `13` promotions, `82%` taux d'insertion à 6 mois, `47` cadres ministères en cours, `5` spécialités, `3` partenaires internationaux.
- Animation `count-up` au scroll (lib `react-intersection-observer` + `useState`, pas de dep externe).
- Mini-sparkline SVG inline pour 1-2 chiffres (évolution promos par année).
- Typo display Playfair pour les nombres, Inter pour les labels.
- Respect `prefers-reduced-motion` : afficher directement la valeur finale sans count-up.

**Critères réception** :
- Section spécialités cliquable, chaque card mène à `/specialites/<slug>`.
- Section chiffres clés performante (pas de re-render en boucle).
- Snapshot Playwright des 2 sections desktop + mobile.

### PR T — Polish global : transitions, scroll, focus, footer (1 jour)

**Branche** : `feat/uxboost-t-polish`

**Liste détaillée des polish** :

- **Scroll smooth** : `html { scroll-behavior: smooth }` global + offset header sticky.
- **Header sticky** avec backdrop-blur quand scroll > 40px (transition 200ms).
- **Footer institutionnel refondu** : 4 colonnes (Plan du site, Contact, Partenaires, Réseaux) + bandeau bas avec mentions légales + crédits + lien CDC.
- **Liens visités** : couleur violet 700 distincte du violet 600 par défaut (signal que l'utilisateur a déjà cliqué).
- **Focus rings** : `focus-visible:ring-2 ring-or-500 ring-offset-2` sur tous les éléments interactifs (a11y critique).
- **Transitions 150-250ms** ease-out sur tous les hover (cards, links, buttons).
- **Cursor pointer** systématique sur tous les éléments cliquables (incluant cards entières si la card est cliquable).
- **Sélection texte** : couleur custom `::selection { background: #C9A227; color: #1F1A24 }`.
- **Underline animé** sur les liens du footer (left-to-right reveal via pseudo-element).
- **Pages /actualites et /partenaires** : grilles éditoriales magazine-style (titre → photo → meta → excerpt).
- **404 page** : illustration SVG sobre + message + retour accueil.
- **Loading state** : skeleton screens (pas de spinner) sur les listes.

**Critères réception** :
- Lighthouse Best Practices = 100, A11y = 100.
- Audit manuel : 0 élément cliquable sans `cursor-pointer`, 0 lien sans focus-visible.
- Tab navigation au clavier : ordre logique vérifié sur la home et 1 page interne.

### PR U (optionnelle, si temps) — Dark mode + page Le Programme refondue (1 jour)

**Branche** : `feat/uxboost-u-darkmode-programme`

- Implémenter le dark mode (toggle dans le header, persist `localStorage`).
- Palette dark : Violet 950 #1A0A2E base, Or #C9A227 accent inchangé, texte Crème.
- Refonte page « Le Programme » avec parallax storytelling (sections qui se révèlent au scroll), photographie de couverture, typo display généreuse.
- Utiliser `prefers-color-scheme` pour le défaut.

---

## 5. Garde-fous transverses (à respecter sur chaque PR)

1. **Charte CDC v5 §10.1 reste la source de vérité** sur les couleurs et typo. Le skill propose, la charte décide.
2. **Anti-patterns interdits absolument** :
   - Pas de gradients « AI purple/pink » (#A855F7 → #EC4899) — c'est devenu un cliché d'IA, incompatible institutionnel.
   - Pas de neon, glow excessif, néomorphisme.
   - Pas d'emojis comme icônes (utiliser Lucide ou Heroicons en SVG).
   - Pas d'animations gratuites qui distraient le contenu.
3. **Performance** : Lighthouse Performance ≥ 90 sur la home. Préférer SVG inline + animations CSS à des libs JS lourdes (Framer Motion à utiliser parcimonieusement).
4. **A11y** : tous les composants doivent passer axe-core sans violation critique. Focus visible obligatoire. `prefers-reduced-motion` respecté partout.
5. **i18n** : tous les nouveaux textes en clés `next-intl`, jamais en dur. FR par défaut, EN à provisionner.
6. **Tests** : Playwright snapshots versionnés ; pre-delivery checklist du skill cochée à 100%.

---

## 6. Prompt à donner à Claude Code (copier-coller)

```
Sprint S4 UX Boost Phase 2.

Lis attentivement docs/sprints/sprint-S4-uxboost-phase2.md de bout en bout
puis exécute les PRs Q, R, S, T en séquence (U optionnelle si temps).

Avant la PR Q, installe le skill ui-ux-pro-max via:
  npm install -g uipro-cli
  cd apps/frontend && uipro init --ai claude

Puis génère le design-system MASTER + 5 overrides comme indiqué section 2.
Override la palette générée par le skill avec celle de la charte CDC v5 §10.1
(Violet #6B2FA0 / Or #C9A227 / Lavande #EDE7F6).

Pour le hero (PR R), inspire-toi de https://21st.dev/community/components/kokonutd/shape-landing-hero/default
mais adapté au thème finances publiques camerounaises:
- formes = courbes/sparklines/glyphes XAF stylisés (pas formes Y2K)
- couleurs = charte PSSFP (pas violet/rose AI)
- animations lentes 8-12s, respect prefers-reduced-motion

Pour chaque PR:
1. Branche dédiée
2. Tests Playwright + axe-core ajoutés
3. Capture d'écran avant/après dans la PR description
4. Pre-delivery checklist du skill cochée
5. Lighthouse run local avant push
6. PR créée avec gh CLI, attend ma validation avant merge

Garde-fous absolus (section 5):
- Charte CDC §10.1 source de vérité
- Anti-patterns interdits: AI purple/pink, neon, emojis-as-icons
- A11y axe-core 0 violation critique
- prefers-reduced-motion respecté partout
- i18n: aucun texte en dur

Si un point est ambigu, ouvre une issue GitHub avec label `question-cowork`
et continue sur les autres tâches sans bloquer.

Reporte chaque PR mergée dans le canal habituel avec lien GitHub.
```

---

## 7. Checklist validation Cowork après merge

- [ ] Comparer captures avant/après hero (a-t-on gagné en gravitas ?)
- [ ] Vérifier que la palette respecte la charte (pas de glissement)
- [ ] Tester sur 3 viewports (375 / 768 / 1440)
- [ ] Tester avec `prefers-reduced-motion` activé (System Preferences macOS)
- [ ] Lighthouse Production ≥ 90 partout
- [ ] Lighthouse A11y = 100
- [ ] Audit visuel sur les 5 sites de référence (ENA, Sciences Po, HEC, AFC, IMF) — sommes-nous au niveau ?
- [ ] Présentation rapide à l'équipe USI pour avis interne avant démo COPIL

---

## 8. Risques & mitigation

| Risque | Probabilité | Impact | Mitigation |
|---|---|---|---|
| Skill propose une palette qui s'écarte de la charte | Élevée | Faible | Override systématique post-génération |
| Animations hero pèsent sur LCP | Moyenne | Moyen | SVG inline + CSS pur, pas de Framer Motion |
| Bento Grid mal responsive sur 375px | Moyenne | Moyen | Mobile-first, tester sur iPhone SE virtual |
| Dark mode introduit régressions accessibilité | Élevée | Élevé | Reporter en PR U optionnelle, tester axe en dark |
| 4 PRs glissent sur 1 semaine | Moyenne | Faible | Cadence 1 PR/jour, U sautée si retard |
| Skill ui-ux-pro-max fait des choix incompatibles institutionnel | Moyenne | Moyen | Fichier CHARTE-OVERRIDE.md + revue Cowork sur PR Q |

---

**Document à versionner** : `pssfp/docs/sprints/sprint-S4-uxboost-phase2.md`
**Validation requise avant kickoff** : Anatole confirme go/no-go sur chaque PR ou délègue tout d'un bloc à Claude Code en mode autonome.
