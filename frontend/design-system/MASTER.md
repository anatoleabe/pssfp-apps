# Design System Master File

> **LOGIC:** When building a specific page, first check `design-system/pages/[page-name].md`.
> If that file exists, its rules **override** this Master file.
> If not, strictly follow the rules below.
>
> ⚠️ **CHARTE-OVERRIDE.md prime sur ce fichier.** Toute divergence entre MASTER et CHARTE
> est résolue en faveur de la CHARTE (palette, typo, anti-patterns CDC v5 §10.1).

---

**Project:** PSSFP — Programme Supérieur de Spécialisation en Finances Publiques
**Generated:** 2026-05-10 (overridden 2026-05-10 to match CHARTE)
**Category:** Higher Education / Public Finance / Government Institutional

---

## Global Rules

### Color Palette (aligned CHARTE CDC v5 §10.1)

| Role | Hex | CSS Variable |
|------|-----|--------------|
| Primary (Violet institutionnel) | `#6B2FA0` | `--color-primary` |
| On Primary | `#FFFFFF` | `--color-on-primary` |
| Secondary (Or excellence) | `#C9A227` | `--color-secondary` |
| Tertiary (Lavande) | `#EDE7F6` | `--color-tertiary` |
| Background (Crème) | `#FAF8F5` | `--color-background` |
| Foreground (Anthracite) | `#1F1A24` | `--color-foreground` |
| Surface (white) | `#FFFFFF` | `--color-surface` |
| Muted | `#F3EEF7` | `--color-muted` |
| Border | `#E5DDEC` | `--color-border` |
| Success | `#2E7D32` | `--color-success` |
| Destructive | `#C62828` | `--color-destructive` |
| Ring (focus) | `#6B2FA0` | `--color-ring` |

**Color Notes:** Violet institutionnel pour autorité + Or sobre pour distinction académique. Aucun gradient AI purple/pink autorisé (cf. CHARTE-OVERRIDE.md).

### Typography (aligned CHARTE CDC v5 §10.1)

- **Heading Font (H1-H4):** Playfair Display (600 / 700 / 900)
- **Body Font:** Inter (400 / 500 / 600)
- **UI / Boutons:** DM Sans (500)
- **Mood:** institutionnel, éditorial, francophone, accessible, sobre

**CSS Import (via next/font/google de préférence) :**
```css
@import url('https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700;900&family=Inter:wght@400;500;600&family=DM+Sans:wght@500&display=swap');
```

### Spacing Variables

| Token | Value | Usage |
|-------|-------|-------|
| `--space-xs` | `4px` / `0.25rem` | Tight gaps |
| `--space-sm` | `8px` / `0.5rem` | Icon gaps, inline spacing |
| `--space-md` | `16px` / `1rem` | Standard padding |
| `--space-lg` | `24px` / `1.5rem` | Section padding |
| `--space-xl` | `32px` / `2rem` | Large gaps |
| `--space-2xl` | `48px` / `3rem` | Section margins |
| `--space-3xl` | `64px` / `4rem` | Hero padding |

### Shadow Depths

| Level | Value | Usage |
|-------|-------|-------|
| `--shadow-sm` | `0 1px 2px rgba(31,26,36,0.06)` | Subtle lift |
| `--shadow-md` | `0 4px 6px rgba(31,26,36,0.08)` | Cards, buttons |
| `--shadow-lg` | `0 10px 15px rgba(31,26,36,0.10)` | Modals, dropdowns |
| `--shadow-xl` | `0 20px 25px rgba(31,26,36,0.14)` | Hero images, featured cards |

---

## Component Specs

### Buttons

```css
/* Primary Button — Violet institutionnel */
.btn-primary {
  background: #6B2FA0;
  color: #FFFFFF;
  padding: 12px 24px;
  border-radius: 8px;
  font-family: 'DM Sans', system-ui, sans-serif;
  font-weight: 500;
  transition: all 200ms ease-out;
  cursor: pointer;
}

.btn-primary:hover {
  background: #5A2585; /* violet plus profond, jamais glow ou gradient */
  transform: translateY(-1px);
}

.btn-primary:focus-visible {
  outline: none;
  box-shadow: 0 0 0 3px rgba(107, 47, 160, 0.35);
}

/* Secondary Button — outline violet */
.btn-secondary {
  background: transparent;
  color: #6B2FA0;
  border: 2px solid #6B2FA0;
  padding: 10px 22px;
  border-radius: 8px;
  font-family: 'DM Sans', system-ui, sans-serif;
  font-weight: 500;
  transition: all 200ms ease-out;
  cursor: pointer;
}

.btn-secondary:hover {
  background: #EDE7F6;
}

/* Tertiary Button — Or accent (sparingly, badges/CTA d'excellence) */
.btn-gold {
  background: #C9A227;
  color: #1F1A24;
  padding: 12px 24px;
  border-radius: 8px;
  font-family: 'DM Sans', system-ui, sans-serif;
  font-weight: 500;
  transition: all 200ms ease-out;
  cursor: pointer;
}
```

### Cards

