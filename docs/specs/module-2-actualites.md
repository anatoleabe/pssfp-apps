# Spec — Module 2 — Actualités et communication

> **Référence** : Sprint Specs A6
> **Statut** : v1.0 — proposé pour validation Chef USI
> **Date** : 2026-05-05
> **Source CDC** : §8.1 Module 2 du CDC v5

Ce document spécifie le périmètre fonctionnel et technique du Module 2 « Actualités et Communication ». Il couvre la gestion des articles, les galeries photos/vidéos, l'agenda des événements, et l'intégration du flux Facebook officiel. Il s'aligne sur le data-model.md (tables `articles`, `categories`, `tags`, `evenements`, `media`) et l'api-contract.md (endpoints `/v1/articles*`, `/v1/categories*`, `/v1/tags*`, `/v1/evenements*`).

## 1. Périmètre

Cinq sous-ensembles fonctionnels :

1. **Articles d'actualités** — gestion CRUD via Filament, publication par catégorie + tags, page liste + page détail côté public.
2. **Galerie photos et vidéos** — albums thématiques, lightbox, support YouTube embed.
3. **Agenda événements** — examens, soutenances, séminaires de FC, événements MEDIAFIP.
4. **Flux Facebook officiel** — widget intégré sur l'accueil et la page actualités.
5. **Workflow éditorial** — brouillon → relecture → publié, avec notifications email.

Ce module n'inclut pas : newsletter (Phase II potentielle), commentaires utilisateurs (jamais en V1, contraire au format institutionnel), forum (jamais en V1).

## 2. Modèle des articles

L'article a sept facettes éditoriales : catégorie, tags, image principale, accroche, corps, auteur, statut. Tout passe par Filament (cf. spec module 6 CMS).

**Catégories prédéfinies** au lancement (seedées via `CategorySeeder`) :

- `evenements` (slug = `evenements`, color = `#9B59B6`, icon = `calendar`)
- `formations` (color = `#6B2FA0`, icon = `graduation-cap`)
- `partenariats` (color = `#C9A227`, icon = `handshake`)
- `resultats` (color = `#2E7D32`, icon = `trophy`)
- `vie-academique` (color = `#5C6BC0`, icon = `book-open`)
- `communication` (color = `#FF7043`, icon = `megaphone`)

Une catégorie peut être désactivée en CMS sans suppression (cohabite avec ses articles archivés).

**Tags** sont libres et illimités. Ils sont créés à la volée par les éditeurs lors de la rédaction d'un article. Filament propose une recherche typeahead pour réutiliser les tags existants. Pas de hiérarchie de tags.

**Champ corps article** : éditeur Markdown (preferred) avec preview live, ou TinyMCE riche au choix éditorial. Markdown rendu en HTML serveur via `commonmark` Laravel, avec support des extensions GFM (tables, strikethrough, autolinks). Sanitisation via `mews/purifier` pour éliminer XSS dans le HTML rendu.

## 3. Liste articles

Route Next.js : `frontend/app/(public)/actualites/page.tsx`.

Endpoint API : `GET /v1/articles?page=...&category=...&tag=...&q=...&from=...&to=...`.

Stratégie de rendu : **ISR** (Incremental Static Regeneration) avec revalidation 60 secondes. Permet à un nouvel article publié dans Filament d'apparaître sur le site dans la minute suivante sans rebuild complet.

**Filtres UX** :

- Par catégorie — chips horizontales en haut, multi-sélection optionnelle.
- Recherche full-text — barre de recherche, déclenche `?q=...`.
- Période — sélecteur de plage de dates, déclenche `?from=...&to=...`.

**Tri** : par défaut `?sort=-published_at` (plus récents en premier). Les articles `is_pinned = true` apparaissent en tête, puis le reste chronologique.

**Pagination** : 20 par page, navigation précédent/suivant + numéros de page.

**Card article** : image principale (ratio 16:9), badge catégorie en couleur de la catégorie, titre H3, accroche 2 lignes max truncated, métadonnées discrètes (auteur, date, temps de lecture).

**État vide** : si filtres ne renvoient rien, message accompagné d'un lien « Voir tous les articles ».

**Critères d'acceptation** :

- Page chargée en < 2s sur connexion 3G simulée (DevTools).
- Lighthouse mobile ≥ 90 sur Performance, Accessibilité, SEO, Best Practices.
- Test Playwright : `tests/playwright/actualites-list.spec.ts` — vérifie chargement, filtre catégorie, pagination, recherche.

