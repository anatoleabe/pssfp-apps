# Spec — Email, monitoring et SecOps

> **Référence** : Sprint Specs A10
> **Statut** : v1.0 — proposé pour validation Chef USI
> **Date** : 2026-05-05

Cette spec couvre les sujets opérationnels critiques laissés implicites dans le CDC v5 : configuration SMTP transactionnel, monitoring applicatif et infrastructure, sauvegardes, sécurité opérationnelle. Elle conditionne la recette V1 — sans email fonctionnel, le formulaire de contact ne passe pas la recette §15.2.

## 1. SMTP transactionnel

### 1.1 Configuration retenue

Le PSSFP dispose d'un compte SMTP fourni par creawebhosting (hébergeur courriel actuel). Configuration fournie le 5 mai 2026 :

- **Compte d'envoi** : `noreply@pssfp.net`
- **Serveur SMTP** : `mail.creawebhosting.org`
- **Port** : `465` (SSL/SMTPS)
- **Authentification** : LOGIN avec mot de passe du compte

Un second compte `contact@pssfp.net` reçoit les messages du formulaire de contact.

### 1.2 Variables d'environnement Laravel

Dans `backend/.env` (jamais commité) :

```
MAIL_MAILER=smtp
MAIL_HOST=mail.creawebhosting.org
MAIL_PORT=465
MAIL_USERNAME=noreply@pssfp.net
MAIL_PASSWORD=__a_definir_dans_env__
MAIL_ENCRYPTION=ssl
MAIL_FROM_ADDRESS=noreply@pssfp.net
MAIL_FROM_NAME="PSSFP"
MAIL_TO_CONTACT=contact@pssfp.net
```

Le fichier `.env.example` versionné contient les clés sans les valeurs secrètes. Le mot de passe production est stocké uniquement dans le coffre 1Password ou Bitwarden du Chef USI et dans le `.env` du serveur Contabo.

**Régénération du mot de passe** : recommandé une fois avant la mise en production, vu que ce mot de passe a transité par une conversation Cowork le 5 mai 2026.

### 1.3 Configuration DNS pour la délivrabilité

À ajouter dans la zone DNS de `pssfp.net` chez le registrar :

**SPF** — autorise creawebhosting et le serveur Contabo à envoyer pour `pssfp.net` :

```
pssfp.net. TXT "v=spf1 include:creawebhosting.org ip4:<IP_CONTABO> ~all"
```

**DKIM** — la clé publique fournie par creawebhosting, à ajouter en TXT sur le sélecteur indiqué (typique `default._domainkey.pssfp.net`).

**DMARC** — politique progressive : démarrer en `p=none` pour observer, puis passer à `p=quarantine` après 30 jours sans incident :

```
_dmarc.pssfp.net. TXT "v=DMARC1; p=none; rua=mailto:dmarc@pssfp.net; ruf=mailto:dmarc@pssfp.net; fo=1"
```

Sans SPF + DKIM corrects, les emails partent en spam chez Gmail, Outlook, Yahoo. À tester avec mail-tester.com après configuration — score cible ≥ 9/10.

### 1.4 Templates email à créer

Dans `backend/resources/views/emails/` :

| Template | Déclenchement | Destinataire |
|---|---|---|
| `auth/email-verification.blade.php` | Inscription utilisateur | Nouvel utilisateur |
| `auth/password-reset.blade.php` | Mot de passe oublié | Utilisateur demandeur |
| `contact/notification.blade.php` | Soumission formulaire `/contact` | `contact@pssfp.net` |
| `contact/auto-reply.blade.php` | Soumission formulaire `/contact` | Émetteur du message |
| `articles/published-notification.blade.php` | Article publié | Auteur de l'article |
| `articles/review-requested.blade.php` | Article soumis en relecture | Relecteur configuré |
| `applications/profile-required.blade.php` | Inscription candidat | Candidat (rappel compléter profil) |
| `applications/submitted-confirmation.blade.php` | Soumission candidature | Candidat |
| `applications/status-changed.blade.php` | Changement statut candidature | Candidat |
| `applications/payment-received.blade.php` | Réception paiement frais CCA | Candidat |
| `applications/decision-accepted.blade.php` | Acceptation candidature | Candidat |
| `applications/decision-rejected.blade.php` | Refus candidature | Candidat |
| `library/document-restricted-warning.blade.php` | Tentative accès document restreint sans droit | (informational, optionnel) |

Tous les templates héritent d'un `emails/layouts/main.blade.php` avec en-tête PSSFP (logo + bandeau violet), corps en typographie Inter, pied avec coordonnées Campus Messa et lien désinscription si applicable. Maximum 600px de largeur (compatible Outlook).

### 1.5 Limite d'envoi et anti-abus

Rate limit côté Laravel : 10 emails par minute par IP source de la requête déclenchante (formulaire contact = 5/h par IP comme défini dans api-contract.md). Pour les emails transactionnels de masse (notification publication article aux abonnés, à terme), planifier une queue Redis avec délai de 1s entre envois pour rester sous les limites creawebhosting.