```css
.card {
  background: #FFFFFF;
  border: 1px solid #E5DDEC;
  border-radius: 12px;
  padding: 24px;
  box-shadow: var(--shadow-md);
  transition: all 200ms ease-out;
}

.card:hover {
  box-shadow: var(--shadow-lg);
  transform: translateY(-2px);
  border-color: #EDE7F6;
}

.card[role="link"],
.card a {
  cursor: pointer;
}
```

### Inputs

```css
.input {
  padding: 12px 16px;
  background: #FFFFFF;
  color: #1F1A24;
  border: 1px solid #E5DDEC;
  border-radius: 8px;
  font-family: 'Inter', system-ui, sans-serif;
  font-size: 16px;
  transition: border-color 200ms ease-out, box-shadow 200ms ease-out;
}

.input:focus-visible {
  border-color: #6B2FA0;
  outline: none;
  box-shadow: 0 0 0 3px rgba(107, 47, 160, 0.20);
}
```

### Modals

```css
.modal-overlay {
  background: rgba(31, 26, 36, 0.5);
  backdrop-filter: blur(4px); /* opacité ≤ 60% par CHARTE */
}

.modal {
  background: #FFFFFF;
  color: #1F1A24;
  border-radius: 16px;
  padding: 32px;
  box-shadow: var(--shadow-xl);
  max-width: 500px;
  width: 90%;
}
```

---

## Style Guidelines

**Style:** Institutional Trust & Academic Authority

**Keywords:** Sobriété éditoriale, autorité académique francophone, conformité CAMES, distinction or, accessibilité WCAG AA, hiérarchie typographique Playfair/Inter, contraste élevé, photographie institutionnelle.

**Best For:** Site institutionnel public, école supérieure, finances publiques, gouvernance, communication officielle francophone, conformité accréditation CAMES.

**Key Effects:** Hover discrets sur cartes (lift + ombre), focus-visible ring violet, transitions 150-300ms ease-out, animation des chiffres clés (Framer Motion limité), pas de Ken Burns ni d'autoplay, respect strict de `prefers-reduced-motion`.

### Page Pattern

**Pattern Name:** Institutional Editorial

- **Conversion Strategy:** Parcours par persona (Candidat / Auditeur / Partenaire / Enseignant / Décideur). Mega menu sobre, breadcrumb systématique, règle des 3 clics.
- **CTA Placement:** "Candidater" (primary violet) visible sur le sticky header + en fin de hero. "Voir la bibliothèque" / "Contacter" (secondary outline).
- **Section Order (accueil-type):** 1. Hero éditorial avec mission, 2. Chiffres clés (animation discrète), 3. Spécialités / Programmes, 4. Actualités récentes, 5. Partenaires (logo wall sobre), 6. CTA Candidature.

---

## Anti-Patterns (Do NOT Use)

Voir aussi `CHARTE-OVERRIDE.md` (priorité absolue).

- ❌ **Gradients AI purple/pink** (`#A855F7 → #EC4899`) — cliché tech/AI incompatible institutionnel
- ❌ **Glow néon, brutalism, claymorphism** — incompatibles ton CAMES
- ❌ **Glassmorphism > 60% transparence** — lisibilité d'abord
- ❌ **Emojis comme icônes** — utiliser exclusivement lucide-react
- ❌ **Texte en dur dans le JSX** — toujours `useTranslations()` / `t('key')` (next-intl)
- ❌ **Animations sans `prefers-reduced-motion`** — autoplay, Ken Burns, stagger interdits sans guard
- ❌ **`cursor-pointer` manquant** sur cliquables
- ❌ **Hovers qui shiftent le layout** (scale uniformes, padding qui change)
- ❌ **Texte basse contraste** (< 4.5:1 sur corps, < 3:1 sur UI)
- ❌ **State changes instantanés** — toujours transition 150-300ms
- ❌ **Focus invisible** — `focus-visible:ring-2` ring violet obligatoire
- ❌ **Fond dark slate par défaut** (`#0F172A` etc.) — institutionnel = crème `#FAF8F5` clair sauf section délibérément contrastée

---

## Pre-Delivery Checklist

Voir `CHARTE-OVERRIDE.md` pour la version exhaustive non-négociable. Avant de livrer tout code UI, vérifier :

- [ ] Aucun emoji utilisé comme icône (lucide-react à la place)
- [ ] Toutes les icônes du même set (lucide-react)
- [ ] `cursor-pointer` sur tous les éléments cliquables
- [ ] Hover states avec transitions 150-300ms ease-out
- [ ] Contraste texte ≥ 4.5:1 (corps), ≥ 3:1 (UI)
- [ ] `focus-visible:ring-2` ring violet visible au clavier
- [ ] `prefers-reduced-motion` respecté (pas d'autoplay, pas de Ken Burns, pas de stagger)
- [ ] Responsive testé sur 375 / 768 / 1024 / 1440
- [ ] Aucun contenu masqué derrière le sticky header
- [ ] Aucun scroll horizontal sur mobile
- [ ] Dark mode validé si supporté
- [ ] axe-core : 0 violation critique
- [ ] Tous les textes utilisateur via `next-intl` `t('key')`
