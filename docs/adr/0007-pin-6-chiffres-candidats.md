# ADR-0007 — PIN 6 chiffres pour les candidats — UX prioritaire

**Statut** : Accepté
**Date** : 2026-05-05
**Décideur** : M. ABE ETOUMOU Anatole — Chef USI
**Référence** : ADR-0005 (politique de mots de passe globale), spec module 5 §M4

## Contexte

L'ADR-0005 acte une politique de mots de passe forte pour tous les rôles authentifiés du projet : minimum 12 caractères, complexité testée à l'inscription via zxcvbn (score ≥ 3), hashage Argon2id, recommandations NIST SP 800-63B 2024.

Cette politique est appropriée pour les rôles **admin, editor, librarian, admission_committee** (accès au CMS Filament avec données institutionnelles sensibles), pour les **enseignants** (dépôt de ressources pédagogiques, accès à des cours restreints), et pour les **auditeurs** (accès à des supports de cours).

Mais elle pose un **problème opérationnel sérieux pour le rôle candidat**. Trois facteurs convergent :

1. **Profil utilisateur** : les candidats au PSSFP sont souvent des cadres administratifs camerounais peu à l'aise avec les outils numériques. Le système actuel (PHP P13) utilise « numéro candidat + numéro de téléphone » comme couple login/password — décision UX héritée mais avec une excellente mémorisation côté utilisateur (« mon login c'est mon numéro »).

2. **Friction de saisie** : les candidats remplissent souvent le formulaire depuis un smartphone bas de gamme avec clavier tactile peu confortable. Saisir un mot de passe complexe de 12+ caractères avec majuscules, chiffres et symboles allonge significativement le temps d'inscription et augmente le taux d'abandon.

3. **Contexte d'usage** : un candidat se connecte typiquement 3-5 fois maximum sur toute la durée d'une campagne (création + soumission + 1-2 consultations + récupération récépissé). Le coût UX d'un mot de passe complexe est disproportionné par rapport au gain de sécurité sur ces données peu critiques.

