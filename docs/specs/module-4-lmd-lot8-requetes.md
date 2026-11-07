# Module 4 — LMD, lot 8 : requêtes

**Date** : 2026-11-07. **Décideur** : M. ABE ETOUMOU Anatole, Chef USI. **ADR** : 0011. Feuille de route : `module-4-lmd-feuille-de-route.md`.
**Sources** : dossier « PSSFP Système » : décisions D26, D27, D28 ; RG-055 à RG-058 ; « Fiche de requête des notes » du PSSFP ; `sql/17_schema_requetes.sql`.

## 1. Objectif

L'auditeur dépose depuis son espace :
- ses **requêtes de notes**, une par ECUE ;
- ses **demandes de documents** : lettre de stage, attestation de stage, PV de soutenance, autre.

Il suit chaque étape en temps réel. La scolarité cote, instruit, présente au jury des requêtes, fait valider par le chef de la commission, puis applique. Une note ne change qu'à la validation définitive, avec la trace de la requête.

## 2. Règles

1. **Requête de note** (fiche du PSSFP) :
   - une par ECUE ;
   - note visée : CC, assiduité ou examen ;
   - motif : non-publication, erreur de report, absence de note, autre ;
   - description ;
   - pièces : PDF, JPEG ou PNG, 5 Mo au plus chacune, stockage privé.
2. **Fenêtre de dépôt** (D28) :
   - la requête n'est recevable qu'une fois l'étape visée verrouillée ;
   - si une délibération publiée couvre la session : jusqu'à la date limite des requêtes (publication + 10 jours) ;
   - une requête identique encore ouverte (même ECUE, même note) est refusée.
3. **Accusé de réception** : numéro `RQ-AAAA-NNNNNN`, date et heure, empreinte SHA-256 du contenu et des pièces.
4. **Circuit** (D27, D28) :
   1. déposée ;
   2. cotée : automatiquement à l'agent de scolarité actif le moins chargé, ou à la main par le responsable ;
   3. en instruction : l'agent coté seul réunit les éléments ; la **photocopie du PV des notes** est exigée ;
   4. instruite ;
   5. au jury : inscrite à une séance du jury des requêtes ;
   6. statuée : fondée, partiellement fondée (note retenue), non fondée, ou ajournée (retour en instruction) ;
   7. validée : validation définitive du PV de séance par le chef de la commission des requêtes (permission `lmd.requetes_valider`) ;
   8. appliquée (la note change) ou clôturée (non fondée).

   Hors circuit :
   - irrecevable : décision motivée ;
   - annulée : par l'auditeur, avant la cotation.
5. **Séance du jury des requêtes** :
   - jury désigné (nature « requêtes ») ;
   - PV numéroté, une ligne par requête ;
   - la clôture est refusée tant qu'une requête n'a pas de mention ;
   - un PV clos ne se modifie plus.
6. **Application** :
   - la note de l'ECUE prend la note retenue, même si l'étape est verrouillée, avec pour motif le numéro de la requête (historique des notes) ;
   - si une délibération arrêtée couvre la session, elle est signalée « à rectifier ».
7. **Séparation des tâches** :
   - qui instruit ne valide pas ;
   - le demandeur ne voit que les étapes, jamais le détail interne.
8. **Historique** de chaque requête en ajout seul (déclencheur) ; notification à l'auditeur à chaque étape, dans son espace.
9. **Demandes de documents** :
   - déposées à tout moment ;
   - circuit : déposée, cotée, en traitement, document disponible (PDF déposé par l'agent) ou rejetée (motif) ;
   - les documents délivrés automatiquement (lot 7) n'ont pas besoin de demande.

## 3. Écrans

- **Espace auditeur**, rubrique « Mes requêtes » : dépôt (formulaire guidé, pièces), liste, étapes en frise, accusé de réception, document disponible.
- **Administration** :
  - Requêtes : liste par état, agent, échéance ; fiche avec éléments, pièces, actions du circuit ;
  - séances du jury des requêtes et leur PV ;
  - délibérations à rectifier.

## 4. Permissions

| Permission | Rôle |
|---|---|
| `lmd.requetes_traiter` | Agent de scolarité : instruire ses requêtes, traiter les demandes de documents |
| `lmd.requetes_coter` | Responsable : coter, recoter, déclarer irrecevable |
| `lmd.requetes_jury` | Responsable : séances, mentions, clôture du PV |
| `lmd.requetes_valider` | Chef de la commission des requêtes (nouveau rôle `chef_commission_requetes`) : validation définitive |

## 5. Critères d'acceptation

1. Une requête est refusée sur une étape non verrouillée, hors délai, ou en double. Une requête recevable reçoit son accusé (numéro, empreinte).
2. La cotation automatique va à l'agent le moins chargé. L'instruction est refusée à un autre agent, et sans photocopie du PV des notes.
3. La clôture d'un PV est refusée tant qu'une mention manque. Un PV clos ne se modifie plus.
4. La note ne change qu'à la validation définitive, avec la requête pour motif dans l'historique. La délibération arrêtée est signalée à rectifier.
5. L'auditeur voit ses étapes, jamais celles d'un autre. L'historique ne se modifie ni ne se supprime.
