# ADR-0008 — Évolution de la charte graphique 2026 (prune institutionnel, bleu pétrole, or champagne, Cormorant Garamond / Source Sans 3)

**Statut** : Accepté
**Date** : 2026-05-14
**Décideur** : M. ABE ETOUMOU Anatole (Chef USI, Chef de Projet), validation symbolique COPIL à la prochaine démo
**Référence CDC** : remplace §10.1 (couleurs et typographies uniquement) du CDC v5 Site Web PSSFP. Le reste du §10 (grille, accessibilité, hiérarchie, micro-interactions) reste valide.

## Contexte

Depuis le sprint S5 (mai 2026), un audit visuel comparatif (cf. `docs/audits/sprint-s5-visual-audit.md`) a mis en lumière que la palette CDC §10.1 d'origine, fondée sur le violet `#6B2FA0` PSSFP, donnait au site un rendu plus "SaaS / tech" que "ministère / institution financière". Le rapprochement visuel avec les sites de référence cités au CDC (ENA, Sciences Po, AFC, IMF Institute) restait incomplet :

- Mono-violet sans 2e accent d'autorité — les sites institutionnels francophones de référence (Cour des Comptes, Trésor, Banque de France) utilisent quasi systématiquement un bleu profond comme ancrage.
- Or `#C9A227` lu comme jaune signal — moins éditorial qu'un or champagne plus chaud.
- Typo Playfair Display très répandue dans la tech US — Cormorant Garamond donne une gravitas francophone plus distinctive (codes Gallimard / Le Monde diplomatique / La République française).

Anatole a partagé le 14 mai 2026 une proposition d'évolution ("PSSFP — Identité visuelle proposée") qui formalise une charte 2026 alignée sur les codes des grandes écoles francophones de finances publiques.

La charte CDC v5 §10.1 étant **gelée par ADR**, toute modification doit passer par une nouvelle ADR. C'est l'objet de la présente.

## Décision

Adopter la **charte 2026 PSSFP** détaillée ci-dessous, en remplacement de la palette et des typographies du §10.1 du CDC v5. Le reste du §10 (grille, accessibilité, hiérarchie) reste applicable.

### Palette de couleurs (tokens design-system)

| Token | Nom officiel | Hex | Rôle |
|---|---|---|---|
| `--pssfp-prune` | Prune institutionnelle | `#4A2E67` | **Primary** — titres, navigation, CTA principal, autorité |
| `--pssfp-prune-dark` | Prune profonde | `#3A2452` | Hover/active states, fond dark variant |
| `--pssfp-prune-light` | Prune claire | `#5C3A7E` | Hover light, accents |
| `--pssfp-lavande` | Lavande grisée | `#A592BD` | Tertiary — fonds doux, dividers, états neutres |
| `--pssfp-bleu-petrole` | Bleu pétrole | `#0F3A4A` | **NOUVEAU** — bandeaux institutionnels, 2e ancrage d'autorité |
| `--pssfp-bleu-petrole-dark` | Bleu pétrole profond | `#082A37` | Dark variant |
| `--pssfp-or` | Or champagne | `#D4AF6A` | Accent — excellence, partenariats, highlights |
| `--pssfp-or-light` | Or chaud | `#E5C788` | Hover or, micro-éléments |
| `--pssfp-ivoire` | Blanc ivoire | `#FAF7F2` | Background clair |
| `--pssfp-graphite` | Gris graphite | `#3C3C3C` | Texte principal |
| `--pssfp-graphite-light` | Graphite clair | `#6B6B6B` | Texte secondaire |

### Typographies

| Famille | Usage | Source |
|---|---|---|
| **Cormorant Garamond** SemiBold 600, Bold 700 | Titres H1–H4, accents éditoriaux | Google Fonts |
| **Source Sans 3** Regular 400, Medium 500, SemiBold 600 | Body, paragraphes, navigation, UI | Google Fonts |
| **DM Sans** Medium 500 | Micro-UI letter-spaced (eyebrows, badges) — optionnel | Google Fonts |

Playfair Display et Inter sortent du design system.

### Quatre piliers identitaires

1. **Institutionnel** — solidité, rigueur, confiance (poids prune dominant, lignes nettes)
2. **Chic** — élégance, sobriété, raffinement (espaces blancs généreux, typo serif)
3. **Moderne** — clarté, innovation, ouverture (touches or, animations subtiles)
4. **Excellence** — performance, exigence, impact durable (data viz, chiffres clés visibles)

### Tagline officielle

> **« Former. Moderniser. Transformer les finances publiques. »**

