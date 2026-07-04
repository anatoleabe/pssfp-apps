# Plan de mise en production v1 — Site institutionnel + Candidature Promotion 14

> **Auteur** : appropriation projet (audit multi-agents 2026-07-03)
> **Objectif** : livrer une **première version en production** de `pssfp.net` (site) et `candidature.pssfp.net` (appel à candidature), en priorisant **la candidature** pour la vague **Promotion 14** imminente.
> **Méthode** : Plan → Act → Verify (PLAYBOOK §7). Un lot = une branche = une PR. Chaque lot ≤ 8 étapes. À dérouler **lot par lot avec Claude Fable 5**.
> **Hors périmètre v1** : bibliothèque (`library/`), paiement en ligne, SSO Moodle, i18n EN/AR.

---

## 0. État des lieux (vérifié par audit code + build réel)

| Brique | Complétion | Verdict |
|---|---|---|
| **Candidature — frontend** (`candidature/`) | ~80 % | Parcours réel et branché sur l'API. **Build prod OK** (11 pages). Manque : Turnstile, CGU, idempotency effective. |
| **Candidature — backend** (module 5) | ~90 % | Auth PIN/OTP, draft/submit, récépissé PDF (QR+SHA256), photo MinIO, Filament admin, 237 tests Pest. Manque surtout du **provisioning/config prod** + email de soumission. |
| **Site — frontend** (`frontend/`) | UI ~85 % / livrable ~65-70 % | 14 routes, design system, SEO/JSON-LD, dark mode. **Bloqué par le contenu** : pages profondes 100 % API, images 100 % MinIO (non peuplé). |
| **Site — backend** (module 1) | ~85 % | Pages/articles/menu/contact servent du **vrai contenu seedé** (seeders de 10-40 Ko). À seeder en prod. |
| **Infra / déploiement** | ~30 % | CI verte (build+test des 4 apps). Mais `deploy.sh`, PM2, nginx, systemd **documentés mais absents du disque**. Pas de CD. |

**Chemin critique candidature** = LOT A + LOT B + LOT C → candidature en ligne.
**Le site** (LOT D) peut partir dans **le même déploiement** ou juste après.

### Décisions à acter par Anatole (avant de lancer les lots)

