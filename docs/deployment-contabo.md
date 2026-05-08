# Déploiement — VPS Contabo

> **Référence** : Sprint Bootstrap B7
> **Statut** : v1.0 — proposé pour validation après audit B8
> **Date** : 2026-05-05

Ce document décrit la configuration du VPS Contabo qui héberge la production de pssfp.net. Il sera affiné après l'audit SSH du VPS existant (tâche B8). Les valeurs spécifiques (IP, ressources réelles) seront remplies dans la version validée.

## 1. Caractéristiques cibles du VPS

Selon le CDC v5 §9.1 : Ubuntu 22 LTS, 4 vCPU, 8 Go RAM, 100 Go SSD. À confirmer par audit B8.

Si le VPS est partagé avec d'autres services PSSFP, prévoir cohabitation propre — pas d'écrasement de configurations existantes.

## 2. Stack à installer

```
Ubuntu 22.04 LTS
├── Nginx 1.18+ (reverse proxy + serveur statique Next.js builds)
├── PHP 8.3-FPM avec extensions : pgsql, redis, gd, intl, bcmath, zip, mbstring, xml, curl
├── Composer 2.x
├── Node.js 20 LTS (pour build des apps Next.js si build sur serveur)
├── PostgreSQL 16
├── Redis 7
├── Meilisearch 1.7 (binaire systemd)
├── MinIO (binaire systemd, ports 9000/9001 internes)
├── ClamAV daemon (scan uploads)
├── Certbot (Let's Encrypt)
├── ufw (firewall)
├── fail2ban
└── unattended-upgrades
```

## 3. Topologie réseau et domaines

| Domaine | Type | Cible | TLS |
|---|---|---|---|
| `pssfp.net` | A | IP VPS | Let's Encrypt |
| `www.pssfp.net` | CNAME | pssfp.net (redirection 301 → pssfp.net) | Let's Encrypt |
| `bibliotheque.pssfp.net` | A | IP VPS | Let's Encrypt |
| `candidature.pssfp.net` | A | IP VPS | Let's Encrypt |
| `api.pssfp.net` | A | IP VPS | Let's Encrypt |
| `status.pssfp.net` | A | IP VPS (Uptime Kuma) | Let's Encrypt |
| `foad.pssfp.net` | inchangé | Moodle existant | inchangé |
| `mail.pssfp.net` | inchangé | creawebhosting | inchangé |
| `pssfp.org`, `pssfp.cm` | A | IP VPS — redirection 301 vers pssfp.net | Let's Encrypt |
| `www.pfinancespubliques.org` | redirection 301 → pssfp.net |  | conserver TLS existant |

Les redirections 301 sont configurées au niveau Nginx (cf. §6).

## 4. Configuration Nginx — vhosts

Un fichier de config par domaine dans `/etc/nginx/sites-available/`, lien symbolique dans `sites-enabled/`.

### `pssfp.net.conf`

```nginx
# Redirection HTTP → HTTPS
server {
    listen 80;
    listen [::]:80;
    server_name pssfp.net www.pssfp.net;
    location /.well-known/acme-challenge/ {
        root /var/www/html;
    }
    location / {
        return 301 https://pssfp.net$request_uri;
    }
}

# Redirection www → apex
server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name www.pssfp.net;
    ssl_certificate /etc/letsencrypt/live/pssfp.net/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/pssfp.net/privkey.pem;
    return 301 https://pssfp.net$request_uri;
}

# Vhost principal — Next.js frontend
server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name pssfp.net;

    ssl_certificate /etc/letsencrypt/live/pssfp.net/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/pssfp.net/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;

    # Headers de sécurité
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains; preload" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-Frame-Options "DENY" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
    # CSP à durcir progressivement
    add_header Content-Security-Policy "default-src 'self'; img-src 'self' data: https://media.pssfp.net; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline' fonts.googleapis.com; font-src 'self' fonts.gstatic.com" always;

    # Logs
    access_log /var/log/nginx/pssfp.net.access.log;
    error_log /var/log/nginx/pssfp.net.error.log;

    # Proxy vers Next.js frontend (port 6001 local)
    location / {
        proxy_pass http://127.0.0.1:6001;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection 'upgrade';
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_cache_bypass $http_upgrade;
    }

    # Cache statique Next.js
    location /_next/static {
        proxy_pass http://127.0.0.1:6001;
        proxy_cache_valid 200 1y;
        add_header Cache-Control "public, max-age=31536000, immutable";
    }
}
```

