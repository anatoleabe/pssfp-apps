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
- Tant que A2 n'est pas livré, une prise en charge s'enregistre comme un **moratoire** accordé par le responsable, avec la lettre de l'employeur en pièce.

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
| `engagement_id` | nullable | Ajouté par A2 |
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

### `engagements_prise_en_charge`

- Organisme ; référence et date de la lettre ; lettre scannée (obligatoire).
- Contact pour le règlement : nom, e-mail, téléphone.
- Portée : `droits_inscription` ou `scolarite_annee`.
- Année académique ; échéance ; notes ; auteur ; dates.

Les auditeurs sont rattachés par des déblocages de type `prise_en_charge` (`deblocages_acces.engagement_id`). L'engagement couvre, pour chacun, soit les droits d'inscription, soit le total de l'année.

### `reglements_organismes`

- Engagement ; montant ; date ; mode ; référence (unique par mode parmi les règlements non annulés) ; pièce ; auteur.
- Annulation : par qui, quand, pourquoi.

**Règlement réparti.** La plateforme propose, pour chaque auditeur rattaché, le reste dû dans la portée de l'engagement. Le formulaire est modifiable, et la somme des parts doit égaler le montant. La plateforme crée ensuite un **versement par auditeur** :

- `payeur = employeur` ;
- `reglement_organisme_id` renseigné ;
- référence `<référence>-<MATRICULE>` (respecte l'unicité par mode).

Reçus, situation et régularisation fonctionnent comme pour tout versement. Annuler le règlement annule ses versements (responsable).

### Écran « Prises en charge » (Vie académique)

- Une ligne par engagement : organisme, lettre, nombre d'auditeurs, couvert, réglé, reste, échéance, état.
- Fiche de l'engagement : auditeurs couverts avec leur situation ; actions « Rattacher des auditeurs », « Enregistrer un règlement », « Relancer l'organisme » (e-mail au contact, sur clic seulement), « Lettre ».

## 10. A3 — Demande de moratoire

Nouveau type de demande `accorder_moratoire` (circuit du lot 14c) :

- Décideurs : `scolarite.deblocage_exceptionnel`. Pas de visa.
- L'agent soumet : l'auditeur, l'échéance proposée et le motif.
- À la décision favorable, l'exécution crée le moratoire au nom du responsable.
- Migration : la liste fermée des types (CHECK) est élargie, sur le même précédent que `deverrouiller_compte`.

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