1. **Hébergement** : cf. §Hébergement en fin de doc. Reco = **Hetzner Cloud CPX21/CX32 (~9 €/mois) + Cloudflare (gratuit)**. (Contabo acceptable si déjà contracté.)
2. **OTP SMS au lancement** : brancher **Africa's Talking** maintenant, **ou** partir en *fallback V1* (candidat qui oublie son PIN → réinitialisé par l'admin dans Filament). Le fallback est acceptable pour ~200 candidats et **ne bloque pas** l'ouverture.
3. **Contenu CGU + Politique de confidentialité candidat** : fournir le texte (obligatoire RGPD — la case d'engagement le référence).
4. **Dates campagne P14** : ouverture / clôture / résultats, et le préfixe `P14026`.

---

## LOT A — Candidature : durcissement code avant prod
> Branche : `feat/prod-a-candidature-hardening` · Priorité : **P0 (chemin critique)**

1. **Cloudflare Turnstile** sur `/inscription` (étape 4) **et** `/forgot-pin` : composant `TurnstileWidget`, clé `NEXT_PUBLIC_TURNSTILE_SITE_KEY`, envoi du token dans `registerCandidat`/`forgotPin` (`lib/api/client.ts`) ; validation serveur `TURNSTILE_SECRET_KEY` dans `AuthCandidatController` (register + forgotPin). Protège aussi le **crédit SMS OTP** contre les bots.
2. **Pages légales candidat** : créer `/cgu` et `/confidentialite` (contenu fourni par Anatole), lier depuis `WizardStep4Engagement.tsx` (case obligatoire) + footer.
3. **Idempotency effective** : générer `crypto.randomUUID()` dans `submitDossierAction` (`app/dossier/actions.ts`) et le passer en `X-Idempotency-Key` à `submitMyCandidature`.
4. **Email de confirmation de soumission** (manquant) : `Mailable CandidatureSubmittedMail` (`ShouldQueue`) avec lien récépissé + instructions frais 50 000 FCFA CREMINCAM ; dispatch dans `CandidatureService::submit()` ; vue Blade + test Pest.
5. **Fix config filesystem** : `config/filesystems.php` n'a **pas** de disk `minio` par défaut alors que `.env.example` pose `FILESYSTEM_DISK=minio` → définir le disk ou aligner la variable. Poser `SCOUT_DRIVER=database` pour V1 (retire la dépendance Meilisearch).
6. **Env de prod** : créer `candidature/.env.production` et `frontend/.env.production` (`NEXT_PUBLIC_API_URL=https://api.pssfp.net/v1`, `NEXT_PUBLIC_MAIN_SITE_URL`, `NEXT_PUBLIC_CANDIDATURE_URL`, Turnstile). Compléter `.env.example` manquants.
7. **(Option)** Sentry léger (candidature + backend) pour l'observabilité prod — sinon différer explicitement.
8. **Verify** : `pnpm build` candidature vert · Pest backend vert (services docker up) · sous-agents `candidature-reviewer` + `a11y-reviewer` + `i18n-reviewer`.

### LOT A — reliquats actés en fast-follow (revues du 2026-07-04)

Corrigé pendant le Verify : double enregistrement des listeners email (submitted/accepted/refused — chaque candidat aurait reçu 2 emails), bypass Turnstile silencieux si secret absent en prod, `turnstile_token` non validé (500 → 422, +6 tests Pest), PIN/confirmation exclus de la sessionStorage, widget Turnstile avec état d'erreur perceptible + retry (WCAG 4.1.3), modale d'avertissement avant le timeout 30 min (WCAG 2.2.1), focus outline or→prune (1.4.11), migration i18n complète des composants LOT A (~95 chaînes → `messages/fr.json`), throttle backstop `verify-otp`, collage OTP dans `PinInput`.

Restent explicitement différés (LOW / dette pré-existante) :
1. `CandidatureController` submit/withdraw : `RuntimeException::getMessage()` renvoyé brut (anglais) — remplacer par message FR + `kind` structuré, et supprimer le couplage `message?.includes('déjà décidée')` dans `dossier/actions.ts`.
2. `presentUser()` expose l'`id` interne BIGSERIAL dans register/login (règle repo n°10) — pré-existant au LOT A.
3. Rejeu d'une `X-Idempotency-Key` après expiration du cache 5 min → 409 (client le gère) — à documenter dans `docs/api-contract.md`.
4. Corps des pages légales : externaliser par locale (`content/legal/cgu.fr.tsx`) pour préparer EN/AR (ADR-0006) ; metadata en dur = pattern pré-existant sur toutes les pages de l'app.
5. `config/mail.php` fallback `hello@example.com` — verrouiller `MAIL_FROM_ADDRESS=noreply@pssfp.net` dans l'env prod (fait dans `.env.example`).
6. i18n de `WizardStepper` (« Étape {n} / {total} », aria) — composant pré-existant hors périmètre LOT A.

---

## LOT B — Backend : go-live candidature (données & ops)
> Branche : `feat/prod-b-candidature-golive` · Priorité : **P0 (chemin critique)**

1. **Campagne Promotion 14 ouverte en prod** : `ProdCampagneP14Seeder` **idempotent** (`updateOrCreate`, `status=open`, dates + préfixe `P14026`), OU procédure documentée via `CampagneCandidatureResource` (Filament). *Sans campagne ouverte, `currentCampaign()` renvoie null → personne ne peut postuler.*
2. **Compte admin comité** : `AdminUserSeeder` via `FILAMENT_ADMIN_EMAIL`/`FILAMENT_ADMIN_PASSWORD` + rôle `admission_committee`/`admin`.
3. **Amorçage 2FA** : documenter/scripter `FILAMENT_REQUIRE_2FA=false` au 1er login → enrôlement TOTP → repasser `=true` (`config/pssfp.php`, `User::canAccessPanel()`), OU autoriser la page profil sans 2FA.
4. **OTP SMS** (selon décision Anatole) : vérifier/compléter `AfricasTalkingProvider` + binding conditionnel + `config/services.php` ; sinon acter le fallback admin. **⚠️ Au branchement du vrai SMS, ajouter un cooldown par numéro dans `OtpService::generate()`** (issue HIGH revue LOT A : le throttle IP ajouté ne protège pas un numéro ciblé depuis des IP tournantes ; Turnstile fail-open ne suffit pas seul à protéger le crédit SMS).
5. **Queue worker Redis** : les mails (soumission/décision) sont `ShouldQueue` → **sans worker, aucun email ne part**. Prévu en systemd au LOT C ; ici, vérifier que les jobs s'empilent et se traitent.
6. **ClamAV** : conserver `NoopPhotoScanner` en V1 (acceptable, détecte EICAR) OU brancher `ClamAvPhotoScanner` si le daemon est dispo.
7. **Verify** : parcours bout-en-bout sur staging — `register → dossier → photo → submit → récépissé PDF → email soumission → Filament markPaid/accept → email décision`.

---

## LOT C — Infra : premier déploiement production
> Branche : `chore/prod-c-infra-deploy` · Priorité : **P0 (chemin critique)** · Crée les fichiers **absents du disque**

1. **`infra/deploy/deploy.sh`** (référencé par `Makefile:128`, absent) : `git pull` → composer `--no-dev` → `migrate --force` → `optimize` → build `frontend` + `candidature` → `pm2 reload` → `systemctl reload nginx` + restart queue.
2. **`ecosystem.config.js`** PM2 : `frontend` (:6001), `candidature` (:6003). *(library exclue de la v1.)*
3. **Vhosts nginx** : `pssfp.net`, `candidature.pssfp.net`, `api.pssfp.net` (PHP-FPM) + redirections 301 Joomla → Let's Encrypt (certbot).
4. **Unit systemd** `pssfp-queue.service` (`queue:work` Redis) + MinIO systemd (stockage privé photos/récépissés).
5. **Provisionner le VPS** : PostgreSQL 16, Redis 7, MinIO, PHP 8.3-FPM (ext. pgsql/redis/gd/intl/bcmath/zip), Node 20, Nginx, Certbot, ufw, fail2ban.
6. **DNS + Cloudflare** : enregistrements A `pssfp.net`/`candidature.pssfp.net`/`api.pssfp.net` **proxifiés** (TLS, cache statique, WAF, Turnstile). `SESSION_DOMAIN=.pssfp.net`, `SANCTUM_STATEFUL_DOMAINS` prod.
7. **Déployer** : `.env` prod (générer `APP_KEY`, DB/MINIO/MAIL/TURNSTILE/SMS) mode 600 · `migrate` · seed contenu + campagne P14 · créer buckets MinIO (`pssfp-media`, `pssfp-candidatures`).
8. **Verify** : smoke test prod candidature + site · Lighthouse ≥ 85 (candidature) / ≥ 90 (site) · `securityheaders.com` ≥ A · **1er backup testé en restauration**.

---

## LOT D — Site institutionnel : contenu & go-live
> Branche : `feat/prod-d-site-contenu` · Priorité : **P1 (même déploiement ou juste après)**

1. **Consolider `main`** : committer le WIP home (branche `codex/home-institutionnelle-wow`) + merger/nettoyer les branches feature obsolètes → `main` déployable.
2. **Seed contenu prod** : `AProposPagesSeeder`, `FormationsPagesSeeder`, `VieAcademiquePagesSeeder`, `ArticlesSeeder` ; passer les 2 articles draft → `published` (Centre Pasteur, Assemblée Nationale).
3. **Assets images** : débloquer quarantine macOS (`xattr -rd com.apple.quarantine assets-source/`) + `php artisan pssfp:import-assets` (180 photos), committer `frontend/public/photos/` (sinon `campus-messa` 404).
4. **Turnstile sur `ContactForm`** (anti-spam formulaire de contact).
5. **Env site** : `NEXT_PUBLIC_SITE_URL`, `NEXT_PUBLIC_MEDIA_URL`, `NEXT_PUBLIC_PUBLIC_DOC_URL` ; compléter `frontend/.env.example`.
6. **Dette i18n** (règle projet) : externaliser le texte en dur de `contact/page.tsx`, `ContactForm`, index `formations`/`vie-academique` vers `messages/fr.json`.
7. **(Option)** Persistance des messages de contact en base + Sentry site.
8. **Verify** : Lighthouse ≥ 90 (`/`, `/a-propos`, `/formations`, `/actualites`) · sous-agents `cames-reviewer` + `a11y-reviewer` + `i18n-reviewer`.

---

## Séquencement recommandé

```
Jours 1-2  : LOT A (candidature code) ‖ LOT C.1-C.4 (écrire fichiers infra)
Jour  3    : LOT C.5-C.7 (provisionner VPS + déployer backend + candidature) + LOT B (données/ops)
Jour  4    : LOT B.7 verify E2E staging→prod · ouverture candidature P14  ✅
Jours 5-6  : LOT D (contenu site) + go-live pssfp.net
```

**Livrable minimal pour recevoir des candidatures P14** : LOT A (1,2,3) + LOT B (1,2,3,5) + LOT C entièrement. Le reste est du durcissement qui peut suivre la 1ʳᵉ vague.

---

## Hébergement — recommandation

**Architecture retenue : un seul VPS + Cloudflare (gratuit) en frontal.** Un VPS unique garde l'auth cross-sous-domaine (`SESSION_DOMAIN=.pssfp.net`) triviale, coûte le moins cher, et colle à la doc de déploiement existante.

| Option | Coût | Verdict |
|---|---|---|
| **Hetzner Cloud CPX21 / CX32** (3-4 vCPU, 8 Go, 80 Go NVMe) | **~8-9 €/mois** | ★ **Recommandé** — meilleur rapport fiabilité/perf/prix, datacenters UE. |
| Contabo Cloud VPS (4 vCPU, 8-24 Go) | ~6-8 €/mois | Acceptable **si déjà contracté** (le CDC le prévoit). RAM moins chère mais réseau/support moins fiables. |
| Vercel + Neon + host Laravel (managé) | variable, plus cher | Rejeté : plus de pièces mobiles, egress Vercel, auth cross-sous-domaine complexifiée. |

**Le levier qualité clé pour un public camerounais** : mettre **Cloudflare (plan gratuit)** devant le VPS (UE). Cache CDN des assets sur PoPs africains, TLS auto, DDoS/WAF, et Turnstile déjà intégré. Gain de latence majeur pour ~0 €.

**Compléments** :
- **Sauvegardes** : snapshots Hetzner + dump hebdo vers Storage Box (~3,50 €/mois) ou Scaleway.
- **Emails transactionnels** : SMTP creawebhosting OK à faible volume, **mais configurer SPF + DKIM + DMARC** sur `pssfp.net` (sinon confirmations candidature en spam). Alternative faible volume : Brevo (300 mails/j gratuits).
- **SMS OTP** : Africa's Talking (~15-25 FCFA/SMS, ≈10 000 FCFA/campagne).

**Coût total v1 ≈ 12-15 €/mois** (VPS + backup) + ~10 000 FCFA/campagne SMS + Cloudflare 0 €.
