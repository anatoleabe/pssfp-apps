# ADR-0006 — i18n FR par défaut, structure prête EN/AR

**Statut** : Accepté
**Date** : 2026-05-05
**Décideur** : M. ABE ETOUMOU Anatole — Chef USI
**Référence CDC** : CDC-PSSFP-WEB-2026-005 v5.0 §3.3 « Objectifs spécifiques » (rayonnement régional CEMAC)

## Contexte

Le PSSFP rayonne au-delà du Cameroun francophone : la convention ISFP de mai 2024 confère un mandat élargi à l'**espace CEMAC** (Cameroun, Gabon, République centrafricaine, Tchad, République du Congo, Guinée équatoriale) et aux administrations publiques africaines francophones. Trois langues sont potentiellement pertinentes :

1. **Français** — langue de travail principale de l'institution, langue source de tous les contenus actuels.
2. **Anglais** — provinces anglophones du Cameroun, indexation académique internationale (Google Scholar, CAMES anglophone), partenariats internationaux (FMI, edX, Institut Maroc partiellement anglophone).
3. **Arabe** — partenaires CAMES Maghreb (notamment l'Institut des Finances Basil Fuleihan au Maroc, qui travaille en arabe et en français).

Le CDC v5 ne spécifie pas de calendrier i18n — il évoque le « rayonnement régional CEMAC » comme objectif (§3.3) sans imposer de bilinguisme contractuel. Les partenaires anglophones et arabophones consultent aujourd'hui le site français existant.

Le risque architectural est de figer le frontend et le modèle de données en mono-langue, puis devoir tout refactorer en Phase II ou III pour ajouter EN/AR — opération coûteuse qui touche routes, composants, BDD, SEO, sitemaps.

## Décision

**Français comme langue source unique au lancement V1**, mais **architecture prête pour EN et AR** dès le bootstrap. Aucun contenu EN ou AR n'est produit ni publié en Phase I — la structure technique est en place pour activer ces langues sans migration ultérieure.

### Côté frontend (Next.js)

- **Bibliothèque i18n** : `next-intl` (https://next-intl-docs.vercel.app), choix le plus mature pour Next.js App Router.
- **Routing** : structure `app/[locale]/...` activée dès le bootstrap. Locale `fr` par défaut, **sans préfixe d'URL** au lancement (`pssfp.net/formations` reste tel quel) via `localePrefix: 'as-needed'`. Les locales `en` et `ar` sont déclarées dans la config mais désactivées (404) tant que la traduction n'est pas livrée.
- **Fichiers de traduction** : `messages/fr.json`, `messages/en.json`, `messages/ar.json`, organisés en namespaces par rubrique (`home`, `formations`, `bibliotheque`, `candidature`, etc.). Tous les composants utilisent `useTranslations()` même au lancement — le développeur n'écrit jamais de texte français en dur dans le JSX.
- **RTL ready** : config Tailwind avec plugin `tailwindcss-rtl`, attribut `dir="rtl"` calculé depuis la locale sur `<html>` côté layout. Permet l'arabe sans refonte CSS.
- **Polices** : Playfair Display + Inter + DM Sans (latin) pour FR/EN ; substitution prévue pour AR via `IBM Plex Sans Arabic` ou `Noto Sans Arabic` (chargé conditionnellement).

### Côté backend (Laravel + PostgreSQL)

- **Stratégie translatable** : champs traduisibles stockés en colonnes JSONB par défaut (PostgreSQL JSONB indexé), avec helper Eloquent `spatie/laravel-translatable`. Exemple : `pages.title = {"fr": "Présentation", "en": null, "ar": null}`. Permet de stocker des traductions partielles sans migration.
- **Champs traduisibles identifiés** : `pages.title`, `pages.body`, `articles.title`, `articles.excerpt`, `articles.body`, `formations.nom`, `formations.description`, `specialites.nom`, `specialites.description`, `documents.title`, `documents.abstract`, `partenaires.description`. Tous les autres champs (slugs, dates, prix, références) restent mono-langue.
- **Slugs** : un slug différent par locale (`pages.slug = {"fr": "presentation", "en": "about"}`) géré par `spatie/laravel-translatable` ou tables séparées au choix du Module 1 — à trancher dans `docs/data-model.md`.
- **API REST** : header `Accept-Language: fr|en|ar` détermine la locale renvoyée. Le fallback est toujours `fr`. Réponse inclut `available_locales` pour permettre au frontend d'afficher un sélecteur de langue.
- **Filament admin** : interfaces de traduction prévues dans les Resources concernées via le plugin `filament-translatable-fields` ou équivalent — UI en français pour l'éditeur, mais avec onglets de saisie EN/AR par champ.

### Politique d'activation

Une langue n'est rendue publique que lorsqu'**au moins 95% des contenus prioritaires** (page d'accueil, présentation PSSFP, 5 fiches spécialités, formation continue, contact, mentions légales) sont traduits et relus. Évite les sites « moitié français, moitié anglais » qui dégradent l'image institutionnelle.

## Conséquences

### Positives

L'ajout futur de l'anglais ou de l'arabe ne demandera **aucune migration** — uniquement la production des contenus traduits et l'activation de la locale dans la config. SEO multilingue préparé (sitemaps `hreflang`, balises `<link rel="alternate">` à mettre dans le layout dès le départ même avec une seule locale active). Les développeurs et l'USI prennent dès le départ l'habitude de travailler avec `t('home.title')` plutôt qu'avec du texte en dur — discipline qui évite les régressions. La structure JSONB côté BDD permet de stocker des traductions partielles sans casser l'existant. La compatibilité RTL préparée évite la principale difficulté de l'arabe a posteriori.

### Négatives ou trade-offs

Léger surcoût de développement pour chaque composant : passage par `useTranslations()` même quand on n'a qu'une langue. Coût marginal estimé à 5-10% du temps de dev frontend Phase I. Les contenus en BDD prennent un peu plus d'espace (JSONB vs TEXT simple) — négligeable sur notre volume. La discipline « ne jamais écrire de texte en dur » demande de la rigueur lors des relectures de PR — à inscrire dans le sous-agent `cames-reviewer` ou un nouveau `i18n-reviewer`.

### Neutres ou à surveiller

Décision à reprendre lors de la planification Phase II : faut-il commencer la traduction EN dès le lancement V1 ou attendre la stabilisation des contenus FR ? Tendance recommandée : attendre 3-6 mois post-lancement pour éviter de traduire des contenus qui changent encore. Surveiller les retours utilisateurs anglophones (analytics Matomo) — si la part de trafic anglophone est notable, accélérer la traduction.

## Alternatives envisagées

**Alternative A — Mono-langue français strict, migration future.** Rejetée : cf. contexte, refacto en Phase II ou III estimée à 3-4 semaines de dev (routes, composants, modèles, slugs, sitemaps). Coût immédiat de la préparation : 5-10% en Phase I. Économie nette évidente.

**Alternative B — Multi-domaines (pssfp.fr / pssfp.com / pssfp.ar).** Rejetée : multiplication des certificats SSL, dilution du SEO et du « link juice » entre domaines, complexité opérationnelle accrue, hostile à la stratégie de domaine unique pssfp.net actée par le CDC.

**Alternative C — i18n par sous-domaine (en.pssfp.net, ar.pssfp.net).** Rejetée pour des raisons SEO similaires et car cela duplique l'infrastructure frontend Next.js.

**Alternative D — Tables séparées par locale (`pages_fr`, `pages_en`).** Rejetée : prolifération de tables, jointures complexes, difficile à maintenir avec Eloquent et Filament.

**Alternative E — Service de traduction automatique en temps réel (Google Translate, DeepL widget).** Rejetée comme stratégie principale : qualité insuffisante pour un contenu institutionnel et académique, terminologie financière et juridique mal traduite, mauvaise image institutionnelle, dépendance à un service externe. Peut éventuellement être proposée comme aide ponctuelle sur des pages secondaires en complément des traductions humaines validées.

## Références

- CDC v5 §3.3 « Objectifs spécifiques » (mention rayonnement CEMAC)
- next-intl documentation (https://next-intl-docs.vercel.app)
- Spatie Laravel Translatable (https://github.com/spatie/laravel-translatable)
- W3C Internationalization recommandations
- Web.dev i18n best practices
