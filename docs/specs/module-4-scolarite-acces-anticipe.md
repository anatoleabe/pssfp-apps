# Module 4 — Scolarité, lot A : accès anticipé et prises en charge

**Date** : 2026-11-15
**Décideur** : M. ABE ETOUMOU Anatole, Chef USI
**Décision d'architecture** : [ADR-0010](../adr/0010-vie-academique-auditeurs-phase-1.md)
**Spec de référence** : [Scolarité, lot 1](module-4-scolarite-lot1-2026-11.md), §5 « Accès à eCampus »

## 1. Objectif

Aujourd'hui, un auditeur ne reçoit ses codes FOAD qu'une fois ses droits d'inscription soldés : 185 000 FCFA en M1, 135 000 en M2. Trois situations bloquent pourtant des auditeurs de bonne foi :

- **Virement annoncé.** L'auditeur prouve qu'il a fait un virement, mais l'argent n'est pas encore sur nos comptes.
- **Prise en charge par l'employeur.** Son administration s'engage par écrit à payer : c'est une promesse administrative.
- **Moratoire ou dérogation.** Le responsable de scolarité accorde un délai à un auditeur qui le sollicite.

Ce lot permet de **débloquer l'accès avant le paiement**, avec un suivi serré : échéance, relances, régularisation à réception de l'argent. Un montant n'est jamais tenu pour payé tant qu'il n'est pas reçu.

### Feuille de route

| Étape | Contenu |
|---|---|
| **A1 (cette spec, §2 à §8)** | Déblocage anticipé (virement annoncé, moratoire ou dérogation), règle d'accès, envoi du lien, filtre de liste, relances automatiques, contrôle interne |
| **A2 (§9)** | Engagements de prise en charge par organisme, règlement réparti entre auditeurs |
| **A3 (§10)** | L'agent demande un moratoire au responsable par le circuit des demandes (lot 14c) |
| Lot B | Étiquettes sur les auditeurs |
| Lot C | Listes PDF à en-tête PSSFP (versé et reste, par promotion, spécialité et niveau) |
| Lot D | Comptes auditeur : solde sur toute la scolarité, report d'une année sur l'autre |

### Hors périmètre

- Re-blocage automatique à l'échéance : les codes remis restent valables sur eCampus, on ne peut pas les reprendre.
- Relance automatique d'un organisme : seul l'agent l'envoie, d'un clic (A2).
- Comptes auditeur et report du trop-perçu (lot D).

## 2. Les trois cas

