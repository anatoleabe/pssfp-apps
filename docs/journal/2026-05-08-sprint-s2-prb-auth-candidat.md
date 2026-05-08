# 2026-05-08 — Sprint S2 — PR B — Auth candidat phone + PIN + OTP

> Branche : `feat/m5-candidatures` (continuation post-merge PR A)
> Périmètre : auth candidat phone E.164 + PIN 6 chiffres + OTP SMS via stub
> Spec : `docs/specs/module-5-candidatures.md` v1.1 §M4 + §M13
> ADR : `docs/adr/0007-pin-6-chiffres-candidats.md`
> Mise à jour : `docs/adr/0005-auth-sanctum-tokens-scoped.md` (dérogation TTL 7j candidat)

## Endpoints livrés

Tous sous `/v1/auth/candidat/*`. Préfixé par le middleware Sanctum stateful global du projet.

| Endpoint | Auth | Throttle | Réponses |
|---|---|---|---|
| `POST /register` | aucune | — | 201 token + abilities, 409 phone existant, 422 validation |
| `POST /login` | aucune | `throttle.candidat.login` | 200 token, 401 wrong pin/phone, 423 phone lockout, 429 IP throttled/banned |
| `POST /forgot-pin` | aucune | — | 202 toujours (anti-énumération) |
| `POST /verify-otp` | aucune | OtpVerify 5/60min | 200 token court `pin:reset`, 401 wrong/exhausted, 410 expired, 409 already_used, 429 |
| `POST /reset-pin` | `auth:sanctum` + `ability:pin:reset` | — | 204 PIN mis à jour, tous tokens révoqués, OTPs annulés |
| `POST /logout` | `auth:sanctum` | — | 204 |

## Décisions de PR (3 arbitrages + 5 ajouts intégrés)

### Arbitrages validés

- **A — Spatie exclusivement** : pas de colonne `users.role` enum. Le rôle candidat est attaché via `assignRole('candidat')`. Permissions Spatie de base seedées : `profile.read`, `profile.write`, `application.create`, `application.read`. Évite la dualité avec `model_has_roles` + risque de désync.
- **B — Register minimal** : phone + PIN + nom + prénom + date_naissance + cgu. Date de naissance requise pour la règle ADR-0007 « PIN ≠ DDMMYY/YYMMDD ». La création de la `Candidature` draft viendra en PR C via `POST /v1/applications/me`.
- **C — Hash OTP via `Hash::make`** : bcrypt par défaut Laravel. `BCRYPT_ROUNDS=4` en testing pour rester rapide.

### Ajouts intégrés au plan

1. **TTL token candidat = 7 jours** (au lieu de 24h ADR-0005 ou 30j proposé initialement). ADR-0005 mis à jour avec justification UX. Token court `pin:reset` reste à 10 min.
2. **Rate limit verify-otp** : 4ème compteur Redis `candidat:otp:phone:{sha1(phone)}` — 5 tentatives / 60 min / phone, indépendant du compteur sur l'OTP individuel.
3. **Reset-pin révoque TOUS les tokens** : `$user->tokens()->delete()` après update du password. Mesure compensatoire au TTL 7j.
4. **Cleanup OTPs au reset réussi** : `OtpService::invalidatePending()` annule les OTP non consommés du même phone+purpose. Évite le rejeu d'un OTP issu d'une session de reset précédente.
5. **Test isolation token candidat** : `CandidatTokenIsolationTest` vérifie qu'un token candidat ne peut accéder ni à `ability:admin.*` ni à `ability:library:read:restricted`. Routes-leurres définies dans le test pour rester indépendant des PRs futures.

### Précisions intégrées

- **`cancelled_at` distinct de `used_at`** sur `sms_otps` : sémantique propre (consommé vs invalidé administrativement).
- **`activity_log` Spatie** sur chaque `login_failed` (anonyme, `phone_e164_masked`, `reason`, `ip` — jamais le PIN). Helper `App\Support\PhoneMasker::mask()` produit `+237***4567`.
- **Idempotency-Key** sur register : reportée. Annoter au TODO post-V1 si nécessaire après volumes réels.

## Décisions techniques notables

- **Aliases middleware Sanctum** : `abilities` et `ability` ne sont pas auto-aliased en Laravel 11 (vs Laravel 10). Ajoutés explicitement dans `bootstrap/app.php` aux côtés de `throttle.candidat.login`.
- **Tests Pest unitaires** : extension `pest()->extend(TestCase::class)->in('Unit/Services')` ajoutée à `tests/Pest.php` pour permettre l'utilisation de `config()` et `app()` dans les tests Service sans `RefreshDatabase` (pas d'accès DB).
- **`users.email` rendu nullable** via migration `extend_users_for_candidat_auth` : un candidat peut s'inscrire sans email. L'unicité Postgres reste appliquée sur les valeurs non-NULL.
- **`UserFactory::candidat()`** state ajouté + assigne le rôle `candidat` via `afterCreating()`. Simplifie tous les tests Feature.
- **Pas de FK** entre `sms_otps.phone_e164` et `users.phone_e164` : un OTP peut être généré avant que le User existe (futur cas `verify_phone` à l'inscription).

## Tests Pest — 88 verts, 214 assertions

| Suite | Tests | Notes |
|---|---|---|
| `Unit/Services/PinServiceTest` | 9 | Format, blacklist, suffix phone, DOB DDMMYY/YYMMDD, multi-reasons |
| `Unit/Services/SmsProviderBindingTest` | 4 | Bind selon SMS_PROVIDER, NotConfigured, Fake sans réseau |
| `Feature/Auth/RegisterCandidatTest` | 10 | Happy path, 409 phone existant, blacklist, suffix, DOB, validation E.164/age/CGU/confirm |
| `Feature/Auth/LoginCandidatTest` | 8 | Happy path, 401 wrong (no leak), 423 lockout, 429 IP, clear counters, role rejection, activity_log |
| `Feature/Auth/ForgotPinFlowTest` | 12 | SMS triggered, anti-enum, attempts, 410 expired, 401 exhausted, 409 reused, ability check, revoke all tokens, invalidate pending OTPs, PinService rules, rate limit 5/60min |
| `Feature/Auth/LogoutCandidatTest` | 2 | Revoke current token, 401 sans token |
| `Feature/Auth/CandidatTokenIsolationTest` | 3 | Pas d'accès admin.*, pas d'accès library:read:restricted, abilities scope strict |
| `Feature/Console/PurgeExpiredOtpsTest` | 3 | Delete >7j, preserve <7j et valides, --days option |

Plus les 30 tests PR A toujours verts.

## Hors périmètre / à venir en PR C

- Endpoints `/v1/applications/*` (création/édition/soumission Candidature, génération PDF récépissé).
- Endpoints `/v1/reference/*` (pays, regions, departements pour le cascading frontend).
- Vraie intégration Africa's Talking (V1 reste en `SMS_PROVIDER=fake`).
- Idempotency-Key sur register (annoté en TODO).
- Vérification phone à l'inscription via OTP (purpose `verify_phone` est dans le schéma mais non câblé V1 — on accepte le téléphone tel que saisi).

## Variables d'environnement nouvelles

```
SMS_PROVIDER=fake
AFRICAS_TALKING_USERNAME=
AFRICAS_TALKING_API_KEY=
AFRICAS_TALKING_SENDER_ID=PSSFP
```

À ajouter dans `.env.example` et `.env.testing.example` (déjà fait dans cette PR).
