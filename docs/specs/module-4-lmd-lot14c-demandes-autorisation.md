# Module 4 — LMD, lot 14c : demandes d'autorisation

**Date** : 2026-11-11. **Décideur** : M. ABE ETOUMOU Anatole, Chef USI. **ADR** : 0011.
**Sources** : dossier « PSSFP Système » : décision D31 du 30/10/2026 (demande de M. MBIANA) ; `sql/20_schema_messagerie_demandes.sql` (tables `type_demande`, `demande`, `decision_demande`, fonctions `soumettre_demande`, `decider_demande`, `executer_demande`).

## 1. Objectif

Qu'un agent qui n'a pas le droit d'une opération puisse la demander à ceux qui l'ont, sans contourner les droits. La demande est routée vers les bons décideurs. Une annulation exige deux décisions par deux personnes. À l'approbation, la plateforme exécute elle-même l'opération, et tout est tracé.

## 2. Traduction des droits

Le dossier d'origine gradue les droits (lecture, saisie, validation, administration) sur des permissions larges. La plateforme raisonne en permissions précises (Spatie), une par opération. Chaque type de demande désigne donc **la permission de l'opération demandée** : sont décideurs les comptes du personnel qui la détiennent, et seulement eux. Le visa du contrôle interne revient aux titulaires de `supervision.traiter`.

## 3. Catalogue du lot

| Type | Famille | Décideurs (permission) | Visa du contrôle interne | Délai de réponse |
|---|---|---|---|---|
| Levée du verrou d'une étape de saisie des notes | levée de verrou | `lmd.notes_rouvrir` | non | 24 h |
| Validation d'un quota horaire | validation | `lmd.programmation_valider` | non | 48 h |
| Validation d'une maquette | validation | `lmd.maquette_valider` | non | 72 h |
| Demande de validation (autre objet) | validation | `settings.manage` | non | 72 h |
| Annulation d'un émargement d'enseignant validé, non payé | annulation | `lmd.pointage_enseignants` | oui | 48 h |
| Révocation d'un document délivré | annulation | `lmd.documents_revoquer` | oui | 48 h |
| Rejet d'un état de paie vérifié, visé ou transmis | annulation | `lmd.paie_verifier` (`lmd.paie_payer` s'il est transmis) | oui | 48 h |

Reportés, faute de l'opération dans la plateforme :
- **déverrouillage d'un compte** : avec le verrouillage des comptes, au lot 14d ;
- **réouverture d'un PV de requêtes clos** et **annulation d'une requête de note appliquée** : à ajouter d'abord au module des requêtes (lot 8).

## 4. Règles

1. **Soumission** : tout compte du personnel peut soumettre une demande. Il choisit le type, puis l'objet visé parmi ceux qui s'y prêtent (par exemple une étape verrouillée, une maquette en brouillon). Il indique un motif d'au moins 10 caractères.
   - La demande reçoit un numéro `DM-AAAA-NNNNN`, continu sur l'année civile.
   - Son échéance est l'heure de soumission plus le délai de réponse.
   - Qui détient déjà la permission d'un type sans visa agit directement : sa demande est refusée.
   - Un type, et la liste de ses objets, ne s'ouvre qu'à qui peut consulter ces objets : le LMD pour les notes, quotas, maquettes et documents ; les pointages pour les émargements ; la paie pour les états de paie. « Autre objet » est ouvert à tout le personnel.
   - Un compte soumet 10 demandes par heure au plus, et en garde 20 en attente au plus. Une seule demande en cours par objet, garantie par la base.
   - La demande garde l'empreinte de l'objet tel qu'il était à la soumission (pour une maquette, avec ses UE et ECUE).
2. **Routage** : un message (catégorie « demande », avec copie par courriel) part aux décideurs, sauf au demandeur. Faute de décideur, il part à l'administrateur, puis au super administrateur. Les levées de verrou partent en priorité haute.
3. **Décision** :
   - Le demandeur ne décide jamais sa propre demande. Une décision porte un commentaire, obligatoire pour un rejet (10 caractères au moins).
   - Sans visa, l'approbation du responsable exécute l'opération.
   - Avec visa (les annulations), l'approbation du responsable fait passer la demande en « visa attendu » et prévient le contrôle interne. Le visa doit venir d'une autre personne que le responsable et que le demandeur. Il exécute alors l'opération.
   - Un rejet, du responsable ou du contrôle interne, clôt la demande.
