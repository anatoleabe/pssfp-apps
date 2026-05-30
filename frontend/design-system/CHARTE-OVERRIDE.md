# CHARTE-OVERRIDE.md — Règles non-négociables PSSFP

Ces règles **priment** sur toute suggestion de `MASTER.md` ou de tout outil tiers.
Source : **ADR-0008** (2026-05-14) — évolution de la charte CDC v5 §10.1.

## Couleurs (charte 2026 PSSFP, gelée par ADR-0008)

### Tokens primaires

- **Primary** `--pssfp-prune` : `#4A2E67` (Prune institutionnelle) — titres, navigation, CTA, autorité. Remplace ancien `#6B2FA0`.
- **Primary hover/dark** `--pssfp-prune-dark` : `#3A2452`
- **Primary light** `--pssfp-prune-light` : `#5C3A7E`
- **Tertiary** `--pssfp-lavande` : `#A592BD` (Lavande grisée) — fonds doux, dividers. Remplace ancien `#EDE7F6`.
- **Secondary** `--pssfp-bleu-petrole` : `#0F3A4A` (Bleu pétrole) — **NOUVEAU** 2e accent d'autorité, bandeaux institutionnels.
- **Secondary dark** `--pssfp-bleu-petrole-dark` : `#082A37`
- **Accent** `--pssfp-or` : `#D4AF6A` (Or champagne) — excellence, highlights. Remplace ancien `#C9A227`.
- **Accent light** `--pssfp-or-light` : `#E5C788`

### Tokens de surface

- **Background clair** `--pssfp-ivoire` : `#FAF7F2` (Blanc ivoire). Remplace ancien crème `#FAF8F5`.
- **Texte principal** `--pssfp-graphite` : `#3C3C3C` (Gris graphite). Remplace ancien anthracite `#1F1A24`.
- **Texte secondaire** `--pssfp-graphite-light` : `#6B6B6B`

### États sémantiques (inchangés)

- Success `#2E7D32` · Warning `#F9A825` · Error `#C62828` · Info `#1565C0`

### INTERDITS

- ❌ Aucune valeur hex hardcodée hors de ces tokens. Toujours via `var(--pssfp-*)` ou `text-pssfp-*`.
- ❌ Pas de vert utilisé en décoration (réservé à `success`).
- ❌ Pas de jaune signal `#C9A227` — remplacer par or champagne `#D4AF6A`.
- ❌ Pas de gradients AI purple/pink (#A855F7 → #EC4899) — cliché incompatible institutionnel.
- ❌ Pas de glow néon, brutalism, claymorphism.
- ❌ Glassmorphism transparence > 60% (lisibilité d'abord).

## Typographie (ADR-0008)

- **Titres H1–H4** : `Cormorant Garamond` (600 SemiBold, 700 Bold) — `font-heading`
- **Corps + UI** : `Source Sans 3` (400 Regular, 500 Medium, 600 SemiBold) — `font-body`
- **Micro-UI letter-spaced** (eyebrows, badges) : `DM Sans` (500 Medium) — `font-ui`

Playfair Display et Inter sortent du design system.

## Tagline officielle

> « Former. Moderniser. Transformer les finances publiques. »

À utiliser dans le footer + meta description + tagline éditoriale.

## Anti-patterns INTERDITS

- ❌ Emojis utilisés comme icônes (utiliser lucide-react)
- ❌ Texte en dur dans le JSX (toujours next-intl `t('key')`)
- ❌ Animations sans respect de `prefers-reduced-motion`
- ❌ NumberTicker qui n'arrive jamais à sa valeur finale (toujours fournir la valeur finale au render initial)
- ❌ Image hero qui ne charge pas au SSR (toujours `priority` sur slide 1)

## Pre-delivery non négociable

- [ ] Contraste WCAG AA partout (≥ 4.5:1 corps, ≥ 3:1 UI)
- [ ] `focus-visible:ring-2` sur tous éléments interactifs
- [ ] `cursor-pointer` sur tout cliquable
- [ ] Hover transitions 150-300ms ease-out
- [ ] Responsive testé sur 375 / 768 / 1024 / 1440
- [ ] `prefers-reduced-motion` respecté (pas d'autoplay, pas de Ken Burns, pas de stagger)
- [ ] Dark mode validé sur chaque composant
- [ ] axe-core 0 violation critique