À l'inverse, les comptes candidat ne donnent accès qu'à **leurs propres** données candidature (pas de données institutionnelles, pas de données d'autres candidats, pas de cours, pas de PII tierce). Le périmètre d'exposition en cas de compromission d'un compte est strictement limité au dossier du candidat compromis.

## Décision

**Dérogation explicite à l'ADR-0005 pour le rôle `candidat` uniquement** :

- **Login** = numéro de téléphone E.164 du candidat (`+237691234567`). Identifiant unique, mémorisable.
- **Password** = **PIN à 6 chiffres** choisi par le candidat à l'inscription. Pas de zxcvbn, pas de complexité requise, format `[0-9]{6}` exact.
- **Hashage** : Argon2id (défaut Laravel 11) pour le stockage. **Pas de stockage en clair**, pas de bcrypt seul.
- **Récupération** si oubli : OTP SMS sur le numéro de téléphone enregistré, code à 6 chiffres valable 10 minutes, livré via passerelle Africa's Talking (cf. spec module 5 §M13).

Cette dérogation s'applique **uniquement** au rôle `candidat`. Les autres rôles (`admin`, `editor`, `librarian`, `admission_committee`, `teacher`, `auditor`) restent strictement soumis à la politique forte d'ADR-0005.

### Mesures compensatoires de sécurité

Pour neutraliser le risque accru lié au PIN court (10⁶ = 1 million de combinaisons, vs ~10²⁰ pour un password 12 caractères avec entropie moyenne), trois protections obligatoires sont mises en place :

1. **Rate limiting agressif** sur l'endpoint `POST /v1/auth/login` côté candidat : maximum **3 tentatives par 10 minutes par IP**, **lockout de 30 minutes** après 10 échecs sur le même compte, **ban IP** après 50 échecs cumulés sur 24h. Ces seuils sont plus stricts que pour les autres rôles (ADR-0005 prévoyait 5 tentatives / 10 min IP).

2. **Détection comportementale** : alerte Sentry automatique en cas de pattern suspect — multiple comptes candidats attaqués depuis la même IP, distribution anormale des PIN tentés, etc. Le Chef USI reçoit notification email pour intervention manuelle si seuil critique franchi.

3. **Périmètre minimal des tokens candidats** : les Sanctum personal access tokens émis pour le rôle candidat portent uniquement les abilities `application:create`, `application:read`, `profile:read`, `profile:write`. Aucune ability transverse, aucun accès aux données d'autres candidats, aucun accès biblio ou pages institutionnelles.

### Politique de PIN renforcée à la création

Pour limiter les PIN triviaux choisis par défaut, le formulaire d'inscription **rejette explicitement** :

- Suites évidentes : `123456`, `654321`, `111111`, `000000`, `123123`, `121212`, etc.
- Le PIN identique aux **6 derniers chiffres du numéro de téléphone** du candidat.
- Le PIN identique à la **date de naissance** au format DDMMYY ou YYMMDD.

Un dictionnaire des ~50 PIN les plus courants est embarqué côté backend (statistiques publiques Have I Been Pwned). Validation à l'inscription via FormRequest Laravel.

## Conséquences

### Positives

UX significativement améliorée pour le public cible : un PIN à 6 chiffres est trivial à mémoriser et à saisir sur un clavier tactile, particulièrement comparé à un mot de passe 12 caractères mixtes. Aligne le projet sur les pratiques mobile-first africaines (MTN MoMo, Orange Money, applications bancaires locales utilisent toutes des PIN à 4-6 chiffres). Réduit le taux d'abandon à l'inscription estimé empiriquement à 15-30 % pour les publics peu numériques face à un mot de passe complexe imposé. Le coût opérationnel des récupérations de mot de passe baisse fortement (un PIN s'oublie moins qu'un mot de passe rarement utilisé).

Conserve la séparation cookies/tokens d'ADR-0005 : un compte candidat compromis ne donne aucun accès aux panneaux Filament admin.

### Négatives ou trade-offs

L'entropie d'un PIN à 6 chiffres (~20 bits) est dramatiquement inférieure à celle d'un mot de passe 12 caractères mixtes (~78 bits). Une attaque brute force non rate-limitée le casse en quelques minutes. **Mitigations** : rate limit 3/10 min, lockout 30 min, ban IP, dictionnaire des PIN courants — détaillés ci-dessus. La défense ne repose donc pas sur la complexité du secret mais sur les contre-mesures opérationnelles. Cette stratégie est **acceptable** pour le périmètre candidat (faible enjeu, fort besoin UX) mais **n'est pas généralisable** aux autres rôles.

Risque de PIN devinés via ingénierie sociale (un proche connaissant la date de naissance pourrait tenter `010590` etc.). **Mitigation** : politique de rejet à la création (cf. ci-dessus). Mais ne couvre pas tous les cas — un comportement utilisateur qui choisit son PIN bancaire MoMo restera fragile au social engineering. Compromis acceptable étant donné le faible enjeu des données.

### Neutres ou à surveiller

Surveiller les statistiques de tentatives login candidats sur les 6 premiers mois post-lancement : si le taux de PIN devinés ou de comptes compromis dépasse 0,5 %, réévaluer cet ADR et envisager un passage à PIN 8 chiffres ou activation OTP SMS systématique au login (pas seulement à la récupération).

Surveiller les statistiques de récupération de PIN : si > 30 % des candidats utilisent l'OTP SMS pour se reconnecter, le coût SMS devient significatif (~10 000 FCFA / campagne devient ~30 000 FCFA). Acceptable mais à budgétiser.

Si Phase II ajoute un module de paiement biblio ouvert aux candidats, **revoir cette décision** : un compte payant doit avoir une auth plus robuste, idéalement passage à mot de passe complexe + 2FA TOTP optionnel.

## Alternatives envisagées

**Alternative A — Politique forte ADR-0005 stricte (12 caractères + zxcvbn).** Rejetée : friction UX prohibitive pour le public cible, taux d'abandon estimé > 20 %, désaligné des usages mobile africains.

**Alternative B — Magic links email seul (pas de mot de passe).** Rejetée par l'ADR-0005 alternative E le 5 mai 2026 — double chemin d'auth à coder, formation utilisateur plus complexe.

**Alternative C — OTP SMS systématique (pas de PIN du tout, juste un code unique à chaque login).** Tentante en termes UX. Rejetée : coût SMS récurrent (200 candidats × 5 sessions × 25 FCFA ≈ 25 000 FCFA / campagne, plus complexe à monitorer), dépendance forte à la qualité du réseau SMS camerounais (latence ou échec d'envoi = candidat bloqué), fragile en cas de changement de numéro de téléphone du candidat. À reconsidérer Phase II si la passerelle SMS est éprouvée et le coût acceptable.

**Alternative D — PIN à 4 chiffres au lieu de 6.** Rejetée : entropie trop faible (10⁴ = 10 000 combinaisons, à la portée d'un brute force même avec rate limiting modeste). 6 chiffres est le standard MTN MoMo / Orange Money — familier au public.

**Alternative E — PIN 6 chiffres + 2FA SMS au login.** Rejetée pour V1 : double friction (saisir PIN puis attendre SMS), coût SMS doublé, complexité d'orchestration. À réévaluer Phase II si volumétrie le justifie.

**Alternative F — Numéro candidat + numéro de téléphone (système actuel P13).** Rejetée : tout proche qui connaît le numéro de téléphone d'un candidat peut s'authentifier. Faille triviale.

## Plan d'implémentation

Côté backend Laravel (à coder lors du Sprint B5 + dev module 5) :

1. Migration `users.password` : permettre Argon2id pour PIN candidat avec marqueur de rôle.
2. FormRequest `RegisterCandidatRequest` : validation `pin` regex `[0-9]{6}` + dictionnaire interdit.
3. Middleware `RateLimiter` candidat strict : 3/10 min, lockout 30 min, ban IP 50/24h.
4. Service `CandidatPinService` : `validate(string $pin, User $user): bool` qui applique les règles métier (pas la date de naissance, pas les 6 derniers chiffres du téléphone, pas dans le dictionnaire).
5. Endpoint `POST /v1/auth/candidat/login` distinct de `POST /v1/auth/login` standard, avec ses propres règles rate limit.
6. Endpoint `POST /v1/auth/candidat/forgot-pin` qui déclenche l'envoi OTP SMS via Africa's Talking.
7. Endpoint `POST /v1/auth/candidat/reset-pin` qui valide l'OTP et permet le reset.

Côté frontend Next.js `candidature/` (à coder lors du dev module 5) :

1. Composant `PinInput` (6 cases pour 6 chiffres, focus auto).
2. Composant `PhoneInput` E.164 avec validation locale.
3. Page `/login` minimale : phone + pin.
4. Page `/forgot-pin` : phone → SMS sent → enter OTP → choose new PIN.
5. Lors de l'inscription : étape « Choisir un PIN à 6 chiffres pour vous reconnecter ».

## Références

- ADR-0005 (politique de mots de passe forte — politique principale)
- Spec module 5 candidatures §M4 et §M13
- NIST SP 800-63B (recommandations modernes mots de passe)
- OWASP Authentication Cheat Sheet 2024 — section sur les PIN courts et compensations
- Have I Been Pwned API (statistiques PIN courants)
- Africa's Talking SMS API documentation
- Pattern MTN MoMo / Orange Money (référence UX mobile africaine)
