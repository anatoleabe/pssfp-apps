# Spec — Module 1 — Site institutionnel public

> **Référence** : Sprint Specs A5
> **Statut** : v1.0 — proposé pour validation Chef USI
> **Date** : 2026-05-05
> **Source CDC** : §7 (arborescence) et §8.1 Module 1 du CDC v5

Ce document spécifie le périmètre fonctionnel et technique du Module 1 — site institutionnel public sur `pssfp.net`. Il couvre les 8 rubriques principales de la navigation, les pages transversales (mentions légales, confidentialité, sitemap, 404), et la mise en cohérence avec les modules 2 (actualités), 3 (biblio sur sous-domaine), 5 (candidatures sur sous-domaine).

## 1. Architecture Next.js

App `frontend/` du mono-repo. Next.js 14 App Router, TypeScript strict, Tailwind CSS, shadcn/ui, next-intl (cf. ADR-0006).

Domaine production : `pssfp.net` (avec redirection `www.pssfp.net` → `pssfp.net`).

Stratégie de rendu par défaut :

- **SSG** pour les pages institutionnelles stables (présentation, gouvernance, conformité CAMES, partenaires, mentions légales).
- **ISR** (revalidate 5 min) pour les pages avec contenu éditorial fréquemment mis à jour (accueil avec actualités featured, listes formations, événements).
- **SSR** pour les pages personnalisées (rare — uniquement si on ajoute un parcours connecté côté site institutionnel, ce qui n'est pas le cas en V1).

Les données viennent de l'API `api.pssfp.net/v1` via `fetch` natif côté Server Component, avec `next: { revalidate: N }`.

Structure de routes :

```
frontend/app/
├── layout.tsx                          # RootLayout : header, footer, providers
├── (public)/
│   ├── page.tsx                        # /
│   ├── pssfp/
│   │   ├── page.tsx                    # /pssfp (sommaire section)
│   │   ├── presentation/page.tsx
│   │   ├── mot-president/page.tsx
│   │   ├── gouvernance/page.tsx
│   │   ├── organigramme/page.tsx
│   │   ├── conventions/page.tsx
│   │   ├── infrastructure/page.tsx
│   │   ├── partenaires/page.tsx
│   │   └── conformite-cames/page.tsx
│   ├── formations/
│   │   ├── page.tsx                    # /formations (sommaire)
│   │   ├── master/page.tsx
│   │   ├── tronc-commun/page.tsx
│   │   ├── specialites/
│   │   │   ├── page.tsx                # liste 5 spécialités
│   │   │   └── [slug]/page.tsx         # fiche spécialité
│   │   ├── continue/
│   │   │   ├── page.tsx                # catalogue 10 modules
│   │   │   └── [slug]/page.tsx
│   │   ├── certifications/page.tsx
│   │   ├── admission/page.tsx
│   │   └── frais-de-scolarite/page.tsx
│   ├── candidature/
│   │   └── page.tsx                    # page d'atterrissage qui redirige vers candidature.pssfp.net
│   ├── vie-academique/
│   │   ├── page.tsx
│   │   ├── promotions/
│   │   │   ├── page.tsx
│   │   │   └── [numero]/page.tsx
│   │   ├── corps-enseignant/
│   │   │   ├── page.tsx
│   │   │   └── [slug]/page.tsx
│   │   ├── calendrier-academique/page.tsx
│   │   ├── stages-et-soutenances/page.tsx
│   │   ├── programme-mediafip/page.tsx
│   │   └── cooperation-internationale/page.tsx
│   ├── actualites/
│   │   ├── page.tsx                    # liste articles (cf. spec module 2)
│   │   ├── [slug]/page.tsx             # détail article
│   │   ├── agenda/page.tsx
│   │   ├── agenda/[slug]/page.tsx
│   │   ├── galerie/page.tsx
│   │   └── galerie/[slug]/page.tsx
│   └── contact/page.tsx
├── mentions-legales/page.tsx
├── confidentialite/page.tsx
├── plan-du-site/page.tsx
└── not-found.tsx                       # 404
```

## 2. Layout global

### 2.1 Header (sticky)

Présent sur toutes les pages publiques. Sticky en haut avec léger blur backdrop.

Composition :

- Logo PSSFP à gauche (lien vers `/`).
- Menu de navigation horizontale avec les 8 rubriques (cf. CDC table 16). Sur mobile : hamburger.
- Boutons d'action à droite : « Accéder à la FOAD » (lien externe Moodle), « Bibliothèque » (lien externe biblio), « Candidater » (CTA principal couleur or).
- Barre de recherche globale (icône loupe, ouvre une modale de recherche fédérée articles + pages + biblio).

Le menu est généré par l'endpoint `/v1/menu` qui lit les pages `is_in_menu = true` + entrées dynamiques fixes.

### 2.2 Footer

Quatre colonnes :

1. **À propos** : logo PSSFP, courte description institutionnelle, adresse Campus Messa.
2. **Navigation** : liens rapides vers les 8 rubriques.
3. **Services numériques** : FOAD, Bibliothèque, Candidature, Email institutionnel.
4. **Contact** : email contact@pssfp.net, téléphone, lien Google Maps.

Bandeau bas : copyright, lien mentions légales, lien confidentialité, plan du site, réseau social Facebook.

### 2.3 Cookie consent banner

Au premier accès, bannière discrète en bas indiquant l'usage de cookies analytiques (Matomo) avec deux boutons « Accepter » et « Refuser ». Conforme à l'esprit RGPD. Choix conservé en cookie 6 mois. Refus = aucun tracking Matomo, le site fonctionne quand même.

## 3. Page d'accueil `/`

Endpoint API : agrège plusieurs appels (articles featured, settings, partenaires featured, prochain événement).

Stratégie de rendu : ISR avec revalidate 5 min.

### 3.1 Sections de la page

**Section 1 — Hero plein écran**.

- Image de fond : campus de Messa ou amphithéâtre HD.
- Titre H1 : « Programme Supérieur de Spécialisation en Finances Publiques » (institutionnel) avec accroche secondaire.
- Sous-titre : « Institut de référence en formation en finances publiques au Cameroun et dans l'espace CEMAC. »
- CTA principal : bouton « Candidature 2026 » couleur or, large, mène à `https://candidature.pssfp.net`.
- CTA secondaire : « Découvrir les formations » mène à `/formations`.

**Section 2 — Chiffres clés animés**.

- 4 compteurs animés (count-up au scroll dans la viewport) :
  - 13 promotions formées
  - 5 spécialités du Master
  - ~1200 cadres formés
  - 10+ ans d'expertise
- Animation Framer Motion light, déclenchée une seule fois.

**Section 3 — Actualités vedette**.

- Titre H2 « Actualités récentes ».
- Grille de 3 cards d'articles featured (récupérés via `/v1/articles/featured`).
- Lien « Toutes les actualités » → `/actualites`.

**Section 4 — Présentation PSSFP courte**.

- Titre H2 « Le PSSFP en bref ».
- Texte 2 paragraphes : mission + impact.
- Lien « En savoir plus » → `/pssfp/presentation`.

**Section 5 — Cards des 5 spécialités**.

- Titre H2 « Nos 5 spécialités ».
- Grille de 5 cards : couleur de la spécialité, icon, nom, nom_court, description_courte (50 mots max), lien « Découvrir ».
- Sur mobile, carrousel horizontal swipeable.

**Section 6 — Logos partenaires**.

- Titre H2 « Nos partenaires ».
- Grille de logos en niveaux de gris au repos, couleur au hover.
- Filtre par type optionnel (institutionnel / technique / académique).

**Section 7 — Accès rapides**.

- Titre H2 « Accédez rapidement ».
- 3 cartes grand format : « Plateforme FOAD » → `foad.pssfp.net`, « Bibliothèque virtuelle » → `bibliotheque.pssfp.net`, « Déposer ma candidature » → `candidature.pssfp.net`.

**Section 8 — Newsletter simple (V2 si temps)**.

- Encart d'inscription email pour recevoir les actualités. Phase II le plus probable — pas indispensable V1.

**Section 9 — Flux Facebook intégré** (cf. spec module 2).

### 3.2 Critères d'acceptation accueil

- Lighthouse mobile ≥ 90 sur les 4 dimensions (mesuré via lighthouse-ci).
- LCP < 2,0s sur 3G simulée.
- Hero image lazy avec `priority` Next.js Image, formats AVIF/WebP, sizes responsive.
- Test Playwright `tests/playwright/home.spec.ts` : tous les CTAs cliquables, animations chiffres déclenchées au scroll, navigation cards spécialités fonctionnelle.

## 4. Section LE PSSFP `/pssfp/*`

8 sous-pages.

### `/pssfp/presentation`

Page éditoriale gérée via Filament — endpoint `/v1/pages/pssfp/presentation`. Histoire institutionnelle depuis 2013, mission, vision ISFP. Image illustrative.

### `/pssfp/mot-president`

Page éditoriale. Mot du Pr. BASAHAG en pleine page avec sa photo officielle à gauche (mobile : photo au-dessus). Signature manuscrite scannée optionnelle.

### `/pssfp/gouvernance`

Liste des instances et responsables : Comité de Pilotage (Pr. BASAHAG), Comité Scientifique (Pr. AVOM), USI (M. ABE ETOUMOU), UPASS (M. MBIANA), UDCFC (Dr. MBALLA ZAMBO), UAAF (M. MBA), Centre de Documentation (M. BENOH BENOH). Format : cartes nominatives avec photo, fonction, brève bio.

### `/pssfp/organigramme`

Diagramme hiérarchique de l'organisation. Format : SVG produit en design ou mermaid.js rendu côté client. Cliquable : un clic sur une fonction pointe vers la fiche `/pssfp/gouvernance#anchor`.

### `/pssfp/conventions`

Liste des textes fondateurs avec téléchargement PDF :

- Convention tripartite MINFI-MINESUP-UY2 du 09 octobre 2013.
- Convention renouvelée ISFP du 08 mai 2024 (CRITIQUE — visible accueil, accessible).
- Lois et décrets de référence.

Pour chaque texte : titre, date, court résumé, bouton « Télécharger PDF ».

### `/pssfp/infrastructure`

Présentation Campus Messa : 3 amphithéâtres + 5 salles de classe avec photos HD. Galerie organisée par type d'espace. Carte Google Maps embed Campus Messa.

### `/pssfp/partenaires`

Liste exhaustive des partenaires avec descriptions étendues. Endpoint `/v1/partenaires`. Groupé par type (institutionnels, techniques, académiques, clients). Pour chaque : logo, nom, description, lien externe, convention PDF si applicable.

### `/pssfp/conformite-cames`

**Page critique CAMES**. Présente les 12 exigences CAMES (table 37 du CDC v5) sous forme de checklist avec liens vers les pages du site qui satisfont chaque exigence. Permet à un évaluateur CAMES de cocher mentalement la conformité.

Format : tableau avec 12 lignes, chaque ligne ayant : numéro, exigence, lien interne vers la page satisfaisante, statut « ✓ Intégré ».

### Critères d'acceptation section PSSFP

- Toutes les 8 sous-pages publiées avec contenu réel (CDC §15.4).
- Page conformité CAMES particulièrement soignée — c'est elle que les évaluateurs liront.
- Accessibilité WCAG 2.1 AA : contraste ≥ 4.5:1 vérifié, navigation clavier complète, alts sur toutes les images.

## 5. Section FORMATIONS `/formations/*`

### `/formations` (page sommaire)

Hub de la section. Présente 4 piliers : Master Professionnel, Formation Continue, Certifications, Admission. Chaque pilier = grande card avec photo, description courte, lien.

### `/formations/master`

Présentation générale du Master Professionnel : durée 2 ans, 4 semestres, hybride présentiel/FOAD, BAC+3 requis, VAE possible, coût 1 185 000 FCFA/an, débouchés. Schéma année 1 (tronc commun) → année 2 (5 spécialités).

### `/formations/tronc-commun`

Liste des UE de 1ère année (S1-S2). Endpoint `/v1/formations/tronc-commun`. Tableau organisé par semestre avec code, intitulé, volume horaire, crédits ECTS.

### `/formations/specialites` (liste)

Page de présentation des 5 spécialités. Endpoint `/v1/formations/specialites`. Hero introductif puis grille de 5 cards grand format avec couleur de la spécialité et CTA « Découvrir ».

### `/formations/specialites/[slug]` (fiche spécialité × 5)

**Page critique** — la fiche spécialité est la page la plus consultée par les candidats potentiels. Endpoint `/v1/formations/specialites/{slug}`.

Structure :

1. Hero avec illustration + nom spécialité + description courte.
2. Section « Objectifs et compétences visées ».
3. Section « Unités d'enseignement » : tableau S3 + S4, code, intitulé, volume, crédits.
4. Section « Débouchés professionnels » : liste des métiers visés.
5. Section « Corps enseignant de la spécialité » : cards d'enseignants avec photo, nom, grade, lien vers fiche détaillée.
6. Section « Pour aller plus loin » : ressources biblio liées (5 dernières thèses de la spécialité).
7. CTA « Déposer ma candidature » → `candidature.pssfp.net`.

Generation static : `generateStaticParams` pour les 5 slugs au build.

### `/formations/continue` (catalogue)

Liste des 10 modules de formation continue. Endpoint `/v1/formations/continues`. Format : grille de cards avec numéro, intitulé, durée, tarifs résumés, CTA « Demander un devis » (mailto avec sujet pré-rempli).

### `/formations/continue/[slug]` (fiche module FC)

Détail d'un module FC : cibles, objectifs, programme jour par jour, durée, tarifs détaillés (administrations / individus / auditeurs), modalités d'inscription. CTA « Demander un devis » + « Contacter UDCFC ».

### `/formations/certifications`

Liste certifications internationales (FMI/edX) + voyages d'étude (Maroc, Expertise France). Endpoint `/v1/formations/certifications`. Cards avec partenaire, modalité, lien externe.

### `/formations/admission`

Conditions d'admission complètes : niveau de diplôme requis, public cible, calendrier de candidature, étapes de sélection, dossier requis. Rédige l'expérience candidat de bout en bout.

### `/formations/frais-de-scolarite`

Frais 1 185 000 FCFA/an avec décomposition (inscription, scolarité, matériel pédagogique). Modalités de paiement (échéancier, agence CREMINCAM, etc.). Frais de candidature 50 000 FCFA. Frais formation continue (table 4995/500/250 kFCFA).

### Critères d'acceptation section Formations

- Les 5 fiches spécialités sont publiées et complètes (CDC §15.3).
- Le catalogue FC complet est en ligne.
- Frais de scolarité visibles en moins de 3 clics depuis l'accueil.
- Lighthouse ≥ 90 sur la fiche spécialité (page la plus visitée par les candidats).
- Test Playwright parcours candidat : accueil → formations → master → spécialités → fiche spécialité Fiscalité → CTA candidature.

## 6. Section CANDIDATURE `/candidature`

Page d'atterrissage simple expliquant le processus de candidature et redirigeant vers l'app dédiée `https://candidature.pssfp.net` (cf. spec module 5). Liens vers les sous-pages utiles : conditions d'admission, dossier à constituer, FAQ.

Si la campagne courante n'est pas ouverte, message « Les candidatures pour la prochaine promotion ouvriront le [date]. Inscrivez-vous pour être notifié. » avec bouton de capture email.

Pas de sous-pages CRUD sur `/candidature/*` côté pssfp.net — tout est centralisé sur le sous-domaine candidature.pssfp.net qui est une app Next.js séparée.

## 7. Section VIE ACADÉMIQUE `/vie-academique/*`

### `/vie-academique` (sommaire)

Hub avec 6 cards menant aux sous-pages : Promotions, Corps enseignant, Calendrier, Stages, MEDIAFIP, Coopération.

### `/vie-academique/promotions`

Liste des 13 promotions. Endpoint `/v1/promotions`. Format galerie : pour chaque promotion une card avec numéro, années, photo de groupe, statut (terminée / en cours), nombre d'étudiants, lien vers détail.

### `/vie-academique/promotions/[numero]`

Fiche promotion : photo de groupe HD, années, nombre d'étudiants par spécialité, faits marquants, liste des thèses/mémoires soutenus de cette promotion (extrait biblio).

### `/vie-academique/corps-enseignant` (liste)

Liste paginée des enseignants. Endpoint `/v1/enseignants`. Filtres par spécialité, par grade. Card par enseignant : photo, civilité + prénom + nom, grade, spécialités intervenantes (badges).

### `/vie-academique/corps-enseignant/[slug]`

Fiche enseignant : photo HD, identité, grade, qualifications, domaines de recherche, bio, UE enseignées, publications listées (avec liens biblio si applicable).

### `/vie-academique/calendrier-academique`

Calendrier académique synthétique 2026-2027 : début des cours, vacances, examens, soutenances, événements importants. Tableau ou frise chronologique.

### `/vie-academique/stages-et-soutenances`

Présentation du dispositif stages et soutenances : objectifs, durée, encadrement, modalités d'évaluation. Galerie photos des soutenances passées.

### `/vie-academique/programme-mediafip`

Présentation détaillée du Programme MEDIAFIP en partenariat avec Expertise France : objectifs, modules, séminaires, voyages d'étude. Galerie photos des sessions passées.

### `/vie-academique/cooperation-internationale`

Page consolidée sur la coopération : Institut Maroc, Expertise France, FMI/edX, Assemblée Nationale, réseau CEMAC. Pour chaque partenariat : description étendue, témoignages, retombées.

## 8. Section ACTUALITÉS `/actualites/*`

Couverte intégralement par la spec **module 2** (`module-2-actualites.md`). Inclut liste, détail article, agenda, galerie photos/vidéos, flux Facebook.

## 9. Section BIBLIOTHÈQUE

Liens externes uniquement depuis le site pssfp.net vers `bibliotheque.pssfp.net`. Pas de pages biblio sur `pssfp.net`. Couvert par la spec **module 3** (`module-3-bibliotheque.md`).

## 10. Section CONTACT `/contact`

Page de contact avec :

- Formulaire de contact (champs : nom, email, téléphone optionnel, organisation optionnelle, sujet, message). Captcha Cloudflare Turnstile. Soumet à `POST /v1/contact`. Confirmation à l'écran + email récapitulatif au candidat.
- Coordonnées Campus Messa : adresse postale, téléphone, email contact@pssfp.net, horaires d'ouverture.
- Carte Google Maps embed centrée sur Campus Messa.
- Plan d'accès rédigé : depuis l'aéroport, depuis le centre-ville, transports publics.

### Critères d'acceptation contact

- Formulaire envoie un email vers `contact@pssfp.net` ET un email de confirmation à l'émetteur (cf. spec A10).
- Captcha empêche les envois automatisés.
- Rate limit 5/h par IP appliqué.
- Carte Google Maps chargée en lazy avec consentement RGPD préalable (sinon image statique avec lien vers maps.google.com).

## 11. Pages transversales

### `/mentions-legales`

Statut juridique du PSSFP (institut MINFI Phase I de l'ISFP, convention 2024), responsable de publication (Pr. BASAHAG), hébergeur (VPS Contabo, Allemagne — à actualiser selon localisation réelle), accréditations CAMES, contact pour signalement.

### `/confidentialite`

Politique de confidentialité conforme à l'esprit RGPD : données collectées (formulaires, comptes auditeurs, candidatures), finalités, durées de conservation, droits utilisateurs, contact DPO délégué (Chef USI par défaut). Section cookies : Matomo (analytics, durée 13 mois), session Sanctum (auth, durée 24h), cookie consent (durée 6 mois).

### `/plan-du-site`

Sitemap HTML hiérarchique listant toutes les pages publiques accessibles. Auto-généré depuis `/v1/pages` + routes dynamiques.

### Page 404 `not-found.tsx`

Page d'erreur personnalisée : titre « Cette page n'existe pas », accroche amicale, suggestions de navigation (lien accueil + bouton recherche), illustration sobre. Évite la page 404 Next.js par défaut.

### Page 500

Page d'erreur applicative côté frontend : message rassurant, suggestion de recharger, lien vers contact. Sentry capture l'erreur côté Next.js.

## 12. SEO et discoverabilité

### 12.1 Meta tags par page

Chaque page dispose de :

- `<title>` unique et descriptif (50-60 caractères).
- `<meta name="description">` 150-160 caractères.
- `<meta property="og:*">` Open Graph complet (title, description, image, url, type).
- `<meta name="twitter:card">` Twitter Card.
- `<link rel="canonical">` pour éviter le duplicate content.

Generation : `generateMetadata` côté Next.js, lit les champs `meta_title`, `meta_description`, `og_image` du contenu BDD (avec fallback sur title si absent).

### 12.2 JSON-LD schema.org

Sur les pages pertinentes :

- Page d'accueil : `EducationalOrganization` schema.
- Pages spécialités : `EducationalOccupationalProgram` schema.
- Pages enseignants : `Person` schema.
- Articles : `Article` schema avec author, datePublished, image.
- Événements : `Event` schema.

### 12.3 Sitemap XML

Endpoint `/sitemap.xml` côté Next.js, généré dynamiquement, inclut toutes les pages publiées. Soumis à Google Search Console et Bing Webmaster Tools. Inclut `<lastmod>`.

### 12.4 robots.txt

```
User-agent: *
Allow: /
Disallow: /api/

Sitemap: https://pssfp.net/sitemap.xml
```

### 12.5 hreflang

Préparé pour multi-locale même si EN/AR pas activés en V1. Balises `<link rel="alternate" hreflang="fr">` etc. dans `<head>`.

## 13. Accessibilité WCAG 2.1 AA

Engagement contractuel CDC §10.3 et §15.1 :

- Contraste ≥ 4.5:1 pour le texte normal, ≥ 3:1 pour le grand texte.
- Navigation clavier complète : Tab logique, focus visible, Skip-to-content lien.
- Tous les images ont des `alt` significatifs (ou `alt=""` si décoratif).
- Tous les boutons icônes ont `aria-label`.
- Formulaires : labels associés, messages d'erreur liés via `aria-describedby`.
- Pas de texte uniquement véhiculé par la couleur.
- Animations respectent `prefers-reduced-motion`.

Outils de vérification : `eslint-plugin-jsx-a11y`, `axe-core` intégré aux tests Playwright via `@axe-core/playwright`.

## 14. Performance

Cible CDC §9.3 : Lighthouse ≥ 90 sur les 4 dimensions, LCP < 2s sur 3G.

Mesures :

- Images via `next/image` avec formats AVIF/WebP, sizes responsive, lazy par défaut.
- Polices via `next/font` (Playfair Display + Inter + DM Sans), `display: swap`, subsetting Latin.
- Code splitting automatique Next.js. Aucun import lourd dans les Server Components.
- Composants client minoritaires : seuls les vrais besoins interactifs sont `'use client'` (formulaires, lightbox, animations).
- Pas de framework CSS lourd : Tailwind compilé avec purge automatique.
- Pré-chargement DNS pour `api.pssfp.net`, `media.pssfp.net`, `fonts.googleapis.com`.

## 15. Critères d'acceptation Module 1

- Toutes les pages des 8 rubriques publiées avec contenu réel ou minimum garanti (cf. plan éditorial A4 §4).
- Lighthouse ≥ 90 sur les 4 dimensions sur les pages critiques : `/`, `/formations/specialites/fiscalite-finance-comptabilite-publique`, `/actualites`, `/contact`.
- Tests Playwright des 5 parcours utilisateurs (cf. CDC §7.2 personas) :
  - Candidat : Accueil → Formations → Master → Spécialités → Fiche spécialité → CTA Candidature.
  - Auditeur actif : Accueil → boutons rapides FOAD ou Bibliothèque.
  - Enseignant : Accueil → Vie académique → Calendrier + Bibliothèque.
  - Partenaire : Accueil → Formations → Continue → Contact.
  - Journaliste/Décideur : Accueil → PSSFP → Gouvernance + Conventions → Actualités.
- Score axe-core 0 violation critique sur les pages critiques.
- HTML rendu valide (pas de balises mal fermées).
- Aucune erreur 404 sur lien interne (test crawler).
- Redirection 301 active de `www.pfinancespubliques.org` vers `pssfp.net` (à activer Phase 8).

## 16. Composants Next.js à coder

Liste indicative non exhaustive, à compléter pendant le dev :

| Composant | Type | Rôle |
|---|---|---|
| `Header` | RSC + Client menu mobile | Navigation principale sticky |
| `Footer` | RSC | Pied de page 4 colonnes |
| `CookieBanner` | Client | Consentement RGPD |
| `Hero` | RSC | Hero accueil avec CTA |
| `KeyNumbersSection` | Client | 4 compteurs animés |
| `SpecialiteCard` | RSC | Card spécialité |
| `PartenairesGrid` | RSC | Grille logos partenaires |
| `QuickAccessCards` | RSC | 3 cards FOAD/Biblio/Candidature |
| `Breadcrumb` | RSC | Fil d'Ariane générique |
| `ContactForm` | Client | Formulaire contact + Turnstile |
| `MapEmbed` | Client | Google Maps avec consentement |
| `OrganigramSvg` | RSC | Diagramme organigramme |
| `CamesChecklist` | RSC | Tableau 12 exigences CAMES |
| `UeTable` | RSC | Tableau UE par semestre |
| `EnseignantCard` | RSC | Card enseignant |
| `PromotionCard` | RSC | Card promotion |
| `EventCard` | RSC | Card événement (réutilisé spec 2) |

## Annexe — Test Playwright parcours candidat

Squelette du test à compléter par Claude Code :

```typescript
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

test('Parcours candidat — accueil vers candidature spécialité Fiscalité', async ({ page }) => {
  await page.goto('/');
  await expect(page.getByRole('heading', { level: 1 })).toContainText('Programme Supérieur');

  // Vérifier accessibilité accueil
  let axe = await new AxeBuilder({ page }).analyze();
  expect(axe.violations.filter(v => v.impact === 'critical')).toEqual([]);

  // Aller vers Formations
  await page.getByRole('link', { name: /formations/i }).click();
  await expect(page).toHaveURL(/\/formations/);

  // Aller vers Master
  await page.getByRole('link', { name: /master/i }).click();

  // Aller vers Spécialités
  await page.getByRole('link', { name: /spécialités/i }).click();

  // Cliquer sur Fiscalité
  await page.getByRole('link', { name: /fiscalité, finance/i }).click();

  // Vérifier sur la fiche
  await expect(page.getByRole('heading', { level: 1 })).toContainText('Fiscalité');

  // CTA candidature
  const cta = page.getByRole('link', { name: /candidature/i });
  await expect(cta).toHaveAttribute('href', /candidature\.pssfp\.net/);
});
```
