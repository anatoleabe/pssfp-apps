# Module 4 — LMD, lot 14b-2 : relances graduées du contrôle interne

**Date** : 2026-11-10. **Décideur** : M. ABE ETOUMOU Anatole, Chef USI. **ADR** : 0011.
**Sources** : dossier « PSSFP Système » : décisions D30 (relances) et D31 (relances devenues messages) du 30/10/2026 ; `sql/19_schema_supervision.sql` (tables `regle_relance`, `relance`, fonction `generer_relances`) ; `sql/20_schema_messagerie_demandes.sql` (`relance_vers_message`).

## 1. Objectif

Qu'une anomalie du contrôle interne (lot 14a) ne reste pas sans suite. À son échéance, son responsable est relancé. Si elle reste ouverte, la relance monte d'un cran, jusqu'à la direction. Les relances arrivent dans la messagerie interne (lot 14b-1).

## 2. Règles

1. **Responsable** : chaque contrôle désigne le rôle chargé de traiter ses anomalies.

   | Contrôle | Responsable |
   |---|---|
   | `PAI_RAPPROCHEMENT`, `PAIE_ETATS_BLOQUES` | service administratif et financier |
   | `REQ_RETARD` | agent de scolarité |
   | `REQ_NON_COTEES` | responsable de scolarité |
   | `SEC_SEPARATION`, `SEC_COMPTES`, `SYS_CONTROLES`, `SYS_DESTINATAIRES` | administrateur |

2. **Barème des relances**, par criticité. Le délai court à partir de l'échéance de l'anomalie.

   | Criticité | Niveau 1 (responsable) | Niveau 2 (responsable de scolarité) | Niveau 3 (direction) |
   |---|---|---|---|
   | bloquant | à l'échéance : interne, courriel, SMS | + 2 jours : interne, courriel, SMS | + 5 jours : interne, courriel |
   | majeur | à l'échéance : interne, courriel | + 5 jours : interne, courriel | + 15 jours : interne |
   | mineur | à l'échéance : interne | + 15 jours : interne | — |

   La direction est le rôle « chef de la structure ».
3. **Pas de retour au responsable** : une escalade ne revient jamais au rôle déjà relancé au niveau 1. Elle passe alors à la direction, et, si la direction est elle-même responsable, à l'administrateur.
4. **Destinataires** : les comptes du personnel qui détiennent le rôle visé. À défaut, l'administrateur. À défaut encore, le super administrateur. Si personne n'est joignable, la relance est notée sans destinataire, et le contrôle `SYS_DESTINATAIRES` le signale.
5. **Une relance par anomalie et par niveau**, jamais deux : une même relance n'est pas renvoyée au passage suivant. Les niveaux dus en même temps partent ensemble (une anomalie très en retard reçoit ses niveaux 1, 2 et 3 le même jour). Seules les anomalies ouvertes ou en traitement sont relancées : ni les justifiées, ni les corrigées.
6. **Regroupement** : les relances d'un même passage, de même niveau et pour le même rôle, forment un seul message. Il liste les anomalies concernées et pointe vers l'écran « Contrôle interne ».
   - Le message ne porte aucune donnée personnelle : contrôle, numéro d'anomalie, ancienneté, échéance, criticité.
   - Priorité : normale au niveau 1, haute au niveau 2, urgente au niveau 3.
   - Copies externes : les canaux réunis des relances regroupées. Comme toute copie (lot 14b-1), elles ne portent que l'objet et le lien.
7. **Ajout seul** : une relance enregistrée ne se modifie ni ne se supprime.
8. **Exécution** : chaque matin, après les contrôles de 05:30 (`supervision:executer`), puis à la demande depuis l'écran « Contrôle interne ».
9. **Écran « Contrôle interne »** : la colonne « Relances » du tableau de bord donne le nombre de relances envoyées par contrôle. La liste des anomalies affiche le dernier niveau de relance.

## 3. Modèle de données

`relances_anomalies` : `anomalie_id`, `niveau` (1 à 3), `destinataire_role` (rôle réellement relancé, après repli), `canaux` (jsonb), `message_id`, `destinataires` (nombre de comptes), `cree_le` ; unique (`anomalie_id`, `niveau`). Un déclencheur refuse toute modification ou suppression.

## 4. Tests

1. Niveaux selon le retard et la criticité, pour les trois criticités ; aucun niveau 3 pour une anomalie mineure.
2. Une relance n'est jamais renvoyée ; une anomalie justifiée ou corrigée n'est pas relancée.
3. Escalade : jamais au responsable déjà relancé, direction, puis administrateur ; repli sur l'administrateur puis le super administrateur faute de titulaire ; relance sans destinataire signalée par `SYS_DESTINATAIRES`.
4. Regroupement : un message par niveau et par rôle, sans donnée personnelle, avec la priorité et les copies externes du barème.
5. La commande `supervision:executer` relance après les contrôles ; le tableau de bord compte les relances.