À intégrer footer + meta description site + tagline éditoriale Hero.

## Conséquences

### Positives

- Rendu **plus proche des codes institutionnels francophones** (Cour des Comptes, ENA, École Nationale des Finances Publiques, AFD). Aligne PSSFP avec la cible visuelle CDC §10.1 (sites de référence francophones).
- Ajout du **bleu pétrole** ouvre un 2e accent d'autorité — possibilité de bandeaux institutionnels (4 piliers, sections sombres, callouts) qui n'existaient pas avec une palette mono-violet.
- **Or champagne** plus chaud et moins agressif que `#C9A227` — repositionne l'or comme accent éditorial et non comme signal d'alerte.
- **Cormorant Garamond** distinctive (Gallimard, Le Monde diplomatique) — sort le site du défaut "Playfair = Medium-like" très répandu.
- **Source Sans 3** mieux optimisée pour le français qu'Inter (glyphes accentués, ligatures).
- Tagline officielle « Former. Moderniser. Transformer les finances publiques. » donne au site un repère narratif fort.

### Négatives ou trade-offs

- **Coût de migration** : audit grep + remplacement des anciens hex `#6B2FA0`, `#C9A227`, `#EDE7F6`, `#FAF8F5`, `#1F1A24` partout (`frontend/`, `packages/ui/`, `library/`, `candidature/`). Risque de régression visuelle si une occurrence est oubliée.
- **Snapshots Playwright** à régénérer (10+ pages auditées) → effort estimé 30 min de runner.
- **Communication COPIL** : prévenir lors de la prochaine démo (validation symbolique attendue, charte déjà adoptée techniquement).
- **Logo PSSFP** : reste sur prune `#4A2E67` (cohérent avec ancien `#6B2FA0`, juste plus profond). Vérifier que les versions PNG/SVG du logo restent acceptables ; sinon prévoir refonte logo en sprint dédié.

### Neutres ou à surveiller

- **Bibliothèque virtuelle** et **portail candidature** : alignement automatique via `@pssfp/ui`. Mais leur design dédié (palette + composants) à reverifier.
- **Filament admin** (backend Laravel) : conserve sa palette par défaut Filament (admin non public). Pas d'impact prioritaire.
- **Conformité CAMES** : la grille et la hiérarchie restent inchangées, donc pas d'impact accréditation.

## Alternatives envisagées

### Option A — Statu quo

Garder la palette CDC §10.1 d'origine et investir uniquement dans les pages internes pour atteindre le niveau visuel ENA. **Écartée** car le mono-violet limite intrinsèquement la capacité du site à passer pour "institution financière" : il manque un 2e accent d'autorité, et le violet seul reste lu comme couleur SaaS.

### Option B — Glissement marginal (assombrir violet, garder Playfair)

Passer `#6B2FA0` → `#4A2E67` mais conserver Playfair Display + Inter, sans ajouter de bleu pétrole. **Écartée** car le bleu pétrole est la signature des sites de référence (Cour des Comptes `#1C355E`, Banque de France `#003D7A`, Trésor `#1F4E79`) — sans lui, on reste à 80% du rendu visé.

### Option C — Refonte complète (logo + tagline + palette)

Refondre simultanément logo, tagline, palette et typo via agence externe. **Écartée** pour cause de coût (10-15 k€) et de timing (démo COPIL imminente). Le logo PSSFP existant reste lisible en prune `#4A2E67` ; refonte logo reportée à un sprint dédié si COPIL le demande.

### Option D (retenue) — Évolution palette + typo, logo inchangé

Palette prune + bleu pétrole + or champagne + ivoire, typo Cormorant Garamond + Source Sans 3, logo conservé. Migration en 1 PR ciblé (sprint S5.1) avec audit grep + snapshots Playwright régénérés.

## Références

- Audit visuel sprint S5 : `docs/audits/sprint-s5-visual-audit.md`
- Spec sprint S5.1 : `docs/sprints/sprint-S5.1-identite-evolution.md`
- CDC v5 Site Web PSSFP : `docs/CDC_Site_Web_PSSFP_v5_2026.docx` §10
- ADR précédente couleurs : aucune (§10.1 du CDC tenait lieu de spec gelée — la présente ADR rend explicite l'évolution)
- Sites de référence consultés : ena.fr · sciencespo.fr · hec.edu · africafinance.org · imf.org/institute · cour-des-comptes.fr · banque-france.fr
- Google Fonts : `Cormorant Garamond` (https://fonts.google.com/specimen/Cormorant+Garamond), `Source Sans 3` (https://fonts.google.com/specimen/Source+Sans+3)
