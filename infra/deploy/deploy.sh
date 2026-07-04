#!/usr/bin/env bash
# =============================================================================
# Déploiement PSSFP v1 — appelé par `make deploy` (main uniquement, CI verte).
# Cible : VPS unique derrière Cloudflare (cf. infra/README.md).
# Idempotent : rejouable sans effet de bord si rien n'a changé.
# =============================================================================
set -euo pipefail

APP_DIR="${PSSFP_APP_DIR:-/var/www/pssfp}"
BRANCH="${PSSFP_DEPLOY_BRANCH:-main}"
PHP_BIN="${PHP_BIN:-/usr/bin/php}"

log() { printf '\n\033[1;35m[deploy]\033[0m %s\n' "$*"; }

cd "$APP_DIR"

log "Git : bascule sur ${BRANCH} et pull"
git fetch origin "$BRANCH"
git checkout "$BRANCH"
git pull --ff-only origin "$BRANCH"

# --- Backend Laravel ---------------------------------------------------------
log "Backend : dépendances composer (sans dev)"
cd "$APP_DIR/backend"
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader

log "Backend : migrations (--force) puis caches optimisés"
"$PHP_BIN" artisan migrate --force
"$PHP_BIN" artisan optimize          # config + routes + events + views

# --- Apps Next.js (v1 : frontend + candidature ; library hors périmètre) -----
log "Frontend : build production"
cd "$APP_DIR/frontend"
pnpm install --frozen-lockfile
pnpm build

log "Candidature : build production"
cd "$APP_DIR/candidature"
pnpm install --frozen-lockfile
pnpm build

# --- Rechargements sans downtime ----------------------------------------------
log "PM2 : reload des apps Next.js"
pm2 reload "$APP_DIR/infra/pm2/ecosystem.config.js" --update-env

log "Queue worker : restart (obligatoire — le code des jobs a pu changer)"
sudo systemctl restart pssfp-queue.service

log "Nginx : test de config puis reload"
sudo nginx -t
sudo systemctl reload nginx

log "Déploiement terminé ✔ — smoke test :"
echo "  curl -sSf https://api.pssfp.org/v1/health"
echo "  curl -sSf -o /dev/null -w '%{http_code}\n' https://pssfp.org"
echo "  curl -sSf -o /dev/null -w '%{http_code}\n' https://apply.pssfp.org"
