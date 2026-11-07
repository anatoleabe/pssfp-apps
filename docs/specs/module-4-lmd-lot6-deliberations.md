# Module 4 — LMD, lot 6 : délibérations

**Date** : 2026-11-07. **Décideur** : M. ABE ETOUMOU Anatole, Chef USI. **ADR** : 0011. Feuille de route : `module-4-lmd-feuille-de-route.md`.
**Sources** : dossier « PSSFP Système » :
- décisions D2, D4, D14, D15, D17, D20, D35 et D37 ;
- RG-020 à RG-023 et RG-030 à RG-041 ;
- paramètres V-02 à V-09 ;
- `sql/22_schema_deliberations.sql` et `sql/23_corrections_audit.sql` ;
- critères DEL-01 à DEL-26.

## 1. Objectif

Le jury arrête les résultats de l'année à partir des notes verrouillées :
- crédits, décision (AD, AS, AJ), moyenne, MGP, rang et mention ;
- avec, le cas échéant, des mesures motivées.

Le PV est numéroté, scellé, signé, puis publié. La plateforme calcule ; le jury décide et chaque décision est tracée.

## 2. Modèle

| Table | Contenu |
|---|---|
| `jurys` | Année, niveau, parcours (ou tous), nature, objet ; acte de désignation (référence, date, signataire, pièce numérisée) ; date de séance. Composition figée dès l'ouverture d'une séance. |
| `membres_jury` | Rôle (président, vice-président, rapporteur, membre), rang, titre, nom, compte pour la signature ; un seul président et un seul rapporteur (index partiels) |
| `deliberations` | `uuid` ; année, niveau, parcours, session, jury ; états : simulation, arrêtée, rectifiée. Contenu : séance, quorum, décision du jury (« avec » ou « sans délibération »), numéro de PV `PVD-AAAA-AAAA-NNNN`, sceau SHA-256 des résultats et du PDF, publication, date limite des requêtes, rectification (deliberation d'origine, motif, acte). |
| `participations_deliberation` | Présence et motif de l'absence de chaque membre, signature |
| `scenarios` | Scénario de base (application stricte des règles) et variantes ; scénario retenu ; effectifs AD, AS, AJ, taux |
| `mesures_jury` | Relèvement, repêchage, cas particulier ; paramètres, motif, ordre, bénéficiaires |
| `resultats_scenario_ue`, `resultats_scenario` | Projection d'un scénario, UE par UE puis auditeur par auditeur |
| `resultats_annuels` | Résultats figés à l'arrêt, en ajout seul (déclencheur) |
| `acquis_ue` | UE capitalisées : auditeur, UE, crédits, note retenue, année, session ; origine (évaluation, délibération) |

## 3. Règles

1. **Population** : inscriptions annuelles de l'année et du niveau (et du parcours, s'il est précisé) qui ont une inscription pédagogique.
2. **Préalables** :
   - bloquants :
     - pas de jury ;
     - jury sans président ou sans rapporteur ;
     - inscription sans UE ;
     - étape de saisie de la session non verrouillée sur un ECUE de la population ;
   - avertissement : acte de désignation non numérisé.
3. **Scénario de base** (D35) :
   - chaque UE de la maquette de référence est prise avec sa note de la session (lot 5) ;
   - une UE déjà acquise une année antérieure (même UE, même niveau) est capitalisée ;
   - UE acquise : au moins 12, sans note éliminatoire (D1, D16).
