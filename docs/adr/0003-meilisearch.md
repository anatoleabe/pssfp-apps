# ADR-0003 — Meilisearch plutôt qu'Elasticsearch ou Algolia

**Statut** : Accepté
**Date** : 2026-05-05
**Décideur** : M. ABE ETOUMOU Anatole — Chef USI
**Référence CDC** : CDC bibliothèque v2.0 §E.1.2 « Technologies recommandées » ; CDC v5 §A.4 « Fonctionnalités de recherche »

## Contexte

La bibliothèque virtuelle (Module 3) doit offrir une recherche multicritères performante avec exigence formelle de **résultats en moins de 500 ms** pour un catalogue de 500+ documents (CDC biblio v2.0 §K.2). Les fonctionnalités attendues incluent : recherche plein-texte sur titre/auteur/résumé/mots-clés, filtres combinables (type, promotion, spécialité, année, langue), tolérance aux fautes de frappe, auto-complétion, tri par pertinence/date/auteur, pagination 20 par page (CDC biblio v2.0 §E.1.1).

Le moteur de recherche public du site institutionnel (formations, actualités, pages) a des exigences plus modestes, mais bénéficierait d'être servi par la même infrastructure pour la cohérence opérationnelle.

Quatre solutions ont été examinées : Meilisearch auto-hébergé, Elasticsearch auto-hébergé, Algolia (SaaS), PostgreSQL FTS seul.

## Décision

**Meilisearch 1.7+** auto-hébergé sur le VPS Contabo, intégré à Laravel via **Laravel Scout** avec le driver Meilisearch officiel.

Configuration cible :

- Version : Meilisearch 1.7.x ou supérieure (binaire ~50 Mo).
- Indices : un par type de ressource recherchable (`documents`, `articles`, `pages`, `formations`, `enseignants`).
- Synchronisation : automatique via les observers Eloquent + Scout (ajout, modification, suppression).
- Réindexation full : commande artisan `scout:import` planifiée hebdomadairement (cron) en plus du temps réel.
- Sécurité : API key publique (search-only) exposée au frontend Next.js, master key strictement côté backend Laravel.
- Réseau : Meilisearch écoute sur `127.0.0.1:7700` uniquement, pas d'exposition publique. Les requêtes frontend passent par le proxy API Laravel `/v1/search` qui délègue à Meilisearch — permet rate limiting, logging, et masquage de l'API key.

## Conséquences

### Positives

Latence typique de Meilisearch sur notre volume cible : 5-50 ms par requête, très en-dessous des 500 ms exigés. La tolérance aux fautes de frappe est native (algorithme prefix + typo) — critique pour les utilisateurs mobiles tapant sur de petits claviers. Le binaire est léger (~50 Mo, ~200 Mo RAM en charge nominale), parfaitement compatible avec les 8 Go RAM du VPS Contabo. L'intégration Laravel Scout est mature et bien documentée. Pas de coût récurrent : Meilisearch est open source (MIT/Elastic License v2). L'auto-hébergement garantit la **souveraineté des données** — exigence implicite pour une institution publique camerounaise et explicitement mentionnée dans le CDC biblio v2.0 (préférence Matomo pour la même raison).

### Négatives ou trade-offs

Meilisearch est plus jeune qu'Elasticsearch (2018 vs 2010) — l'écosystème de plugins, intégrations tierces et littérature de débogage est moins étoffé. Les agrégations complexes (faceted search avancée, rollups statistiques) sont plus limitées qu'Elasticsearch — non bloquant pour notre besoin (filtres simples + tri + pagination). Pas de cluster multi-nœuds en édition open source — single-node uniquement, mais notre volume ne justifie pas un cluster. Surveillance et reprise après crash à automatiser via un service systemd avec restart automatique.

### Neutres ou à surveiller

Surveiller la croissance de l'index documents biblio : tant qu'on reste sous ~50 000 documents, le single-node Meilisearch est largement dimensionné. Au-delà (improbable Phase I/II), envisager Meilisearch Cloud ou un cluster Elasticsearch. Surveiller aussi la cohérence index/BDD : prévoir un job de réconciliation hebdomadaire qui compte documents en BDD vs documents indexés et alerte en cas d'écart > 1%.

## Alternatives envisagées

**Alternative A — Elasticsearch auto-hébergé.** Référence du marché, écosystème mature, agrégations puissantes. Rejetée : consommation RAM élevée (1 Go minimum, 4 Go conseillé) qui mangerait une part significative des 8 Go du VPS Contabo, complexité opérationnelle disproportionnée pour notre besoin (cluster, JVM tuning, snapshots S3), changement de licence en 2021 (SSPL) qui complique les usages commerciaux futurs.

**Alternative B — Algolia (SaaS).** Excellent produit, latence < 20 ms mondialement via leurs CDN. Rejetée : (1) **les données documentaires partent chez un fournisseur tiers américain**, problème de souveraineté pour une institution publique gérant potentiellement des contenus sensibles (cours restreints, ressources enseignants) ; (2) coût récurrent estimé à ~50 USD/mois sur notre volume avec leur formule Build, plus cher en prod ; (3) dépendance forte au service externe — toute panne Algolia tombe la biblio ; (4) ne s'aligne pas avec la logique d'auto-hébergement déjà retenue pour Matomo (CDC biblio v2.0 §I.1).

**Alternative C — PostgreSQL FTS + pg_trgm uniquement.** Simple, déjà disponible via ADR-0002, zéro infrastructure additionnelle. Rejetée comme moteur principal : la tolérance aux fautes de frappe est moins fluide qu'avec Meilisearch (pg_trgm trouve les similitudes mais ne classe pas par pertinence aussi bien), l'auto-complétion est laborieuse à implémenter, les performances sur 500+ docs avec full-text deviennent variables (200-2000 ms selon les requêtes). **Conservée comme fallback automatique** si Meilisearch est indisponible — à coder explicitement dans le service de recherche Laravel.

**Alternative D — Typesense.** Concurrent direct de Meilisearch, performances similaires, écrit en C++. Rejetée : écosystème Laravel moins mature que Scout+Meilisearch, pas de différentiel décisif pour justifier le risque de dépendre d'un outil moins intégré.

## Références

- CDC bibliothèque v2.0 §E.1 « Moteur de recherche avancée » et §K.2 « Critères techniques »
- CDC v5 §A.4 « Fonctionnalités de recherche » (annexe biblio)
- Meilisearch documentation (https://www.meilisearch.com/docs)
- Laravel Scout documentation (https://laravel.com/docs/11.x/scout)
- Comparaison Meilisearch vs Elasticsearch vs Algolia — billet Meilisearch 2024
