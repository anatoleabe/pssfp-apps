# Playbook Claude Code — Refonte pssfp.net

> **Version** : 1.0 · **Date** : Avril 2026 · **Référence CDC** : CDC-PSSFP-WEB-2026-005 v5.0
> **Auteur** : M. ABE ETOUMOU Anatole — Chef USI · Chef de Projet opérationnel
> **Objet** : Méthodologie 100 % pour livrer le projet en 13 semaines avec Claude Code.

Ce document est le **mode d'emploi opérationnel** du projet. Il traduit le CDC v5 en une séquence d'actions exécutables avec Claude Code, en couvrant : organisation du dépôt, configuration de Claude Code, patrons de prompts, découpage sprint par sprint, critères de recette, pièges à éviter. Il est conçu pour être posé à la racine du dépôt Git et relu à chaque début de session.

---

## Table des matières

1. Principes directeurs — comment « réussir » avec Claude Code
2. Pré-requis poste de travail et comptes
3. Stack technique validée et décisions architecturales
4. Organisation du mono-repo
5. Bootstrap du dépôt — séance 1 avec Claude Code
6. Fichiers `CLAUDE.md` (racine, frontend, backend)
7. Workflow quotidien : Plan → Act → Verify
8. Bibliothèque de prompts par module
9. Skills maison à créer
10. Slash commands et sous-agents
11. Plan 13 semaines — sprint par sprint
12. Playbook détaillé par module (M1 à M6)
13. Modèle de données & ressources Filament
14. Qualité — Lighthouse, WCAG 2.1 AA, CAMES
15. Déploiement, CI/CD et bascule DNS
16. Gouvernance projet, DoD et anti-patterns

---

## 1. Principes directeurs — comment « réussir » avec Claude Code

Claude Code n'est **pas** un simple générateur de code : c'est un collaborateur exécutant qui a besoin d'un cadre de travail clair. « Réussir à 100 % » repose sur six principes que toute l'équipe USI doit intérioriser.

**1.1 Un CDC n'est pas un prompt.** Le CDC v5 fait 30 pages. Claude Code ne doit jamais être sollicité avec « construis le site à partir du CDC ». Nous découpons le CDC en **unités de travail de 2 h à 1 jour maximum**, chacune formulée comme un objectif précis avec critères d'acceptation.

**1.2 La vérité vit dans le dépôt, pas dans la conversation.** Toute décision (modèle de données, arborescence, palette, conventions) doit être écrite dans un fichier versionné — principalement `CLAUDE.md`, `docs/adr/*`, `docs/specs/*`. Les sessions de Claude Code sont éphémères ; les fichiers persistent.

**1.3 Boucle Plan → Act → Verify obligatoire.** Pour chaque tâche : (a) demander à Claude un **plan écrit** avant tout code, (b) le relire et le valider, (c) exécuter, (d) vérifier par tests, captures, `lighthouse`, revue de diff. Jamais de code généré sans plan lu.

**1.4 Les tests sont non négociables.** Chaque endpoint Laravel a un test Pest. Chaque page Next.js critique a un test Playwright. Le score Lighthouse est vérifié automatiquement en CI. Sans tests, pas de merge.

**1.5 Sous-agents pour la revue.** Après chaque module, un sous-agent dédié (`cames-reviewer`, `a11y-reviewer`, `security-reviewer`) relit les diffs. Cela évite que le même modèle valide son propre travail.

**1.6 Le Chef USI reste le MOA technique.** Claude Code propose, l'humain dispose. Chaque PR est relue par M. ABE ETOUMOU avant merge. Aucun déploiement production sans signature.

---

## 2. Pré-requis poste de travail et comptes

Avant la séance 1 de développement, l'équipe USI doit disposer des éléments suivants. Ces pré-requis conditionnent le démarrage de la Phase 4 (développement backend) du planning CDC.

**2.1 Logiciels locaux (chef USI + binôme dev).** Node.js 20 LTS, pnpm 9, PHP 8.3 avec extensions `bcmath`, `intl`, `gd`, `redis`, `pdo_mysql`, Composer 2, Docker Desktop, Git 2.40+, VS Code avec extensions Laravel/Tailwind/Playwright. Claude Code installé via `npm install -g @anthropic-ai/claude-code`.

**2.2 Comptes et accès.** Compte Anthropic avec accès Claude Code (facturation validée par UAAF), compte GitHub organisation PSSFP (dépôts privés), compte Cloudflare (DNS + CDN assets), compte Sentry (monitoring erreurs), accès VPS Ubuntu 22 LTS (4 vCPU / 8 Go RAM / 100 Go SSD) provisionné par hébergeur, Figma (maquettes), Meilisearch Cloud ou instance auto-hébergée.

**2.3 Domaines.** `pssfp.net` configuré chez le registrar, `pssfp.org` et `pssfp.cm` à acquérir avant Phase 8 (bascule DNS). `pfinancespubliques.org` conservé pour redirection 301.

**2.4 Assets institutionnels.** Logo PSSFP en SVG + PNG haute résolution, logos partenaires (MINFI, MINESUP, UY2, Expertise France, Institut Finances Maroc, FMI, edX), 50 photos institutionnelles minimum (campus, amphithéâtres, promotions). Ces éléments conditionnent la Phase 3 du planning CDC.

---

## 3. Stack technique validée et décisions architecturales

Le CDC v5 a acté la stack. Ce playbook la traduit en conventions concrètes pour Claude Code.

**3.1 Rappel de la stack.** Frontend : Next.js 14+ (App Router, TypeScript strict, Tailwind CSS, shadcn/ui). Backend : Laravel 11 (PHP 8.3), Filament 3 pour l'administration, Sanctum pour l'auth API. Base : PostgreSQL 16 (recommandé sur MySQL 8 pour les performances de recherche et les types JSON). Recherche : Meilisearch via Laravel Scout. Cache : Redis 7. Stockage : local + S3 compatible (Scaleway ou MinIO). Serveur : Nginx + PHP-FPM. SSL : Let's Encrypt. CI/CD : GitHub Actions.