4. **Mesures du jury** (D37 ; limites dans les règles de l'année) :
   - **relèvement** : les moyennes d'une plage sont portées à une cible ; l'écart ne dépasse pas `relevement_ecart_max` (0,5 point) ;
   - **repêchage** : pour un auditeur ayant au moins N crédits, les UE à moins de `repechage_ecart_max` (1 point) du seuil sont portées au seuil, au plus K UE par auditeur ;
   - **cas particulier** : une UE d'un auditeur, acquise par décision motivée (30 caractères au moins) ;
   - pour toutes : motif obligatoire ; la note éliminatoire s'impose si `note_eliminatoire_opposable`.

   La note saisie n'est jamais modifiée : la mesure produit une note distincte.
5. **Décision** (D2, D4) :
   - M1 : 60 crédits = AD, sinon AJ ;
   - M2 : 44 crédits écrits = AS, 60 crédits (mémoire compris) = AD, moins de 44 = AJ.

   La décision calculée (sans mesure) est conservée à côté de la décision arrêtée.
6. **Moyenne, MGP, rang, mention** (D14, D15) :
   - moyenne : moyenne des UE pondérée par les crédits ;
   - MGP : points des UE acquises, pondérés par leurs crédits ;
   - rang : par parcours, parmi les AD et AS, à la moyenne puis à la MGP ;
   - mention de l'année : pour les AD et AS seulement ;
   - félicitations proposées à partir de 18.
7. **Séance** : présences, et motif de chaque absence. Quorum : au moins 50 % des membres présents, dont le président (ou tous, selon la règle). Le jury retient un scénario ; sans mesure, la délibération est « sans délibération » (D20).
8. **Arrêt** (permission `lmd.deliberation_arreter`, responsable de la scolarité). Il est refusé :
   - si un préalable bloquant subsiste ;
   - sans séance ou sans quorum ;
   - sans scénario retenu ;
   - si une note a changé après le calcul.

   À l'arrêt :
   - les résultats sont écrits (figés) et les acquis de l'année aussi ;
   - un numéro de PV est attribué et un sceau SHA-256 posé.

   Les scénarios et les mesures ne bougent plus.
9. **Signatures** : chaque membre présent signe depuis son compte ; un absent ne signe pas. La publication exige les signatures de tous les présents, ou du président et du rapporteur, selon la règle.
10. **Publication** (permission `lmd.deliberation_publier`) : date de publication ; date limite des requêtes = publication + 10 jours. L'auditeur ne voit que les résultats publiés et non rectifiés (lot 7).
11. **Rectification** (même permission que l'arrêt) :
    - motif et référence de l'acte rectificatif obligatoires ;
    - une nouvelle délibération est liée à l'ancienne, avec le même jury et les mesures du scénario retenu ;
    - à son arrêt, l'ancienne passe « rectifiée » et ses acquis sont remplacés ;
    - les écarts (favorables, défavorables) sont portés au PV ;
    - une décision défavorable n'est admise que dans les 120 jours suivant la publication (règle de l'année).
12. **Documents** :
    - PV de délibération en PDF : en-tête bilingue, jury, présences et absences motivées, mesures en clair, résultats, synthèse, signatures, sceau ;
    - liste d'affichage sans notes.

## 4. Écrans

- **Jurys** : désignation, membres, acte numérisé.
- **Délibérations** :
  - création ;
  - fiche : préalables, scénarios comparés, mesures, résultats du scénario, cas limites (AJ à 8 crédits ou moins de la décision supérieure), séance ;
  - actions : calculer, dupliquer, retenir, arrêter, signer, publier, rectifier, PV, liste d'affichage.
- **Fiche auditeur** : résultats arrêtés de l'année.

## 5. Permissions

| Permission | Rôle |
|---|---|
| `lmd.deliberation_preparer` | Agent et responsable : jurys, délibérations, scénarios, mesures, séance |
| `lmd.deliberation_arreter` | Responsable : arrêt et rectification |
| `lmd.deliberation_publier` | Responsable : publication |
| Signature | Tout compte rattaché à un membre présent du jury |

## 6. Critères d'acceptation

1. Le scénario de base applique strictement les règles : décisions AD, AS et AJ en M1 et en M2 ; UE capitalisée comptée une fois.
2. Les mesures :
   - le relèvement et le repêchage respectent leurs limites et la note éliminatoire ;
   - le cas particulier exige son motif ;
   - les bénéficiaires sont tracés, avant et après.
3. L'arrêt est refusé :
   - avant la séance ;
   - sans quorum ;
   - par l'agent ;
   - après une note modifiée.

   Accepté, il fige les résultats : la base refuse ensuite leur modification.
4. La publication est refusée sans les signatures requises. Un absent ne signe pas.
5. La rectification crée un PV lié. L'ancien passe « rectifié » à l'arrêt du nouveau.
6. Le PV en PDF porte le numéro, le jury, les mesures, les résultats et le sceau.