Vhosts similaires pour `bibliotheque.pssfp.net` (port 6002), `candidature.pssfp.net` (port 6003), `api.pssfp.net` (PHP-FPM via fastcgi). Variations principales sur :

- Origines CORS autorisées dans api.pssfp.net.
- Path racine vers Laravel `public/` dans api.pssfp.net.
- CSP adaptée.

### Vhost api.pssfp.net (PHP-FPM Laravel)

```nginx
server {
    listen 443 ssl http2;
    server_name api.pssfp.net;
    root /var/www/pssfp/backend/public;

    ssl_certificate /etc/letsencrypt/live/api.pssfp.net/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/api.pssfp.net/privkey.pem;

    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
    add_header X-Content-Type-Options "nosniff" always;

    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

## 5. Services systemd

### Meilisearch

`/etc/systemd/system/meilisearch.service` :

```ini
[Unit]
Description=Meilisearch
After=network.target

[Service]
Type=simple
User=meilisearch
Group=meilisearch
WorkingDirectory=/var/lib/meilisearch
ExecStart=/usr/local/bin/meilisearch --master-key=__a_definir__ --env=production --http-addr=127.0.0.1:7700
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

### MinIO

`/etc/systemd/system/minio.service` :

```ini
[Unit]
Description=MinIO Object Storage
After=network.target

[Service]
Type=simple
User=minio
Group=minio
EnvironmentFile=/etc/default/minio
ExecStart=/usr/local/bin/minio server $MINIO_VOLUMES --console-address ":9001"
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

`/etc/default/minio` (mode 600, root only) :

```
MINIO_ROOT_USER=__a_definir__
MINIO_ROOT_PASSWORD=__a_definir__
MINIO_VOLUMES=/srv/minio/data
```

### Apps Next.js (PM2 ou systemd)

Décision : **PM2** plus simple pour gérer 3 apps Node, avec reload sans downtime.

```bash
pm2 start ecosystem.config.js
pm2 save
pm2 startup
```

`ecosystem.config.js` :

```javascript
module.exports = {
  apps: [
    { name: 'pssfp-frontend', cwd: '/var/www/pssfp/frontend', script: 'pnpm', args: 'start', env: { PORT: 6001, NODE_ENV: 'production' } },
    { name: 'pssfp-library', cwd: '/var/www/pssfp/library', script: 'pnpm', args: 'start', env: { PORT: 6002, NODE_ENV: 'production' } },
    { name: 'pssfp-candidature', cwd: '/var/www/pssfp/candidature', script: 'pnpm', args: 'start', env: { PORT: 6003, NODE_ENV: 'production' } },
  ],
};
```

### Workers Laravel (queue Redis)

`/etc/systemd/system/pssfp-queue.service` :

```ini
[Unit]
Description=PSSFP Laravel Queue Worker
After=redis.service

[Service]
Type=simple
User=deploy
Group=www-data
WorkingDirectory=/var/www/pssfp/backend
ExecStart=/usr/bin/php artisan queue:work --queue=high,default,low --sleep=3 --tries=3 --max-time=3600
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

## 6. Redirections 301 ancien Joomla

Vhosts dédiés pour `pfinancespubliques.org` et `candidature.pfinancespubliques.org` :

```nginx
server {
    listen 80;
    listen 443 ssl http2;
    server_name pfinancespubliques.org www.pfinancespubliques.org;
    # ssl certs si conservés
    return 301 https://pssfp.net$request_uri;
}

server {
    listen 80;
    listen 443 ssl http2;
    server_name candidature.pfinancespubliques.org;
    return 301 https://candidature.pssfp.net$request_uri;
}
```

## 7. Firewall ufw

```bash
ufw default deny incoming
ufw default allow outgoing
ufw allow 22/tcp     # SSH (à passer sur 2222 après audit)
ufw allow 80/tcp     # HTTP (Let's Encrypt)
ufw allow 443/tcp    # HTTPS
ufw enable
```

