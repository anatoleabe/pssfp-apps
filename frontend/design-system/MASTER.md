# Design System Master File

> **LOGIC:** When building a specific page, first check `design-system/pages/[page-name].md`.
> If that file exists, its rules **override** this Master file.
> If not, strictly follow the rules below.
>
> ⚠️ **CHARTE-OVERRIDE.md prime sur ce fichier.** Toute divergence entre MASTER et CHARTE
> est résolue en faveur de la CHARTE (palette, typo, anti-patterns ADR-0008).

---

**Project:** PSSFP — Programme Supérieur de Spécialisation en Finances Publiques
**Generated:** 2026-05-10 · **Updated:** 2026-05-14 (charte 2026 ADR-0008)
**Category:** Higher Education / Public Finance / Government Institutional

---

## Global Rules

### Color Palette (charte 2026 PSSFP — ADR-0008)

| Role | Token CSS | Hex | Usage |
|------|-----------|-----|-------|
| **Primary** — Prune institutionnelle | `--pssfp-prune` | `#4A2E67` | Titres, navigation, CTA principal, autorité |
| Primary dark/hover | `--pssfp-prune-dark` | `#3A2452` | Hover, active states |
| Primary light | `--pssfp-prune-light` | `#5C3A7E` | Hover light, accents |
| **Secondary** — Bleu pétrole | `--pssfp-bleu-petrole` | `#0F3A4A` | Bandeaux institutionnels, sections sombres, 2e accent autorité |
| Secondary dark | `--pssfp-bleu-petrole-dark` | `#082A37` | Variants profonds |
| **Tertiary** — Lavande grisée | `--pssfp-lavande` | `#A592BD` | Fonds doux, dividers, états neutres |
| **Accent** — Or champagne | `--pssfp-or` | `#D4AF6A` | Excellence, partenariats, highlights |
| Accent light | `--pssfp-or-light` | `#E5C788` | Hover or, micro-éléments |
| **Background ivoire** | `--pssfp-ivoire` | `#FAF7F2` | Page light mode |
| **Texte principal** — Graphite | `--pssfp-graphite` | `#3C3C3C` | Body text light mode |
| Texte secondaire | `--pssfp-graphite-light` | `#6B6B6B` | Muted, captions |
| Success | sémantique | `#2E7D32` | Validation |
| Warning | sémantique | `#F9A825` | Avertissement |
| Error / Destructive | sémantique | `#C62828` | Erreurs |
| Info | sémantique | `#1565C0` | Notifications |

