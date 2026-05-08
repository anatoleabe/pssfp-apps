# Spec — Module 5 — Candidatures (rapatrié Phase I)

> **Référence** : Sprint Specs A12
> **Statut** : v1.1 — révisée après analyse du système PHP existant (candidature_p13.zip)
> **Date** : 2026-05-05
> **Décision** : Anatole 5 mai 2026 — réécriture complète Phase I (initialement Phase II au CDC v5)
> **Source CDC** : §8.2 Module 5 (qui prévoyait Phase II) — désormais Phase I
> **Source code existant** : `candidature_p13.zip` analysé dans `docs/migration-candidature/analyse-candidature-php.md`

## Mises à jour v1.1 — alignement sur le système existant (5 mai 2026)

Après analyse du code PHP de la promotion 13 (`candidature_p13.zip`), la spec est **simplifiée** sur plusieurs points pour s'aligner sur le besoin opérationnel réel observé. Ces décisions **prévalent** sur les sections détaillées ci-dessous.

**M1 — 1 seul vœu de spécialité** (au lieu de 3 ordonnés). Le système actuel n'utilise qu'un seul vœu et le comité d'admission gère les réorientations manuellement. **Conséquence** : le wizard étape 2 demande uniquement `specialite` (single select) + un champ texte court optionnel « second choix éventuel ».

