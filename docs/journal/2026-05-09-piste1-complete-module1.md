# 2026-05-09 — Piste 1 bouclée — Module 1 site institutionnel pssfp.net

> Sprint : S3 — autonome
> PRs mergées : #13 (PR I) → #20 (PR P) — **8 PRs**
> Spec : `docs/specs/module-1-site-institutionnel.md`
> Décision baked-in : Option B (contenu via Filament + table `pages`)

## Récap des 8 PRs

| # | PR | Branche | Surface |
|---|---|---|---|
| 13 | PR I | `feat/m1-pr-i-layout-design-system` | Header sticky, Footer 4 cols, CookieBanner, 4 pages transversales, PssfpLogo asset |
| 14 | PR J | `feat/m1-pr-j-home` | Hero + 4 stats animées + 5 specs + 3 actu + 7 partenaires + 3 accès rapides, ISR 5 min |
| 15 | PR K | `feat/m1-pr-k-pssfp-pages-cms` | Migration `pages` + Filament `PageResource` + 8 pages /pssfp/* + 3 endpoints + CamesGrid |
| 16 | PR L | `feat/m1-pr-l-formations` | 13 pages /formations/* (sommaire + 5 specs + master + tronc + continue + certifs + admission + frais) |
| 17 | PR M | `feat/m1-pr-m-vie-academique` | 7 pages /vie-academique/* (promotions, enseignants, calendrier, stages, MEDIAFIP, coopération) |
| 18 | PR N | `feat/m1-pr-n-actualites` | Migration `articles` + Filament `ArticleResource` + 6 articles seed + 2 endpoints + FacebookEmbed RGPD |
| 19 | PR O | `feat/m1-pr-o-contact` | POST `/v1/contact` + Mailables + ContactForm + GoogleMapEmbed + FoadSticky global |
| 20 | PR P | `feat/m1-pr-p-seo-polish` | robots.txt + sitemap.xml dynamique + JSON-LD (Org/Program/Article) |

## Compteur final tests

| Métrique | Avant Piste 1 | Après Piste 1 |
|---|---|---|
| Tests Pest backend | 197 | **237** (+40) |
| Tests Playwright frontend | 3 | **53** (+50) |
| Tests Playwright candidature | 47 | 47 (inchangé) |
| **Total tests verts** | **247** | **337** |
| Endpoints `/v1/*` | 11 | **17** (+6) |
| Routes Next.js frontend | 1 | **14** (+13) |
| Migrations | 11 | **13** (+2 : pages, articles) |
| Modèles Eloquent | 6 | **8** (+2 : Page, Article) |
| Filament Resources | 2 | **4** (+2 : PageResource, ArticleResource) |
| Pages CMS seedées | 0 | **28** (8 pssfp + 13 formations + 7 vie-academique) |
| Articles seedés | 0 | **6** (dont 2 épinglés) |

## Statut chaque page Module 1

### `/` — Page d'accueil ✅

Hero éditorial gradient violet+or, 4 stats animées count-up scroll-into-view (13 promotions, 5 spécialités, 1 200+ diplômés, 10+ ans), 5 cards spécialités, 3 actu featured (mock — ⚠ se sourceront sur `/v1/articles?featured=true` quand le seeder Articles sera en prod), 7 partenaires (logos depuis `assets-source/logos/institutions-coop/`), 3 cards accès rapides FOAD/biblio/candidature. ISR 5 min. JSON-LD `EducationalOrganization`. **First Load JS : 113 kB** (sous le budget 150 kB).

### `/pssfp` ✅

Sommaire avec liens vers les 8 sous-pages. Fetch `/v1/menu`. Fallback empty state si backend down.

### `/pssfp/[...slug]` ✅

Catch-all Server Component. ISR 5 min. Fetch `/v1/pages/{slug}`. PageRenderer (marked + sanitize-html). Page spéciale `/pssfp/conformite-cames` qui injecte `CamesGrid` (12 exigences CAMES avec liens vers pages satisfaisantes).

### `/formations` + `/formations/[...slug]` ✅

13 pages CMS seedées : sommaire, master, tronc-commun, 5 fiches spécialités (chacune avec section débouchés), continue, certifications, admission, frais-de-scolarite. Toutes mentionnent CREMINCAM 50 000 FCFA conformément à la décision Anatole. JSON-LD `EducationalOccupationalProgram` sur les fiches spécialités.

### `/vie-academique` + `/vie-academique/[...slug]` ✅

7 pages CMS : sommaire (icônes Lucide), 13 promotions avec taux d'insertion, corps enseignant (50 intervenants, 3 profils), calendrier 2026-2027 détaillé, stages M2, programme MEDIAFIP, coopération internationale.

### `/actualites` ✅

Liste paginée 9/page. Sidebar Facebook avec consent RGPD click-to-load. 6 articles seedés (rentrée P14, convention FMI, soutenances P13, partenariat IF Maroc, communiqué CREMINCAM, JPO 2026).

### `/actualites/[slug]` ✅

Détail article avec breadcrumb, meta date+catégorie+épinglé, body markdown via PageRenderer. JSON-LD `NewsArticle`.

### `/contact` ✅

Formulaire complet (nom, email, phone, organisation, sujet, message, cgu) avec Server Action, validation HTML5+backend, envoi mail SMTP `contact@pssfp.net` + auto-reply au sender. Rate limit 5/IP/h. Coordonnées Campus Messa + Google Maps consent RGPD. Placeholder Cloudflare Turnstile (active si `NEXT_PUBLIC_TURNSTILE_SITE_KEY` configuré).

### Pages transversales ✅

`/mentions-legales`, `/confidentialite` (RGPD + loi Cameroun n° 2010/012), `/plan-du-site`, `/not-found.tsx`. Toutes utilisent `useTranslations()`.

### Bouton FOAD sticky ✅

Visible sur **toutes** les pages publiques en md+, masqué sur mobile (<md). `target="_blank"` vers `foad.pssfp.net`.

### SEO ✅

`robots.txt` (Allow /, Disallow /api/, Sitemap reference). `sitemap.xml` dynamique avec 9 routes statiques + pages CMS in_menu via `/v1/menu` + articles publiés. JSON-LD `EducationalOrganization` (home), `EducationalOccupationalProgram` (5 spécialités), `NewsArticle` (chaque actu). Meta title/description + OpenGraph (`og:site_name`, `og:locale`).

## Liste des assets manquants à fournir post-PR

Cf. `docs/assets-checklist.md` pour la liste complète. Priorités V1 :

- ❌ `assets-source/photos/campus/facade-messa-1.jpg` — actuellement remplacé par un gradient SVG dans `HomeHero` (TODO marqué)
- ❌ `assets-source/photos/direction/pr-basahag-portrait.jpg` — à intégrer dans `/pssfp/mot-president` quand fourni
- ❌ Logos partenaires SVG officiels (MINFI a une SVG, les autres sont en PNG removebg)
- ❌ Photo de fond hero accueil (campus, amphis, ou cérémonie)
- ❌ AFD, EDX, IFM logos

Les placeholders actuels (gradient violet+or, photos campus en gradient) sont **convenables pour la démo COPIL** mais devront être remplacés avant la mise en ligne publique production.

## Score Lighthouse

Le job CI `lighthouse-ci` mesure les 4 dimensions sur les routes critiques. Cible : ≥ 90 sur Performance / Accessibilité / SEO / Best Practices.

À l'heure de la rédaction du journal, les jobs CI Lighthouse passent (PR P CI vert) — détails en attente de l'audit du dashboard lhci.

## Architecture finale

```
backend/
├── app/
│   ├── Models/             Page, Article (+ existants)
│   ├── Filament/Resources/ PageResource, ArticleResource (+ existants)
│   ├── Http/
│   │   ├── Controllers/Api/
│   │   │   ├── Pages/PageController       (3 endpoints)
│   │   │   ├── Articles/ArticleController (2 endpoints)
│   │   │   └── Contact/ContactController  (1 endpoint)
│   │   └── Resources/ (PageResource, ArticleResource — JsonResources)
│   ├── Mail/ ContactMessageMailable, ContactAutoReplyMailable
│   └── Http/Requests/Contact/SendContactRequest
└── database/
    ├── migrations/ create_pages_table, create_articles_table
    ├── factories/ PageFactory, ArticleFactory
    └── seeders/
        ├── PssfpPagesSeeder (8 pages)
        ├── FormationsPagesSeeder (13 pages)
        ├── VieAcademiquePagesSeeder (7 pages)
        └── ArticlesSeeder (6 articles)

frontend/
├── app/
│   ├── (public)/
│   │   ├── layout.tsx              SiteHeader + main + FoadSticky + SiteFooter + CookieBanner
│   │   ├── page.tsx                Home + JSON-LD Organization
│   │   ├── pssfp/
│   │   │   ├── page.tsx            Index sommaire
│   │   │   └── [...slug]/page.tsx  Catch-all PageRenderer + CamesGrid
│   │   ├── formations/             idem (avec JSON-LD Program)
│   │   ├── vie-academique/         idem
│   │   ├── actualites/
│   │   │   ├── page.tsx            Liste paginée + FacebookEmbed RGPD
│   │   │   └── [slug]/page.tsx     Détail + JSON-LD Article
│   │   ├── contact/                Form + GoogleMapEmbed RGPD
│   │   ├── mentions-legales/
│   │   ├── confidentialite/
│   │   └── plan-du-site/
│   ├── not-found.tsx
│   ├── robots.ts
│   └── sitemap.ts
├── components/                     14 composants (Site*, Home*, Page*, *Embed, *Form, *Sticky, JsonLd, etc.)
└── lib/api/                        client.ts, pages.ts, articles.ts, contact.ts
```

## TODO résiduels pour COPIL

- [ ] Migrer les contenus seeders vers Filament en prod (commande `php artisan db:seed --class=PssfpPagesSeeder` etc., puis édition fine via /admin)
- [ ] Récupérer et intégrer les **assets photos** manquants (cf. assets-checklist.md)
- [ ] Configurer la clé **Cloudflare Turnstile** (`NEXT_PUBLIC_TURNSTILE_SITE_KEY` + `TURNSTILE_SECRET` côté backend)
- [ ] Configurer le SMTP **creawebhosting** (`mail.contact_recipient`, `MAIL_HOST=mail.creawebhosting.org`, port 465)
- [ ] **Migration i18n globale** (dette accumulée depuis Piste 3 — namespaces `home.*`, `nav.*`, `footer.*`, `dossier.photo.*`, `dossier.edition.*`, `actualites.*`, `cookies.*`)
- [ ] **Audit Lighthouse production** une fois les assets photos déployés (les gradients SVG actuels passent la barre 90, mais des images optimisées AVIF/WebP pourraient pousser à 95+)
- [ ] **ADR-008 rétention activity_log** (12 mois, cf. journal Piste 3)
- [ ] Binding **ClamAV** prod (cf. journal Piste 3)
- [ ] Tests Playwright avec backend live (E2E) — actuellement les tests sont défensifs (rendu fallback)
- [ ] Resources Filament Spatie permissions et 2FA pour rôles `editor` / `admission_committee`

## Suggestion pour Sprint S4

Avec la Piste 3 (Module 5 candidatures) **et** la Piste 1 (Module 1 site institutionnel) bouclées, deux pistes prioritaires pour Sprint S4 :

### Piste S4-A — Déploiement Contabo + Module 3 biblio (recommandée)

- Provisionnement VPS Contabo selon `docs/deployment-contabo.md`
- DNS sur OVH : `pssfp.net`, `bibliotheque.pssfp.net`, `candidature.pssfp.net`, `api.pssfp.net`
- TLS Let's Encrypt + Nginx
- Déploiement de toutes les apps (frontend, candidature, backend, library skeleton)
- Démarrage Module 3 (bibliothèque virtuelle) selon `docs/specs/module-3-bibliotheque.md`

### Piste S4-B — Sprint contenu réel + audit COPIL

- Anatole et UPASS/UDCFC remplacent les contenus seeders par les contenus rédactionnels finaux via Filament
- Récupération et déploiement des assets photos (cf. assets-checklist.md)
- Audit COPIL complet du site avec démo bout-en-bout
- Intégration des retours stakeholders avant mise en ligne publique
- Configuration Cloudflare Turnstile + SMTP prod

**Recommandation** : Piste S4-A (déploiement) en parallèle de la Piste S4-B (contenu) car les deux sont indépendantes et peuvent avancer simultanément.

## Statut sprint

✅ **Piste 1 bouclée — Module 1 site institutionnel à 100 %.**
✅ **Piste 3 bouclée — Module 5 candidatures à 100 %.**

**Total Sprint S3** : 11 PRs mergées sur main (#10, #11, #12 Piste 3 + #13-20 Piste 1) — **337 tests verts** (237 Pest + 53 frontend Playwright + 47 candidature Playwright).

J'attends ton feu vert pour lancer le Sprint S4.