**Color Notes:**
- Prune institutionnelle pour autorité francophone (référence Cour des Comptes, ENA).
- Bleu pétrole en 2e accent d'autorité (référence institutions financières).
- Or champagne en accent éditorial (pas signal d'alerte).
- Lavande grisée en remplacement de la lavande pâle, plus mature.
- Aucun gradient AI purple/pink. Aucun vert décoratif (réservé `success`).

### Typography (ADR-0008)

- **Heading Font (H1–H4):** `Cormorant Garamond` (600 SemiBold, 700 Bold) — `font-heading`
- **Body Font:** `Source Sans 3` (400 Regular, 500 Medium, 600 SemiBold) — `font-body`
- **UI / Micro-UI letter-spaced:** `DM Sans` (500 Medium) — `font-ui`
- **Mood:** institutionnel, éditorial, francophone (Gallimard / Le Monde Diplomatique), accessible, sobre

**Chargement via `next/font/google`** (cf. `app/layout.tsx`) avec `display: swap` et `subsets: ['latin']`. Préchargement automatique des poids déclarés.

### Spacing Variables

| Token | Value | Usage |
|-------|-------|-------|
| `--space-xs` | `4px` | Tight gaps |
| `--space-sm` | `8px` | Icon gaps, inline spacing |
| `--space-md` | `16px` | Standard padding |
| `--space-lg` | `24px` | Section padding |
| `--space-xl` | `32px` | Large gaps |
| `--space-2xl` | `48px` | Section margins |
| `--space-3xl` | `64px` | Hero padding |

### Shadow Depths

| Level | Value | Usage |
|-------|-------|-------|
| `--shadow-sm` | `0 1px 2px rgba(60,60,60,0.06)` | Subtle lift |
| `--shadow-md` | `0 4px 6px rgba(60,60,60,0.08)` | Cards, buttons |
| `--shadow-lg` | `0 10px 15px rgba(60,60,60,0.10)` | Modals, dropdowns |
| `--shadow-xl` | `0 20px 25px rgba(60,60,60,0.14)` | Hero images, featured cards |

---

## Component Specs

### Buttons

```css
/* Primary Button — Prune */
.btn-primary {
  background: var(--pssfp-prune); /* #4A2E67 */
  color: #FFFFFF;
  padding: 12px 24px;
  border-radius: 8px;
  font-family: 'DM Sans', system-ui, sans-serif;
  font-weight: 500;
  transition: all 200ms ease-out;
  cursor: pointer;
}
.btn-primary:hover { background: var(--pssfp-prune-dark); transform: translateY(-1px); }
.btn-primary:focus-visible {
  outline: none;
  box-shadow: 0 0 0 3px rgba(74, 46, 103, 0.35);
}

/* Secondary Button — outline prune */
.btn-secondary {
  background: transparent;
  color: var(--pssfp-prune);
  border: 2px solid var(--pssfp-prune);
  padding: 10px 22px;
  border-radius: 8px;
  font-family: 'DM Sans', system-ui, sans-serif;
  font-weight: 500;
}
.btn-secondary:hover { background: rgba(74, 46, 103, 0.08); }

/* Tertiary Button — Or champagne (sparingly) */
.btn-gold {
  background: var(--pssfp-or);
  color: var(--pssfp-graphite);
  padding: 12px 24px;
  border-radius: 8px;
  font-family: 'DM Sans', system-ui, sans-serif;
  font-weight: 500;
}
```

### Cards

```css
.card {
  background: #FFFFFF;
  border: 1px solid rgba(165, 146, 189, 0.30); /* lavande grisée 30% */
  border-radius: 12px;
  padding: 24px;
  box-shadow: var(--shadow-md);
  transition: all 200ms ease-out;
}
.card:hover {
  box-shadow: var(--shadow-lg);
  transform: translateY(-2px);
  border-color: var(--pssfp-prune-light);
}
```

### Inputs

```css
.input {
  padding: 12px 16px;
  background: #FFFFFF;
  color: var(--pssfp-graphite);
  border: 1px solid rgba(165, 146, 189, 0.40);
  border-radius: 8px;
  font-family: 'Source Sans 3', system-ui, sans-serif;
  font-size: 16px;
}
.input:focus-visible {
  border-color: var(--pssfp-prune);
  outline: none;
  box-shadow: 0 0 0 3px rgba(74, 46, 103, 0.20);
}
```

### Modals

```css
.modal-overlay {
  background: rgba(15, 58, 74, 0.5); /* teinte bleu pétrole pour ancrage institutionnel */
  backdrop-filter: blur(4px);
}
.modal {
  background: #FFFFFF;
  color: var(--pssfp-graphite);
  border-radius: 16px;
  padding: 32px;
  box-shadow: var(--shadow-xl);
}
```

### Bandeau institutionnel bleu pétrole

```css
/* Section pleine largeur — autorité, ancrage 2e accent */
.section-petrole {
  background: var(--pssfp-bleu-petrole);
  color: #FFFFFF;
  padding: 64px 0;
}
.section-petrole h2 {
  color: #FFFFFF;
  font-family: 'Cormorant Garamond', serif;
}
.section-petrole .eyebrow {
  color: var(--pssfp-or);
  font-family: 'DM Sans', sans-serif;
  letter-spacing: 0.18em;
  text-transform: uppercase;
  font-size: 0.75rem;
}
```

---

## Style Guidelines

**Style:** Institutional Trust & Academic Authority (charte 2026 PSSFP)

**Quatre piliers identitaires :**
1. **Institutionnel** — solidité, rigueur, confiance (prune dominant, lignes nettes)
2. **Chic** — élégance, sobriété, raffinement (espaces blancs, typo serif Cormorant)
3. **Moderne** — clarté, innovation, ouverture (touches or, animations subtiles)
4. **Excellence** — performance, exigence, impact (data viz, chiffres clés)

**Keywords:** Gravitas francophone, autorité institutionnelle, conformité CAMES, distinction or champagne, accessibilité WCAG AA, hiérarchie typographique Cormorant Garamond / Source Sans 3, contraste élevé, photographie institutionnelle.

**Best For:** Site institutionnel public, école supérieure, finances publiques, gouvernance, communication officielle francophone, conformité accréditation CAMES.

**Tagline officielle :** « Former. Moderniser. Transformer les finances publiques. »

**Key Effects:** Hover discrets sur cards (lift + ombre), focus-visible ring prune, transitions 150-300ms ease-out, animation des chiffres clés (NumberTicker avec valeur finale au render initial), pas de Ken Burns ni d'autoplay non-respecté, respect strict de `prefers-reduced-motion`.

### Page Pattern

**Pattern Name:** Institutional Editorial 2026

- **Conversion Strategy:** Parcours par persona (Candidat / Auditeur / Partenaire / Enseignant / Décideur). Mega menu sobre, breadcrumb systématique, règle des 3 clics.
- **CTA Placement:** "Candidater" (primary prune) visible sur sticky header + fin de hero. "Voir la bibliothèque" / "Contacter" (secondary outline).
- **Section Order (accueil-type 2026):**
  1. Hero éditorial avec mission + photo bâtiment néoclassique
  2. **Bandeau 4 piliers bleu pétrole** (Formations qualifiantes / Expertise publique / Réseau & partenariats / Éthique & intégrité)
  3. Chiffres clés (NumberTicker)
  4. Bento Grid 5 spécialités
  5. **Section « Nos engagements »** 3 cards éditoriales (Rigueur / Innovation / Impact)
  6. Actualités récentes
  7. Accès rapides (Bibliothèque / Candidature / FOAD — tous en palette charte, pas de vert)
  8. Partenaires (logo wall sobre)
  9. CTA Candidature

---

## Anti-Patterns (Do NOT Use)

Voir aussi `CHARTE-OVERRIDE.md` (priorité absolue).

- ❌ **Anciens hex CDC §10.1 d'origine** `#6B2FA0` / `#C9A227` / `#EDE7F6` / `#FAF8F5` / `#1F1A24` — remplacés par charte 2026 (ADR-0008)
- ❌ **Anciennes typos** Playfair Display, Inter — remplacées par Cormorant Garamond + Source Sans 3
- ❌ **Gradients AI purple/pink** (`#A855F7 → #EC4899`)
- ❌ **Vert décoratif** (réservé strictement à `success`)
- ❌ **Jaune signal `#C9A227`** — remplacer par or champagne `#D4AF6A`
- ❌ **Glow néon, brutalism, claymorphism** — incompatibles ton CAMES
- ❌ **Glassmorphism > 60% transparence** — lisibilité d'abord
- ❌ **Emojis comme icônes** — exclusivement lucide-react
- ❌ **Texte en dur dans le JSX** — toujours `useTranslations()` / `t('key')` (next-intl)
- ❌ **Animations sans `prefers-reduced-motion`** — autoplay, Ken Burns, stagger interdits sans guard
- ❌ **NumberTicker qui n'atteint jamais sa valeur finale** — toujours fournir la valeur finale au render initial
- ❌ **Image hero qui n'apparaît qu'après hydratation** — toujours `priority` sur slide 1
- ❌ **`cursor-pointer` manquant** sur cliquables
- ❌ **Hovers qui shiftent le layout**
- ❌ **Texte basse contraste** (< 4.5:1 sur corps, < 3:1 sur UI)
- ❌ **Focus invisible** — `focus-visible:ring-2` ring prune obligatoire

---

## Pre-Delivery Checklist

Voir `CHARTE-OVERRIDE.md` pour la version exhaustive non-négociable. Avant de livrer tout code UI, vérifier :

- [ ] Aucun emoji utilisé comme icône (lucide-react à la place)
- [ ] Toutes les icônes du même set (lucide-react)
- [ ] `cursor-pointer` sur tous les éléments cliquables
- [ ] Hover states avec transitions 150-300ms ease-out
- [ ] Contraste texte ≥ 4.5:1 (corps), ≥ 3:1 (UI)
- [ ] `focus-visible:ring-2` ring prune visible au clavier
- [ ] `prefers-reduced-motion` respecté
- [ ] Responsive testé sur 375 / 768 / 1024 / 1440
- [ ] Aucun contenu masqué derrière le sticky header
- [ ] Aucun scroll horizontal sur mobile
- [ ] Dark mode validé
- [ ] axe-core : 0 violation critique
- [ ] Tous les textes utilisateur via `next-intl` `t('key')`
- [ ] Aucun hex hardcodé hors tokens (`var(--pssfp-*)` uniquement)
- [ ] Aucune référence à Playfair / Inter / anciens hex CDC dans les diffs
