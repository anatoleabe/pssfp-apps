# Instructions Fable — Bascule domaine canonique `pssfp.net → pssfp.org`

> **Décision Anatole (2026-07-04)** : le domaine canonique de production devient **`pssfp.org`** (domaine gardé à long terme). L'app candidature vit sur **`apply.pssfp.org`**. Tous les autres domaines (`pssfp.net`, `pssfp.cm`, `pfinancespubliques.org`, `www.*`) seront de simples **redirections 301** configurées côté Nginx/Cloudflare au déploiement — **rien à coder pour les redirections ici**.
>
> **Nature de la tâche** : balayage **mécanique** de config/env + quelques valeurs par défaut SEO. Aucun domaine n'est écrit en dur dans la logique métier (vérifié). **Ne pas** refactorer, **ne pas** toucher au comportement — uniquement remplacer des chaînes de domaine.
>
> **Branche** : `chore/prod-domain-sweep-org` (partir de la branche de travail candidature courante).

---

## Règle de mapping des domaines

| Ancien (`.net`) | Nouveau (`.org`) |
|---|---|
| `pssfp.net` | `pssfp.org` |
| `www.pssfp.net` | `www.pssfp.org` |
| `api.pssfp.net` | `api.pssfp.org` |
| `candidature.pssfp.net` | **`apply.pssfp.org`** ← renommage délibéré |
| `bibliotheque.pssfp.net` | `bibliotheque.pssfp.org` |
| `foad.pssfp.net` | `foad.pssfp.org` |
| `media.pssfp.net` | `media.pssfp.org` |
| `noreply@pssfp.net`, `contact@…`, `admissions@…` | `…@pssfp.org` |

> ⚠️ **NE PAS toucher** `MAIL_HOST` / SMTP `mail.creawebhosting.org` — c'est le fournisseur d'envoi, pas le domaine institutionnel.
> ⚠️ **NE PAS** renommer `bibliotheque` en `library` (la biblio est hors périmètre v1 ; on garde le nom FR existant, juste `.net → .org`).

---

## 1. Backend — config

### `backend/config/cors.php` (lignes 23-26)
Remplacer le bloc des origines de production :
```php
        'https://pssfp.net',
        'https://www.pssfp.net',
        'https://bibliotheque.pssfp.net',
        'https://candidature.pssfp.net',
```
par :
```php
        'https://pssfp.org',
        'https://www.pssfp.org',
        'https://bibliotheque.pssfp.org',
        'https://apply.pssfp.org',
```
Mettre aussi à jour le commentaire ligne 8 (« sous-domaines pssfp.net » → « sous-domaines pssfp.org »).

### `backend/config/pssfp.php` (ligne 17)
```php
'candidature_app_url' => rtrim((string) env('CANDIDATURE_APP_URL', 'https://candidature.pssfp.net'), '/'),
```
→ défaut `'https://apply.pssfp.org'`.

### `backend/app/Http/Controllers/Api/Contact/ContactController.php` (ligne 42)
`config('mail.contact_recipient', 'contact@pssfp.net')` → défaut `'contact@pssfp.org'`. (Le commentaire ligne 21 aussi.)

### `backend/app/Mail/ContactMessageMailable.php` (ligne 28)
Le libellé de sujet `'[Contact pssfp.net] '` → `'[Contact pssfp.org] '`.

---

## 2. Env — templates versionnés (⚠️ uniquement les `*.example`, pas les `.env.local` des devs)

### `.env.example` (racine)
- `SESSION_DOMAIN=.pssfp.net` → `.pssfp.org`
- `MAIL_USERNAME=noreply@pssfp.net` → `noreply@pssfp.org`
- `MAIL_FROM_ADDRESS=noreply@pssfp.net` → `noreply@pssfp.org`
- `MAIL_TO_CONTACT=contact@pssfp.net` → `contact@pssfp.org`
- `MAIL_TO_ADMISSIONS=admissions@pssfp.net` → `admissions@pssfp.org`
- `NEXT_PUBLIC_FOAD_URL=https://foad.pssfp.net` → `https://foad.pssfp.org`
- `SANCTUM_STATEFUL_DOMAINS=localhost:6001,localhost:6002,localhost:6003,pssfp.net,bibliotheque.pssfp.net,candidature.pssfp.net`
  → `…,pssfp.org,bibliotheque.pssfp.org,apply.pssfp.org`

### `backend/.env.example`
- `MAIL_FROM_ADDRESS="noreply@pssfp.net"` → `"noreply@pssfp.org"`

### `candidature/.env.production.example`
- En-tête commentaire ligne 2 : `candidature.pssfp.net` → `apply.pssfp.org`
- `NEXT_PUBLIC_API_URL=https://api.pssfp.net/v1` → `https://api.pssfp.org/v1`
- `NEXT_PUBLIC_MAIN_SITE_URL=https://pssfp.net` → `https://pssfp.org`
- Commentaire Turnstile ligne 17 : `candidature.pssfp.net` → `apply.pssfp.org`

