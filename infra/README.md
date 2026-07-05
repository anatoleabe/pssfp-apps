# infra/ — déploiement production v1 (LOT C)

> Domaine canonique : **pssfp.org** (Cloudflare actif depuis le 2026-07-04).
> Candidature : **apply.pssfp.org**. API + admin : **api.pssfp.org**.
> Architecture : 1 VPS + Cloudflare gratuit en frontal (plan §Hébergement).

## Contenu

| Fichier | Rôle | Destination VPS |
|---|---|---|
| `deploy/deploy.sh` | pipeline `make deploy` (pull → composer → migrate → builds → reloads) | exécuté depuis `/var/www/pssfp` |
| `pm2/ecosystem.config.js` | frontend :6001 + candidature :6003 (library commentée) | `pm2 start … && pm2 save && pm2 startup` |
| `nginx/pssfp.org.conf` | vhost site (proxy :6001, CSP, HSTS) | `/etc/nginx/sites-available/` + symlink |
| `nginx/apply.pssfp.org.conf` | vhost candidature (proxy :6003, no-store PII, CSP Turnstile) | idem |
| `nginx/api.pssfp.org.conf` | vhost API/Filament (PHP 8.3-FPM, 12 Mo upload) | idem |
| `nginx/media.pssfp.org.conf` | **CRITIQUE** : reverse-proxy public MinIO (Host préservé) — sans lui, les URLs présignées (récépissé, photo) pointent vers 127.0.0.1 et sont inaccessibles depuis un navigateur | idem |
| `nginx/redirects-legacy.conf` | 301 pssfp.net / pssfp.cm / pfinancespubliques.org → .org | idem (filet côté origine) |
| `nginx/cloudflare-real-ip.conf` | **CRITIQUE** : vraie IP client (sinon les throttles par IP frappent tout le monde) | `include` dans le bloc `http {}` |
| `systemd/pssfp-queue.service` | **CRITIQUE** : worker queue Redis (emails candidat) | `/etc/systemd/system/` |
| `systemd/minio.service` + `minio.env.example` | stockage privé S3 (photos, récépissés) | `/etc/systemd/system/` + `/etc/default/minio` (600) |

Meilisearch : **différé** — v1 tourne avec `SCOUT_DRIVER=database` (LOT A.5).

## C.5 — Provisionner le VPS (checklist)

```bash
# Base
apt update && apt upgrade -y
apt install -y nginx certbot python3-certbot-nginx ufw fail2ban git unzip

# PHP 8.3 + extensions requises
apt install -y php8.3-fpm php8.3-pgsql php8.3-redis php8.3-gd php8.3-intl \
               php8.3-bcmath php8.3-zip php8.3-curl php8.3-xml php8.3-mbstring
# Composer 2, Node 20 + pnpm + PM2, PostgreSQL 16, Redis 7, MinIO binaire

# Comptes : deploy (clé SSH, sudo restreint nginx/systemctl), minio (nologin)
# ufw : deny incoming, allow 22/80/443. PG/Redis/MinIO : 127.0.0.1 uniquement.
# fail2ban : sshd + nginx-limit-req (cf. docs/deployment-contabo.md §7-8)
mkdir -p /var/log/pssfp /var/log/pm2 && chown deploy:www-data /var/log/pssfp
```

## C.6 — DNS Cloudflare (zone pssfp.org, déjà active)

| Enregistrement | Type | Cible | Proxy |
|---|---|---|---|
| `pssfp.org` | A | IP VPS | 🟠 proxied |
| `www` | CNAME | pssfp.org | 🟠 proxied |
| `apply` | A | IP VPS | 🟠 proxied |
| `api` | A | IP VPS | 🟠 proxied |
| `media` | A | IP VPS | 🟠 proxied — **requis dès la v1** (reverse-proxy MinIO : sans lui, aucune URL présignée récépissé/photo n'est accessible) |

Côté Cloudflare : SSL/TLS **Full (strict)** · Always Use HTTPS · Turnstile
(créer le widget → `NEXT_PUBLIC_TURNSTILE_SITE_KEY` + `TURNSTILE_SECRET_KEY`).
Anciens domaines (`pssfp.net`…) : Redirect Rules 301 vers `https://pssfp.org/$1`
si leurs zones sont dans Cloudflare, sinon `nginx/redirects-legacy.conf` fait foi.

⚠️ Certbot derrière le proxy : émettre les certs via le challenge HTTP-01
(chemin `/.well-known/acme-challenge/` laissé passant dans chaque vhost) —
ou utiliser un certificat d'origine Cloudflare (15 ans) et désactiver certbot.

## C.7 — Premier déploiement (ordre exact)

1. `git clone` du repo dans `/var/www/pssfp` (branche `main`).
2. `.env` backend prod (mode 600) : `APP_KEY` (`php artisan key:generate`),
   `APP_ENV=production`, DB/REDIS/MINIO/MAIL/TURNSTILE/SMS,
   `SESSION_DOMAIN=.pssfp.org`, `SANCTUM_STATEFUL_DOMAINS=pssfp.org,apply.pssfp.org`,
   `CANDIDATURE_APP_URL=https://apply.pssfp.org`, `CAMPAGNE_P14_*` (dates direction),
   `FILAMENT_ADMIN_*`. Copier `frontend/.env.production.example` → `.env.production`
   (idem candidature) avec la vraie site key Turnstile.
3. Buckets MinIO : `pssfp-media` (public read), `pssfp-candidatures` (privé),
   `pssfp-documents` (privé) — via `mc` sur 127.0.0.1:9000.
4. Poser vhosts + `cloudflare-real-ip.conf` + units systemd → `nginx -t` →
   certbot → `systemctl enable --now pssfp-queue minio`.
5. `bash infra/deploy/deploy.sh` (première exécution = install + migrate + builds).
6. Seeds : `RolePermissionSeeder` → `AdminUserSeeder` → contenu site
   (`AProposPagesSeeder`, `FormationsPagesSeeder`, `VieAcademiquePagesSeeder`,
   `ArticlesSeeder`) → `ProdCampagneP14Seeder` (le tout `--force`).
7. Amorçage 2FA admin (cf. `docs/ops/go-live-candidature.md` §B.3).

## C.8 — Verify (avant annonce publique)

- Smoke : `curl https://api.pssfp.org/v1/health` · home + apply en 200.
- Parcours E2E candidature complet (go-live-candidature.md §B.7) — email reçu.
- `queues:default` qui redescend, `queue:failed` vide.
- Lighthouse ≥ 90 (site) / ≥ 85 (candidature) · securityheaders.com ≥ A.
- SPF + DKIM + DMARC sur pssfp.org (sinon emails candidature en spam).
- 1er backup `laravel-backup` lancé ET restauré en test.