**3.2 Décisions architecturales enregistrées (ADR).** Chaque choix non trivial est consigné dans `docs/adr/NNNN-titre.md` au format ADR classique (contexte / décision / conséquences). À créer dès la séance 1 : ADR-0001 Stack Next.js + Laravel découplé, ADR-0002 PostgreSQL plutôt que MySQL, ADR-0003 Meilisearch plutôt qu'Elasticsearch, ADR-0004 Filament 3 comme CMS unique, ADR-0005 Authentification JWT via Sanctum pour la bibliothèque, ADR-0006 i18n FR par défaut avec structure prête pour EN/AR.

**3.3 Découplage Frontend / Backend.** Le frontend Next.js consomme une API REST JSON servie par Laravel sur `api.pssfp.net`. Les pages publiques sont rendues en SSG (Static Site Generation) avec revalidation ISR (Incremental Static Regeneration) toutes les 5 minutes pour les actualités, 1 heure pour le catalogue de formations. Les pages dynamiques (bibliothèque, recherche) sont en SSR. Ce découplage permet d'atteindre le score Lighthouse ≥ 90 exigé.

**3.4 Multi-domaines.** `pssfp.net` = frontend Next.js (site institutionnel). `api.pssfp.net` = Laravel (API + Filament sur `/admin`). `bibliotheque.pssfp.net` = sous-application Next.js dédiée (projet séparé dans le mono-repo), consommant la même API Laravel. `foad.pssfp.net` inchangé (Moodle existant).

---

## 4. Organisation du mono-repo

Mono-repo décidé. Structure imposée dès la séance 1 — toute dérive doit être justifiée en ADR.

```
pssfp/
├── CLAUDE.md                          # Contexte racine (ce fichier est lu à chaque session)
├── README.md                          # Pour les humains : comment démarrer
├── PLAYBOOK_Claude_Code_PSSFP.md      # Ce document
├── .claude/
│   ├── commands/                      # Slash commands projet
│   │   ├── plan-module.md
│   │   ├── verify-lighthouse.md
│   │   ├── cames-check.md
│   │   └── ship.md
│   └── agents/                        # Sous-agents projet
│       ├── cames-reviewer.md
│       ├── a11y-reviewer.md
│       ├── security-reviewer.md
│       └── filament-reviewer.md
├── docs/
│   ├── adr/                           # Architecture Decision Records
│   ├── specs/                         # Specs fonctionnelles par module
│   │   ├── module-1-site-institutionnel.md
│   │   ├── module-2-actualites.md
│   │   ├── module-3-bibliotheque.md
│   │   └── module-6-cms.md
│   ├── data-model.md                  # Schéma BDD complet
│   ├── api-contract.md                # Contrat API Laravel ↔ Next.js
│   └── content-plan.md                # Plan éditorial : qui livre quoi, quand
├── frontend/                          # Next.js 14+ — pssfp.net
│   ├── CLAUDE.md                      # Contexte frontend
│   ├── app/                           # App Router
│   │   ├── (public)/                  # Route group pages publiques
│   │   ├── pssfp/
│   │   ├── formations/
│   │   ├── candidature/
│   │   ├── vie-academique/
│   │   ├── actualites/
│   │   ├── contact/
│   │   └── api/                       # Routes API côté Next (webhooks, ISR)
│   ├── components/
│   ├── lib/
│   ├── public/
│   └── tests/playwright/
├── library/                           # Next.js 14+ — bibliotheque.pssfp.net
│   ├── CLAUDE.md
│   └── …
├── backend/                           # Laravel 11 — api.pssfp.net + /admin
│   ├── CLAUDE.md
│   ├── app/
│   │   ├── Filament/                  # Resources Filament 3
│   │   ├── Http/Controllers/Api/
│   │   ├── Models/
│   │   └── Services/
│   ├── database/migrations/
│   ├── database/seeders/
│   ├── routes/api.php
│   ├── tests/Feature/
│   └── tests/Unit/
├── infra/
│   ├── docker/
│   │   ├── docker-compose.yml         # Dev local : pgsql, redis, meili, mailpit
│   │   └── production/
│   ├── nginx/
│   └── deploy/
│       └── deploy.sh
└── .github/
    └── workflows/
        ├── ci.yml                     # Lint + tests sur chaque PR
        ├── lighthouse.yml             # Lighthouse CI sur preview
        └── deploy.yml                 # Déploiement VPS
```

**Convention de branches.** `main` protégée (PR obligatoire + CI verte + 1 approbation). `develop` pour intégration. Branches de feature : `feat/m1-page-accueil`, `feat/m3-catalogue-biblio`, etc. Branches de correction : `fix/`. Branches de docs : `docs/`. Aucun push direct sur `main`.

**Convention de commits.** Conventional Commits obligatoire : `feat(m1): hero section avec CTA candidature`, `fix(biblio): pagination des résultats`, `docs(adr): choix postgresql`, `test(api): coverage endpoints formations`.

---

## 5. Bootstrap du dépôt — séance 1 avec Claude Code

La séance 1 est la plus importante. Objectif : sortir de cette séance de 3 h avec un dépôt opérationnel, un `CLAUDE.md` racine complet, un `docker-compose.yml` qui démarre, et les deux apps (Next.js + Laravel) qui affichent une page « Hello ». Voici la séquence exacte.

**5.1 Création du dépôt.** Sur GitHub, créer `pssfp/pssfp` en privé. Cloner localement. `cd pssfp`. `claude` pour démarrer Claude Code.

**5.2 Premier prompt.** Coller ce prompt à Claude Code (reproduit ici pour copier-coller exact) :

```
Je démarre le projet de refonte du site pssfp.net (Programme Supérieur de
Spécialisation en Finances Publiques). Le CDC v5 est dans
docs/CDC_Site_Web_PSSFP_v5_2026.docx (je vais l'ajouter au dépôt).

Objectif de cette séance : bootstrapper le mono-repo selon la structure
définie dans PLAYBOOK_Claude_Code_PSSFP.md (que je colle ci-dessous).

Plan attendu avant toute commande :
1. Liste des commandes exactes à exécuter (dans l'ordre) pour créer la
   structure de dossiers, initialiser Next.js 14 dans /frontend, Laravel 11
   dans /backend, et /library en deuxième app Next.js.
2. Contenu précis du CLAUDE.md racine, du .gitignore, du docker-compose.yml
   (pgsql 16, redis 7, meilisearch 1.7, mailpit).
3. Liste des ADR à créer (ADR-0001 à ADR-0006).

Ne lance AUCUNE commande avant que j'aie validé le plan.
```

