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
