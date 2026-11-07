# Module 4 — LMD, lot 12b : actes produits à partir du calendrier

**Date** : 2026-11-07. **Décideur** : M. ABE ETOUMOU Anatole, Chef USI. **ADR** : 0011. Feuille de route : `module-4-lmd-feuille-de-route.md`. Suite du lot 12a (calendrier).
**Sources** : dossier « PSSFP Système » : décisions D18, D19, D20, D21 ; `sql/13_schema_calendrier_actes.sql` (modèles d'actes, actes, tableau de suivi, contrôle `acte_pendant_interruption`) ; `reprise/25_calendrier_2026_2027.sql` (modèles d'actes).

## 1. Objectif

Les actes qui encadrent l'année sont produits par la plateforme à partir des événements du calendrier, dans les délais qui en découlent. Ce sont :
- les notes de service qui désignent les jurys ;
- les communiqués d'examens ;
- les procès-verbaux ;
- les listes de résultats et d'admis ;
- la programmation des enseignants.

La Scolarité voit les actes à produire dans les 15 jours et ceux qui sont en retard. Les auditeurs retrouvent dans leur espace les communiqués qui les concernent.

## 2. Règles

1. **Modèle d'acte** (D18). Chaque modèle fixe :
   - un code et un libellé ;
   - le type d'événement déclencheur ;
   - le signataire ;
   - un délai en jours, compté par rapport au début de l'événement (négatif : avant) ;
   - un gabarit de texte ;
   - s'il est diffusé aux auditeurs ;
   - s'il est actif.

   Les modèles se modifient avec la permission `lmd.calendrier_reajuster` (responsable de la scolarité). Chaque modification est journalisée.
2. **Modèles livrés** (D19, reprise 25). Les délais suivants sont modifiables :

   | Code | Événement | Signataire | Délai | Diffusé aux auditeurs |
   |---|---|---|---|---|
   | NS_JURY_EXAMENS | examens | Le Chef de la structure | −10 j | non |
   | NS_JURY_RATTRAPAGE | rattrapage | Le Chef de la structure | −10 j | non |
   | COMMUNIQUE_EXAMENS | examens | Le Chef de la structure | −45 j | oui |
   | COMMUNIQUE_RATTRAPAGE | rattrapage | Le Chef de la structure | −25 j | oui |
   | PV_DELIBERATION | jury après rattrapage | Le Président du jury | 0 | non |
   | PV_SYNTHESE | jury de délibération | Le Président du jury | 0 | non |
   | LISTE_RESULTATS | publication | Le Chef de la structure | 0 | non |
   | LISTE_ADMIS | publication des admis | Le Chef de la structure | 0 | non |
   | PROGRAMMATION_ENSEIGNANTS | période de cours | Le Chef de la structure | −15 j (D22, proposé) | non |

   Les listes nominatives ne sont pas diffusées dans l'espace auditeur : chaque auditeur y voit déjà ses propres résultats (lot 7).
3. **Génération** :
   - un acte par événement (hors phases et événements annulés) et par modèle actif de son type ;
   - échéance = début prévu de l'événement + délai du modèle ;
   - objet = libellé du modèle, puis celui de l'événement ;
   - la génération est rejouable et ne crée jamais de doublon ;
   - elle a lieu à la signature du calendrier, à la reprise d'un calendrier signé, et à la demande ;
   - un événement ajouté à un calendrier signé reçoit ses actes à la génération suivante.
4. **Échéances suivies** :
   - le réajustement d'un événement (lot 12a) recalcule l'échéance de ses actes qui ne sont pas encore signés ;
   - le changement du délai d'un modèle a le même effet ;
   - un acte signé garde son échéance ;
   - l'annulation d'un événement annule ses actes non signés, avec le motif « Événement annulé ».
5. **Cycle** (D18) :
   - à produire → brouillon → soumis → signé → diffusé ;
   - annulé, avec motif, possible tant que l'acte n'est pas signé.

   Détail des étapes :
   - **Brouillon** : le texte est prérempli depuis le gabarit, avec les champs {objet}, {evenement}, {promotion}, {niveau}, {debut}, {fin}, {date_seance}, {annee}, {signataire}. Il reste modifiable jusqu'à la soumission. Le projet d'acte se télécharge en PDF.
   - **Soumis** : l'acte peut être renvoyé en brouillon, avec un motif.
   - **Signé** : la plateforme enregistre le numéro attribué par l'autorité, la date de signature, le signataire et l'acte signé numérisé (PDF, avec son empreinte SHA-256). Les quatre sont obligatoires. La base refuse un acte signé ou diffusé sans numéro ni date (contrainte CHECK), et un même numéro ne sert qu'une fois.
   - **Diffusé** : date et auteur de la diffusion. Un acte d'un modèle « diffusé aux auditeurs » devient visible dans l'espace des auditeurs concernés.