Surveiller les bounces : ajouter un compte IMAP `noreply@pssfp.net` dans Laravel pour récupérer les bounces et désactiver les adresses invalides après 3 échecs.

## 2. Monitoring applicatif (Sentry)

### 2.1 Périmètre

Trois projets Sentry séparés (free tier suffit pour V1, ~5K events/mois) :

- `pssfp-frontend` — app Next.js institutionnelle.
- `pssfp-library` — app Next.js bibliothèque.
- `pssfp-candidature` — app Next.js candidature.
- `pssfp-backend` — Laravel API + Filament.

Utilisateur Sentry : `anatoleabe@gmail.com` (free tier supporte jusqu'à 1 utilisateur dev par projet).

### 2.2 Configuration côté frontend

Dans chaque app Next.js, package `@sentry/nextjs` :

```
SENTRY_DSN=__a_definir__
SENTRY_ENVIRONMENT=production
SENTRY_TRACES_SAMPLE_RATE=0.1
SENTRY_REPLAY_SESSION_SAMPLE_RATE=0.0
SENTRY_REPLAY_ON_ERROR_SAMPLE_RATE=1.0
```

Le sample rate 0.1 sur les traces signifie qu'on capte 10% des transactions complètes — ajustable selon volume. Replays activés uniquement sur erreurs (preserve la confidentialité utilisateur).

### 2.3 Configuration côté backend

Package `sentry/sentry-laravel` :

```
SENTRY_LARAVEL_DSN=__a_definir__
SENTRY_TRACES_SAMPLE_RATE=0.1
SENTRY_PROFILES_SAMPLE_RATE=0.1
```

### 2.4 Filtres de bruit

Configurer les `beforeSend` Sentry pour ignorer :

- 404 sur fichiers statiques (favicon.ico, robots.txt) — bruit.
- 401/403 attendus sur endpoints auth — pas des erreurs applicatives.
- Erreurs réseau côté client (offline) — pas des bugs.

Activer les alertes email Sentry sur : nouvelle erreur en production, taux d'erreur > 5% sur 10 min, performance dégradée (TTFB médian > 2s sur 10 min).

## 3. Monitoring infrastructure (Uptime Kuma)

### 3.1 Setup

**Uptime Kuma** auto-hébergé sur le VPS Contabo, Docker, exposé sur `status.pssfp.net` (sous-domaine dédié, certificat Let's Encrypt). Free, open source, simple.

```
docker run -d --restart=always -p 3001:3001 \
  -v uptime-kuma:/app/data \
  --name uptime-kuma louislam/uptime-kuma:1
```

Accès admin protégé par mot de passe + 2FA optionnel.

### 3.2 Sondes à configurer

| Sonde | URL | Fréquence | Alerte |
|---|---|---|---|
| Site institutionnel | https://pssfp.net | 60s | > 2 fails |
| Bibliothèque | https://bibliotheque.pssfp.net | 60s | > 2 fails |
| Candidature | https://candidature.pssfp.net | 60s | > 2 fails |
| API healthcheck | https://api.pssfp.net/v1/health | 60s | > 2 fails ou status != 'ok' |
| Filament admin | https://api.pssfp.net/admin (HEAD) | 5min | > 2 fails |
| Moodle FOAD | https://foad.pssfp.net | 5min | > 2 fails |
| Certificate SSL pssfp.net | TLS check | 1h | < 30 jours d'expiration |
| Certificate SSL bibliotheque | TLS check | 1h | < 30 jours |
| Certificate SSL candidature | TLS check | 1h | < 30 jours |
| Certificate SSL api | TLS check | 1h | < 30 jours |
| PostgreSQL local | TCP 127.0.0.1:5432 | 60s | down |
| Redis | TCP 127.0.0.1:6379 | 60s | down |
| Meilisearch | HTTP 127.0.0.1:7700/health | 60s | down |
| MinIO | HTTP 127.0.0.1:9000/minio/health/live | 60s | down |

### 3.3 Notifications

Canaux : email vers `anatoleabe@gmail.com`, et idéalement Telegram ou Slack pour réception immédiate sur mobile (à configurer selon préférence). Webhook custom possible pour intégrer plus tard avec une stack ChatOps.

Page de statut publique optionnelle : exposée sur `status.pssfp.net` pour permettre aux candidats et auditeurs de voir l'état des services en cas d'incident — bonne pratique de transparence.

## 4. Sauvegardes

### 4.1 Périmètre des données à sauvegarder

| Donnée | Volume estimé V1 | Fréquence | Destination |
|---|---|---|---|
| BDD PostgreSQL `pssfp` | ~500 Mo après 6 mois | Quotidien (3h) | Local + Scaleway |
| MinIO bucket `pssfp-media` (images CMS) | 5-10 Go | Quotidien | Scaleway |
| MinIO bucket `pssfp-documents` (PDFs biblio) | 20-50 Go selon migration | Quotidien | Scaleway |
| MinIO bucket `pssfp-candidatures` (pièces jointes) | Variable, croît avec les campagnes | Quotidien | Scaleway |
| Configuration Nginx + certificats | < 10 Mo | Hebdomadaire | Scaleway + Git |
| Logs applicatifs Laravel | < 1 Go par mois (rotation) | Conservés 30 jours | Local uniquement |

### 4.2 Outil

**Spatie Laravel Backup** package `spatie/laravel-backup`. Configuré avec deux destinations :

- **Local** : `/var/backups/pssfp/` sur le VPS Contabo (rétention 7 jours, sécurise contre erreur humaine sans dépendre du réseau).
- **Distant** : Scaleway Object Storage bucket `pssfp-backups` (rétention 30 jours, DR géographique externe au Cameroun).

Cron Laravel (`backend/app/Console/Kernel.php`) :

```
$schedule->command('backup:clean')->daily()->at('01:00');
$schedule->command('backup:run')->daily()->at('03:00');
```

### 4.3 Test de restauration

**Tous les 6 mois**, procédure de restauration testée sur un environnement de staging :

1. Provisionner un VPS test ou container Docker.
2. Restaurer la dernière sauvegarde Scaleway.
3. Vérifier que les 3 sites Next.js démarrent.
4. Vérifier qu'on peut se connecter à Filament et lire les données.
5. Vérifier qu'un PDF biblio se télécharge.
6. Documenter le PRA dans `docs/runbooks/disaster-recovery.md`.

Sans test régulier, la sauvegarde est théorique.

## 5. Logs et rotation

Logs Laravel : driver `daily` rotation à minuit, conservation 30 jours, format JSON (parsable Sentry/Loki si on évolue).

Logs Nginx access et error : rotation logrotate quotidienne, conservation 14 jours, compression gzip après J+2.

Logs PostgreSQL : log_min_duration_statement = 1000ms (capture les requêtes > 1s), rotation quotidienne, conservation 7 jours.

Pas de centralisation Loki/Datadog en V1 — les logs locaux suffisent pour le volume initial. À réévaluer Phase II si besoin.

## 6. Hardening sécurité du VPS

À appliquer dans la doc de déploiement (B7) :

- SSH par clé uniquement (pas de mot de passe).
- Port SSH non standard (2222 au lieu de 22) pour réduire le bruit de scan.
- Utilisateur `deploy` non-root pour les déploiements applicatifs, sudo seulement pour les commandes système ciblées.
- Firewall `ufw` : seuls les ports 22 (ou 2222), 80, 443 ouverts depuis l'extérieur.
- `fail2ban` actif sur SSH et Nginx (bannir IPs avec > 10 tentatives échec login en 10 min).
- Mises à jour de sécurité automatiques `unattended-upgrades` activées.
- ClamAV installé et utilisé via job Laravel pour scanner les uploads candidatures et biblio.
- Headers de sécurité HTTP à configurer dans Nginx (CSP, HSTS, X-Frame-Options, etc.) — déjà détaillés dans api-contract.md §12.3.

## 7. Politique de gestion des incidents

Document court à créer en `docs/runbooks/incident-response.md` lors du Sprint B :

- Définition d'un incident (P1 = site down, P2 = fonctionnalité critique cassée, P3 = bug mineur).
- Procédure de notification interne (qui prévenir et comment).
- Communication externe : page status.pssfp.net + tweet/post Facebook officiel pour incidents prolongés.
- Post-mortem obligatoire pour P1 et P2 dans `docs/post-mortems/YYYY-MM-DD-titre.md`.

## 8. Critères d'acceptation

- Email de test envoyé depuis Filament reçu en boîte test gmail.com et outlook.com sans aller en spam (score mail-tester ≥ 9/10).
- Sentry remonte une erreur volontaire (faux 500 sur un endpoint test) en moins de 2 minutes.
- Uptime Kuma affiche tous les services au vert dans son tableau de bord.
- Backup quotidien produit un fichier dans Scaleway, testable via téléchargement du dernier dump et restauration locale.
- Header `Strict-Transport-Security` présent sur toutes les pages HTTPS.
- ClamAV scan d'un fichier EICAR test détecté et bloqué côté upload.

## Annexe — Checklist pré-prod SecOps

À cocher avant le go-live :

- [ ] SMTP fonctionnel, score mail-tester ≥ 9/10.
- [ ] DKIM, SPF, DMARC configurés et validés.
- [ ] Sentry connecté et reçoit les erreurs (test événementiel).
- [ ] Uptime Kuma installé, sondes actives, notifications testées.
- [ ] Backups quotidiens automatisés, dernier backup vérifié sur Scaleway.
- [ ] Premier test de restauration documenté.
- [ ] Firewall ufw actif, ports limités à 22/80/443.
- [ ] fail2ban actif sur SSH.
- [ ] SSH par clé uniquement, pas de mot de passe.
- [ ] Mises à jour automatiques système activées.
- [ ] ClamAV installé et configuré pour les uploads.
- [ ] Headers de sécurité HTTP configurés dans Nginx.
- [ ] Mots de passe rotatés (SMTP, DB, Redis admin) avant prod.
- [ ] 2FA activé sur le compte admin Filament technique.
- [ ] Coffre 1Password ou Bitwarden mis à jour avec tous les secrets.
- [ ] Runbook incident-response.md écrit et accessible.