| Cas | Qui l'accorde | Pièce | Échéance |
|---|---|---|---|
| **Virement annoncé** (`virement_annonce`) | Agent de scolarité (`scolarite.deblocage`) | Preuve de virement, **obligatoire** | 15 jours par défaut, au plus 60 jours |
| **Prise en charge** (`prise_en_charge`) | Agent, via un engagement (A2) | Lettre de l'organisme, sur l'engagement | Celle de l'engagement |
| **Moratoire ou dérogation** (`moratoire`) | Responsable de scolarité (`scolarite.deblocage_exceptionnel`) | Facultative (la demande écrite de l'auditeur) | **Obligatoire**, au plus 180 jours |

Règles communes :

- Un motif est obligatoire (au moins 10 caractères).
- Un déblocage porte sur l'**inscription annuelle courante**. Il ne peut y en avoir **qu'un en cours** par inscription.
- Il est refusé :
  - pour un dossier retiré ;
  - pour un auditeur au statut « exclu » ou « abandon » ;
  - pour une inscription dont les droits sont déjà soldés.
- Depuis A2 (§9), une prise en charge passe toujours par un **engagement** : le rattachement de l'auditeur crée le déblocage. Avant A2, elle s'enregistrait comme un moratoire, avec la lettre de l'employeur en pièce.

## 3. Règle d'accès

`AccesEcampus::inscriptionSoldee()` devient `AccesEcampus::accesOuvert()` : **droits d'inscription soldés OU déblocage en cours** sur l'inscription courante. Tous les chemins existants en découlent :

- l'état de l'accès (`etat()`) : fiche, liste, espace de l'auditeur ;
- les codes remettables (`FraisInscription::payes`, `IdentifiantsMoodle::remettables`) ;
- le lien vers la lettre d'accès (`LiensLettreAcces::possible`) et la lettre PDF de la fiche ;
- l'export Word groupé des lettres d'accès (`DocumentsAuditeurs::auditeurs`), dont le filtre SQL ajoute « ou déblocage en cours ».

La situation financière (`SituationFinanciere`, badges, scopes, export) **ne change pas** : un auditeur débloqué reste « Non soldé » tant qu'il n'a pas payé.

À l'enregistrement du déblocage, l'auditeur est prévenu comme après un paiement (`NotificationAcces::siDisponible`) : SMS et e-mail avec le lien vers sa lettre d'accès.

## 4. Vie d'un déblocage

| État | Passage |
|---|---|
| `en_cours` | À l'enregistrement |
| `regularise` | **Automatique** dès que les droits d'inscription sont soldés : après un versement ou une remise, et en rattrapage par la tâche quotidienne |
| `revoque` | Par le responsable, motif obligatoire. L'accès redevient « bloqué » si les droits ne sont pas soldés |

- **Prolonger** : nouvelle échéance et motif. L'agent prolonge un virement annoncé, une seule fois et d'au plus 30 jours. Le responsable prolonge tout.
- Un déblocage clos ne se rouvre pas : on en crée un nouveau.
- Après une **révocation**, seul le responsable accorde un nouveau déblocage sur la même inscription : c'est son garde-fou, puisque les codes remis ne se reprennent pas.
- Une pièce ne sert qu'une fois : ni le bordereau d'un versement, ni la pièce d'un autre déblocage.
- Un auditeur passé au statut « exclu » ou « abandon » après son déblocage n'en profite plus : son accès redevient « bloqué » tant qu'il n'a pas payé.

## 5. Relances et alertes

- **Tâche quotidienne** `scolarite:relancer-deblocages`, à 08:00 heure de Yaoundé. Pour chaque déblocage `en_cours` dont l'échéance est passée et qui n'a pas été relancé depuis 7 jours, elle envoie un SMS et un e-mail à l'auditeur. Les relances suivent le circuit des messages aux auditeurs : trace dans l'espace, journal.
  - Le jour de l'échéance compte encore dans le délai : la première relance part le lendemain.
  - Même texte sur les deux canaux (l'espace n'affiche alors qu'un message), tracé avec la cause `deblocage`.
  - Un auditeur soldé entre-temps est régularisé au lieu d'être relancé ; un dossier retiré ou un auditeur exclu ou en abandon n'est pas relancé.
- Messages : ASCII, un seul segment, sans numéro de téléphone, sans ponctuation collée à une adresse.
  - Virement annoncé : « PSSFP : votre virement des droits d'inscription n'est pas encore arrive. Merci de regulariser ou de contacter la scolarite. »
  - Moratoire : « PSSFP : le delai accorde pour vos droits d'inscription est depasse. Merci de regulariser aupres de la scolarite. »
  - Prise en charge : « PSSFP : le reglement de vos droits par votre employeur n'est pas encore arrive. Merci de le relancer ou de contacter la scolarite. »
- **Contrôle interne** `DebEchus` (majeur, délai de 7 jours) : liste des déblocages échus et non régularisés. Responsable : agent de scolarité, avec escalade vers le responsable de scolarité (relances graduées du lot 14b2).

## 6. Données (A1)

### `deblocages_acces`

| Colonne | Type | Règle |
|---|---|---|
| `id` | bigint | |
| `inscription_annuelle_id` | FK `inscriptions_annuelles`, RESTRICT | |
| `type` | varchar(20) | CHECK : `virement_annonce`, `prise_en_charge`, `moratoire` |
| `motif` | text | |
| `justificatif_path` | varchar, nullable | Obligatoire pour `virement_annonce` (CHECK) |
| `echeance` | date | |
| `statut` | varchar(12) | CHECK : `en_cours`, `regularise`, `revoque` ; défaut `en_cours` |
| `accorde_par` | FK `users`, nullable | `ON DELETE SET NULL`, comme `versements.saisi_par` : le journal garde l'auteur |
| `clos_le`, `clos_par`, `motif_cloture` | nullable | Posés à la régularisation ou à la révocation |
| `prolongations` | smallint, défaut 0 | Ajoutée en A1 : l'agent ne prolonge qu'une fois un virement annoncé |
| `relances` | smallint, défaut 0 | |
| `derniere_relance_le` | timestamptz, nullable | |
| `engagement_id` | FK `engagements_prise_en_charge`, nullable | Ajouté par A2 (§9.2) : renseigné pour une prise en charge, et pour elle seule (`CHECK`) |
| `created_at`, `updated_at` | timestamptz | |

- **Index unique partiel** : un seul déblocage `en_cours` par inscription (`WHERE statut = 'en_cours'`).
- Les pièces sont stockées comme les bordereaux de versement (`scolarite/justificatifs/<ulid>`, PDF, JPG ou PNG, 10 Mo).
- Journal d'activité (`scolarite`) : `deblocage_accorde`, `deblocage_prolonge`, `deblocage_regularise`, `deblocage_revoque`, `deblocage_relance`.

## 7. Administration (A1)

- **Fiche de l'auditeur** :
  - menu « Accès eCampus » : « Débloquer l'accès », avec le cas, l'échéance proposée, le motif et la pièce. Le moratoire n'apparaît que pour le responsable ;
  - encadré « Accès anticipé » dans la section Accès à eCampus : cas, échéance (en rouge si dépassée), qui, quand, pièce, relances ;
  - actions « Prolonger » et « Révoquer » sur le déblocage en cours.
- **Liste des auditeurs** :
  - filtre « Accès anticipé » : en cours, échu, par cas ;
  - colonne masquable « Accès anticipé » : cas et échéance.
- **Espace de l'auditeur** : sous ses accès, la mention « Accès accordé avant paiement : à régulariser avant le JJ/MM/AAAA ».

## 8. Droits (A1)

| Permission | Rôles | Effet |
|---|---|---|
| `scolarite.deblocage` | agent et responsable de scolarité, admin, super_admin | Virement annoncé, prolongation limitée |
| `scolarite.deblocage_exceptionnel` | responsable de scolarité, admin, super_admin | Moratoire ou dérogation, toute prolongation, révocation |

Ajout par **migration additive** : on ne relance jamais `RolePermissionSeeder` en prod. Il faut aussi mettre à jour `ModulePermissionMap` et `RolePermissionSeeder`.

## 9. A2 — Engagements de prise en charge

**Statut** : réalisé (branche `feat/scolarite-prises-en-charge-a2`), sur le socle du compte de l'auditeur (sous-lot D1, [ADR-0013](../adr/0013-compte-de-l-auditeur.md)).

### 9.1 Principe

- Un **engagement** est la promesse écrite d'un organisme (lettre de prise en charge). Il ouvre l'accès des auditeurs qu'on y rattache, par un déblocage `prise_en_charge`. Il ne compte jamais un franc comme payé.
- Un **règlement** est l'argent reçu de l'organisme. Il se répartit en **un versement par auditeur** (payeur `employeur`), que le compte de l'auditeur impute comme tout versement (D1, R2) : d'office sur l'année couverte par l'engagement, l'excédent restant en avance réservée (décisions 2 et 7, libellé « avance sur la scolarité à venir » inchangé).
- La relance de l'organisme n'est **jamais automatique** : un e-mail au contact, sur clic de l'agent.

### 9.2 Données

#### `engagements_prise_en_charge` (nouvelle)

| Colonne | Type | Règle |
|---|---|---|
| `id` | bigint | Interne |
| `uuid` | uuid, unique | Seul identifiant routé (règle 10 du projet) |
| `organisme` | varchar(200) | Obligatoire, espaces de bord retirés |
| `lettre_reference` | varchar(100) | Obligatoire, normalisée comme une référence de bordereau (majuscules) |
| `lettre_date` | date | Obligatoire, pas dans le futur |
| `lettre_path` | varchar(255), **unique** | Lettre scannée, **obligatoire** : dossier des justificatifs (`scolarite/justificatifs/<ulid>`, PDF, JPG ou PNG, 10 Mo), une pièce ne sert qu'une fois |
| `contact_nom`, `contact_email`, `contact_telephone` | varchar(150), varchar(255), varchar(30), nullable | Contact pour le règlement. L'e-mail, s'il est donné, est une adresse valide |
| `portee` | varchar(20) | `CHECK` : `droits_inscription` ou `scolarite_annee` |
| `annee_academique` | varchar(9) | `CHECK` : `AAAA-AAAA` ; une année d'un barème existant (service) |
| `echeance` | date | Postérieure à aujourd'hui ; au plus 60 jours pour l'agent (comme un virement annoncé), 180 pour le responsable (comme un moratoire) |
| `notes` | text, nullable | 2 000 caractères au plus |
| `cree_par` | FK `users`, nullable, `ON DELETE SET NULL` | Comme `deblocages_acces.accorde_par` : le journal garde l'auteur |
| `prolongations`, `relances` | smallint, défaut 0 | |
| `derniere_relance_le` | timestamptz, nullable | |
| `created_at`, `updated_at` | timestamptz | |

- Index unique `engagements_prise_en_charge_lettre_unique` sur `(lower(organisme), lettre_reference, annee_academique)` : une même lettre n'est pas saisie deux fois (double clic, deux agents).
- Index sur `annee_academique`, `echeance` et `cree_par`.
- Aucune suppression : un engagement saisi par erreur reste, sans auditeur (état « Sans auditeur »).

#### `reglements_organismes` (nouvelle)

| Colonne | Type | Règle |
|---|---|---|
| `id`, `uuid` | bigint, uuid unique | |
| `engagement_id` | FK `engagements_prise_en_charge`, RESTRICT, indexée | |
| `montant` | integer | `CHECK (montant > 0)` ; au plus 1 000 000 000 FCFA (service) |
| `date_reglement` | date | Pas dans le futur |
| `mode` | varchar(30) | Un mode de `Versement::MODES` |
| `reference` | varchar(60) | Normalisée (majuscules). **Index unique partiel** `(mode, reference) WHERE annule_at IS NULL` |
| `piece_path` | varchar(255), nullable | Avis de virement ou bordereau, facultatif comme le scan d'un versement. Index unique partiel : une pièce ne sert qu'une fois |
| `saisi_par` | FK `users`, nullable, `ON DELETE SET NULL` | |
| `annule_at`, `annule_par` (FK `users`, `SET NULL`), `annule_motif` (varchar(500)) | nullable | `CHECK` : les trois absents, ou `annule_at` et un motif présents |
| `created_at`, `updated_at` | timestamptz | |

Le modèle `ReglementOrganisme` refuse la suppression et toute modification autre que l'annulation, une fois (garde-fous Eloquent, comme `RecuVersement`). L'argent lui-même est porté par les versements, déjà immuables en base (D1).

#### `versements.reglement_organisme_id` (nouvelle colonne)

- FK `reglements_organismes`, RESTRICT, nullable, indexée.
- `CHECK (reglement_organisme_id IS NULL OR payeur = 'employeur')`.
- Figée après l'insertion par le déclencheur `versements_immuables` de D1, qui compare la ligne entière : une colonne ajoutée est figée par défaut.

#### `deblocages_acces.engagement_id` (nouvelle colonne)

- FK `engagements_prise_en_charge`, RESTRICT, nullable, indexée.
- `CHECK ((type = 'prise_en_charge') = (engagement_id IS NOT NULL))` : une prise en charge passe toujours par un engagement, et seul ce cas en porte un. La migration vérifie d'abord qu'aucune prise en charge n'existe sans engagement (A1 les refusait) ; sinon elle s'arrête avec un message clair.
- La pièce d'un déblocage rattaché reste vide : la lettre est portée par l'engagement.

#### Migration

`2026_12_03_100000_create_engagements_prise_en_charge_tables.php` : les deux tables, les deux colonnes, les `CHECK` et les index (SQL brut limité aux `CHECK` et aux index partiels ou fonctionnels, précédents A1 et D1). Son `down()` refuse dès qu'un engagement existe (« Des engagements de prise en charge sont enregistrés : revenez au code précédent sans annuler la migration. ») ; sinon il retire colonnes, contraintes et tables.

**Aucune nouvelle permission**, donc aucune migration de droits (§9.3).

### 9.3 Droits

| Geste | Permission | Rôles (état actuel) |
|---|---|---|
| Voir l'écran « Prises en charge » et la fiche d'un engagement | `view_any_auditeur`, `view_auditeur` (module Scolarité) | Agent et responsable de scolarité, service financier, admin, super_admin |
| Créer un engagement, modifier son contact et ses notes, rattacher des auditeurs, relancer l'organisme | `scolarite.deblocage` (ou `scolarite.deblocage_exceptionnel`) | Agent et responsable, admin, super_admin |
| Prolonger un engagement | `scolarite.deblocage_exceptionnel` | Responsable, admin, super_admin |
| Enregistrer un règlement | `scolarite.versement_saisir` (le droit qui enregistre déjà les versements) | Agent et responsable, service financier, admin, super_admin |
| Annuler un règlement | `scolarite.versement_annuler` | Responsable, admin, super_admin |
| Ouvrir la lettre ou la pièce d'un règlement | `view_auditeur` ; consultation journalisée | |

Chaque service revérifie la permission du geste, même si l'écran l'a déjà fait. La description de `scolarite.deblocage` dans `ModulePermissionMap` mentionne désormais les engagements.

### 9.4 Créer, modifier, prolonger un engagement

- **Créer** : organisme, référence et date de la lettre, lettre scannée, contact, portée, année académique (par défaut la plus récente des barèmes), échéance, notes. Journal `engagement_cree`. L'échéance est bornée à 60 jours pour l'agent et à 180 jours pour le responsable : un accès de plus de 60 jours sans paiement reste une décision du responsable, comme en A1.
- **Modifier** : contact et notes seulement (`engagement_modifie`, avec l'avant et l'après). Organisme, lettre, portée et année ne changent pas : une erreur se corrige par un nouvel engagement.
- **Prolonger** (responsable) : nouvelle échéance après l'actuelle et après aujourd'hui, au plus 180 jours, et motif d'au moins 10 caractères. Elle s'applique à l'engagement et à tous ses déblocages en cours (`prolongations + 1` chacun). Journal `engagement_prolonge`, et `deblocage_prolonge` pour chaque déblocage.
- Sur la fiche de l'auditeur, « Prolonger l'accès anticipé » n'est plus proposé pour un déblocage rattaché à un engagement, et le service le refuse : « Cet accès dépend d'un engagement de prise en charge : prolongez l'engagement (écran Prises en charge). » L'échéance d'un auditeur couvert est toujours celle de son engagement.

### 9.5 Rattacher des auditeurs

- L'agent choisit, par matricule ou nom, des auditeurs dont l'**inscription courante est de l'année de l'engagement**.
- Chaque auditeur est traité **séparément** : les uns sont rattachés, les autres refusés, avec le motif par matricule. Le résultat s'affiche en fin de geste.
- Pour chaque auditeur accepté, `DeblocagesAcces::rattacher` crée un déblocage `prise_en_charge` :
  - lié à l'engagement (`engagement_id`) ;
  - échéance de l'engagement ;
  - motif posé par la plateforme : « Prise en charge : <organisme>, lettre <référence> du JJ/MM/AAAA » ;
  - sans pièce : la lettre est celle de l'engagement.
- **Refus** :
  - ceux de A1 : dossier retiré ; auditeur exclu ou en abandon ; droits d'inscription déjà soldés (quelle que soit la portée : l'accès est déjà ouvert, et le règlement de l'organisme s'enregistre alors sur la fiche, payeur « employeur ») ; déblocage déjà en cours ; après une révocation sur l'inscription, le responsable seul ;
  - en plus : inscription courante d'une autre année que celle de l'engagement ; inscription déjà couverte par **un autre** engagement (lien non révoqué) ; échéance de l'engagement dépassée (à faire prolonger d'abord).