6. **Permissions** :
   - `lmd.actes_produire` : rédiger, soumettre, annuler, générer. Rôles : agent et responsable de la scolarité, admin, super_admin.
   - `lmd.actes_signer` : enregistrer la signature, renvoyer, diffuser. Rôles : responsable de la scolarité, chef de la structure, admin, super_admin.
7. **Tableau de suivi** (D18) :
   - un acte non signé dont l'échéance est passée est **en retard** ;
   - un acte non signé dont l'échéance tombe dans les 15 jours est **à traiter** ;
   - les événements en retard d'exécution restent dans les contrôles du lot 12a.
8. **Contrôle `acte_pendant_interruption`** (non bloquant) : l'échéance d'un acte non signé tombe pendant une interruption du calendrier (événement « vacances ») ou un jour non ouvré. Message : « Produire l'acte avant le JJ/MM/AAAA » (veille de l'interruption).
9. **Gantt** : les échéances des actes apparaissent comme repères sur la ligne de leur événement (D21).

## 3. Écrans

- **Administration**, Parcours LMD :
  - **Actes** :
    - tableau de suivi trié par échéance, avec des filtres par alerte (en retard, à traiter), statut, calendrier et modèle ;
    - fiche de l'acte : texte, événement, échéance, historique ;
    - actions : rédiger, soumettre, renvoyer, enregistrer la signature, diffuser, annuler, télécharger le projet ou l'acte signé ;
    - génération des actes d'un calendrier signé.
  - **Modèles d'actes**.
  - Fiche du calendrier : génération des actes, contrôle des actes pendant une interruption, repères d'échéance dans le Gantt.
- **Espace auditeur**, « Mon parcours » : sous le calendrier, les communiqués diffusés de son niveau (ou communs), avec leur numéro et l'acte signé à télécharger.

## 4. API

- `GET /v1/applications/me/calendrier` : ajoute `communiques` (uuid, objet, numéro, date de signature).
- `GET /v1/applications/me/calendrier/communiques/{uuid}` : l'acte signé (PDF), si le communiqué est diffusé, que son modèle est diffusé aux auditeurs et qu'il concerne le niveau de l'auditeur. Sinon 404.

## 5. Reprise

`lmd:importer-calendrier` génère les actes du calendrier 2026-2027 repris. Avec les neuf modèles, cela donne 24 actes : les 21 du dossier source (D19), plus 3 programmations des enseignants ajoutées ensuite (D22).

Trois échéances tombent pendant l'interruption du 20/12/2026 au 04/01/2027 :
- les deux communiqués d'examens de février (02/01/2027) ;
- la programmation de la seconde période de cours (21/12/2026).

Les trois sont à produire avant le 19/12/2026. Le dossier source relevait les deux communiqués (D20).

## 6. Critères d'acceptation

1. La génération sur le calendrier 2026-2027 crée 24 actes. Les échéances suivent les délais : communiqué d'examens de février M1 au 02/01/2027, note de service du jury au 06/02/2027. Une seconde génération ne crée rien.
2. Un réajustement motivé de l'événement déplace l'échéance de ses actes non signés, pas celle d'un acte signé. L'annulation de l'événement annule ses actes non signés.
3. Signature refusée sans numéro, date, signataire ou PDF, ou avec un numéro déjà utilisé. Refusée aussi à l'agent de scolarité ; acceptée pour le responsable.
4. Le contrôle signale les 3 actes dus pendant l'interruption, avec « avant le 19/12/2026 ».
5. Tableau de suivi : un acte dont l'échéance est passée est en retard, un acte dû dans 10 jours est à traiter. Un acte signé ne l'est plus.
6. L'auditeur M1 voit le communiqué diffusé de son niveau et le télécharge. Il ne voit pas celui du M2, ni une note de service, ni un communiqué non diffusé (404).