## 4. Détail article

Route Next.js : `frontend/app/(public)/actualites/[slug]/page.tsx`.

Endpoint API : `GET /v1/articles/{slug}`.

Stratégie de rendu : **SSG** avec `generateStaticParams` listant les articles publiés. Revalidation ISR à 5 minutes pour propager mises à jour.

**Structure de la page** :

1. Image hero pleine largeur (image principale ou fallback bannière catégorie).
2. Titre H1 + sous-titre catégorie/tag.
3. Métadonnées : auteur (avatar + nom), date publication, temps de lecture, vues, partage.
4. Corps : HTML rendu, typographie soignée (`prose` Tailwind), tables responsives, citations stylisées.
5. Tags en bas, cliquables.
6. Section « Articles liés » : 3 articles de la même catégorie.
7. CTA partage social (X, Facebook, LinkedIn, copier le lien).

**SEO** : balises Open Graph et Twitter Cards générées dynamiquement, JSON-LD `Article` schema, balises `article:published_time`, `article:author`, `article:section`, `article:tag`.

**Critères d'acceptation** :

- HTML rendu sans XSS injectable (test : injecter `<script>` dans le body en BDD via Filament, vérifier qu'il est neutralisé).
- Test Playwright `actualites-detail.spec.ts` : navigation depuis liste, vérifie titre, body, métadonnées, partage.

## 5. Galerie photos et vidéos

Le CDC §8.1 Module 2 mentionne « Galerie photos et vidéos avec albums thématiques (promotions, séminaires, partenariats), Lightbox, Support vidéo (YouTube embed ou upload direct) ». Décision V1 : **YouTube embed uniquement** pour les vidéos (pas d'upload direct, ce qui imposerait du transcoding et un player vidéo). Rationalisé pour V1.

Route Next.js : `frontend/app/(public)/actualites/galerie/page.tsx` et `frontend/app/(public)/actualites/galerie/[slug]/page.tsx`.

**Modèle de données** : pas de table dédiée — un album est une `Page` Filament avec `parent_slug = 'actualites/galerie'` et son corps est une grille de médias (champ JSON `gallery_items` à ajouter via repeater Filament). Évite la complexité d'une table galleries dédiée pour quelques albums par an.

Alternative à considérer si > 50 albums : créer `albums` + `album_items` en migration séparée.

**Lightbox** : `yet-another-react-lightbox` (lib React maintenue, ~10 KB) — déclenchement au clic sur miniature, navigation flèches clavier, fermeture Esc, support tactile.

**Vidéos** : iframe YouTube avec `loading="lazy"` et image preview cliquable pour réduire le poids initial.

## 6. Agenda événements

Route Next.js : `frontend/app/(public)/actualites/agenda/page.tsx` et `frontend/app/(public)/actualites/agenda/[slug]/page.tsx`.

Endpoint API : `GET /v1/evenements?from=...&to=...&type=...`.

**Vue par défaut** : liste chronologique sur 30 jours futurs, regroupée par mois. Une bascule permet d'aller en vue calendrier mensuel (FullCalendar React ou simple grille HTML).

**Carte par événement** : badge type (couleur), titre, date + heure, lieu, illustration optionnelle.

**Détail événement** : titre, description riche, dates, lieu (avec carte Google Maps embed si lat/lng renseignés), CTA « Ajouter à mon agenda » qui télécharge un fichier `.ics` standard.

**iCalendar export** : endpoint backend `GET /v1/evenements/{slug}/ics` (à ajouter en complément du contrat API si pas déjà présent — sinon dérivable directement côté Next.js).

## 7. Flux Facebook intégré

CDC §8.1 demande un widget Facebook @pssfpcameroun en page d'accueil et page actualités.

**Décision technique V1** : **widget officiel Facebook Page Plugin** (https://developers.facebook.com/docs/plugins/page-plugin/). Iframe officiel, gratuit, mais charge ~200 Ko de scripts Meta et pose des questions RGPD.

**Mitigation RGPD** : afficher d'abord un placeholder « Cliquez pour afficher le flux Facebook (cookies tiers Meta) », ne charger l'iframe qu'après consentement explicite (single click). Aligné sur la politique de confidentialité.

**Alternative considérée** : Facebook Graph API + cache 15 min serveur — supprime les cookies tiers mais demande une App Facebook avec token, tokens à renouveler tous les 60 jours, complexité opérationnelle. **Rejeté pour V1**, à réévaluer Phase II si traffic justifie.

**Composant Next.js** : `components/FacebookFeedWidget` client component avec lazy loading déclenché par interaction utilisateur.

## 8. Workflow éditorial

Cycle de vie d'un article :

1. **Brouillon (`draft`)** — création initiale, modifiable, invisible du public.
2. **En relecture (`in_review`)** — soumission par l'éditeur à un relecteur. Notification email au relecteur configuré dans Filament.
3. **Publié (`published`)** — basculement par le relecteur ou un admin. Si `published_at` est dans le futur, programmation différée (job Laravel scheduler à minuit). Notification email à l'auteur.
4. **Archivé (`archived`)** — invisible du public, conservé en BDD, restaurable.

**Permissions Filament** par rôle :

- `editor` peut créer, modifier ses propres brouillons, soumettre en relecture, dépublier ses articles.
- `admin` peut tout, y compris publier directement sans relecture.
- `auditor` et `teacher` n'ont aucun accès au CMS articles.

**Notifications email** : envoyées via SMTP `mail.creawebhosting.org` (cf. spec A10), templates dans `backend/resources/views/emails/articles/`.

**Activity Log** : chaque transition de statut tracée dans `activity_log`.

## 9. Programmation publication différée

Filament permet de saisir une date `published_at` dans le futur. Un job Laravel `scheduler` (commande `articles:publish-scheduled`) tourne **chaque minute** et bascule les articles `status = 'published'` AND `published_at <= now()` mais qui n'apparaissent pas encore (parce que créés avec `published_at` futur). À implémenter dans `app/Console/Commands/PublishScheduledArticles.php`.

Alternative simple si scheduler ne tourne pas : la requête publique filtre déjà par `published_at <= now()`, donc l'article apparaît automatiquement après la date sans intervention. **C'est la solution V1 retenue** — simple et robuste.

## 10. Recherche full-text

La recherche `?q=...` sur `/v1/articles` utilise dans l'ordre :

1. **Meilisearch** si disponible (index `articles`) — recherche typo-tolérante, classement par pertinence, < 50 ms.
2. **Fallback PostgreSQL FTS + pg_trgm** sur `title->>'fr'` et `excerpt->>'fr'` si Meilisearch est down — moins fluide mais sécurise la continuité.

L'index Meilisearch est synchronisé via Laravel Scout sur les events `created`, `updated`, `deleted` du modèle `Article`. Réindexation full hebdomadaire `php artisan scout:import "App\Models\Article"` planifiée le dimanche 4h.

## 11. Critères d'acceptation Module 2

- Toutes les sous-pages (`/actualites`, `/actualites/[slug]`, `/actualites/galerie`, `/actualites/galerie/[slug]`, `/actualites/agenda`, `/actualites/agenda/[slug]`) fonctionnelles avec contenus.
- Au moins 3 articles publiés au lancement (CDC §15.4).
- Au moins 1 album de galerie avec 10+ photos institutionnelles.
- Au moins 5 événements à venir dans l'agenda au lancement (calendrier académique 2026-2027).
- Flux Facebook chargé après consentement RGPD, ne casse pas le score Lighthouse.
- Lighthouse mobile ≥ 90 sur les 4 dimensions, sur les pages liste + détail article.
- Tests Playwright passent sur le parcours « visiteur lit une actualité » et « éditeur publie un article via Filament ».
- Aucune faille XSS exploitable via le body article rendu.

## Annexe — Liste des composants Next.js à coder

| Composant | Type | Rôle |
|---|---|---|
| `ArticleCard` | RSC | Carte article dans liste |
| `ArticleHero` | RSC | Image hero détail article |
| `ArticleBody` | RSC | Rendu HTML body avec typographie prose |
| `ArticleMeta` | RSC | Auteur, date, temps lecture |
| `ArticleShare` | Client | Boutons partage social + copier lien |
| `RelatedArticles` | RSC | Liste 3 articles de même catégorie |
| `CategoryFilter` | Client | Chips de filtres catégories |
| `DateRangeFilter` | Client | Sélecteur dates |
| `Pagination` | Client | Navigation pagination |
| `GalleryGrid` | Client | Grille miniatures + lightbox |
| `Lightbox` | Client | yet-another-react-lightbox |
| `EventCard` | RSC | Carte événement |
| `EventCalendar` | Client | Vue calendrier mensuel |
| `FacebookFeedWidget` | Client | Iframe FB Page Plugin avec consentement |
| `MarkdownRenderer` | RSC | Rendu Markdown sécurisé |