**5.3 Validation humaine du plan.** Relire le plan proposé, questionner les choix, corriger si nécessaire (ex : versions de dépendances, ports Docker). Ne valider qu'après lecture complète.

**5.4 Exécution par étapes.** Demander à Claude Code d'exécuter **une étape à la fois**, avec vérification entre chaque. Checkpoint après : création structure, init Next.js `/frontend`, init Next.js `/library`, init Laravel `/backend`, docker-compose up, migrations Laravel, page d'accueil Next.js « Bienvenue PSSFP », page admin Filament accessible.

**5.5 Premier commit.** `git add . && git commit -m "chore: bootstrap mono-repo Next.js + Laravel + Filament 3"`. Pousser sur GitHub. Créer la PR de bootstrap et la merger manuellement.

**5.6 Livrable sortie de séance 1.** Un dépôt où `make dev` (ou `docker compose up && pnpm dev && php artisan serve`) lance les trois apps, un CLAUDE.md racine relu et validé, six ADR rédigés, le CDC v5 archivé sous `docs/`.

---

## 6. Fichiers `CLAUDE.md` (racine, frontend, backend)

Les trois `CLAUDE.md` sont la **mémoire longue** de Claude Code pour ce projet. Ils sont lus automatiquement à chaque session ouverte dans leur dossier. Les négliger = tout refaire dire à chaque fois = 30 % de temps perdu.

**6.1 `CLAUDE.md` racine — template à adopter**

```markdown
# pssfp.net — mono-repo

## Mission
Refonte du site institutionnel PSSFP + bibliothèque virtuelle.
Référence contractuelle : docs/CDC_Site_Web_PSSFP_v5_2026.docx (v5.0).
Chef de Projet : M. ABE ETOUMOU Anatole (USI).

## Stack
- Frontend public : Next.js 14 (App Router, TS strict, Tailwind, shadcn/ui) — /frontend → pssfp.net
- Bibliothèque : Next.js 14 — /library → bibliotheque.pssfp.net
- API + Admin : Laravel 11 + Filament 3 — /backend → api.pssfp.net
- BDD : PostgreSQL 16 · Cache : Redis 7 · Recherche : Meilisearch · Stockage : S3-compat

## Règles de travail
1. JAMAIS de code sans plan écrit validé d'abord. Toujours répondre par un plan avant d'agir.
2. Commits en Conventional Commits. Branche par feature.
3. Tests obligatoires : Pest côté Laravel, Playwright côté Next.js.
4. Accessibilité WCAG 2.1 AA non négociable. Contraste ≥ 4.5:1.
5. Score Lighthouse ≥ 90 sur Performance / Accessibilité / SEO / Best Practices.
6. Tous les textes publics en français (source). Balisage prêt pour i18n (EN/AR).
7. Aucune clé API ni secret en clair : .env uniquement, jamais commit.
8. Les données de test (seeders) ne doivent pas contenir de vraies données nominatives.

## Charte graphique (section 10 du CDC)
- Violet institutionnel : #6B2FA0 (titres, CTA)
- Violet moyen : #9B59B6 (hover, liens actifs)
- Lavande : #EDE7F6 (fonds doux)
- Or : #C9A227 (badges, partenaires premium)
- Texte : #333333 sur fond #FFFFFF (contraste AA)
- Police titres : Playfair Display Bold
- Police corps : Inter 16px min
- Police UI : DM Sans Medium

## Arborescence publique (section 7 du CDC)
8 rubriques : Accueil, Le PSSFP, Formations, Candidature, Vie académique,
Actualités, Bibliothèque (sous-domaine), Contact.
Règle des 3 clics maximum. Sticky header, breadcrumb, footer complet.

## Personae prioritaires
1. Candidat (CTA Candidature 2026 visible partout)
2. Auditeur actif (accès rapide FOAD + Bibliothèque)
3. Partenaire / administration (formations continues)

## Intégrations externes
- Moodle FOAD : foad.pssfp.net — simple lien (SSO = Phase II)
- Formulaire candidature existant : iframe ou lien — refonte Phase II
- Facebook : widget flux @pssfpcameroun
- Email institutionnel : @pssfp.net (contact form)

## Modules en cours
Voir docs/specs/module-*.md. Phase I = M1, M2, M3, M6. M4 et M5 = Phase II.

## Hors périmètre Phase I
- Espace étudiant sécurisé
- Refonte complète du formulaire candidature
- SSO Moodle

## Commandes utiles
- make dev         # Démarre docker + frontend + backend
- make test        # Pest + Playwright + lint
- make lighthouse  # Audit Lighthouse sur routes critiques
- make cames       # Vérifie la checklist CAMES automatisée
- make deploy      # Déploiement VPS (main uniquement)
```

**6.2 `frontend/CLAUDE.md` — spécifique Next.js**

```markdown
# /frontend — site public pssfp.net

## Principes
- App Router (pas de pages router). Server Components par défaut.
- Client Components marqués "use client" uniquement si indispensable (formulaires, interactivité).
- Fetch côté serveur via fetch() natif avec `next: { revalidate: N }` — pas d'axios côté serveur.
- Images : next/image obligatoire. WebP + lazy loading + sizes.
- i18n : next-intl. Namespace par rubrique. FR source.

## Structure des routes
- app/(public)/page.tsx                      — Accueil
- app/(public)/pssfp/[slug]/page.tsx         — Sous-pages PSSFP
- app/(public)/formations/specialites/[slug] — 5 fiches spécialités
- app/(public)/actualites/[slug]/page.tsx    — Article
- …

## Conventions composants
- Un composant = un dossier : components/HeroAccueil/{index.tsx,styles.module.css,Hero.test.tsx}
- shadcn/ui pour les primitives (Button, Card, Dialog).
- Pas de CSS inline. Tailwind + classes sémantiques.

## Performance
- LCP < 2s. Pré-charger uniquement la hero image.
- Polices : next/font/google pour Playfair / Inter / DM Sans. `display: swap`.
- Pas d'animation JS lourde. Framer Motion autorisé uniquement pour les chiffres animés accueil.

## Tests
- Playwright pour parcours critiques :
  test-candidat.spec.ts, test-auditeur.spec.ts, test-partenaire.spec.ts
- Tests a11y : axe-core intégré à chaque test Playwright
- Lighthouse CI sur : /, /formations/specialites/fiscalite, /actualites, /contact
```

**6.3 `backend/CLAUDE.md` — spécifique Laravel**