4. **Exécution** : la plateforme appelle l'opération existante, sous l'identité du responsable qui a approuvé, avec le motif « Demande DM-… : motif ».
   - Si l'objet a changé depuis la soumission (empreinte différente), l'opération est refusée : le responsable n'approuve que ce qu'il a examiné.
   - Une décision prise sur une fiche périmée (son statut a changé depuis l'ouverture) est refusée : il faut la recharger.
   - Rien n'est supprimé : réouverture, validation, révocation, rejet, annulation.
   - Si l'opération échoue (l'objet a changé entre-temps, par exemple), la demande passe « échec d'exécution » avec la raison, et les décisions restent enregistrées.
   - « Autre objet » n'exécute rien : la validation est accordée, l'action reste au demandeur.
5. **Information** : le demandeur reçoit un message (catégorie « décision ») à chaque étape. Il peut retirer sa demande tant qu'aucune décision n'est prise.
6. **Ajout seul** : les décisions ne se modifient ni ne se suppriment, et il y en a au plus une par qualité (responsable, contrôle interne). Une demande ne se supprime pas ; ce qui a été demandé (type, objet, motif, empreinte, échéance) est figé par la base, et son statut ne suit que les transitions prévues.
   - Les messages de demande et de décision échappent à la limite de débit des messages ordinaires.
7. **Suivi** : un contrôle `DEM_ATTENTE` (majeur, 1 jour, responsable de scolarité) signale les demandes sans réponse après leur échéance. Le contrôle interne les relance (lot 14b-2).
8. **Journal** : soumission, décision, exécution, échec et retrait, dans le journal `messagerie`.
9. **Écran « Demandes »**, sous la messagerie, dans le socle commun :
   - les onglets « À décider », « Mes demandes », et « Toutes » pour `supervision.consulter` ;
   - « Nouvelle demande » ;
   - la fiche avec ses décisions et les actions approuver, rejeter et retirer.

   Pastille : les demandes qui attendent ma décision. Elle remonte dans l'encart « À traiter ».

## 5. Limite connue

Le visa ne s'impose qu'aux opérations passées par une demande. Les titulaires de la révocation des documents et du rejet des états de paie agissent encore directement depuis leurs écrans, sans visa : imposer le visa à ces chemins directs est une décision de la direction, à prendre avant de les fermer.

## 5 bis. Opération ajoutée

**Annuler un émargement validé non payé** (`PointagesEnseignant::annulerValide`) : réservé à `lmd.pointage_enseignants`, avec un motif. Un émargement validé, qui n'est rattaché à aucune ligne de paie, passe « rejeté ». S'il est déjà payé, il faut un état rectificatif.

## 6. Modèle de données

- `demandes_autorisation` : uuid, `numero` (unique), `annee`, `sequence`, `type`, `demandeur_id`, `entite_id`, `objet` (libellé de l'objet au moment de la demande), `motif`, `statut` (`soumise`, `visa_attendu`, `rejetee`, `executee`, `echec_execution`, `retiree`), `soumise_le`, `echeance`, `traitee_le`, `resultat`.
- `decisions_demandes` : `demande_id`, `decideur_id`, `qualite` (`responsable`, `controle_interne`), `decision` (`approuve`, `rejete`), `commentaire`, `decide_le` ; unique (`demande_id`, `qualite`). Ajout seul, par déclencheur.

## 7. Tests

1. Soumission : numéro, échéance, objets proposés par type, motif, refus à qui détient déjà le droit sans visa, message routé aux décideurs (repli sur l'administrateur).
2. Décision sans visa : exécution sous l'identité du responsable, pour chaque type ; refus au demandeur et à un compte sans la permission.
3. Décision avec visa : visa attendu, puis visa par une autre personne ; refus du même décideur ; rejet au visa.
4. Échec d'exécution enregistré sans perdre les décisions ; retrait ; décisions en ajout seul.
5. `DEM_ATTENTE` ; écran : onglets, pastille, accès.
