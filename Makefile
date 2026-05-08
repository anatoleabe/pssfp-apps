# Makefile — pssfp mono-repo
# Usage : make <target>

.DEFAULT_GOAL := help
.PHONY: help install dev dev-services dev-stop frontend library candidature backend test lint build lighthouse cames clean deploy

help: ## Liste les commandes disponibles
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-25s\033[0m %s\n", $$1, $$2}'

# ===========================================================================
# Installation
# ===========================================================================

install: install-frontend install-library install-candidature install-backend install-ui ## Installe les dépendances de toutes les apps

install-frontend: ## Installe deps frontend
	cd frontend && pnpm install

install-library: ## Installe deps library
	cd library && pnpm install

install-candidature: ## Installe deps candidature
	cd candidature && pnpm install

install-backend: ## Installe deps backend Laravel
	cd backend && composer install

install-ui: ## Installe deps package ui partagé
	cd packages/ui && pnpm install

# ===========================================================================
# Développement
# ===========================================================================

dev-services: ## Démarre les services Docker (postgres, redis, meili, mailpit, minio)
	docker compose -f infra/docker/docker-compose.yml up -d
	@echo "Services lancés. Postgres:5432, Redis:6379, Meilisearch:7700, MinIO:9000, Mailpit:8025"

dev-stop: ## Arrête les services Docker
	docker compose -f infra/docker/docker-compose.yml down

dev: dev-services ## Démarre tout l'environnement de dev (services + 4 apps en parallèle)
	@echo "Démarre les 4 apps. Use Ctrl+C pour arrêter."
	@(cd backend && php artisan serve --host=0.0.0.0 --port=8000) & \
	(cd frontend && pnpm dev --port 3000) & \
	(cd library && pnpm dev --port 3001) & \
	(cd candidature && pnpm dev --port 3002) & \
	wait

# Démarrages individuels
frontend: ## Démarre uniquement frontend
	cd frontend && pnpm dev --port 3000

library: ## Démarre uniquement library
	cd library && pnpm dev --port 3001

candidature: ## Démarre uniquement candidature
	cd candidature && pnpm dev --port 3002

backend: ## Démarre uniquement backend Laravel
	cd backend && php artisan serve --port=8000

# ===========================================================================
# Tests et qualité
# ===========================================================================

test: test-backend test-frontend test-library test-candidature ## Lance tous les tests (Pest + Playwright)

test-backend: ## Tests Pest backend
	cd backend && php artisan test

test-frontend: ## Tests Playwright frontend
	cd frontend && pnpm test

test-library: ## Tests Playwright library
	cd library && pnpm test

test-candidature: ## Tests Playwright candidature
	cd candidature && pnpm test

lint: lint-frontend lint-library lint-candidature lint-backend ## Lance tous les linters (ESLint + Pint)

lint-frontend:
	cd frontend && pnpm lint

lint-library:
	cd library && pnpm lint

lint-candidature:
	cd candidature && pnpm lint

lint-backend:
	cd backend && ./vendor/bin/pint --test

lint-fix: ## Auto-fix des linters
	cd frontend && pnpm lint --fix
	cd library && pnpm lint --fix
	cd candidature && pnpm lint --fix
	cd backend && ./vendor/bin/pint

lighthouse: ## Audit Lighthouse sur les routes critiques
	@echo "Audit Lighthouse en cours..."
	cd frontend && pnpm lighthouse

cames: ## Vérifie la checklist CAMES (12 exigences) automatiquement
	@bash infra/scripts/cames-check.sh

# ===========================================================================
# Build et déploiement
# ===========================================================================

build: build-frontend build-library build-candidature build-backend ## Build de production

build-frontend:
	cd frontend && pnpm build

build-library:
	cd library && pnpm build

build-candidature:
	cd candidature && pnpm build

build-backend:
	cd backend && php artisan optimize

deploy: ## Déploiement VPS Contabo (main uniquement, CI verte requise)
	@echo "Déploiement vers VPS Contabo..."
	bash infra/deploy/deploy.sh

# ===========================================================================
# Migrations et seeders
# ===========================================================================

migrate: ## Lance les migrations Laravel
	cd backend && php artisan migrate

migrate-fresh: ## Drop + recrée la BDD avec seeders
	cd backend && php artisan migrate:fresh --seed

seed: ## Lance les seeders
	cd backend && php artisan db:seed

# ===========================================================================
# Utilitaires
# ===========================================================================

clean: ## Nettoie les caches et builds
	cd frontend && rm -rf .next
	cd library && rm -rf .next
	cd candidature && rm -rf .next
	cd backend && php artisan optimize:clear

logs: ## Tail des logs Laravel
	tail -f backend/storage/logs/laravel.log

shell-db: ## Ouvre un psql sur la BDD dev
	docker compose -f infra/docker/docker-compose.yml exec postgres psql -U pssfp_admin -d pssfp

shell-redis: ## Ouvre un redis-cli sur le Redis dev
	docker compose -f infra/docker/docker-compose.yml exec redis redis-cli