### `frontend/.env.production.example`
- En-tête commentaire ligne 2 : `pssfp.net` → `pssfp.org`
- `NEXT_PUBLIC_API_URL` → `https://api.pssfp.org/v1`
- `NEXT_PUBLIC_SITE_URL` → `https://pssfp.org`
- `NEXT_PUBLIC_LIBRARY_URL` → `https://bibliotheque.pssfp.org`
- `NEXT_PUBLIC_CANDIDATURE_URL` → `https://apply.pssfp.org`
- `NEXT_PUBLIC_FOAD_URL` → `https://foad.pssfp.org`
- `NEXT_PUBLIC_MEDIA_URL` → `https://media.pssfp.org/pssfp-media`
- `NEXT_PUBLIC_PUBLIC_DOC_URL` → `https://media.pssfp.org/pssfp-documents`

### `frontend/.env.example` (commentaires + défaut FOAD)
- `NEXT_PUBLIC_FOAD_URL=https://foad.pssfp.net` → `.org`
- Commentaires mentionnant `media.pssfp.net` / « canonique … pssfp.net » → `.org`

---

## 3. Frontend — valeurs par défaut SEO (défauts durs, importants même si surchargés par l'env)

> Ces fichiers peuvent contenir du WIP non committé (branche home). **Ne changer que la chaîne de domaine sur la ligne indiquée**, ne pas toucher au reste.

- `frontend/app/layout.tsx:40` — `metadataBase: new URL('https://pssfp.net')` → `'https://pssfp.org'`
- `frontend/app/robots.ts:3` — `?? 'https://pssfp.net'` → `?? 'https://pssfp.org'`
- `frontend/app/sitemap.ts:5` — `?? 'https://pssfp.net'` → `?? 'https://pssfp.org'`
- `frontend/components/JsonLd/index.tsx:26` — `?? 'https://pssfp.net'` → `?? 'https://pssfp.org'`
- `frontend/app/(public)/plan-du-site/page.tsx:77` — `?? 'https://foad.pssfp.net'` → `foad.pssfp.org`
- `frontend/components/HomeAccessRapide/index.tsx:32` — `?? 'https://foad.pssfp.net'` → `foad.pssfp.org`
- `frontend/components/FoadSticky/index.tsx:12` — `?? 'https://foad.pssfp.net'` → `foad.pssfp.org`
- `frontend/components/SiteFooter/index.tsx:91` — `?? 'https://foad.pssfp.net'` → `foad.pssfp.org`

---

## 4. NE PAS toucher (garde-fous)

- ❌ `docs/**` en masse (172 occurrences documentaires) — **hors périmètre de ce sweep**. Ne pas éditer `docs/sprints/plan-mise-en-prod-v1.md` (en cours d'édition).
- ❌ Les composants candidature `candidature/components/**`, `candidature/app/**`, `candidature/messages/**` — **migration i18n en cours en parallèle**. Ici on ne touche QUE `candidature/*.env*.example`.
- ❌ `MAIL_HOST` / `mail.creawebhosting.org` (SMTP).
- ❌ Les `.env.local` locaux des développeurs.
- ❌ Ne pas renommer `bibliotheque` → `library`.
- ⚪ **Optionnel / follow-up séparé** : les `CLAUDE.md` (`/CLAUDE.md`, `backend/CLAUDE.md`, `candidature/CLAUDE.md`, `frontend/CLAUDE.md`) citent encore `pssfp.net` et `candidature.pssfp.net`. Utile de les corriger pour la mémoire projet, mais **dans un commit distinct** — pas dans ce sweep.

---

## 5. Vérification (obligatoire avant commit)

```bash
# 1. Plus aucune occurrence .net dans config/app/env-templates (hors docs, CLAUDE.md, mail.creawebhosting)
grep -rn "pssfp\.net" \
  backend/config backend/app frontend/app frontend/components \
  .env.example backend/.env.example frontend/.env.example \
  candidature/.env.production.example frontend/.env.production.example \
  2>/dev/null | grep -v "creawebhosting"
# → attendu : AUCUNE ligne (sortie vide)

# 2. Backend boote + style
cd backend
php artisan config:clear && php artisan route:list >/dev/null && echo "boot OK"
./vendor/bin/pint --test config app

# 3. Frontend + candidature : type-check + build de prod
cd ../frontend && pnpm exec tsc --noEmit && pnpm build
cd ../candidature && pnpm exec tsc --noEmit && pnpm build
```

Aucun test ne devrait casser (ce sont des défauts de config ; les tests utilisent `localhost`). Si un test CORS existe et référence `pssfp.net`, l'aligner sur `.org`.

---

## 6. Commit

```
chore(prod): bascule domaine canonique pssfp.net → pssfp.org (candidature = apply.pssfp.org)

- CORS, candidature_app_url, contact recipient → .org
- SESSION_DOMAIN, SANCTUM_STATEFUL_DOMAINS, MAIL_* → .org
- templates .env.production (front + candidature) → api/apply/site .org
- défauts SEO frontend (metadataBase, robots, sitemap, JSON-LD, FOAD) → .org

Redirections 301 des anciens domaines : à configurer côté Nginx/Cloudflare (LOT C infra).
CLAUDE.md + docs : follow-up séparé.
```
