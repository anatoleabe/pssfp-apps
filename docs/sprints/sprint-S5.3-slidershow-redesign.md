# Sprint S5.3 — Refonte design slidershow style institutionnel

> **Origine** : Anatole a partagé une maquette de référence pour le hero slider (image style « PSSFP — DEPUIS 2013 » avec top bar, contenu central, bottom bar, et photo de cérémonie à droite).
> **Date** : 2026-05-14
> **Effort estimé** : ½ jour
> **PR cible** : `fix/s5.3-slidershow`
> **Photo source** : `assets-source/slidershow/slidershow1_pssfp1.jpg` (cérémonie remise diplômes Promotion 2025, drapeaux camerounais, étudiants en tenue académique)

---

## 1. Intervention chirurgicale — périmètre exact

**On modifie UNIQUEMENT** :
- `frontend/components/HomeShowcase/index.tsx` — le rendu visuel de chaque slide (le JSX à l'intérieur du `<div className="relative h-[80vh] min-h-[560px] w-full shrink-0 grow-0 basis-full">` ligne 182, donc lignes 184-258 environ)
- Le tableau `SLIDES` (ligne 43-98) pour pointer vers la nouvelle photo en slide 1

**On NE TOUCHE PAS** :
- Le scaffolding Embla (lignes 100-148) — autoplay, dots, navigation, keyboard
- Les boutons prev/next chevrons (lignes 264-284)
- Les indicateurs dots (lignes 286-310)
- La structure `<section>` racine et le `<div ref={emblaRef}>` avec ARIA roledescription
- Le reste de la home (HomeStats, HomePiliers, HomeEngagements, HomeActualites, etc.)

---

## 2. Analyse de la maquette

Six zones distinctes à reproduire :

### Zone A — Top bar institutionnel (haut du slide)
- Fond légèrement sombre/dégradé bleu pétrole sur l'overlay
- Logo PSSFP rond (gauche) — fichier `public/logo-pssfp.svg` ou équivalent
- Wordmark **« PSSFP »** en Cormorant Garamond gras (à côté du logo)
- Séparateur vertical or fin
- Tagline 3 lignes en lettres espacées : **EXCELLENCE / RIGUEUR / INTÉGRITÉ** (or champagne)
- Liseré or horizontal (gradient transparent → or → transparent) en bas du bandeau

### Zone B — Overlay gradient gauche-droite
- Pas un overlay sombre uniforme comme aujourd'hui
- Dégradé `linear-gradient(to right, #0F3A4A/95 0%, #0F3A4A/70 30%, #0F3A4A/30 60%, transparent 100%)`
- Côté **gauche** : sombre pour lisibilité du texte
- Côté **droit** : transparent pour voir la photo de cérémonie
- C'est le différenciateur visuel majeur de la maquette

### Zone C — Contenu textuel (centre-gauche)
- **Trait or horizontal court** (12-16px de large) à gauche du eyebrow — élément décoratif
- **Eyebrow** : `PSSFP — DEPUIS 2013` (ou eyebrow de la slide), or champagne, lettres espacées, petites
- **H1/H2 Cormorant Garamond** TRÈS GRAND (clamp 2.5rem → 6rem) blanc, leading serré 1.05
- **Sous-titre** : Source Sans 3 18-20px blanc/85, max-width 600px
- **2 badges-cards** (au lieu de boutons CTA traditionnels) :
  - Bordure or fine (1px `pssfp-or/40`)
  - Fond translucide bleu pétrole (`#0F3A4A/60` + `backdrop-blur-sm`)
  - Padding x5 y3
  - Layout : icône ronde or sur fond `pssfp-or/15` + label blanc Source Sans 3
  - Badge 1 : icône `Award` (lucide-react) + label « Excellence académique »
  - Badge 2 : icône `GraduationCap` + label « Promotion 2025 » (ou label dynamique selon slide)
  - Hover : élévation légère, ring or plus prononcé
  - **NOTE IMPORTANTE** : ces badges sont décoratifs/informatifs dans la maquette, mais on doit garder les CTAs cliquables existants (`primaryCta` et `secondaryCta`). Solution : transformer les CTAs existants en ces badges-cards stylés (lien `<Link>` wrappant le badge).

### Zone D — Bottom bar institutionnel (bas du slide)
- Liseré or horizontal en haut du bandeau (même style que top bar)
- Icône `Building2` ou colonnes (Lucide) en or à gauche
- Au centre : **« PROGRAMME SUPÉRIEUR DE SPÉCIALISATION EN FINANCES PUBLIQUES »** en lettres espacées blanc/70
- À droite : **« EXCELLENCE · RIGUEUR · INTÉGRITÉ »** en lettres espacées or
- Padding y4 px6 md:px10

### Zone E — Photo background
- Plein cadre `object-cover object-center` (déjà OK dans le code actuel)
- Slide 1 : nouvelle photo `slidershow1_pssfp1.jpg`
- Slides 2-5 : conserver les photos actuelles (`dsc-0466`, `affiche`, `dsc-0302`, `whatsapp-image-2025`)

### Zone F — Hauteur de la slide
- Actuellement `h-[80vh] min-h-[560px]`
- Passer à `h-screen min-h-[680px]` pour avoir un hero plein écran plus impactant comme dans la maquette
- En mobile (< 768px) : `h-[85vh] min-h-[600px]` (un peu moins que 100vh pour laisser entrevoir le bandeau 4 piliers en dessous)

---

## 3. Modification du tableau SLIDES

```typescript
const SLIDES: ReadonlyArray<ShowcaseSlide> = [
  {
    id: 'identite',
    eyebrow: 'PSSFP — depuis 2013',
    title: "Former l'élite des finances publiques",
    subtitle:
      "Une institution d'excellence au service de la modernisation de l'action publique.",
    imagePath: 'photos/slidershow/slidershow1-pssfp1.webp', // ← NOUVELLE PHOTO
    imageAlt: 'Cérémonie de remise des diplômes — Promotion 2025',
    primaryCta: { label: 'Excellence académique', href: '/formations/master' },
    secondaryCta: { label: 'Promotion 2025', href: '/actualites' },
  },
  // ... les 4 autres slides conservent leur contenu mais le rendu visuel change pareillement
];
```

**Note** : le `label` du primaryCta et secondaryCta devient le **label du badge-card** (Zone C). Adapter les 4 autres slides en remplaçant leurs CTAs par 2 labels courts type « Excellence académique » + « Promotion 2025 » / « Master Pro » + « 5 spécialités » / etc. Le `href` reste fonctionnel.

---

## 4. Import des photos

Étape obligatoire avant tout : importer la photo source dans MinIO.

### Inventaire (Inspect-First)
```bash
ls -lh "/Users/anatole-savics/Documents/PS/03_PROJETS/SITE WEB PSSFP/pssfp/assets-source/slidershow/"
```

Anatole annonce d'autres photos à venir dans ce dossier. Le script d'import doit gérer **tout le dossier** (pas juste 1 fichier).

### Procédure import
Réutiliser le mécanisme PR V (`php artisan pssfp:import-assets` ou équivalent). Cible MinIO :
- Bucket : `pssfp-media`
- Préfixe : `photos/slidershow/`
- Conversion WebP qualité 85
- Génération 3 tailles : thumb (400px), medium (1200px), full (original)
- Tag : `slidershow`

### Fallback si script absent
Upload direct via `mc` ou la Filament Media Library. Le path final dans le composant doit être par exemple `photos/slidershow/slidershow1-pssfp1.webp` (snake-case ou kebab-case selon convention du repo).

---

## 5. Layout JSX à écrire (référence détaillée)

Remplacer le bloc lignes 184-258 environ par :

```tsx
{/* Slide container — hauteur cinématique */}
<div className="relative h-screen min-h-[680px] w-full shrink-0 grow-0 basis-full">
  {/* Fallback gradient (conservé) */}
  <div
    aria-hidden="true"
    className="absolute inset-0 -z-10"
    style={{
      background:
        'linear-gradient(135deg, #0A1B2E 0%, #0F3A4A 50%, #1F2A4A 100%)',
    }}
  />

  {/* Photo background plein cadre (conservé) */}
  <Image
    src={mediaUrl(slide.imagePath)}
    alt={slide.imageAlt}
    fill
    sizes="100vw"
    priority={index === 0}
    fetchPriority={index === 0 ? 'high' : 'auto'}
    loading={index === 0 ? 'eager' : 'lazy'}
    className="object-cover object-center"
    quality={85}
  />

  {/* NOUVEAU — Overlay gradient gauche-droite */}
  <div
    aria-hidden="true"
    className="absolute inset-0 bg-gradient-to-r from-[#0A1B2E]/95 via-[#0F3A4A]/70 to-transparent md:from-[#0A1B2E]/95 md:via-[#0F3A4A]/55"
  />

  {/* NOUVEAU — Top bar institutionnel */}
  <header
    aria-hidden={index !== selectedIndex}
    className="absolute inset-x-0 top-0 z-10"
  >
    <div className="flex items-center gap-3 px-6 py-4 md:gap-4 md:px-10 md:py-5">
      {/* Logo */}
      <Image
        src="/logo-pssfp.svg"
        alt=""
        width={48}
        height={48}
        className="h-10 w-10 md:h-12 md:w-12"
        priority={index === 0}
      />
      <span className="font-heading text-2xl font-bold tracking-tight text-white md:text-3xl">
        PSSFP
      </span>
      <span aria-hidden="true" className="ml-2 h-10 w-px bg-[#D4AF6A]/40 md:ml-4 md:h-12" />
      <div className="hidden font-ui text-[9px] uppercase leading-tight tracking-[0.28em] text-[#D4AF6A]/85 md:block md:text-[10px]">
        <div>EXCELLENCE</div>
        <div>RIGUEUR</div>
        <div>INTÉGRITÉ</div>
      </div>
    </div>
    {/* Liseré or */}
    <div
      aria-hidden="true"
      className="h-px bg-gradient-to-r from-transparent via-[#D4AF6A]/80 to-transparent"
    />
  </header>

  {/* Contenu central */}
  <div className="relative mx-auto flex h-full max-w-7xl items-center px-6 md:px-10">
    <div className="max-w-3xl">
      {/* Eyebrow avec trait or */}
      <div className="flex items-center gap-3">
        <span
          aria-hidden="true"
          className="h-px w-10 bg-[#D4AF6A] md:w-14"
        />
        <p className="font-ui text-[11px] font-medium uppercase tracking-[0.28em] text-[#D4AF6A] md:text-xs">
          {slide.eyebrow}
        </p>
      </div>

      {/* H1/H2 Cormorant XL */}
      {index === 0 ? (
        <h1 className="mt-5 font-heading font-bold leading-[1.04] text-white text-[clamp(2.5rem,1rem+5vw,5.5rem)] md:mt-7">
          {slide.title}
        </h1>
      ) : (
        <h2 className="mt-5 font-heading font-bold leading-[1.04] text-white text-[clamp(2.5rem,1rem+5vw,5.5rem)] md:mt-7">
          {slide.title}
        </h2>
      )}

      {/* Sous-titre */}
      <p className="mt-5 max-w-xl text-lg leading-relaxed text-white/85 md:mt-7 md:text-xl">
        {slide.subtitle}
      </p>

      {/* 2 badges-cards (transformation des CTAs en badges visuels cliquables) */}
      <div className="mt-8 flex flex-wrap items-center gap-3 md:mt-10 md:gap-4">
        <Link
          href={slide.primaryCta.href}
          data-testid={`showcase-cta-primary-${slide.id}`}
          className="group inline-flex items-center gap-3 rounded-lg border border-[#D4AF6A]/40 bg-[#0F3A4A]/60 px-5 py-3 backdrop-blur-sm transition-all hover:-translate-y-0.5 hover:border-[#D4AF6A] hover:bg-[#0F3A4A]/80 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#D4AF6A] focus-visible:ring-offset-2 focus-visible:ring-offset-[#0A1B2E]"
        >
          <span className="flex h-10 w-10 items-center justify-center rounded-full bg-[#D4AF6A]/15">
            <Award size={20} className="text-[#D4AF6A]" aria-hidden="true" />
          </span>
          <span className="font-ui text-sm font-medium text-white">
            {slide.primaryCta.label}
          </span>
        </Link>
        {slide.secondaryCta && (
          <Link
            href={slide.secondaryCta.href}
            data-testid={`showcase-cta-secondary-${slide.id}`}
            className="group inline-flex items-center gap-3 rounded-lg border border-[#D4AF6A]/40 bg-[#0F3A4A]/60 px-5 py-3 backdrop-blur-sm transition-all hover:-translate-y-0.5 hover:border-[#D4AF6A] hover:bg-[#0F3A4A]/80 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#D4AF6A] focus-visible:ring-offset-2 focus-visible:ring-offset-[#0A1B2E]"
          >
            <span className="flex h-10 w-10 items-center justify-center rounded-full bg-[#D4AF6A]/15">
              <GraduationCap size={20} className="text-[#D4AF6A]" aria-hidden="true" />
            </span>
            <span className="font-ui text-sm font-medium text-white">
              {slide.secondaryCta.label}
            </span>
          </Link>
        )}
      </div>
    </div>
  </div>

  {/* NOUVEAU — Bottom bar institutionnel */}
  <footer
    aria-hidden={index !== selectedIndex}
    className="absolute inset-x-0 bottom-0 z-10"
  >
    {/* Liseré or top */}
    <div
      aria-hidden="true"
      className="h-px bg-gradient-to-r from-transparent via-[#D4AF6A]/80 to-transparent"
    />
    <div className="flex items-center justify-between gap-3 px-6 py-3 md:px-10 md:py-4">
      <Building2 size={18} className="text-[#D4AF6A]" aria-hidden="true" />
      <span className="hidden flex-1 text-center font-ui text-[9px] uppercase tracking-[0.25em] text-white/70 md:block md:text-[10px]">
        Programme Supérieur de Spécialisation en Finances Publiques
      </span>
      <span className="font-ui text-[9px] uppercase tracking-[0.25em] text-[#D4AF6A]/85 md:text-[10px]">
        Excellence · Rigueur · Intégrité
      </span>
    </div>
  </footer>
</div>
```

**Imports à ajouter en haut du fichier** :
```typescript
import { ArrowRight, ChevronLeft, ChevronRight, Award, GraduationCap, Building2 } from 'lucide-react';
```

Retirer l'import `ArrowRight` si plus utilisé après remplacement des CTAs en badges-cards.

---

## 6. Indicateurs dots — déplacement éventuel

Les dots actuels sont en `absolute inset-x-0 bottom-6`. Avec le nouveau bottom bar à `bottom-0`, ils risquent de chevaucher. Soit :
- **Option A (recommandée)** : déplacer les dots à `bottom-14` ou `bottom-16` pour être au-dessus du bandeau bottom
- **Option B** : passer les dots dans une zone au centre du bottom bar (entre l'icône Building et le texte programme)

Recommandation : Option A pour simplicité et conservation de l'esthétique séparée.

---

## 7. Inspect-First (rappel)

- Vérifier l'existence de `public/logo-pssfp.svg` ou équivalent. Si nom différent (logo.svg, pssfp-logo.svg), adapter le path.
- Vérifier qu'aucune slide n'utilise déjà le label « Excellence académique » pour éviter le doublon avec HomeEngagements.
- Pas de migration DB, c'est purement frontend.

---

## 8. Prompt prêt à coller dans Claude Code

```
Sprint S5.3 — Refonte design slidershow style institutionnel (½ jour).

Lis docs/sprints/sprint-S5.3-slidershow-redesign.md de bout en bout.
Le diagnostic + plan layout est précis : pas besoin d'investigation.

Branche: fix/s5.3-slidershow
PR cible: 1 PR consolidé, closes #48.

═══════════════════════════════════════════════
ÉTAPE 1 — Import photos slidershow (Inspect-First)
═══════════════════════════════════════════════

ls -lh "/Users/anatole-savics/Documents/PS/03_PROJETS/SITE WEB PSSFP/pssfp/assets-source/slidershow/"

Photo confirmée présente: slidershow1_pssfp1.jpg
D'autres photos arrivent dans ce dossier — préparer un import qui scanne TOUT
le dossier (pattern slidershow*.jpg / *.png / *.webp).

Importer dans MinIO bucket pssfp-media:
  - Préfixe: photos/slidershow/
  - Conversion WebP qualité 85
  - Génération 3 tailles (thumb 400, medium 1200, full original)
  - Tag: slidershow

Réutiliser php artisan pssfp:import-assets (PR V) en étendant la liste des
sources scannées. Documenter dans docs/asset-inventory.md.

═══════════════════════════════════════════════
ÉTAPE 2 — Refonte composant HomeShowcase
═══════════════════════════════════════════════

Fichier UNIQUE: frontend/components/HomeShowcase/index.tsx

NE PAS toucher au scaffolding Embla (lignes 100-148 actuelles), aux boutons
prev/next chevrons, aux dots, ni à la structure <section> racine.

NE TOUCHER QUE le bloc JSX qui rend chaque slide (lignes 184-258 actuelles)
ET le tableau SLIDES (lignes 43-98).

Layout cible — cf section 5 de la spec (référence détaillée fournie).

6 zones à implémenter:
  A. Top bar institutionnel: logo + PSSFP wordmark + tagline 3 lignes
     (EXCELLENCE/RIGUEUR/INTÉGRITÉ) + liseré or
  B. Overlay gradient gauche-droite (sombre côté texte, transparent côté photo)
     PAS un overlay sombre uniforme comme aujourd'hui
  C. Contenu central: trait or + eyebrow or + H1 Cormorant XL + sous-titre +
     2 badges-cards (Award + GraduationCap) au lieu de boutons CTA classiques
  D. Bottom bar: icône Building2 + nom programme + tagline EXCELLENCE
  E. Photo background plein cadre (déjà OK, juste changer imagePath slide 1)
  F. Hauteur: h-screen min-h-[680px] desktop, h-[85vh] min-h-[600px] mobile

Mettre à jour le tableau SLIDES:
  - Slide 1: imagePath = 'photos/slidershow/slidershow1-pssfp1.webp'
  - Labels CTAs convertis en labels courts pour les badges-cards
    (ex. 'Excellence académique' / 'Promotion 2025' pour slide 1)

Déplacer dots indicators de bottom-6 à bottom-14 pour éviter chevauchement
avec le nouveau bottom bar.

Imports lucide-react à ajouter: Award, GraduationCap, Building2
Retirer ArrowRight si plus utilisé.

═══════════════════════════════════════════════
TESTS
═══════════════════════════════════════════════

À ajouter dans frontend/tests/playwright/:

slidershow-redesign.spec.ts:
  - load localhost:6001
  - assert top bar visible: logo + "PSSFP" wordmark + tagline EXCELLENCE
  - assert bottom bar visible: icône + "Programme Supérieur..." + tagline
  - assert 2 badges-cards visibles sur slide 1
  - assert trait or vertical visible
  - assert dots indicators NOT chevauchant le bottom bar
  - test navigation clavier ← → fonctionne encore (régression check)
  - test prefers-reduced-motion: pas d'autoplay, slide 1 statique

Mettre à jour les snapshots Playwright existants pour les autres tests qui
capturent la home.

═══════════════════════════════════════════════
VÉRIFICATION
═══════════════════════════════════════════════

- npx tsc --noEmit (frontend)
- npx next lint (frontend)
- npx next build production
- Manual QA localhost:6001:
  * Top bar avec logo + PSSFP + tagline OK
  * Photo cérémonie Promotion 2025 visible à droite (pas masquée par overlay)
  * Texte lisible côté gauche (overlay sombre suffisant)
  * H1 Cormorant TRÈS grand
  * 2 badges-cards cliquables (mènent aux bons hrefs)
  * Bottom bar avec icône + nom programme + tagline EXCELLENCE
  * Dots indicators visibles, pas chevauchant bottom bar
  * Navigation flèches ← → fonctionne
  * Toggle dark mode: pas de régression (le slidershow est déjà dark par nature)
  * Mobile 390x844: tagline 3 lignes cachée si trop petit (md:block),
    bottom bar texte cente caché en mobile (md:block)
  * prefers-reduced-motion: slide 1 statique, pas d'autoplay

PR description format:
  ## Refonte chirurgicale slidershow
  Conserve scaffolding Embla + accessibilité.
  Refait le rendu visuel selon maquette Anatole.

  ## Nouvelle photo
  slidershow1_pssfp1.jpg → photos/slidershow/slidershow1-pssfp1.webp
  Import préparé pour les futures photos slidershow*.

  ## Captures avant/après
  [Slide 1 light mode 1440x900]
  [Slide 1 dark mode 1440x900]
  [Slide 1 mobile 390x844]

  Closes #48.

Reporte le PR + captures.
```

---

## 9. Captures de référence

[Maquette reçue d'Anatole — slide design type institutionnel avec top bar, contenu central, bottom bar, badges-cards Award + GraduationCap]
[Photo Promotion 2025 — cérémonie remise diplômes, étudiants tenue académique bleu+jaune, drapeaux camerounais en arrière-plan]

---

**Document versionné** : `pssfp/docs/sprints/sprint-S5.3-slidershow-redesign.md` (v1.0)
**Tâche associée** : #48