PostgreSQL (5432), Redis (6379), Meilisearch (7700), MinIO (9000/9001) : **non exposés à l'extérieur**, accès uniquement via 127.0.0.1.

## 8. fail2ban

`/etc/fail2ban/jail.local` :

```ini
[sshd]
enabled = true
maxretry = 5
bantime = 3600
findtime = 600

[nginx-http-auth]
enabled = true

[nginx-limit-req]
enabled = true
maxretry = 10
bantime = 600
```

## 9. Utilisateur deploy

```bash
useradd -m -s /bin/bash deploy
usermod -aG www-data deploy
mkdir -p /home/deploy/.ssh
# Ajouter clé publique GitHub Actions dans authorized_keys
chown -R deploy:deploy /home/deploy/.ssh
chmod 700 /home/deploy/.ssh
chmod 600 /home/deploy/.ssh/authorized_keys
```

L'utilisateur deploy peut utiliser sudo uniquement pour les commandes de déploiement (`sudo systemctl reload nginx`, `sudo systemctl restart pssfp-queue`, etc.) — fichier sudoers dédié `/etc/sudoers.d/deploy`.

## 10. Pipeline de déploiement

`infra/deploy/deploy.sh` :

```bash
#!/bin/bash
set -e

REPO_DIR=/var/www/pssfp
cd "$REPO_DIR"

# Pull latest
git fetch origin main
git reset --hard origin/main

# Backend
cd backend
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize

# Frontends
for app in frontend library candidature; do
  cd "$REPO_DIR/$app"
  pnpm install --frozen-lockfile --prod=false
  pnpm build
done

# Restart services
sudo systemctl restart php8.3-fpm
sudo systemctl restart pssfp-queue
pm2 reload all
sudo systemctl reload nginx

echo "Déploiement OK"
```

CI GitHub Actions push sur main → SSH sur VPS → `bash /var/www/pssfp/infra/deploy/deploy.sh`.

## 11. Sauvegarde

- **Spatie Laravel Backup** quotidien à 3h vers MinIO local + Scaleway externe (cf. spec A10).
- Test de restauration semestriel obligatoire — runbook dans `docs/runbooks/disaster-recovery.md` (à créer).

## 12. Monitoring

- **Uptime Kuma** sur `status.pssfp.net` (cf. spec A10 §3).
- **Sentry** côté Laravel + Next.js × 3 apps.
- **Logs** : Nginx + PHP-FPM + Laravel rotation logrotate quotidienne, conservation 14j.

## 13. Hardening checklist

- [ ] SSH par clé uniquement, pas de mot de passe.
- [ ] Port SSH passé sur 2222.
- [ ] Utilisateur deploy non-root, sudo limité.
- [ ] ufw activé avec ports limités.
- [ ] fail2ban activé.
- [ ] unattended-upgrades activé.
- [ ] ClamAV installé et utilisé via job Laravel.
- [ ] Headers HTTP de sécurité validés (testssl.sh, securityheaders.com).
- [ ] Score testssl.sh ≥ A.
- [ ] Score securityheaders.com ≥ A.
- [ ] Mots de passe BDD/Redis/MinIO/Meilisearch rotatés (jamais ceux du dev).
- [ ] 2FA admin Filament technique activé.
- [ ] Premier backup vers Scaleway testé en restauration.

## Annexe — Variables d'env de production

À placer dans `/var/www/pssfp/backend/.env` (mode 600, owner deploy:www-data) — **jamais commit**.

Liste exhaustive cf. `.env.example` racine du repo. Spécifique production :

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.pssfp.net
DB_HOST=127.0.0.1
SESSION_DOMAIN=.pssfp.net
SANCTUM_STATEFUL_DOMAINS=pssfp.net,bibliotheque.pssfp.net,candidature.pssfp.net
```

Frontend Next.js : variables `NEXT_PUBLIC_*` à placer dans `.env.production` de chaque app, build-time.

## 14. Quand exécuter ce déploiement

Phase 8 du planning CDC (Juin S4 — Juillet S1). Pré-requis :

- Audit VPS Contabo réalisé (B8).
- DNS pssfp.net configuré et propagé.
- Certificats Let's Encrypt provisionnés.
- Backups Scaleway opérationnels.
- Tests de pré-prod sur l'environnement staging (à monter à mi-juin).