```markdown
# /backend — API + admin (Laravel 11 + Filament 3)

## Principes
- Architecture : Controllers minces, Services pour la logique métier, Models pour les règles BDD.
- Requests pour validation (FormRequest). Ressources API (JsonResource) pour serialisation.
- Jamais de SQL brut sauf justification ADR.
- Eloquent + Scopes nommés. Pas de N+1 : eager loading systématique.

## API publique
Base URL : https://api.pssfp.net/v1
- GET /pages/{slug}
- GET /formations/specialites
- GET /formations/specialites/{slug}
- GET /formations/continue
- GET /actualites?category=&page=
- GET /actualites/{slug}
- GET /enseignants
- GET /promotions
- GET /partenaires
- POST /contact           # Rate-limited 5/h/IP
- GET /library/documents  # Auth : publique / auditeur / enseignant
- GET /library/search?q=  # Meilisearch

## Filament 3 — admin
- URL : /admin (behind HTTP auth + 2FA obligatoire pour rôle admin)
- Resources : Page, Article, Formation, Specialite, Promotion, Enseignant,
  Partenaire, Document, User, Category, Tag, Media
- Rôles Spatie : admin, editor, librarian, auditor, teacher
- Activity Log : Spatie\ActivityLog sur toutes les resources

## Règles BDD
- PostgreSQL. Migrations toujours réversibles (down() complet).
- Noms tables : snake_case pluriel.
- Softdeletes sur : articles, documents, pages, enseignants, promotions.
- UUIDs pour les clés exposées en API publique (slug + id interne en interne).

## Tests Pest
- Feature tests sur chaque endpoint API
- Policy tests sur chaque politique d'accès (ex: DocumentPolicy)
- Seed minimal pour tests (factories)

## Sécurité
- Sanctum pour auth API. Tokens avec scopes.
- Rate limiting : 60/min public, 300/min auditeur.
- CSP stricte sur /admin.
- Storage privé pour docs restreints (StreamedResponse avec signed URL).
```

---

## 7. Workflow quotidien : Plan → Act → Verify

Chaque session Claude Code suit la même boucle, qu'il s'agisse de 10 lignes ou de 1 000. Toute déviation doit être justifiée.

**7.1 Ouverture de session (2 min).** `cd pssfp && claude`. Laisser Claude lire les `CLAUDE.md` en hiérarchie. Lui indiquer : « Nous travaillons sur le Module X, tâche Y. Référence spec : `docs/specs/module-X.md`. Commence par me lire cette spec et me proposer un plan. »

**7.2 Phase PLAN (15 à 30 min).** Claude produit un plan numéroté : fichiers à créer/modifier, ordre, tests à ajouter, risques. **Règle d'or** : refuser tout plan qui fait plus de 8 étapes → demander un découpage en deux tâches. Relire le plan, questionner les choix douteux (« pourquoi ce nom de colonne ? », « pourquoi ce hook ? »), valider formellement par écrit : « Plan validé. Procède étape 1. »

**7.3 Phase ACT (durée variable).** Claude exécute étape par étape. Après chaque étape : diff relu, `pnpm test` ou `php artisan test`, commit avec message Conventional. Interdit de laisser Claude enchaîner 5 étapes sans checkpoint humain.

**7.4 Phase VERIFY (10 à 20 min).** Une fois la tâche finie :
- `make test` passe au vert
- `make lighthouse` si changement front
- Revue de diff complète (`git diff develop...HEAD`)
- Lancement d'un sous-agent approprié : `/a11y-reviewer` ou `/cames-reviewer` ou `/security-reviewer`
- Mise à jour de la spec dans `docs/specs/` si découverte

**7.5 Fermeture de session (5 min).** Commit final, push branche, ouverture PR avec description. Mise à jour du ticket dans l'outil de suivi. Note de passation dans `docs/journal/YYYY-MM-DD.md` (ce que j'ai fait, ce qui reste, ce qui bloque). Cette trace permet de reprendre proprement la session suivante.

---

## 8. Bibliothèque de prompts par module

Voici des prompts éprouvés, prêts à copier-coller, pour les tâches récurrentes. Ces patrons évitent l'improvisation.

**8.1 Prompt — nouvelle page publique**

```
Tâche : créer la page publique /formations/specialites/[slug] pour les
5 spécialités du Master (cf. section 2.2 du CDC).

Contexte :
- Spec : docs/specs/module-1-site-institutionnel.md §3.2
- Design : Figma frame "Fiche spécialité" (ajouter capture dans docs/design/)
- Données : endpoint GET /api/v1/formations/specialites/{slug} (déjà existant,
  voir docs/api-contract.md)

Critères d'acceptation :
- Server Component avec revalidate: 3600
- generateStaticParams pour les 5 slugs
- Hero avec titre spécialité + image illustrative
- 4 sections : Objectifs, Unités d'enseignement, Débouchés, Corps enseignant
- Breadcrumb
- CTA "Déposer ma candidature" (lien /candidature/deposer-candidature)
- Lighthouse ≥ 90 sur les 4 dimensions
- Test Playwright : navigation depuis /formations, contenu visible,
  lien CTA fonctionne

Contraintes :
- Aucun "use client" sauf si justifié
- next/image pour toutes les images
- Respect palette §10.1 du CDC
- WCAG 2.1 AA (contraste, aria-label sur les liens icônes)

Attendu : plan numéroté avant code.
```

**8.2 Prompt — ressource Filament**

```
Tâche : créer la resource Filament 3 pour le modèle Specialite.

Contexte :
- Modèle existant : app/Models/Specialite.php
- Champs : id (uuid), slug (unique), nom_fr, nom_en (nullable), description_courte,
  description_longue (markdown), couleur_hex, ordre_affichage,
  photo_illustrative (media), is_active (bool), debouches (json array),
  timestamps
- Relations : hasMany(UniteEnseignement), hasMany(Enseignant, through)

Exigences :
- Resource avec form : toutes les colonnes, markdown editor pour desc longue,
  SpatieMediaLibraryFileUpload pour photo, Repeater pour debouches
- Table : nom_fr, slug, ordre_affichage, is_active, updated_at — triable par ordre
- Policy : seuls admin et editor peuvent créer/modifier
- ActivityLog activé
- Test Pest Feature : création, édition, suppression, accès refusé pour auditor
- Traductions FR dans lang/fr/filament-specialite.php

Plan attendu avant code.
```

