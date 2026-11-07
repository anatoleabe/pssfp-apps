# Module 4 — LMD, lot 10 : assiduité

**Date** : 2026-11-07. **Décideur** : M. ABE ETOUMOU Anatole, Chef USI. **ADR** : 0011. Feuille de route : `module-4-lmd-feuille-de-route.md`.
**Sources** : dossier « PSSFP Système » : décisions D12 et D13 ; RG-024 ; `sql/12_schema_assiduites.sql` ; classeur de pointage de la 13e promotion.

## 1. Objectif

La note d'assiduité (la note de TP au PSSFP) est calculée à partir du pointage des séances, au lieu d'un classeur Excel. Elle couvre :
- la présence en salle ;
- la connexion à la réunion Zoom pour les auditeurs à distance (formation hybride synchrone).

## 2. Règles

1. **Règle d'assiduité** de l'année :
   - temps de connexion requis à distance : 75 % de la séance ;
   - retard toléré : 15 minutes ;
   - une absence justifiée compte comme une présence (D13) ;
   - note maximale : 20.

   Ces valeurs sont modifiables et journalisées.
2. **Séance** : ECUE, date, heures de début et de fin, modalité (présentiel, distanciel, hybride), salle, lien de réunion.

   États : prévue, tenue, annulée (avec motif). Seules les séances tenues comptent.
3. **Pointage** d'un auditeur à une séance :
   - statut : présent, absent, absence justifiée ;
   - mode : signature, saisie manuelle ou export Zoom ;
   - durée de connexion pour le distanciel.

   Un pointage se corrige tant que l'étape « assiduité » de l'ECUE n'est pas verrouillée.
4. **Import Zoom** : rapport des participants (CSV exporté du portail Zoom) :
   - les connexions d'un même participant s'additionnent ;
   - le participant est reconnu par son adresse électronique ou son nom ;
   - présent si la durée cumulée atteint le temps requis.

   Les participants non reconnus sont listés, jamais rattachés d'office.
5. **Justification d'une absence** : motif et pièce, décidée par la scolarité.
6. **Note d'assiduité** = 20 × (séances présentes + absences justifiées) ÷ séances tenues (D12).

   Elle est reportée, sur demande de la scolarité, dans la note d'assiduité de l'ECUE (lot 5) tant que l'étape est ouverte. Chaque report entre à l'historique des notes.
7. **Espace de l'auditeur** : par ECUE, séances tenues, présences, absences et note d'assiduité provisoire.

## 3. Écrans

- **Séances** : planification par ECUE ; feuille de présence (pointage en un clic par auditeur) ; import Zoom ; clôture de la séance (tenue) ou annulation.
- **Justifications d'absence**.
- **Assiduité par ECUE** : tableau des auditeurs, présences, note calculée, action « Reporter dans les notes ».

## 4. Critères d'acceptation

1. Quatre séances de 3 h, seuil de 75 % (135 min), connexions de 170, 120, 140 et 0 min : 2 présences, note 10/20 (essai de D12).
2. Une absence justifiée compte comme présente. Une séance annulée ne compte pas.
3. L'import Zoom additionne les connexions d'un participant et liste ceux qu'il ne reconnaît pas.
4. Le report écrit la note d'assiduité dans l'étape ouverte, avec historique. Il est refusé si l'étape est verrouillée.
