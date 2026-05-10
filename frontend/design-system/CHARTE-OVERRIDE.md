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