**8.3 Prompt — migration + modèle + seeder**

```
Tâche : créer le schéma bibliothèque — table documents + metadata_terms +
pivot document_term + migration dans PostgreSQL.

Spec : docs/specs/module-3-bibliotheque.md §Catalogue
Exigences :
- UUIDs comme PK exposable
- type enum : these, memoire, article_scientifique, texte_loi, cours
- access_level enum : public, auditor, teacher, admin
- Champs SEO : meta_title, meta_description
- Full-text search : ajouter trigramme GIN index sur titre + auteurs
- Factory + Seeder 50 docs fake (sans données réelles)
- Scout searchable sur titre, auteurs, mots_cles (toArray())

Plan avant code.
```

**8.4 Prompt — test Playwright parcours utilisateur**

```
Tâche : test Playwright du parcours "Candidat potentiel" (cf. §7.2 CDC).

Parcours :
1. Accueil → clic CTA "Candidature 2026"
2. Page /formations/master → scroll → lien "Spécialités"
3. /formations/specialites → clic card "Fiscalité, Finance et Comptabilité"
4. Fiche spécialité → bouton "Dossier à constituer"
5. /candidature/dossier-a-constituer → bouton "Déposer ma candidature"
6. Vérifier redirection vers formulaire candidature externe

Vérifications à chaque étape :
- Titre H1 correct
- Axe-core : 0 violation critique
- Screenshot stocké dans tests/playwright/screenshots/
- Temps de chargement total < 8s (3G slow)

Plan avant code.
```

**8.5 Prompt — refactor / dette technique**

```
Tâche : refactor — extraire la logique d'accès bibliothèque dans un
DocumentAccessService.

Contexte :
- Actuellement dupliquée dans DocumentController, DocumentResource (Filament),
  LibrarySearchController.
- Tests existants : tests/Feature/LibraryAccessTest.php

Règle : aucun changement de comportement. Les tests doivent rester verts
sans modification. Coverage de LibraryAccessTest.php doit rester ≥ niveau
actuel (vérifier avec php artisan test --coverage).

Plan avant refactor.
```

---

## 9. Skills maison à créer

Les skills sont des dossiers `.claude/skills/NOM/SKILL.md` qui encapsulent une procédure récurrente. Ils sont chargés à la demande par Claude Code. Pour ce projet, créer les six skills suivants dès la Phase 3.

**9.1 `cames-check`** — Vérifie les 12 exigences CAMES (Annexe B du CDC) par scraping local des pages générées. Lit une checklist YAML, navigue vers chaque page concernée, vérifie la présence de mots-clés et de sections. Retourne un rapport Markdown `docs/reports/cames-YYYY-MM-DD.md`. À lancer avant chaque recette de phase.

**9.2 `filament-resource`** — Génère une ressource Filament complète (form + table + policy + test + traductions) à partir d'un prompt YAML décrivant le modèle. Standardise la qualité — aucune ressource Filament ne doit être écrite à la main après création de ce skill.

**9.3 `page-builder`** — Scaffold d'une nouvelle page publique Next.js avec tous les garde-fous : Server Component, metadata SEO, breadcrumb, layout, test Playwright, entrée sitemap. Prend en paramètre : slug, titre, sous-titre, sections (tableau).

**9.4 `a11y-audit`** — Lance axe-core sur une liste de routes, produit un rapport consolidé avec captures pour chaque violation. Intégré à la CI via GitHub Actions.

**9.5 `content-import`** — Pipeline d'import des contenus fournis par UPASS/UDCFC/UAAF. Prend des fichiers Word/Excel en entrée, produit des seeders Laravel + médias vers S3. Lancé en Phase 6 (intégration des contenus).

**9.6 `biblio-migrate`** — Migration de la bibliothèque existante. Lit l'export de l'ancien Joomla (SQL ou CSV), transforme les métadonnées, importe dans le nouveau schéma documents. Gère les doublons, la normalisation des auteurs, la détection de langue.

Chaque skill commence par un SKILL.md court (moins de 50 lignes) qui décrit : déclencheurs, entrées attendues, étapes, sorties, pièges connus. Suivre le guide officiel du skill `skill-creator`.

---

## 10. Slash commands et sous-agents

**10.1 Slash commands projet** (dans `.claude/commands/`)

- `/plan-module <numéro>` — charge `docs/specs/module-N.md` et demande à Claude un plan d'implémentation complet découpé en sous-tâches de 2-4h.
- `/verify-lighthouse` — lance Lighthouse CI sur les 8 routes critiques définies dans `lighthouserc.js` et produit un rapport.
- `/cames-check` — invoque le skill `cames-check` et résume les écarts.
- `/ship <branche>` — checklist pré-merge : tests verts, Lighthouse ≥ 90, a11y 0 violation, revue CAMES si page publique, ADR à jour si décision structurelle.
- `/content-status` — lit `docs/content-plan.md` et affiche ce qui est livré vs ce qui est en retard côté UPASS/UDCFC/UAAF.
- `/dns-switch` — checklist Phase 8 (bascule DNS) : TTL abaissé, certifs Let's Encrypt prêts, 301 `pfinancespubliques.org` testée, fenêtre de maintenance communiquée.

**10.2 Sous-agents projet** (dans `.claude/agents/`)

- **`cames-reviewer`** — relit les diffs d'une PR impactant les pages publiques et vérifie l'impact sur la checklist CAMES. Commente la PR avec un verdict : ✅ conforme / ⚠ à documenter / ❌ bloquant.
- **`a11y-reviewer`** — audit axe-core + revue manuelle (contraste, focus ring, aria). Verdict écrit.
- **`security-reviewer`** — revue dédiée sécurité : validation des inputs Laravel, CSP, secrets, dépendances `composer audit` / `pnpm audit`.
- **`filament-reviewer`** — vérifie qu'une nouvelle Filament Resource suit les conventions : Policy, ActivityLog, tests, i18n FR.
- **`content-reviewer`** — revue éditoriale : fautes d'orthographe, ton institutionnel, terminologie CAMES/CEMAC cohérente.

Règle d'or : **un humain (M. ABE ETOUMOU) valide toujours après sous-agent**. Les sous-agents accélèrent ; ils ne remplacent pas la revue finale.

---

## 11. Plan 13 semaines — sprint par sprint

