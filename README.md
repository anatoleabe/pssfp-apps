# PSSFP — Refonte du site institutionnel et de la bibliothèque virtuelle

Mono-repo du projet de refonte numérique du **Programme Supérieur de Spécialisation en Finances Publiques** (PSSFP), Cameroun. Phase I : avril-juillet 2026.

**Chef de Projet** : M. ABE ETOUMOU Anatole — Chef USI.
**Maître d'Ouvrage** : Pr. BASAHAG Achile Nestor — Président du Comité de Pilotage.

## Périmètre Phase I

- **Site institutionnel** sur `pssfp.net` — 8 rubriques, mobile-first, conforme CAMES.
- **Bibliothèque virtuelle refaite** sur `bibliotheque.pssfp.net` — catalogue structuré, recherche Meilisearch, accès différencié.
- **Module candidature** sur `candidature.pssfp.net` — réécriture complète remplaçant l'ancien Joomla.
- **CMS Filament 3** sur `api.pssfp.net/admin` — administration autonome par l'USI.

## Stack

- **Frontend** : Next.js 14 (App Router, TypeScript strict, Tailwind, shadcn/ui)
- **Backend** : Laravel 11 + Filament 3
- **BDD** : PostgreSQL 16 · **Recherche** : Meilisearch · **Cache** : Redis · **Stockage** : MinIO + Scaleway backup

## Démarrer en local

Pré-requis : Node.js 20 LTS, pnpm 9, PHP 8.3 + extensions Laravel, Composer 2, Docker Desktop, Git 2.40+.

```bash
# 1. Cloner et installer
git clone git@github.com:pssfp/pssfp.git
cd pssfp

# 2. Démarrer les services Docker (postgres, redis, meili, mailpit, minio)
make dev-services

# 3. Installer les dépendances et lancer les apps
make install
make dev
```

Les apps tournent ensuite sur :

- `http://localhost:3000` — frontend (site institutionnel)
- `http://localhost:3001` — library (bibliothèque)
- `http://localhost:3002` — candidature
- `http://localhost:8000` — backend Laravel API + Filament admin

Accès Filament admin : `http://localhost:8000/admin` (créer un user admin via `php artisan make:filament-user`).

## Structure du repo

```
pssfp/
├── CLAUDE.md                    # Mémoire projet pour Claude Code
├── README.md                    # Ce fichier
├── PLAYBOOK_Claude_Code_PSSFP.md
├── Makefile                     # Tâches de dev courantes
├── .claude/                     # Slash-commands et sous-agents
├── docs/
│   ├── adr/                     # Décisions architecturales (8 ADR)
│   ├── specs/                   # Specs par module
│   ├── data-model.md            # Schéma BDD complet
│   ├── api-contract.md          # Contrat REST
│   └── content-plan.md          # Plan éditorial
├── frontend/                    # Next.js — pssfp.net
├── library/                     # Next.js — bibliotheque.pssfp.net
├── candidature/                 # Next.js — candidature.pssfp.net
├── backend/                     # Laravel + Filament
├── packages/ui/                 # @pssfp/ui — design system partagé
├── infra/                       # Docker, Nginx, déploiement
└── .github/workflows/           # CI/CD
```

## Documentation

- **Décisions architecturales** : `docs/adr/`
- **Modèle de données** : `docs/data-model.md`
- **Contrat API REST** : `docs/api-contract.md`
- **Specs par module** : `docs/specs/`
- **Plan éditorial** : `docs/content-plan.md`
- **Méthodologie de dev** : `PLAYBOOK_Claude_Code_PSSFP.md`

## Critères de qualité

- Lighthouse ≥ 90 sur les 4 dimensions (≥ 85 biblio et candidature).
- Accessibilité WCAG 2.1 AA.
- Tests Pest + Playwright obligatoires.
- Conformité CAMES sur 12 exigences (cf. `docs/specs/module-1-site-institutionnel.md` §4).

## Licence

Propriétaire — PSSFP / République du Cameroun.

## Contact

Pour toute question technique : `anatoleabe@gmail.com`.
Pour les contenus institutionnels : `contact@pssfp.net`.
