# Architecture Decision Records (ADR)

> Registre des décisions architecturales prises pour le projet de refonte du site institutionnel pssfp.net et de la bibliothèque virtuelle.

## Pourquoi des ADR ?

Un ADR (Architecture Decision Record) capte une **décision technique structurante** au moment où elle est prise, avec son contexte, ses raisons et ses conséquences. C'est un document court, immuable une fois validé, qui permet à toute personne arrivant sur le projet (humain ou Claude Code) de comprendre **pourquoi** le code est ainsi et pas autrement — sans ressortir les emails de discussion.

Règle d'or : si une décision n'est pas dans un ADR, elle peut être renégociée à tout moment. Si elle est dans un ADR, elle est gelée jusqu'à ce qu'un ADR ultérieur la remplace explicitement.

## Index des ADR

| ID | Titre | Statut | Date |
|----|-------|--------|------|
| [0001](0001-stack-nextjs-laravel-decoupled.md) | Stack Next.js 14 + Laravel 11 découplée | Accepté | 2026-05-05 |
| [0002](0002-postgresql-16.md) | PostgreSQL 16 plutôt que MySQL ou MongoDB | Accepté | 2026-05-05 |
| [0003](0003-meilisearch.md) | Meilisearch plutôt qu'Elasticsearch ou Algolia | Accepté | 2026-05-05 |
| [0004](0004-filament-3-cms-unique.md) | Filament 3 comme CMS unique | Accepté | 2026-05-05 |
| [0005](0005-auth-sanctum-tokens-scoped.md) | Authentification Sanctum + tokens scoped | Accepté | 2026-05-05 |
| [0006](0006-i18n-fr-default.md) | i18n FR par défaut, structure prête EN/AR | Accepté | 2026-05-05 |
| [0007](0007-pin-6-chiffres-candidats.md) | PIN 6 chiffres pour les candidats — UX prioritaire | Accepté | 2026-05-05 |
| [0008](0008-evolution-charte-2026.md) | Évolution charte graphique 2026 — prune institutionnel, bleu pétrole, Cormorant Garamond | Accepté | 2026-05-14 |

## Workflow ADR

1. **Proposer** — copier `0000-template.md`, l'incrémenter (ex: `0007-...md`), remplir avec statut `Proposé`.
2. **Discuter** — relecture par M. ABE ETOUMOU (Chef USI) et, si décision stratégique, par le Comité de Pilotage.
3. **Accepter** — passer le statut à `Accepté` et ajouter à l'index ci-dessus. Commit avec message `docs(adr): ADR-NNNN — titre`.
4. **Remplacer** — pour annuler une ADR, créer une nouvelle ADR qui la remplace explicitement (`Remplace ADR-NNNN`) et passer l'ancienne en statut `Remplacé par ADR-MMMM`.

## Statuts possibles

- **Proposé** — en discussion, modifiable
- **Accepté** — décision actée, gelée
- **Remplacé par ADR-NNNN** — historique conservé mais plus en vigueur
- **Déprécié** — n'a plus cours sans avoir été remplacé (rare)

## Référence

Format inspiré de Michael Nygard, *Documenting Architecture Decisions*, 2011.