- **Ordre des verrous** : l'engagement (`FOR SHARE`), puis l'auditeur (R8). L'enregistrement d'un règlement prend l'engagement en `FOR UPDATE` avant les auditeurs : le même ordre partout, donc pas d'interblocage.
- **Notification** : comme en A1 (`NotificationAcces::siDisponible`), SMS et e-mail du lien vers la lettre d'accès.
- Journal : `deblocage_accorde` sur chaque déblocage, avec l'`uuid` de l'engagement.
- Un auditeur **couvert** est un auditeur dont l'inscription a un déblocage lié à l'engagement et non révoqué (en cours ou régularisé). La révocation (responsable, depuis la fiche de l'auditeur, A1) le sort de l'engagement. Après une annulation de règlement, un auditeur dont les droits ne sont plus soldés se rattache de nouveau au même engagement : un nouveau déblocage en cours s'ajoute au lien régularisé.
- La **régularisation** est celle de A1 : le déblocage passe à `regularise` dès que les droits d'inscription sont soldés (après un versement, une remise ou un règlement, et chaque jour en rattrapage). L'auditeur reste couvert.

### 9.6 Couvert, réglé, reste, état

Pour chaque inscription couverte, selon la portée de l'engagement :

| | `droits_inscription` | `scolarite_annee` |
|---|---|---|
| **Couvert** | Dû du palier « inscription » (après remise) | Dû total de l'année (après remise) |
| **Reste** | Reste du palier « inscription » | Reste de l'année |

- **Couvert** et **reste** de l'engagement sont les sommes sur ses inscriptions couvertes. Le reste se lit dans la situation de l'inscription (l'imputé de D1), **quel que soit le payeur** : c'est ce que le règlement réparti propose.
- **Réglé** : la somme des règlements non annulés de l'engagement, c'est-à-dire l'argent reçu de l'organisme. Par auditeur, la somme de ses versements valides issus de ces règlements.
- **État**, calculé, jamais stocké :
  - « Sans auditeur » : aucune inscription couverte ;
  - « Soldé » : reste nul ;
  - « Échu » : reste positif et échéance dépassée ;
  - « En cours » : sinon.

### 9.7 Règlement réparti

**Formulaire** (« Enregistrer un règlement », fiche de l'engagement) :

- montant, date, mode, référence (60 caractères au plus), pièce facultative ;
- une part par auditeur couvert dont le dossier n'est pas retiré, proposée au **reste dû dans la portée** ; chaque part est modifiable (0 : aucun versement pour cet auditeur) ;
- case « Je confirme : … » (`confirmer_depassement`), affichée et obligatoire dès qu'une part dépasse le reste dans la portée, comme `confirmer_avance`. Son libellé dit, par auditeur, ce qui ira sur le reste de l'année puis ce qui restera en avance réservée, en attente de la décision du responsable.

**Règles** (`ReglementsOrganismes::enregistrer`, permission `scolarite.versement_saisir`) :

1. Montant entier de 1 à 1 000 000 000 FCFA ; date non future ; mode de la liste ; référence non vide.
2. Les parts sont des entiers positifs ou nuls ; au moins une est positive ; **leur somme égale le montant** (sinon : « La somme des parts (X FCFA) doit égaler le montant du règlement (Y FCFA). »).
3. Chaque part porte sur une inscription couverte par l'engagement, d'un dossier non retiré.
4. **Doublons**, refusés avant toute écriture :
   - un règlement non annulé de même mode et même référence (l'index unique partiel tranche aussi deux saisies simultanées) ;
   - un versement valide de même mode portant exactement la référence du règlement : l'argent aurait déjà été saisi sur une fiche ;
   - un versement valide de même mode portant la référence `<référence>-<MATRICULE>` d'une part.
   
   Réciproquement, un versement saisi sur une fiche avec la référence d'un règlement non annulé du même mode est refusé.
5. **Une seule transaction** :
   1. verrou de l'engagement (`FOR UPDATE`) ;
   2. verrou de chaque auditeur concerné, **par `id` croissant** (R8 de D1), avant toute écriture ;
   3. sous ces verrous, relecture des restes : une part supérieure au reste dans la portée exige la confirmation, sinon refus ;
   4. création du règlement ;
   5. un versement par part positive, par `id` d'auditeur croissant, par `RegistreVersements` (seul écrivain des versements) :
      - `payeur = employeur` et `reglement_organisme_id` renseigné ;
      - inscription désignée : celle du déblocage qui couvre l'auditeur, donc l'année de l'engagement ;
      - date et mode du règlement, sans pièce (elle est sur le règlement) ;
      - référence `<référence>-<MATRICULE>` (100 caractères au plus, unique par mode) ;
      - imputation par `ImputationsCompte::apresVersement` (R2 : l'inscription désignée seulement ; l'excédent reste en avance réservée).
   
   Un refus sur une part annule tout : rien n'est écrit.
6. **Après la validation** de la transaction, pour chaque auditeur : régularisation du déblocage et notification des accès, comme après un versement (`RegistreVersements`). Un échec d'envoi ne défait jamais le règlement.
7. Reçus (service financier), situation, régularisation, listes et exports traitent ces versements comme tous les autres.

**Annulation d'un règlement** (`ReglementsOrganismes::annuler`, permission `scolarite.versement_annuler`, motif obligatoire) :

1. Une seule transaction : verrou du règlement, puis des auditeurs par `id` croissant.
2. Si l'un des versements est imputé sur plusieurs années (refus R4.1 de D1), **tout est refusé**, avec un message qui nomme le matricule et les années : « Le règlement ne peut pas être annulé : le versement de <MATRICULE> est imputé sur plusieurs années (…) ; son annulation passera par une demande d'autorisation (sous-lot D4). Rien n'a été annulé. Prévenez l'USI. »
3. Sinon, chaque versement valide est annulé par `RegistreVersements` (R4 de D1 : imputations annulées, avance réimputée, écriture d'annulation du reçu), motif « Règlement <référence> annulé : <motif> », puis le règlement lui-même.
4. Un déblocage régularisé par le règlement reste clos : si les droits ne sont plus soldés, l'accès redevient « bloqué » et l'auditeur se rattache de nouveau (§9.5). Un montant n'est jamais tenu pour payé tant qu'il n'est pas reçu.
5. Un versement issu d'un règlement ne s'annule **pas seul** depuis la fiche de l'auditeur : « Ce versement fait partie du règlement <référence> de <organisme> : annulez le règlement entier depuis l'écran « Prises en charge ». » L'action « Annuler » n'y est pas proposée.

### 9.8 Relance de l'organisme

- Action « Relancer l'organisme » (`scolarite.deblocage`), proposée si le contact a une adresse e-mail et si le reste est positif. Une fenêtre de confirmation rappelle la dernière relance. Sous le verrou de l'engagement, une relance est refusée moins de 24 heures après la précédente (un double clic n'envoie pas deux e-mails).
- Un e-mail part au contact, par la file bridée des envois (`SendThrottledMail`) :
  - lettre (référence et date), portée, année et échéance ;
  - auditeurs couverts qui ont un reste, dossier non retiré, avec matricule, nom et reste ;
  - reste total.
- Compteur `relances` et `derniere_relance_le` de l'engagement, journal `engagement_relance` (adresse, reste, nombre d'auditeurs).
- **Rien d'automatique** : ni la tâche quotidienne `scolarite:relancer-deblocages` (qui relance les auditeurs, A1 §5), ni aucune autre tâche planifiée n'écrit à un organisme.

### 9.9 Fiche de l'auditeur

- « Débloquer l'accès » propose le cas « Prise en charge » à qui a `scolarite.deblocage`. Choisi, il demande seulement l'engagement (ceux de l'année de l'inscription courante, échéance non dépassée) et passe par `DeblocagesAcces::rattacher` (mêmes refus qu'au §9.5). Il n'y a ni échéance, ni motif, ni pièce à saisir. `DeblocagesAcces::accorder` refuse une prise en charge hors engagement.
- L'encadré « Accès anticipé » nomme l'engagement (organisme, lettre) ; la pièce est la lettre de l'engagement, ouverte par « Voir la pièce de l'accès anticipé ».
- Onglet « Versements » : pas d'action « Annuler » sur un versement issu d'un règlement (§9.7).

### 9.10 Écran « Prises en charge » (Vie académique)

- Menu Scolarité, après « Auditeurs ». La recherche globale ne trouve que l'organisme. **Liste** : organisme, lettre (référence et date), année, portée, nombre d'auditeurs couverts, couvert, réglé, reste, échéance (en rouge si dépassée), état. Filtres : année, échéance dépassée. Action « Nouvel engagement » (`scolarite.deblocage`).
- **Fiche de l'engagement** :
  - lettre, portée, année, échéance, état, contact, notes, auteur, relances ;
  - totaux couvert, réglé, reste ;
  - onglet « Auditeurs couverts » : matricule, nom, couvert, réglé par l'organisme, reste, état du déblocage, lien vers la fiche de l'auditeur ;
  - onglet « Règlements » : date, montant, mode, référence, nombre de versements, saisi par, état ; actions « Voir la pièce » et « Annuler le règlement » ;
  - actions : « Rattacher des auditeurs » (50 au plus par envoi, masquée une fois l'échéance dépassée), « Enregistrer un règlement » (somme des parts saisies affichée en direct), « Relancer l'organisme », « Lettre », « Prolonger », « Modifier le contact ».
- Chargement groupé (inscriptions couvertes, imputé, règlements) : pas de requête par ligne.

### 9.11 Journal

Journal `scolarite`, scellé (lot 14d-1). Montants et `uuid` seulement, sauf l'adresse du contact relancé.

| Événement | Sujet | Quand |
|---|---|---|
| `engagement_cree`, `engagement_modifie`, `engagement_prolonge`, `engagement_relance`, `engagement_lettre_consultee` | Engagement | §9.4, §9.8, §9.10 |
| `deblocage_accorde` (existant, avec `engagement`) | Déblocage | Rattachement |
| `deblocage_prolonge` (existant) | Déblocage | Prolongation de l'engagement |
| `reglement_enregistre` (montant, mode, référence, parts `{inscription, montant}`) | Règlement | §9.7 |
| `versement_enregistre`, `versement_annule` (existants, avec `reglement`) | Versement | §9.7 |
| `reglement_annule` (motif, nombre de versements) | Règlement | §9.7 |
| `reglement_piece_consultee` | Règlement | §9.10 |

### 9.12 Contrôle interne et vérification

- `CPT_COHERENCE` (D1, §13) gagne un cas `reglement:<uuid>` : la somme des versements valides d'un règlement non annulé diffère de son montant, ou un règlement annulé garde un versement valide (un versement annulé seul, hors application). Résumé : référence et montants, sans nom.
- `scolarite:verifier-comptes` signale le même écart. Les deux restent à 0 après un règlement réparti ou son annulation.

### 9.13 Tests (Pest)

- **Engagement** : création avec lettre obligatoire ; échéance bornée ; doublon de lettre ; modification du contact ; prolongation par le responsable seul, appliquée aux déblocages en cours ; prolongation individuelle refusée.
- **Rattachement** : déblocage lié, échéance de l'engagement, accès ouvert, notification ; refus A1 (retiré, exclu, abandon, soldé, déjà en cours, après révocation) et refus propres (autre année, autre engagement, échéance dépassée) ; traitement séparé de chaque auditeur.
- **Règlement réparti** : parts proposées par portée ; somme différente du montant refusée ; référence par matricule ; doublons ; payeur employeur ; imputations sur l'année couverte ; excédent en avance réservée avec confirmation ; régularisation et notification ; verrous par `id` croissant avant toute écriture (`DB::listen`) ; tout ou rien.
- **Annulation** : réservée au responsable ; cascade sur les versements, imputations et reçus ; refus R4.1 sans rien annuler ; versement d'un règlement non annulable seul.
- **Relance** : e-mail au contact sur clic seulement ; rien par la tâche quotidienne.
- **Filament** : écran visible selon le rôle ; colonnes couvert, réglé, reste ; actions de la fiche selon les droits ; cas « Prise en charge » de « Débloquer l'accès ».
- **Comptes** : `scolarite:verifier-comptes` sans écart et `CPT_COHERENCE` à 0 après un règlement réparti ; écart `reglement:` détecté sur une incohérence injectée.
- **Migration** : `down()` testé (base vide, puis refus avec un engagement).

### 9.14 Déploiement

1. Pousser le code ; `php artisan config:clear` ; `php artisan migrate --force` (une migration, aucune donnée existante touchée) ; `php artisan optimize` ; redémarrer la file (les relances partent par la file).
2. Pas de `permission:cache-reset` nécessaire (aucune permission nouvelle) ; ne pas relancer `RolePermissionSeeder`.
3. Aucune reconstruction du portail apply : l'espace de l'auditeur affiche déjà la mention « accès accordé avant paiement » (A1) et l'avance (D1).
4. Le lendemain : `php artisan scolarite:verifier-comptes`, puis revue du contrôle interne.

### 9.15 Points tranchés en réalisation

1. **Aucune nouvelle permission** : création, rattachement, contact et relance sur `scolarite.deblocage` ; prolongation sur `scolarite.deblocage_exceptionnel` ; règlement sur `scolarite.versement_saisir` ; annulation sur `scolarite.versement_annuler` ; consultation sur `view_any_auditeur` / `view_auditeur`.
2. **« Déjà soldé »** : le refus de A1 porte sur les droits d'inscription, pour les deux portées. Un auditeur déjà en règle de ses droits mais couvert pour l'année par son employeur n'est pas rattaché ; le règlement de l'organisme pour lui s'enregistre sur sa fiche (payeur « employeur »).
3. **Rattachement séparé par auditeur** (et non tout ou rien) : les refus sont listés par matricule.
4. **Une inscription, un engagement** : refus de rattacher une inscription déjà couverte par un autre engagement (contrôle du service ; l'index unique partiel de A1 garantit toujours un seul déblocage en cours).
5. **Échéance** : au plus 60 jours pour l'agent (comme un virement annoncé), 180 pour le responsable (comme un moratoire) ; relecture de sécurité du 15/11/2026, pour qu'un agent seul n'ouvre pas 180 jours d'accès sans paiement. La prolongation de l'engagement revient au responsable et s'applique à tous ses déblocages en cours ; la prolongation individuelle d'un déblocage rattaché est refusée.
6. **Reste** : il se lit dans la situation (quel que soit le payeur) ; le réglé est l'argent reçu de l'organisme. Couvert − réglé ≠ reste quand l'auditeur a payé une part lui-même.
7. **État** calculé : « Sans auditeur », « Soldé », « Échu », « En cours ».
8. **Parts proposées** : l'auditeur dont le dossier est retiré n'en a pas (lot 1 : aucune écriture sur un dossier retiré).
9. **Dépassement** : une part au-delà du reste dans la portée va d'abord sur le reste de l'année désignée (décision 2), puis en avance réservée (décision 7) ; une seule case de confirmation pour tout le règlement.
10. **Pièce** : la lettre et la pièce du règlement sont rangées avec les justificatifs, et une pièce ne sert qu'une fois (versement, déblocage, engagement ou règlement). Les versements d'un règlement n'ont pas de scan : la pièce est sur le règlement.
11. **Doublons croisés** : un versement et un règlement ne partagent pas la même référence dans le même mode.
12. **Annulation d'un règlement** : pas de réouverture automatique de l'accès ; le rattachement se refait à la main si l'engagement tient toujours.
13. **Verrous** : engagement puis auditeurs par `id` croissant (règlement) ; engagement en partage puis auditeur (rattachement) ; règlement puis auditeurs par `id` croissant (annulation).
14. **Contrôle `CPT_COHERENCE`** : nouveau cas `reglement:<uuid>` (même criticité, même responsable).
15. **Relance de l'organisme** : par la file bridée des e-mails, avec la liste des auditeurs couverts qui ont un reste (matricule, nom, reste) : l'organisme les a lui-même nommés dans sa lettre. Dossiers retirés exclus ; 24 heures au moins entre deux relances. Le changement d'adresse du contact est journalisé (avant, après).
16. **Pas de suppression d'engagement** : un engagement sans auditeur reste, à l'état « Sans auditeur ».
17. **Annulation d'une part** : la règle « une part ne s'annule qu'avec son règlement » est relue sous le verrou de l'auditeur, dans `RegistreVersements`.

**Limites connues** (relectures de sécurité et Filament, sans correctif dans A2) :

- Le refus croisé « référence d'un règlement / référence d'un versement » se vérifie sous des verrous différents, sans index commun aux deux tables. Deux saisies **simultanées** du même bordereau, l'une en règlement, l'autre en versement sur une fiche, passeraient toutes deux. `CPT_COHERENCE` ne le voit pas, puisque chaque écriture est cohérente, et le rapprochement bancaire des versements de scolarité est hors périmètre (spec D1, §3) : le cas reste à surveiller en recette.
- Un lien régularisé reste une couverture. Un auditeur dont les droits ont été soldés par l'engagement E ne passe pas à un autre engagement : son règlement suivant s'enregistre sur E. La clôture d'un lien régularisé est laissée à D4, avec la décision sur l'avance réservée.
- Une révocation qui arrive entre la lecture des inscriptions couvertes et l'écriture des parts n'empêche pas la part de l'auditeur révoqué : l'argent est reçu, et il est imputé sur son année.

## 10. A3 — Demande de moratoire

L'agent de scolarité n'accorde pas de moratoire (§2). Il le **demande** au responsable par le circuit des demandes d'autorisation du lot 14c, depuis la fiche de l'auditeur. Le responsable approuve ou rejette ; l'approbation crée le moratoire à son nom.

### Le type `accorder_moratoire`

| Rubrique | Règle |
|---|---|
| Libellé | Accord d'un moratoire sur les droits d'inscription |
| Famille | Levée de verrou (message de priorité haute aux décideurs) |
| Objet visé | L'**inscription annuelle courante** de l'auditeur (`entite_id`) |
| Qui demande | `scolarite.deblocage` : l'agent de scolarité |
| Qui décide | `scolarite.deblocage_exceptionnel` : responsable de scolarité, admin, super_admin. **Pas de visa** |
| Réponse attendue | Sous 48 h (contrôle `DEM_ATTENTE` du lot 14c au-delà) |

Qui détient déjà le droit de décider agit directement, sans demande : le circuit le refuse (« Vous détenez déjà ce droit »).

### Soumission, depuis la fiche de l'auditeur

- Menu « Accès eCampus » :
  - « Demander un moratoire », pour qui a `scolarite.deblocage` **sans** `scolarite.deblocage_exceptionnel`, quand un déblocage serait possible (mêmes conditions que « Débloquer l'accès ») et qu'aucune demande n'attend déjà sur l'inscription ;
  - le responsable garde l'action directe « Débloquer l'accès » (moratoire) d'A1 et ne voit pas l'action de demande ;
  - « Demande de moratoire en attente » mène à la demande, pour qui peut la voir : l'agent qui l'a soumise, les décideurs.
- Saisie :
  - échéance proposée, **obligatoire**, au plus 180 jours ;
  - motif, au moins 10 caractères ;
  - pièce **facultative** : la demande écrite de l'auditeur (PDF, JPG ou PNG, 10 Mo), rangée comme les pièces d'A1.
- Contrôles à la soumission, avec les messages d'A1 :
  - dossier retiré, auditeur exclu ou en abandon, inscription qui n'est pas celle de l'année en cours ;
  - droits d'inscription déjà soldés ;
  - déblocage déjà en cours ;
  - échéance absente, passée ou au-delà de 180 jours ; pièce invalide ou déjà jointe ailleurs ;
  - **une seule demande en attente par inscription** : l'index unique du lot 14c sur (type, objet) le garantit, même sous deux soumissions simultanées.
- Après une révocation, l'agent peut demander : c'est le responsable qui décide, ce qui respecte le garde-fou d'A1 (§4).
- Le type n'apparaît pas dans l'écran général « Nouvelle demande » : seule la fiche saisit l'échéance et la pièce.
- Les décideurs reçoivent le message habituel du lot 14c, avec l'échéance proposée et la mention de la pièce.

### Décision et exécution

- **Approbation** : l'exécution appelle `DeblocagesAcces::accorder`, **au nom du responsable** qui a approuvé :
  - `accorde_par` = le responsable, type `moratoire` ;
  - échéance proposée, pièce s'il y en a une ;
  - motif « Demande DM-AAAA-NNNNN : <motif de l'agent> ».
  
  L'auditeur est prévenu comme en A1 : SMS et e-mail du lien vers sa lettre d'accès. Le résultat de la demande le dit : « Moratoire accordé jusqu'au JJ/MM/AAAA ; auditeur prévenu par SMS et par e-mail ».
- **Le décideur n'ajuste pas l'échéance.** Une demande du lot 14c est figée : ce qui est approuvé est ce qui a été demandé et examiné. Pour un autre délai :
  - plus court : il rejette en le disant, et l'agent soumet une nouvelle demande ;
  - plus long : il approuve, puis prolonge lui-même le moratoire depuis la fiche (« Prolonger », A1).
- **Rejet** : rien n'est créé. Le demandeur est prévenu avec le motif du rejet.
- **Contrôles refaits à l'exécution**, sous les verrous d'A1, car l'état a pu changer depuis la soumission :
  - droits soldés entre-temps ;
  - dossier retiré, auditeur exclu ou en abandon ;
  - déblocage accordé entre-temps (par exemple directement par le responsable) ;
  - échéance désormais passée ;
  - nouvelle inscription annuelle ;
  - droit retiré au décideur.

  Dans ces cas, rien n'est créé. La demande passe en « Échec d'exécution » avec le message d'A1 (par exemple « Échec de l'exécution : Les droits d'inscription sont déjà soldés… »), et le demandeur en est prévenu.
- Pas d'empreinte de l'inscription : les contrôles ci-dessus la remplacent. Une empreinte échouerait sur des changements sans rapport, avec un message moins clair.

### Données

Migration `2026_12_02_110000_add_accorder_moratoire_to_demandes_autorisation` :

- la liste fermée des types (CHECK `demandes_autorisation_check`) s'élargit à `accorder_moratoire`, sur le précédent de `deverrouiller_compte` ;
- nouvelle colonne `demandes_autorisation.parametres` (JSONB, nullable), qui porte ce que le type demande en plus de son objet. Pour `accorder_moratoire` : `{"echeance": "AAAA-MM-JJ", "justificatif_path": <chemin> | null}` ;
- CHECK `demandes_autorisation_parametres_check` :
  - `parametres` est un objet JSON ;
  - une demande `accorder_moratoire` porte une échéance au format date ;
- le déclencheur `demandes_autorisation_figees` fige aussi `parametres` ;
- `down()` rétablit le CHECK et le déclencheur d'avant, puis retire la colonne. Comme pour le précédent, il échoue tant qu'il reste une demande `accorder_moratoire`, puisqu'une demande ne se supprime pas.

### Fiche de la demande

- Rubrique « Précisions » : échéance proposée, pièce jointe ou non.
- « Voir la pièce jointe » : lien signé de 30 minutes. Il exige de voir la demande et d'avoir `view_auditeur`. La consultation est journalisée.
- La fenêtre d'approbation rappelle que le moratoire sera accordé au nom du décideur, à l'échéance proposée.

### Journal

- Journal `messagerie` (lot 14c) : `demande_soumise`, `demande_approuvee` ou `demande_rejetee`, `demande_executee` ou `demande_echec`.
- Journal `scolarite` :
  - `deblocage_accorde`, causé par le responsable (A1) ;
  - `demande_piece_consultee`.

### Tests (A3)

- Soumission :
  - l'agent soumet ;
  - le responsable et l'admin ne peuvent pas soumettre (ils agissent directement), un compte sans `scolarite.deblocage` non plus ;
  - échéance au-delà de 180 jours refusée ;
  - doublon de demande en attente refusé ;
  - refus sur un dossier soldé, retiré, exclu ou débloqué.
- Décision :
  - un décideur sans `scolarite.deblocage_exceptionnel` ne peut pas décider ;
  - une approbation crée le moratoire au nom du responsable, ouvre l'accès et prévient l'auditeur ;
  - un rejet ne crée rien.
- Exécution : soldé, débloqué, retiré ou exclu entre-temps donnent un échec propre, avec un message clair, et rien n'est créé.
- Migration : CHECK et déclencheur, réversibilité.
- Filament :
  - l'action de demande est visible pour l'agent seul ;
  - le lien vers la demande en attente ;
  - soumission avec pièce ;
  - précisions et pièce sur la fiche de la demande, approbation depuis la fiche.

### Déploiement (A3)

`migrate`, puis `optimize`. Aucun nouveau droit : A3 réutilise `scolarite.deblocage` et `scolarite.deblocage_exceptionnel`, posés en A1.

### Points tranchés en réalisation

1. **Objet de la demande : l'inscription courante**, pas l'auditeur. L'index unique du lot 14c sur (type, objet) interdit ainsi une seconde demande en attente sur la même inscription.
2. **Échéance et pièce dans une colonne `parametres` (JSONB) figée.** La demande du lot 14c n'avait pas de place pour ce que le type exige en plus de son objet. Une colonne générique, figée par le déclencheur existant, garde la règle « ce qui est demandé ne change plus ».
3. **Le décideur n'ajuste pas l'échéance** : il approuve telle quelle, ou rejette en disant pourquoi. Après approbation, il peut prolonger (A1). C'est la règle du circuit 14c : ce qui s'exécute est ce qui a été examiné.
4. **Pas d'empreinte** pour ce type : les contrôles d'`accorder()` refaits à l'exécution sont plus justes, et leurs messages plus clairs.
5. **Réponse sous 48 h, famille « Levée de verrou »** : l'auditeur attend ses accès, mais un moratoire n'est pas aussi urgent qu'un compte du personnel verrouillé (8 h).
6. **Hors de l'écran général « Nouvelle demande »** : il ne saisit ni échéance ni pièce. Une demande forcée sans échéance est de toute façon refusée par le service et par la base.
7. **L'agent peut demander après une révocation** : la décision revient au responsable, seul habilité à accorder de nouveau (A1, §4).
8. **Notification de l'auditeur dans l'exécution**, comme le déverrouillage d'un compte qui envoie son lien dans la même exécution. `NotificationAcces` ne lève jamais : un incident de passerelle n'empêche pas le moratoire.
9. **Pièce d'une demande rejetée ou en échec** : elle reste rangée avec la demande, comme trace de ce qui a été examiné. Une nouvelle demande exige un nouveau dépôt.
10. **Motif limité à 900 caractères dans le formulaire** : il laisse la place au numéro de la demande dans le motif du moratoire (1 000 caractères au plus).

## 11. Tests

- **Règle d'accès** :
  - sans paiement ni déblocage : bloqué ;
  - avec un déblocage en cours : disponible, codes remettables, lien et lettre PDF ;
  - après révocation : bloqué ;
  - après paiement : régularisé automatiquement.
- **Droits** :
  - l'agent ne peut pas accorder de moratoire ;
  - l'agent prolonge une seule fois et d'au plus 30 jours ;
  - refus sur un dossier retiré, exclu, déjà soldé, ou s'il y a déjà un déblocage en cours.
- **Notification** : SMS et e-mail du lien à l'enregistrement du déblocage.
- **Relances** : à l'échéance, puis tous les 7 jours, pas avant ; textes ASCII et d'un seul segment (`GabaritsSmsTest`).
- **Contrôle `DebEchus`** et sa visibilité dans le contrôle interne.
- **Export Word groupé** des lettres d'accès : il inclut les débloqués.
- **Filament** : action visible selon le rôle, filtre et colonne de la liste.

## 12. Déploiement

Migration de `deblocages_acces` et des permissions, puis `config:clear`, `migrate`, `permission:cache-reset`, `optimize`, et redémarrage de la file. La tâche planifiée est prise en compte par le cron `schedule:run` déjà en place. L'espace de l'auditeur (portail apply) doit être reconstruit pour la mention « accès accordé avant paiement ».