Alignement strict avec le §11 du CDC (8 phases, 13 semaines). Chaque sprint commence le lundi par une revue Claude Code (planning + `docs/journal/`) et finit le vendredi par une démo interne USI.

**Sprint 1 (S1-S2, Avril S1-S2) — Cadrage & bootstrap**
- Livrables CDC : CDC v5 signé, arborescence, plan éditorial.
- Claude Code : bootstrap mono-repo (séance 1), six ADR, `docs/specs/` ébauchés, Docker dev opérationnel, CI skeleton.
- Jalon : démonstration `make dev` devant le Chef de Projet.

**Sprint 2 (S2-S4, Avril S2-S4) — Audit & collecte de contenus**
- Livrables CDC : audit bibliothèque, inventaire photos, textes institutionnels, fiches spécialités, catalogue FC.
- Claude Code : `docs/content-plan.md` finalisé avec dates butoirs par unité, skill `biblio-migrate` prototypé (dry-run sur export ancien Joomla), templates de fiches spécialités en Markdown dans `docs/content/`.
- Jalon : `content-plan.md` validé par Pr. BASAHAG.

**Sprint 3 (S3-S5, Avril S3 - Mai S1) — Maquettes & prototype**
- Livrables CDC : wireframes, prototype Figma haute fidélité, guide de style.
- Claude Code : tailwind.config.js avec palette + typographie, bibliothèque de composants (Button, Card, Hero, Breadcrumb, Footer) alignée Figma, page d'accueil statique avec données fake, page fiche spécialité idem, test visuel Playwright screenshots.
- Jalon : revue par Comité de Pilotage (retour sous 5 jours ouvrés).

**Sprint 4 (S5-S7, Mai S1-S3) — Backend Laravel + Filament**
- Livrables CDC : backend Laravel 11, BDD, Filament 3, moteur recherche.
- Claude Code : migrations complètes (22 tables), Filament Resources (12), API v1 publique (endpoints §6.2), Meilisearch indexé, Sanctum + rôles Spatie, 80 %+ de coverage Pest.
- Jalon : admin Filament fonctionnel + API testable via Postman.

**Sprint 5 (S7-S9, Mai S3 - Juin S1) — Frontend + bibliothèque**
- Livrables CDC : frontend Next.js complet, bibliothèque refondue, paiement bibliothèque.
- Claude Code : 100 % des pages publiques (8 rubriques), bibliothèque Next.js (recherche avancée, filtres, auth auditeur, favoris), intégration passerelle paiement (MTN MoMo / Orange Money — **démarches réglementaires lancées en Phase 1**).
- Jalon : navigation complète end-to-end sans contenu définitif.

**Sprint 6 (S9-S10, Juin S1-S2) — Intégration des contenus**
- Livrables CDC : textes, photos, documents intégrés ; Moodle + Facebook + formulaire candidature reliés.
- Claude Code : skill `content-import` exécuté sur les livraisons UPASS/UDCFC/UAAF, seeders production, widget Facebook, iframe formulaire candidature, CTA Moodle sur accueil et header.
- Jalon : site « complet » en staging.

**Sprint 7 (S10-S12, Juin S2-S4) — Tests & recette**
- Livrables CDC : rapport tests (fonctionnels, perf, sécurité, CAMES, mobile, a11y).
- Claude Code : Lighthouse CI sur 20 routes, Playwright couverture parcours 5 personae, `cames-check` OK, `security-reviewer` OK sur tout le dépôt, test de charge k6 (100 utilisateurs concurrents), test sauvegarde + restauration BDD.
- Jalon : recette signée par Comité de Pilotage.

**Sprint 8 (S12-S13, Juin S4 - Juillet S1) — Déploiement & clôture**
- Livrables CDC : mise en production, bascule DNS, formation USI, doc, PV réception.
- Claude Code : script `deploy.sh` testé sur VPS staging puis prod, 301 `pfinancespubliques.org → pssfp.net`, monitoring (Sentry + UptimeRobot), formation 2 jours (slides + manuel CMS), documentation technique finalisée.
- Jalon : **PV de réception signé par Pr. BASAHAG**.

---

## 12. Playbook détaillé par module (M1 à M6)

