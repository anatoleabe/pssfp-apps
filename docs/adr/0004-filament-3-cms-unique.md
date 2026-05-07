# ADR-0004 — Filament 3 comme CMS unique

**Statut** : Accepté
**Date** : 2026-05-05
**Décideur** : M. ABE ETOUMOU Anatole — Chef USI
**Référence CDC** : CDC-PSSFP-WEB-2026-005 v5.0 §8.1 Module 6 « CMS Administration » et §15.2 « Critères fonctionnels » ; CDC bibliothèque v2.0 §E.3 « Gestion du catalogue »

## Contexte

Le CDC v5 fait de l'autonomie d'administration par l'équipe USI un critère contractuel de réception (§15.2) : « gestion autonome de l'ensemble des types de contenus par l'USI sans intervention développeur ». Le périmètre fonctionnel CMS couvre sept domaines distincts qui doivent partager une seule interface cohérente :

1. **Pages éditoriales** — pages institutionnelles, mentions légales, conformité CAMES.
2. **Actualités et galeries** — articles, catégories, tags, médiathèque, événements.
3. **Catalogue formations** — Master, tronc commun, 5 spécialités, unités d'enseignement, formation continue (10 modules), certifications.
4. **Vie académique** — promotions (13), corps enseignant, calendrier, partenariats.
5. **Bibliothèque virtuelle** — documents, métadonnées, types, niveaux d'accès, tableau de bord analytique.
6. **Candidatures** (rapatrié Phase I) — workflow déposé→examen→accepté/refusé, pièces jointes, commentaires internes.
7. **Utilisateurs et rôles** — public, auditeur, enseignant, bibliothécaire, admin, comité d'admission.

L'équipe USI cible n'a pas de profil développeur dédié — un Chef USI (M. ABE ETOUMOU) et un éventuel binôme. L'outil CMS doit être productif sans formation lourde, robuste, et maintenable sur 5+ ans.

## Décision

**Laravel Filament 3** (https://filamentphp.com) est retenu comme CMS et back-office unique. Il sert l'ensemble des Resources métier sur `api.pssfp.net/admin`.

Configuration cible :

- Version : Filament 3.x dernière mineure stable au moment du bootstrap (track les releases trimestrielles).
- Authentification : email + mot de passe avec **2FA TOTP obligatoire pour les rôles admin et editor** (cf. CDC biblio v2.0 §I.2). Sessions Filament natives (pas Sanctum côté admin — Sanctum réservé à l'API publique, cf. ADR-0005).
- Permissions : **Spatie Laravel Permission** comme couche RBAC, intégrée à Filament via le panneau `Filament Shield` ou équivalent. Six rôles : `admin`, `editor`, `librarian`, `admission_committee`, `teacher`, `auditor`.
- Auditabilité : **Spatie Activity Log** activé sur toutes les Resources stratégiques (pages, articles, documents, candidatures, utilisateurs) — chaque création/modification/suppression tracée avec auteur et timestamp.
- Médiathèque : **Spatie Media Library** + plugin Filament officiel pour gestion centralisée des images, conversions automatiques (thumb, medium, large en WebP).
- Workflow éditorial : champ `status` (draft, in_review, published, archived) sur Articles et Documents, avec actions Filament « Soumettre à validation » → « Publier » → « Archiver ».

Toutes les Resources Filament suivent le pattern documenté dans `frontend/CLAUDE.md` et `backend/CLAUDE.md` (voir Playbook §6).

## Conséquences

### Positives

Filament 3 fournit gratuitement ce qui représenterait 6 à 9 mois de développement custom : générateurs de Resources avec formulaires, tables, filtres, actions de masse, relations BelongsToMany, polymorphic, repeaters, médiathèque, dashboard widgets, navigation générée. La configuration code-first (PHP class par Resource) est versionnée dans Git — aucune dérive entre environnements, aucun « clic admin invisible ». L'écosystème Spatie (Permissions, Activity Log, Media Library, Backup) s'intègre nativement et couvre 95% des besoins de gouvernance d'un CMS d'institution publique. La courbe d'apprentissage pour l'USI est douce : Filament génère ses interfaces depuis des classes PHP simples — un binôme USI peut ajouter une nouvelle Resource (ex: nouvelle catégorie de partenaire) en 1-2 heures après la formation.

L'auditabilité (Activity Log) répond directement aux exigences CAMES de traçabilité des publications (CDC v5 §15.3). Le 2FA admin couvre l'exigence sécurité §9.4.

### Négatives ou trade-offs

Filament couple le projet à Laravel — on ne peut plus changer de backend sans refaire le CMS. Acceptable puisque ADR-0001 acte Laravel pour la décennie. La courbe d'apprentissage Filament-spécifique (patterns, RelationManagers, conventions) demande 2-3 jours de pratique pour un dev Laravel — coût de formation initial. Quelques fonctionnalités très spécifiques (workflow Kanban, éditeur WYSIWYG très avancé type Notion) ne sont pas natives et demandent des plugins communautaires de qualité variable. Filament reste plus orienté « back-office structuré » que « édition libre type Webflow » — adapté à notre cas mais à expliquer aux contributeurs UPASS/UDCFC s'ils espèrent un éditeur visuel.

### Neutres ou à surveiller

Suivre les releases majeures Filament (Filament 4 attendu fin 2026) : prévoir un jour de migration majeure environ tous les 18 mois. Surveiller la performance des tables avec relations multiples — au-delà de quelques milliers d'enregistrements par table, activer les indexes spécifiques et utiliser les filtres serveur Filament plutôt que client.

## Alternatives envisagées

**Alternative A — Strapi (CMS headless Node.js).** Très bon CMS open source, riche en fonctionnalités. Rejetée : ajoute un troisième stack (Node/Strapi en plus de Laravel et Next.js), gestion des permissions et workflows complexes moins fluide que Filament+Spatie, intégration moins naturelle avec Laravel pour les modèles métier riches (candidatures avec pièces jointes, transactions paiement Phase II).

**Alternative B — Sanity / Contentful (SaaS).** Modernes, expérience éditeur excellente. Rejetées : (1) données institutionnelles partent chez un tiers (souveraineté), (2) coût récurrent (Contentful Pro ~300 $/mois à terme), (3) modélisation des candidatures avec workflow validation difficile.

**Alternative C — Admin custom React + Laravel API.** Liberté totale d'UX. Rejetée : coût de développement prohibitif, maintenance lourde, on réinvente Filament en plus mauvais.

**Alternative D — Laravel Nova.** Concurrent direct de Filament, signé Laravel. Rejetée : licence payante (199 $ par dev/an), communauté plus petite, ergonomie jugée moins moderne, fonctionnalités natives moins riches que Filament 3 sur les Resources avec relations.

**Alternative E — WordPress (CMS classique ou headless).** Connu, plug-and-play. Rejetée : sécurité fragile (l'écosystème de plugins tiers est un vecteur d'attaque historique), gestion des permissions et workflows métier limitée, ne s'aligne pas avec ADR-0001 (Laravel + Next.js).

## Références

- CDC v5 §8.1 Module 6 « CMS Administration » et §15.2 « Critères fonctionnels »
- CDC bibliothèque v2.0 §E.3 « Gestion du catalogue (CMS) » et §I.2 « Sécurité »
- Playbook §6.1 et §13 « Modèle de données & Resources Filament »
- Filament 3 documentation (https://filamentphp.com/docs/3.x)
- Spatie Laravel Permission (https://spatie.be/docs/laravel-permission)
- Spatie Activity Log (https://spatie.be/docs/laravel-activitylog)
