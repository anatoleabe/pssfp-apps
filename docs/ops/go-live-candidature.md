# Go-live candidature — procédures ops (LOT B)

> Complète `docs/sprints/plan-mise-en-prod-v1.md` (LOT B). Les fichiers infra
> (systemd, nginx, deploy.sh) arrivent au LOT C — ici : les procédures données/ops.

## B.1 — Campagne Promotion 14

Sans campagne ouverte, `currentCampaign()` renvoie null → personne ne peut postuler.

1. Renseigner dans le `.env` prod (décision direction) :
   ```
   CAMPAGNE_P14_OPENS_AT="…"    # ouverture
   CAMPAGNE_P14_CLOSES_AT="…"   # clôture
   CAMPAGNE_P14_RESULTS_AT="…"  # publication résultats
   ```
2. `php artisan db:seed --class=ProdCampagneP14Seeder --force`
   - Idempotent (`updateOrCreate` sur slug `p14-2026`, préfixe `P14026-`).
   - Rejouable pour **prolonger la campagne** : modifier `CAMPAGNE_P14_CLOSES_AT` puis re-seed.
   - Refuse de tourner si dates absentes ou incohérentes.
3. Alternative sans redéploiement : Filament → `CampagneCandidatureResource`.

## B.2 — Compte admin comité

1. `.env` prod : `FILAMENT_ADMIN_EMAIL` + `FILAMENT_ADMIN_PASSWORD` (fort, jetable — à changer au 1er login).
2. `php artisan db:seed --class=RolePermissionSeeder --force`
3. `php artisan db:seed --class=AdminUserSeeder --force` (rôle `super_admin`; refuse sans env).
4. Créer ensuite les comptes `admission_committee` via Filament → Users.

## B.3 — Amorçage 2FA (œuf-et-poule)

`User::canAccessPanel()` exige un TOTP actif quand `FILAMENT_REQUIRE_2FA=true` —
or l'enrôlement se fait DANS le panel. Procédure d'amorçage, par admin :

1. `.env` : `FILAMENT_REQUIRE_2FA=false` → `php artisan config:clear`.
2. Login sur `/admin` → profil → **activer la 2FA TOTP** (scanner le QR, valider un code).
3. `.env` : `FILAMENT_REQUIRE_2FA=true` → `php artisan config:clear`.
4. Vérifier : logout/login → le panel doit exiger le code TOTP.

> Fenêtre courte (quelques minutes), à faire avant l'annonce publique de
> l'URL admin. Ne JAMAIS laisser `FILAMENT_REQUIRE_2FA=false` en prod.

## B.4 — SMS OTP : deux modes de lancement

| Mode | Config | Effet |
|---|---|---|
| **Fallback V1** (défaut) | `SMS_PROVIDER=fake` | forgot-pin répond 202 mais aucun SMS ne part (log `sms` uniquement). Un candidat qui oublie son PIN → réinitialisation par l'admin dans Filament. Acceptable ~200 candidats. |
| **SMS réels** | `SMS_PROVIDER=africas_talking` + `AFRICAS_TALKING_USERNAME`/`API_KEY`/`SENDER_ID` approuvé | `AfricasTalkingProvider` (HTTP direct, implémenté LOT B). ~15-25 FCFA/SMS. |

Garde-fous par numéro actifs dans les deux modes (`OtpService`) :
`OTP_COOLDOWN_SECONDS=60` entre deux envois, `OTP_MAX_PER_PHONE_PER_HOUR=5`.
En cooldown, forgot-pin reste 202 **sans** SMS (anti-énumération préservée).

## B.5 — Queue worker Redis (CRITIQUE)

Tous les emails candidat (soumission, décision) sont `ShouldQueue` :
**sans worker, aucun email ne part** (les jobs s'empilent en silence).

- Unit systemd `pssfp-queue.service` livrée au LOT C (`queue:work redis --tries=3`).
- Vérification post-déploiement :
  ```
  redis-cli llen queues:default        # doit redescendre à 0
  php artisan queue:failed             # doit rester vide
  ```
- Test bout-en-bout : soumettre une candidature de test → email reçu < 1 min.

## B.6 — Scan photos : Noop acté en V1

`NoopPhotoScanner` reste bindé en V1 (détecte EICAR, pas de daemon requis).
Bascule `ClamAvPhotoScanner` quand le daemon clamd sera provisionné (LOT C
optionnel). Décision consignée — pas un oubli.

## B.7 — Verify bout-en-bout (staging puis prod)

Parcours complet à dérouler avant l'ouverture publique :

```
register (+Turnstile) → dossier → photo → submit
  → récépissé PDF (QR + SHA256) → email de soumission (queue!)
  → Filament : markPaid → accept → email de décision
  → forgot-pin : OTP (ou fallback admin) → reset PIN → re-login
```

Chaque étape email suppose le worker B.5 actif et SPF/DKIM/DMARC posés sur
`pssfp.org` (sinon spam — cf. plan §Hébergement).