**12.1 Module 1 — Site institutionnel public**
Pages : accueil, /pssfp/*, /formations/*, /candidature/*, /vie-academique/*, /contact, /mentions-legales, /confidentialite, /plan-du-site, 404.
Ordre de construction : (1) layout global (header, footer, breadcrumb), (2) accueil (hero + chiffres + cards spécialités + actualités + partenaires + accès rapides), (3) /pssfp (7 sous-pages), (4) /formations (master, 5 fiches, continue, certifications, admission, frais), (5) /candidature, (6) /vie-academique, (7) /contact + pages transversales.
Définition of done : Lighthouse ≥ 90 sur chaque page testée, breadcrumb cohérent, metadata SEO (title, description, OG image, sitemap.xml, robots.txt).

**12.2 Module 2 — Actualités et communication**
Composants : page `/actualites` (liste paginée + filtres catégories + tags), page article `/actualites/[slug]`, page `/agenda`, page `/galerie` (albums + lightbox + vidéos YouTube embed).
Claude Code : composant `ArticleCard`, composant `Lightbox` (shadcn/ui Dialog), composant `FacebookFeed` (widget iframe safe), génération de RSS `/actualites/feed.xml`.

**12.3 Module 3 — Bibliothèque virtuelle**
Sous-application Next.js séparée sur `bibliotheque.pssfp.net`. Recherche Meilisearch (facets : type, spécialité, promotion, année). Auth : login @pssfp.net via API Laravel Sanctum. Rôles : public / auditor / teacher / admin. Accès différencié : les supports de cours ne sont listés qu'après connexion auditeur.
Plan de migration : skill `biblio-migrate` — audit Joomla (extraction métadonnées), nettoyage doublons, import par lots de 100, validation post-import (échantillon 10 %).
Paiement ouvrages (ligne budgétaire 2) : MTN MoMo ou Orange Money via API officielle — **démarches à lancer en Phase 1** (compte marchand, certification). Fallback : virement bancaire avec code commande.

**12.4 Module 6 — CMS Filament 3**
Resources à créer : Page, Article, Category, Tag, Formation (Master / Continue / Certification), Specialite, UniteEnseignement, Enseignant, Promotion, Partenaire, MembreGouvernance, Document (bibliothèque), User, Role, Media.
Dashboard : widgets « Articles récents », « Demandes de contact », « Documents les plus consultés », « Utilisateurs actifs ».
Formation USI : manuel illustré `docs/manuel-cms.md` généré à partir des captures Filament + vidéo 20 min (Loom) par type de contenu.

**12.5 Modules 4 & 5 (Phase II — HORS PÉRIMÈTRE 2026)**
Ne pas anticiper le code. En revanche, le modèle de données BDD doit **prévoir** la table `users` avec scopes futurs (étudiant, examinateur) et le schéma `candidatures` stub (migration vide commentée) pour éviter une migration lourde en Phase II.

---

## 13. Modèle de données & ressources Filament

Schéma logique (22 tables). À implémenter en PostgreSQL. Les noms sont indicatifs.

**Contenu éditorial** : `pages`, `articles`, `categories`, `tags`, `article_tag`, `media`.
**Offre de formation** : `formations_master`, `specialites`, `unites_enseignement`, `formations_continues`, `certifications`, `frais_scolarite`.
**Personnes** : `users`, `roles`, `role_user`, `enseignants`, `promotions`, `enseignant_specialite`, `enseignant_promotion`.
**Institution** : `partenaires`, `membres_gouvernance`, `conventions`, `infrastructures`.
**Bibliothèque** : `documents`, `document_types`, `metadata_terms`, `document_term`, `document_downloads`, `favoris`.
**Transversal** : `contacts`, `newsletter_subscribers`, `activity_log`, `failed_jobs`.

Chaque table ≥ 10 colonnes a sa propre migration, son modèle Eloquent, sa factory Pest, sa Filament Resource, sa Policy, ses tests. Le skill `filament-resource` (section 9.2) standardise la production.

---

## 14. Qualité — Lighthouse, WCAG 2.1 AA, CAMES

**14.1 Lighthouse — automatisé**
Fichier `lighthouserc.js` à la racine. Seuils : `performance: 0.90, accessibility: 0.90, seo: 0.90, best-practices: 0.90`. Routes testées en CI : `/`, `/pssfp/presentation`, `/formations/master`, `/formations/specialites/fiscalite-finance-comptabilite`, `/candidature/deposer-candidature`, `/actualites`, `/contact`, `/mentions-legales`. Échec = bloque la PR.

**14.2 Accessibilité — WCAG 2.1 AA**
axe-core intégré dans chaque test Playwright. 0 violation critique tolérée. Contraste vérifié sur chaque composant via Storybook + `@storybook/addon-a11y` (optionnel mais recommandé).

**14.3 CAMES — 12 exigences**
Skill `cames-check` exécuté avant chaque jalon de phase. Rapport archivé dans `docs/reports/`. Les 12 exigences sont mappées aux URL dans `docs/specs/cames-checklist.yaml`.

**14.4 SEO**
`sitemap.xml` généré dynamiquement (`next-sitemap` ou route `/app/sitemap.ts`). `robots.txt` autorisant tout sauf `/admin` et `/api/`. JSON-LD : `EducationalOrganization` sur l'accueil, `Course` sur fiches spécialités, `Article` sur actus. OpenGraph + Twitter Cards sur toutes les pages.

**14.5 Tests de charge**
k6 en Phase 7. Scénario : 100 utilisateurs concurrents sur /, /formations, /actualites pendant 5 minutes. Seuil : p95 < 2s, erreur < 0,1 %. Si échec, scaling VPS ou optimisation cache Redis.

---

## 15. Déploiement, CI/CD et bascule DNS

**15.1 Environnements**
- **Local** : Docker Compose (pgsql, redis, meili, mailpit).
- **Staging** : sous-domaine `staging.pssfp.net`, déploiement automatique sur push `develop`. Base seedée.
- **Production** : VPS Ubuntu 22 LTS, déploiement manuel depuis `main` après validation.

**15.2 GitHub Actions**
- `ci.yml` : lint (eslint, phpstan niveau 6), tests (pest + playwright), couverture, sur chaque PR.
- `lighthouse.yml` : Lighthouse sur la preview Vercel (ou preview Coolify) de la PR.
- `deploy-staging.yml` : SSH vers VPS staging, pull, build, migrate, restart PHP-FPM.
- `deploy-prod.yml` : manuel (workflow_dispatch), même flux + rollback automatique si `/health` échoue 3 fois.

**15.3 VPS — provisioning**
Script Ansible ou Dockerfile multi-stage. Composants : Nginx, PHP-FPM 8.3, PostgreSQL 16, Redis 7, Meilisearch 1.7, Node 20 (pour build), Certbot. Firewall UFW : ports 22, 80, 443 uniquement. Fail2ban activé. Backups : cron quotidien `pg_dump` + tarball storage vers S3 offsite, rétention 30 jours.

**15.4 Bascule DNS — Phase 8**
Check-list (skill `/dns-switch`) :
1. TTL DNS `pfinancespubliques.org` abaissé à 300s **48 h avant**.
2. Certificats Let's Encrypt provisionnés pour `pssfp.net`, `www.pssfp.net`, `bibliotheque.pssfp.net`, `api.pssfp.net`.
3. Test 301 `pfinancespubliques.org/*` → `pssfp.net/*` depuis nginx staging.
4. Fenêtre de maintenance communiquée par courrier interne (48 h avant).
5. Annonce Facebook et bannière sur ancien site (J-7).
6. Bascule : modifier A/AAAA records → nouveau VPS. Observer 30 min.
7. Vérifier 301 en prod depuis plusieurs FAI (MTN, Orange, Camtel).
8. Monitoring UptimeRobot actif sur toutes les URL.
9. Rapport de bascule dans `docs/reports/dns-switch-YYYY-MM-DD.md`.

**15.5 Acquisition domaines `.org` et `.cm`**
Lancer les démarches dès Phase 1. `.cm` géré par Antic — compter 4 à 6 semaines, prévoir justificatifs institutionnels. `.org` chez registrar international (Namecheap ou OVH) — immédiat. Redirections 301 vers `pssfp.net` dès provisioning.

---

## 16. Gouvernance projet, DoD et anti-patterns

**16.1 Rituels hebdomadaires USI**
- **Lundi 9h30** : stand-up 30 min — revue `docs/journal/`, tickets, blocages.
- **Mercredi 14h** : revue de PR groupée — merge des PR vertes, discussion des blocantes.
- **Vendredi 16h** : démo 1h + rétrospective courte — ce qui marche, ce qui bloque, ajustements.
- **Rapport hebdomadaire** écrit par Chef USI envoyé au Pr. BASAHAG chaque vendredi soir (format : avancement, risques, décisions attendues).

**16.2 Definition of Done (DoD) par tâche**
Une tâche est terminée si et seulement si :
1. Code sur une branche `feat/*` poussée, PR ouverte.
2. Tests Pest + Playwright pertinents ajoutés et verts.
3. Coverage locale ≥ 75 % (en globalité).
4. Lint 0 erreur, 0 warning nouveau.
5. Lighthouse ≥ 90 si page publique.
6. axe-core : 0 violation critique.
7. Spec `docs/specs/` mise à jour si changement d'interface.
8. ADR créé si décision structurelle.
9. Diff relu par un humain (M. ABE ETOUMOU ou désigné).
10. Sous-agent pertinent passé (cames / a11y / security).
11. PR mergée sur `develop`.

**16.3 Definition of Done par phase (jalon CDC)**
Phase considérée comme livrée si : tous les livrables CDC cochés, rapport de phase rédigé (`docs/reports/phase-N-YYYY-MM-DD.md`), démo enregistrée, validation écrite du Pr. BASAHAG obtenue.

**16.4 Anti-patterns à bannir (pièges Claude Code observés)**

- ❌ **« Fais le site entier »** — trop large, Claude va halluciner une structure. → découper par module, par page.
- ❌ **Accepter le premier plan sans le relire** — Claude surestime parfois. → questionner systématiquement.
- ❌ **Ignorer les tests** « pour aller vite » — dette mortelle en Phase 7. → tests dès la première ligne.
- ❌ **Commit géant de 50 fichiers** — impossible à relire. → commits petits et sémantiques.
- ❌ **Générer du contenu institutionnel** (bio d'un enseignant, texte du Pr. BASAHAG) avec Claude Code — risque de fausses informations. → n'utiliser Claude que pour la structure ; le contenu vient d'UPASS/UDCFC/UAAF.
- ❌ **Modifier `CLAUDE.md` sans revue** — c'est la mémoire longue du projet. → PR obligatoire.
- ❌ **Travailler sans `docs/journal/`** — la session suivante perd 20 minutes à reconstituer le contexte.
- ❌ **Mélanger Phase I et Phase II** dans le code — l'Espace Étudiant et la refonte candidature sont HORS périmètre 2026.

**16.5 Red flags opérationnels — qui escalader**
- Retard ≥ 5 jours sur un jalon → note écrite immédiate au Pr. BASAHAG.
- Faille sécurité découverte → patch sous 48 h, post-mortem dans `docs/security/`.
- Contenu non livré par une unité (UPASS/UDCFC/UAAF) à J-5 du besoin → escalade Comité de Pilotage, activation du plan B (contenu minimaliste).
- Lighthouse passe sous 85 → stop PR, investigation immédiate.
- Score CAMES `cames-check` < 12/12 → bloque la recette de phase.

**16.6 Ressources de formation équipe USI**
- Documentation officielle Claude Code : à lire au complet avant Phase 4.
- Laravel 11 + Filament 3 : cours Laracasts « Filament 3 Masterclass » pour le binôme dev.
- Next.js 14 App Router : parcours officiel Next.js + Vercel.
- Accessibilité web : WebAIM WCAG 2.1 cheatsheet imprimée et affichée dans le bureau USI.

---

## Annexe A — Check-list démarrage « prête dans 48 h »

| # | Élément | Responsable | Statut |
|---|---------|-------------|--------|
| 1 | Compte GitHub org PSSFP créé | Chef USI | ☐ |
| 2 | Dépôt `pssfp/pssfp` privé créé | Chef USI | ☐ |
| 3 | Claude Code installé sur postes dev | Chef USI | ☐ |
| 4 | Crédits Anthropic validés UAAF | Dr. MBA | ☐ |
| 5 | VPS staging provisionné | Hébergeur | ☐ |
| 6 | Domaines `pssfp.org` et `pssfp.cm` — démarches lancées | Chef USI | ☐ |
| 7 | Passerelle paiement (MoMo/Orange) — démarches lancées | UAAF | ☐ |
| 8 | Logo PSSFP SVG + PNG dans `assets/` | USI | ☐ |
| 9 | Logos partenaires collectés | UDCFC | ☐ |
| 10 | Photos institutionnelles — inventaire lancé | USI Communication | ☐ |
| 11 | CDC v5 signé Pr. BASAHAG | Comité de Pilotage | ☐ |
| 12 | Ce playbook relu et validé par Chef USI | Chef USI | ☐ |

---

## Annexe B — Commandes de démarrage (copier-coller)

```bash
# 1) Cloner + entrer
git clone git@github.com:pssfp/pssfp.git && cd pssfp

# 2) Installer
pnpm install --recursive
(cd backend && composer install && cp .env.example .env && php artisan key:generate)

# 3) Démarrer l'environnement
docker compose -f infra/docker/docker-compose.yml up -d

# 4) Migrer + seed
(cd backend && php artisan migrate --seed)

# 5) Lancer frontend + backend
pnpm --filter frontend dev    # terminal 1
pnpm --filter library dev     # terminal 2
(cd backend && php artisan serve)  # terminal 3

# 6) Lancer Claude Code
claude
```

---

## Annexe C — Glossaire Claude Code

- **CLAUDE.md** : fichier de contexte lu automatiquement à chaque session, hiérarchique (racine + dossier courant).
- **Slash command** : raccourci de prompt dans `.claude/commands/NOM.md`. Invocable par `/nom`.
- **Skill** : dossier `.claude/skills/NOM/SKILL.md` décrivant une procédure. Chargé à la demande.
- **Sous-agent (subagent)** : agent spécialisé défini dans `.claude/agents/NOM.md`. Invocable par nom, isolation de contexte.
- **Hook** : script exécuté sur des événements Claude Code (pre-tool, post-tool, etc.). Voir docs officielle.
- **MCP** : Model Context Protocol — serveurs externes qui exposent des outils à Claude Code.

---

*Fin du playbook. Version 1.0 — à ajuster au fil du projet. Toute modification de ce document fait l'objet d'une PR et d'une approbation du Chef de Projet.*