**M2 — Pièces jointes en ligne minimales**. Seule la **photo identité** est obligatoire en ligne. Le **CV PDF** et le **diplôme PDF** sont optionnels. Toutes les autres pièces (lettre, pièce d'identité, relevés de notes) sont apportées en papier au dépôt physique au PSSFP. Aligné sur la réalité culturelle camerounaise. **Conséquence** : `/candidature/[numero]/pieces` simplifié à 3 zones d'upload, dont 2 optionnelles.

**M3 — Statuts métier simplifiés**. 4 statuts au total :

- `postulant` (par défaut, juste inscrit en ligne)
- `candidat` (a déposé physiquement le dossier au PSSFP)
- `accepte` (admis par le comité)
- `refuse` (rejeté par le comité)

Plus un champ `withdrawn_at` (timestamp NULL) pour les retraits volontaires.

**M4 — Auth ultra-simple (décision Anatole 5 mai 2026 — D3)**. Public cible peu à l'aise avec l'IT, friction d'auth à minimiser absolument. Choix actés :

- **Login** = **numéro de téléphone E.164 complet** (ex: `+237691234567`) — c'est aussi l'identifiant principal du candidat dans le système (D1).
- **Password** = **PIN à 6 chiffres** choisi par le candidat à l'inscription (mémorisable, pas de complexité à retenir).
- **Récupération si oubli** = **OTP SMS** sur le numéro enregistré (envoyé via passerelle SMS — voir M13).
- **Pas de 2FA** pour les candidats.
- **Pas de mot de passe complexe** : le PIN 6 chiffres suffit. Le risque de bruteforce est mitigé par : rate limit 3 tentatives/10 min, lockout 30 min après 10 échecs, ban IP après 50 échecs cumulés.

Cette simplification déroge volontairement à ADR-0005 sur la politique de mots de passe (qui imposait 12 caractères + zxcvbn ≥ 3 pour tous les rôles authentifiés). **L'exception est documentée dans ADR-0007 à créer** : « PIN 6 chiffres pour les candidats — UX prioritaire ». Les rôles admin/editor/librarian/admission_committee restent eux soumis à la politique forte.

**M5 — Format double identifiant (décision Anatole 5 mai 2026 — D1)** :

- **Identifiant utilisateur** (login + URLs publiques) : `phone_e164` du candidat, ex: `+237691234567`. Unique par candidat.
- **Numéro de dossier interne** (référence administrative + PDF récépissé + dashboard) : conservé au format `P{N}{YY}-{ID}` où N = numéro de promotion, YY = année à 2 chiffres, ID = auto-increment. Pour P14/2026 : `P14026-001`, `P14026-002`, etc. Affiché sur le PDF, le dashboard admin, et utilisé en interne pour le tracking physique du dossier.

Ce double identifiant donne :

- **UX candidat** : « Mon login c'est mon numéro de téléphone, c'est facile à retenir ».
- **Tracking PSSFP** : « Le dossier P14026-042 est sur ton bureau ? » — référence courte et claire pour les agents.

Champs côté BDD :

```
candidatures.phone_e164      TEXT NOT NULL UNIQUE  -- login + identifiant public
candidatures.numero_dossier  TEXT NOT NULL UNIQUE  -- P14026-XXX, généré atomique
candidatures.phone_country   TEXT NOT NULL          -- 'CM', 'FR', etc. (ISO-2)
```

Génération du `numero_dossier` atomique via `pg_advisory_xact_lock` ou séquence PostgreSQL dédiée par campagne.

Dans les **URLs publiques** côté frontend : utiliser le `phone_e164` est risqué (PII visible dans la barre d'adresse, les logs Nginx, l'historique navigateur). **Décision** : exposer un **UUID public** distinct dans les URLs candidat (`/c/{uuid}`), masquant à la fois le téléphone et le numéro de dossier dans la barre d'adresse. Le téléphone reste utilisé uniquement à l'écran de connexion.

Schéma final :

```
candidatures.uuid            UUID UNIQUE  -- exposé en URL publique
candidatures.phone_e164      TEXT NOT NULL UNIQUE  -- login (jamais en URL)
candidatures.numero_dossier  TEXT NOT NULL UNIQUE  -- référence admin + PDF
```

**M13 — Passerelle SMS pour OTP**. Pour la récupération de PIN via OTP, choix de la passerelle :

- **Africa's Talking** (https://africastalking.com) — agrégateur africain reconnu, support MTN + Orange Cameroun, environ 15-25 FCFA par SMS, API REST simple. **Recommandé V1**.
- **MTN SMS API direct** — moins cher mais nécessite un contrat MTN dédié, plus long à mettre en place.
- **Orange SMS API** — idem.
- **Twilio** — global, plus cher (~50 FCFA / SMS au Cameroun).

Volume estimé V1 : ~200 candidats × ~2 SMS / candidat (PIN définition + 1 récupération moyenne) = ~400 SMS/campagne, soit ~10 000 FCFA/campagne. Coût négligeable.

**Implémentation Laravel** : package `africastalking/africastalking-sdk-php` ou intégration HTTP directe via `Http::post()`. Variables d'env :

```
SMS_PROVIDER=africas_talking
AFRICAS_TALKING_USERNAME=__a_definir__
AFRICAS_TALKING_API_KEY=__a_definir__
AFRICAS_TALKING_SENDER_ID=PSSFP
```

À créer côté Anatole : compte Africa's Talking, achat de crédit initial (~100 USD pour démarrer = ~10 000 SMS).

**Fallback V1 sans SMS** : si la passerelle SMS n'est pas prête au lancement, désactiver temporairement la récupération automatique — un candidat qui oublie son PIN appelle ou se rend au PSSFP qui le réinitialise via Filament. Acceptable pour V1 vu le volume bas.

**M6 — Champs candidat alignés sur le système réel**. Ajout de :

- `epouse` (nom de jeune fille pour les femmes mariées)
- `premiere_langue` (`fr` / `en`)
- `type_etude` (`presentiel` / `distanciel`)
- `engagement_nom` (signature numérique = nom complet tapé)
- `moyen_connaissance` (marketing)
- `region`, `departement` (cascading Cameroun avec FK référentiels)
- `mode_paiement` (rempli par admin lors du dépôt physique)
- `pays_origine`, `pays_residence` (codes ISO-2)
- `indicatif1`, `indicatif2` (avec téléphones)
- `statut_actuel` (`Etudiant` / `Fonctionnaire-Contractuel` / `Privé`)
- `employeur`, `adresse_employeur`, `tel_employeur`

Suppression de :

- `voeu_2_specialite_id`, `voeu_3_specialite_id` (M1)
- `motivation`, `projet_professionnel` longs (rendus optionnels en V1, courts si présents)
- Table `candidate_profiles` séparée → tout dans `candidatures` (modèle plat plus simple)

**M7 — Dashboard avec quotas régionaux**. Ajouter un widget Filament dans `CandidatureResource` qui affiche les 11 régions camerounaises avec leur quota cible (`5%` Adamaoua, `15%` Centre, `18%` Extrême-Nord, etc., cf. table `regions_cameroun` seedée depuis le dump existant) et le nombre actuel de candidats par région. Couleurs vert/orange/rouge selon écart au quota. Critère de sélection important pour le comité.

**M8 — PDF récépissé avec QR code et filigrane**. PDF généré à la soumission avec :

- Page « Copie Étudiant » (filigrane discret)
- Page « Copie Administration » (filigrane « DOCUMENT ADMINISTRATIF »)
- QR code en pied de page pointant vers `https://candidature.pssfp.net/c/{numero}/qr` (page récap candidat) — permet aux agents PSSFP de scanner au dépôt physique pour vérification rapide.
- Logo PSSFP + entête institutionnel (assets repris de `docs/migration-candidature/`)
- Hash SHA256 du contenu imprimé en bas pour traçabilité.

Implémentation : `barryvdh/laravel-dompdf` + `simplesoftwareio/simple-qrcode`.

**M9 — Tables référentielles seedées**. Trois tables alimentées depuis le dump SQL existant :

- `pays` (200+ entrées avec code ISO-2 + indicatif téléphone)
- `regions_cameroun` (11 régions avec quota d'admission)
- `departements_cameroun` (58 départements avec FK région et chef-lieu)

Cascading dropdowns sur le formulaire : Pays → si CM, Région → Département.

**M10 — Pas de migration des candidatures P13**. Décision Anatole 5 mai 2026, bascule franche : la campagne P14 (2026) démarre directement sur le nouveau formulaire `candidature.pssfp.net`. Aucune migration des candidats du système PHP P13.

**M11 — Email confirmation à finaliser**. Le système actuel a `envoyer_mail.php` avec PHPMailer mais SMTP placeholder, marqué TODO. À implémenter dans la V1 via le SMTP creawebhosting déjà configuré (cf. spec A10).

**M12 — Effort réduit**. Avec ces simplifications, l'estimation V1 du module 5 passe de ~6-7 jours à **~3-4 jours** de développement (backend + frontend). Tasks de découpage à reprendre dans `docs/specs/module-5-candidatures.md` §11 ci-dessous (déjà compatibles avec ces décisions).

Ce document spécifie la refonte intégrale du module de candidature en ligne du PSSFP, qui remplace l'ancien formulaire Joomla `candidature.pfinancespubliques.org`. Le module couvre l'expérience candidat (inscription, profil, dossier, soumission, suivi) et l'interface administration (comité d'admission via Filament, processus d'examen et de décision).

## 1. Architecture

App Next.js dédiée `candidature/` du mono-repo, séparée de `frontend/` et `library/`. Domaine production : `candidature.pssfp.net` (sous-domaine dédié). Partage du design system via `@pssfp/ui`.

L'app consomme les endpoints `/v1/applications/*` de l'API Laravel (cf. api-contract.md §9). Auth via Sanctum tokens scoped (`application:create`, `application:read`, `profile:read`, `profile:write`).

Stratégie de rendu :

- **SSR** par défaut pour toutes les pages personnalisées (espace candidat).
- **SSG** pour les pages publiques (présentation campagne, FAQ).

Migration Joomla → Next.js : redirections 301 systématiques de toutes les anciennes URLs `/index.php?option=com_...` vers les nouvelles routes équivalentes.

## 2. Parcours candidat — vue macro

Six étapes successives, chacune une page distincte :

1. **Découverte** — `/` : page d'accueil de la campagne, bouton « Candidater maintenant ».
2. **Inscription** — `/inscription` : création du compte (email + mot de passe + nom + prénom + téléphone). Email de vérification envoyé. Pas de saisie du dossier à cette étape.
3. **Vérification email** — clic sur le lien reçu, retour `/email-verifie` puis redirection vers `/profil`.
4. **Complétion du profil candidat** — `/profil` : informations détaillées (état civil, parcours, projet pro). Nécessaire avant de pouvoir créer une candidature.
5. **Création et complétion du dossier** — `/candidature/nouvelle` puis `/candidature/[numero]` pour l'édition itérative : choix des 3 vœux de spécialités, projet professionnel rédigé, motivation, upload des pièces.
6. **Soumission et suivi** — bascule du statut `draft` à `submitted`, génération du PDF de récépissé, suivi du dossier sur `/candidature/[numero]/suivi`.

Le candidat peut ensuite revenir consulter le statut, et selon la décision, recevoir notification par email d'acceptation, refus, ou demande de complément.

## 3. Pages publiques

### `/`

Accueil de l'app candidature. Présente la campagne courante (lue depuis `/v1/applications/campaigns` filtrée sur `is_currently_open: true`).

Structure :

- Hero : « Candidature 2026 — Promotion 14 », date d'ouverture/clôture, jauge de progression du temps restant.
- Section « Pourquoi le PSSFP » : 4 raisons (excellence académique, partenariats internationaux, débouchés, FOAD).
- Section « Calendrier » : ouverture, clôture, résultats.
- Section « Ce que vous devez fournir » : checklist des pièces requises.
- Section « Frais de dossier » : 25 000 FCFA payables en agence CCA, instructions claires.
- CTA principal : « Créer mon compte candidat » (vers `/inscription`).
- Lien « J'ai déjà un compte » (vers `/login`).

Si **aucune campagne ouverte** au moment de la visite : message d'attente avec encart de capture email pour notification d'ouverture future.

### `/conditions-admission`

Conditions formelles : niveau diplôme, public cible, critères de sélection. Reprend le contenu de `/formations/admission` du site principal en version concise.

### `/dossier-a-constituer`

Détail des pièces à fournir avec exemples et formats acceptés. Permet au candidat de préparer son dossier avant inscription.

### `/faq`

Questions fréquentes : « Puis-je modifier mon dossier après soumission ? », « Combien de temps pour recevoir une réponse ? », « Comment payer les frais à CCA ? », etc.

### `/contact`

Formulaire de contact dédié au comité d'admission, distinct du formulaire principal pssfp.net. Endpoint `/v1/contact` avec destinataire override `admissions@pssfp.net` (à créer côté creawebhosting).

## 4. Pages authentifiées (espace candidat)

### `/login`

Formulaire login email + password. 2FA optionnel — pas obligatoire pour les candidats vu que l'enjeu de sécurité est plus faible que pour les rôles admin (et la friction est trop forte pour des candidats âgés).

### `/inscription`

Formulaire création compte : email, mot de passe (min 12 caractères, complexité validée), confirmation mot de passe, prénom, nom, téléphone (format E.164). CGU à accepter.

À la soumission : compte créé statut `email_verified_at = NULL`, email de vérification envoyé. Redirection vers `/inscription/verification-email-envoyee` qui dit « Vérifiez votre email pour activer votre compte ».

### `/email-verifie`

Atterrissage post-clic sur lien email. Active `email_verified_at`. Redirection automatique vers `/profil`.

### `/profil`

**Page critique** — sans profil complet, le candidat ne peut pas soumettre de candidature.

Endpoint : `GET /v1/applications/profile` et `PUT /v1/applications/profile`.

Formulaire multi-sections (accordéon ou onglets) :

**Section A — Identité** :

- Civilité (M./Mme/autre)
- Prénom (modifiable)
- Nom (modifiable)
- Date de naissance (date picker, validation > 18 ans)
- Lieu de naissance (texte)
- Nationalité (select pays ISO)
- Genre (M/F/autre)
- Situation familiale (célibataire / marié / autre)

**Section B — Coordonnées** :

- Pays de résidence
- Ville
- Adresse postale complète
- Téléphone (déjà fourni à l'inscription, modifiable)

**Section C — Parcours académique** :

- Diplôme le plus élevé (Licence / Master / Doctorat / autre)
- Établissement de délivrance
- Année d'obtention
- Mention obtenue (passable / assez bien / bien / très bien)
- Spécialisation académique

**Section D — Activité professionnelle** :

- Profession actuelle
- Employeur
- Ancienneté (en années)
- Description courte des fonctions (textarea)

**Section E — Projet de formation** :

- Pourquoi le PSSFP (textarea 200-500 mots)
- Objectif professionnel après formation (textarea 200-500 mots)

Sauvegarde : auto-save sur changement de champ avec debounce 2s — évite la perte de saisie. Indicateur visuel « Enregistré il y a 5 secondes ».

Validation : tous les champs obligatoires sauf indication contraire. Le bouton « Continuer vers la candidature » n'apparaît qu'une fois le profil complet (`is_complete: true` retourné par l'API).

### `/candidature/nouvelle`

Wizard de création de candidature (statut = draft).

**Étape 1 — Choix de la campagne** : si plusieurs campagnes ouvertes (rare), sélection. Sinon, présélectionnée.

**Étape 2 — Vœux de spécialité** : sélection ordonnée de 3 spécialités parmi les 5, classées par préférence. Chaque vœu inclut un texte court « Pourquoi cette spécialité » (50-150 mots).

**Étape 3 — Projet professionnel** : textarea longue (500-1500 mots) sur les ambitions post-formation, le lien avec la spécialité choisie en vœu 1.

**Étape 4 — Motivation générale** : textarea (300-800 mots) qui complète la motivation du profil candidat.

À la soumission de l'étape 4 : création de la candidature avec `numero` généré (format `C-2026-0042`), statut `draft`, redirection vers `/candidature/{numero}` pour la suite.

### `/candidature/[numero]`

Vue d'ensemble de la candidature en cours (statut `draft` ou `complement_requested`).

Layout :

- Header : numéro candidature, statut badge, campagne, date de création.
- 5 cartes de progression :
  1. **Profil candidat** : OK (vert) ou À compléter (orange).
  2. **Vœux et projet** : OK ou À compléter.
  3. **Pièces jointes** : X/Y livrées (orange si incomplet).
  4. **Frais de dossier** : payé / non payé (informatif, ne bloque pas la soumission en V1).
  5. **Soumission** : disponible quand 1+2+3 sont OK.
- Section « Pièces jointes » : pour chaque type requis par la campagne, statut (déposé / manquant) et bouton upload ou consultation/suppression.
- CTA principal : « Soumettre ma candidature » (désactivé si éléments manquants).

### `/candidature/[numero]/pieces`

Page d'upload des pièces jointes. Endpoints `/v1/applications/{numero}/documents`.

Pour chaque type requis (CV, diplôme, lettre, photo, pièce d'identité, relevés) :

- Si non déposé : zone drag-and-drop ou bouton fichier. Limite 10 MB, types acceptés PDF/JPG/PNG.
- Si déposé : nom du fichier, taille, lien preview, bouton « Remplacer », bouton « Supprimer » (uniquement si statut `draft` ou `complement_requested`).

Upload progressif avec barre de progression. Validation côté serveur : type MIME, taille, scan antivirus ClamAV (cf. spec A10). Si scan échoue : suppression immédiate + message d'erreur explicite.

### `/candidature/[numero]/soumettre`

Page de récap final avant soumission.

Affichage en lecture seule : profil, vœux, projet, motivation, pièces jointes. Vérification serveur — si tout OK, bouton « Soumettre définitivement ma candidature » avec confirmation modale (« Êtes-vous sûr ? Vous ne pourrez plus modifier votre dossier après soumission. »).

Au clic sur Soumettre :

1. Endpoint `POST /v1/applications/{numero}/submit`.
2. Backend Laravel valide à nouveau toutes les règles côté serveur (idempotency).
3. Bascule statut `draft` → `submitted`, alimente `submitted_at`.
4. Génère le PDF de récépissé via `barryvdh/laravel-dompdf` ou similaire — template `resources/views/pdf/candidature-recipisse.blade.php`.
5. Stocke le PDF dans MinIO bucket `pssfp-candidatures`, met à jour `recipisse_pdf_id` sur la candidature.
6. Envoie email de confirmation au candidat avec lien de téléchargement du récépissé et instructions paiement frais CCA.
7. Notifie le comité d'admission par email (résumé du dossier + lien vers Filament).
8. Redirige le candidat vers `/candidature/{numero}/suivi`.

### `/candidature/[numero]/suivi`

Page de suivi après soumission. Lecture seule.

Affichage :

- Statut courant (badge couleur).
- Frise chronologique des étapes : soumis → examiné → décision.
- Pour chaque étape franchie : date, message éventuel.
- Pour le statut `complement_requested` : encart en évidence indiquant ce que le comité demande, avec lien vers `/candidature/{numero}` pour modifier.
- Pour le statut `accepted` : message de félicitations, instructions de suite (paiement scolarité, calendrier rentrée).
- Pour le statut `rejected` : message respectueux avec motif si fourni par le comité.
- Bouton « Télécharger récépissé PDF ».
- Bouton « Retirer ma candidature » (uniquement si statut < `accepted`/`rejected`).

## 5. PDF récépissé de dépôt

Template `resources/views/pdf/candidature-recipisse.blade.php` :

```
[Logo PSSFP]                                        Numéro de dossier
                                                    C-2026-0042

RÉCÉPISSÉ DE DÉPÔT DE CANDIDATURE

Programme Supérieur de Spécialisation en Finances Publiques
Campus de Messa, Yaoundé — Cameroun

Campagne 2026 — Promotion 14

Candidat : [Civilité] [Prénom] [NOM]
Email : [email]
Date de soumission : [date]

Vœux exprimés :
1. [Spécialité 1]
2. [Spécialité 2]
3. [Spécialité 3]

Pièces fournies : [N]/[Y]

Pour valider votre candidature, veuillez vous acquitter
des frais de dossier de 25 000 FCFA en agence Crédit Communautaire
d'Afrique (CCA), en mentionnant le numéro de dossier ci-dessus.

[Tampon numérique PSSFP]

Document généré automatiquement le [datetime].
```

Format A4 portrait, identité visuelle PSSFP (logo violet, polices Inter), signature numérique optionnelle (hash SHA256 imprimé en bas pour traçabilité).

## 6. Notifications email

Tous les changements de statut déclenchent un email automatique au candidat (cf. spec A10). Templates :

| Événement | Destinataire | Template |
|---|---|---|
| Inscription compte | Candidat | `auth/email-verification.blade.php` |
| Vérification email réussie | (pas d'email — affichage UI) | — |
| Création profil candidat | Candidat | `applications/profile-required.blade.php` (rappel sous 7j si inactif) |
| Soumission candidature | Candidat | `applications/submitted-confirmation.blade.php` |
| Soumission candidature | Comité d'admission | `applications/admin-new-submission.blade.php` |
| Marquage frais payés | Candidat | `applications/payment-received.blade.php` |
| Demande complément | Candidat | `applications/complement-requested.blade.php` |
| Acceptation | Candidat | `applications/decision-accepted.blade.php` |
| Refus | Candidat | `applications/decision-rejected.blade.php` |
| Retrait par candidat | Candidat (confirmation) + comité | `applications/withdrawn.blade.php` |

Tous les emails portent l'identité visuelle PSSFP, sont en français, contiennent un lien direct vers la page de suivi `/candidature/[numero]/suivi`.

## 7. Workflow d'examen côté admin (Filament)

Couvert par la spec module 6 §3.6 (Filament `CandidatureResource`). Récap des actions :

1. **Réception** : nouveau dossier soumis arrive avec statut `submitted`. Email automatique au comité.
2. **Marquage frais payés** : quand le candidat passe à CCA, le bibliothécaire ou un admin saisit dans Filament le mode `cca_agence`, la référence du récépissé bancaire, et la date. `frais_paye` calculé automatiquement.
3. **Examen** : un membre du comité ouvre le dossier dans Filament, consulte les pièces, lit le projet, le motivation. Peut écrire des commentaires internes. Bouton « Examiner » bascule à `under_review`.
4. **Demande complément** : si pièce manquante ou information incomplète, action « Demander complément » avec textarea du motif. Bascule à `complement_requested`. Email automatique au candidat. Le candidat peut alors modifier son dossier et le re-soumettre.
5. **Décision** : action « Accepter » ou « Refuser » (avec motif obligatoire pour refus). Bascule au statut final. Email automatique au candidat.
6. **Suivi pour le COPIL** : page Filament dédiée affichant les statistiques de la campagne en temps réel — nombre soumis / examiné / accepté / refusé, taux de conversion, vœux les plus demandés, taux frais payés.

Tous les changements sont tracés dans `candidature_status_history` (audit trail) et `activity_log` (Spatie).

## 8. Frais de candidature 25 000 FCFA

Payés en agence CCA, pas de paiement en ligne en V1.

Procédure pour le candidat :

1. Soumet sa candidature en ligne.
2. Reçoit email de confirmation avec son numéro `C-2026-XXXX`.
3. Se rend dans une agence CCA avec son numéro.
4. Paie 25 000 FCFA en espèces ou par virement.
5. Reçoit un récépissé bancaire CCA.
6. Le récépissé est validé par UAAF qui marque manuellement `frais_paye` dans Filament avec la référence du récépissé.

Le frais payé n'est **pas bloquant** pour l'examen — un dossier peut être examiné avant paiement, mais l'admission finale en cas d'acceptation suppose que les frais soient payés.

À documenter dans une page `/candidature/frais` claire avec instructions étape par étape, photo de la fiche de versement type CCA, contact UAAF en cas de problème.

## 9. Migration depuis l'ancien formulaire Joomla

`candidature.pfinancespubliques.org` est un formulaire Joomla qui doit être :

1. **Décommissionné** dès que le nouveau formulaire est en ligne.
2. **Redirigé** : toutes les URLs Joomla `/index.php?option=com_xxx&view=yyy` redirigées 301 vers la page d'accueil candidature.pssfp.net.
3. **Données existantes** : la migration des candidatures soumises sur l'ancien formulaire est **hors périmètre — décision Anatole 5 mai 2026, bascule franche**. Tous les candidats utilisent le nouveau formulaire `candidature.pssfp.net`. Les dossiers en cours sur Joomla ne sont **pas** migrés. Communication à prévoir auprès des candidats existants si applicable, via Facebook officiel et email aux candidats déjà inscrits sur Joomla (extraction simple depuis la BDD Joomla pour récupérer les emails).

## 10. Critères d'acceptation Module 5

- Inscription, vérification email, complétion profil, création candidature, upload pièces, soumission, suivi — bout-en-bout testé avec un candidat fictif.
- PDF récépissé généré avec toutes les informations correctes, mise en page propre A4.
- 11 templates email envoyés et reçus (test boîtes Gmail + Outlook + Yahoo).
- Workflow Filament côté comité : examen, demande complément, accept/reject testé avec 2 dossiers de test.
- Statistiques en temps réel sur dashboard Filament campagne fonctionnelles.
- Marquage frais payés fonctionne, met à jour `frais_paye` correctement.
- Antivirus ClamAV bloque un fichier EICAR test à l'upload.
- Lighthouse ≥ 85 sur les pages critiques (`/`, `/profil`, `/candidature/[numero]`, `/candidature/[numero]/pieces`).
- Auto-save profil fonctionne (test : remplir un champ, attendre 3s, recharger la page, valeur conservée).
- Redirections 301 depuis Joomla testées.
- Conformité RGPD : politique de confidentialité accessible, consentement explicite à la soumission, droit à la portabilité (export JSON du dossier candidat).
- Tests Playwright : `tests/playwright/candidature-flow.spec.ts` couvre le parcours candidat complet.
- Tests Pest backend : couvrent les 7 transitions de statut + les politiques d'accès.

## 11. Composants Next.js candidature/

| Composant | Type | Rôle |
|---|---|---|
| `CandidatureHeader` | RSC | Header spécifique candidature (sobre, focus parcours) |
| `CampaignBanner` | RSC | Bannière campagne courante avec compte à rebours |
| `RegistrationForm` | Client | Formulaire inscription |
| `LoginForm` | Client | Formulaire login |
| `ProfileForm` | Client | Formulaire profil avec auto-save par section |
| `CandidatureWizard` | Client | Wizard 4 étapes création candidature |
| `VoeuxPicker` | Client | Sélecteur ordonné 3 spécialités |
| `PiecesJointesUploader` | Client | Drag-drop multi-fichiers |
| `CandidatureSummary` | RSC | Récap dossier en lecture seule |
| `StatusTimeline` | RSC | Frise statuts |
| `RecipissePdfButton` | Client | Téléchargement récépissé |
| `WithdrawDialog` | Client | Confirmation retrait |
| `CcaPaymentInstructions` | RSC | Bloc instructions paiement frais |

## 12. Sous-agent Claude Code

`.claude/agents/candidature-reviewer.md` — sous-agent qui relit les diffs touchant le module candidature et vérifie :

- Tous les endpoints `/v1/applications/*` ont check d'auth + ownership (un candidat ne peut accéder qu'à ses propres données).
- Aucune fuite de PII dans les logs Laravel ou Sentry (notamment date de naissance, adresse, téléphone).
- Le numéro de dossier est généré atomiquement (pas de race condition sur attribution).
- Le PDF récépissé contient toutes les informations + un hash de traçabilité.
- Les emails partent bien lors de chaque transition de statut (vérifier les jobs en queue).
- Les politiques d'accès Spatie sont bien câblées sur les Resources Filament côté comité.

## 13. Risques et mitigations

**R1 — Capacité serveur lors d'un pic de soumissions à la deadline.** Mitigation : queue Redis pour les jobs lourds (génération PDF, OCR, scan antivirus), monitoring temps réel via Uptime Kuma, possibilité de bascule sur Scaleway en cas de saturation Contabo (Phase II).

**R2 — Perte de saisie côté candidat.** Mitigation : auto-save profil + dossier toutes les 2s, indicateur visuel.

**R3 — Candidat oublie de payer les frais.** Mitigation : email de rappel J+3 et J+7 si soumis sans paiement, page de suivi affiche statut frais en vis-à-vis du processus admin.

**R4 — Comité ne traite pas les dossiers à temps.** Mitigation : dashboard Filament avec compteurs et alertes, rappels email programmés au comité si dossier `submitted` depuis > 14 jours sans changement.

**R5 — Faille de sécurité dans le upload.** Mitigation : ClamAV scan obligatoire, types MIME contrôlés, taille max 10 MB, stockage MinIO privé (jamais en URL publique).

**R6 — Décommission Joomla pose des problèmes SEO ou utilisateurs perdus.** Mitigation : redirections 301 systématiques, communication via Facebook officiel et notification aux candidats existants si applicable.
