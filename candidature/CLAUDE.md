# /candidature — formulaire candidature candidature.pssfp.net

> Lu automatiquement par Claude Code à chaque session ouverte dans ce dossier.

## Mission

App Next.js 14 servant le formulaire de candidature en ligne sur `candidature.pssfp.net`. Réécriture complète remplaçant l'ancien Joomla `candidature.pfinancespubliques.org`. Cf. `docs/specs/module-5-candidatures.md`.

**Décision Anatole 5 mai 2026** : bascule franche du Joomla vers cette app — pas de migration des candidatures en cours.

## Principes spécifiques candidatures

- **Sécurité maximale** : aucune fuite de PII (date naissance, adresse, téléphone) dans les logs. Les données candidat sont sensibles.
- **Auto-save** côté profil et dossier — debounce 2s, indicateur visuel « Enregistré il y a Xs ». Évite la perte de saisie.
- **Ownership strict** : un candidat ne peut accéder qu'à ses propres données. Vérification systématique côté API + côté Next.js.
- **Idempotency** sur la soumission finale — header `X-Idempotency-Key` pour éviter les double-soumissions sur retry réseau.
- **PDF récépissé** généré côté Laravel à la soumission (cf. spec §5). Frontend affiche un bouton de téléchargement.

## Structure des routes

```
app/
├── layout.tsx                    # Header sobre + footer
├── page.tsx                      # / : accueil campagne courante
├── conditions-admission/page.tsx
├── dossier-a-constituer/page.tsx
├── faq/page.tsx
├── contact/page.tsx
├── login/page.tsx
├── inscription/
│   ├── page.tsx
│   └── verification-email-envoyee/page.tsx
├── email-verifie/page.tsx
├── profil/page.tsx               # Auth requise
├── candidature/                  # Auth requise
│   ├── nouvelle/page.tsx         # Wizard 4 étapes
│   ├── [numero]/
│   │   ├── page.tsx              # Vue d'ensemble dossier
│   │   ├── pieces/page.tsx       # Upload pièces
│   │   ├── soumettre/page.tsx    # Récap final + soumission
│   │   └── suivi/page.tsx        # Statut + frise
│   └── frais/page.tsx            # Instructions paiement CCA
└── not-found.tsx
```

## Workflow candidat (rappel macro)

1. `/inscription` — création compte (email, mot de passe, prénom, nom, téléphone).
2. Email de vérification — clic lien → `/email-verifie` → redirection `/profil`.
3. `/profil` — complétion 5 sections (identité, coordonnées, parcours, activité, projet). Auto-save.
4. `/candidature/nouvelle` — wizard 4 étapes (campagne, vœux, projet, motivation). Création draft.
5. `/candidature/[numero]/pieces` — upload des 6 pièces obligatoires.
6. `/candidature/[numero]/soumettre` — récap + bouton soumission définitive. Génération récépissé PDF.
7. `/candidature/[numero]/suivi` — statut et frise chronologique.

## Composants spécifiques candidature

- `CandidatureHeader` — header sobre.
- `CampaignBanner` — bannière campagne avec compte à rebours.
- `RegistrationForm` (client) — formulaire inscription avec validation zod.
- `LoginForm` (client) — login.
- `ProfileForm` (client) — profil avec auto-save par section.
- `CandidatureWizard` (client) — wizard 4 étapes.
- `VoeuxPicker` (client) — sélecteur ordonné 3 spécialités.
- `PiecesJointesUploader` (client) — drag-drop multi-fichiers avec progress.
- `CandidatureSummary` (server) — récap lecture seule.
- `StatusTimeline` (server) — frise statuts.
- `WithdrawDialog` (client) — confirmation retrait.

## Validation

- Côté client : `react-hook-form` + `zod` pour validation immédiate. UX > tout.
- Côté serveur : Laravel `FormRequest` validation systématique. **Ne jamais faire confiance au client.**
- Antivirus ClamAV côté Laravel sur uploads — fichier rejeté si scan échoue.

## Auth flow

- Cookie httpOnly Secure SameSite=Lax émis par Laravel sur `Domain=.pssfp.net`.
- Helper `lib/auth/requireAuth.tsx` redirige vers `/login?redirect=...` si non authentifié.
- Helper `lib/auth/requireProfileComplete.tsx` redirige vers `/profil` si profil incomplet (utilisé sur `/candidature/*`).

## Performance cible

Lighthouse ≥ 85 sur les pages critiques (`/`, `/profil`, `/candidature/[numero]`, `/candidature/[numero]/pieces`).

## Tests

Playwright `tests/playwright/candidature-flow.spec.ts` couvre le parcours complet :

1. Inscription → vérification email (mock) → login.
2. Complétion profil avec auto-save vérifié.
3. Création candidature avec 3 vœux.
4. Upload 6 pièces.
5. Soumission → récépissé PDF téléchargé.
6. Consultation suivi.

## Variables d'env

```
NEXT_PUBLIC_API_URL=http://localhost:8000/v1
NEXT_PUBLIC_MAIN_SITE_URL=http://localhost:6000
NEXT_PUBLIC_TURNSTILE_SITE_KEY=...
NEXT_PUBLIC_SENTRY_DSN=...
```

## Sous-agent dédié

`.claude/agents/candidature-reviewer.md` — relit les diffs (PII, ownership, idempotency).
