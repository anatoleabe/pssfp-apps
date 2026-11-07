# Module 4 — LMD, lot 4 : référentiel pédagogique

**Date** : 2026-11-07. **Décideur** : M. ABE ETOUMOU Anatole, Chef USI. **ADR** : 0011. Feuille de route : `module-4-lmd-feuille-de-route.md`.
**Sources** :
- maquette officielle « Programmes de formation du PSSFP », septembre 2026 : M1 tronc commun, cinq options de M2 ;
- dossier « PSSFP Système » : décisions D1, D2, D10, D14 à D16 et D22, schéma `10_schema_socle_lot1.sql` et `11_schema_evaluations_lot2.sql`.

## 1. Objectif

Avant toute note, la plateforme doit savoir :
- ce que chaque auditeur doit suivre : son parcours et la maquette de l'année ;
- selon quelles règles il est évalué : les règles d'évaluation de l'année.

L'auditeur voit sa maquette dans son espace, à la rubrique « Mon parcours ».

## 2. Modèle

| Table | Contenu |
|---|---|
| `parcours` | TC (M1), FFCP, GTFP, ACFP, EPGP, MPP (M2) ; libellé ; niveau ; spécialité du dossier de candidature correspondante |
| `unites_enseignement` | Catalogue des UE : code unique, intitulé. Une UE commune à plusieurs maquettes n'existe qu'une fois. |
| `maquettes` | Une par année académique et par parcours. États : brouillon, validée, archivée. |
| `maquette_ues` | UE d'une maquette : semestre (7 à 10), crédits, pondérations CC, TP (assiduité) et examen, partie (écrite ou soutenance) |
| `maquette_ecues` | ECUE d'une UE : code, intitulé, crédits, heures CM, TD, TP, TPE |
| `regles_evaluation` | Une par année. Valeurs : seuil, note éliminatoire, mode de rattrapage, calcul de la note d'UE, crédits, grilles des grades et des mentions, paramètres du jury. |
| `inscriptions_annuelles.parcours_id` | Parcours de l'inscription |
| `inscriptions_pedagogiques` | Une ligne par inscription annuelle et par UE de la maquette de référence. États : inscrit, dispensé, capitalisée. |

Contraintes en base (ADR-0011) :
- pondérations comprises entre 0 et 1, et de somme 1 ;
- crédits positifs ou nuls ;
- états fermés.

## 3. Règles

1. **Maquette de référence** : celle du parcours de l'inscription, pour son année (D35).
2. **Parcours** :
   - M1 : tronc commun ;
   - M2 : l'option correspondant à la spécialité du dossier.

   Un changement d'option est une action motivée, permission `lmd.parcours_changer`.
3. **Contrôles d'une maquette** :
   - Bloquants :
     - crédits des ECUE différents de ceux de l'UE ;
     - pondérations dont la somme n'est pas 100 % ;
     - code d'ECUE en double dans la maquette ;
     - en M2, aucune UE de soutenance.
   - Avertissements :
     - semestre différent de 30 crédits ;
     - année différente de 60 crédits ;
     - un crédit différent de 15 heures ;
     - UE sans ECUE.
4. **Validation** (permission `lmd.maquette_valider`) : refusée tant qu'un contrôle bloquant subsiste. Une maquette validée ne se modifie plus. Elle se rouvre en brouillon, avec un motif, tant qu'aucun auditeur n'y est inscrit.
5. **Inscription pédagogique** : automatique sur une maquette validée, à l'UE ; les ECUE suivent leur UE. Elle se rejoue sans doublon.
6. **Règles d'évaluation 2026-2027** (créées par défaut, modifiables avec la permission `lmd.regles`, chaque modification journalisée) :
   - pondération par défaut : CC 30 %, TP (assiduité) 15 %, examen 55 % ;
   - seuil 12/20 ; note éliminatoire 7/20 sur l'examen de l'UE ;
   - rattrapage : remplace la moyenne de l'UE (instruction du 06/11, à confirmer face à D10) ;
   - note d'UE : moyenne des ECUE pondérée par les crédits ;
   - crédits : 60 par niveau, 44 crédits écrits en M2 ;
   - grades et points de la grille nationale (D1) ;
   - mentions 12, 14, 16, 18 (D15) ;
   - jury : V-02 à V-09.
7. **Mémoire** : à l'import, l'UE du S10 de chaque option est scindée en une UE écrite de 14 crédits (`MPxx101`) et une UE « Mémoire et stage pratique professionnel » de 16 crédits (`MPxx102`, partie soutenance), qui garde l'ECUE `MPxx1016` (D2, D22 point 2).
8. **Codes du M1** : la maquette reprend, pour le tronc commun, le préfixe de chaque option. La plateforme retient `MPMPP7x` et `MPMPP8x`, codes harmonisés par la Scolarité (D22).

## 4. Écrans d'administration (groupe « Parcours LMD »)

- **Maquettes** :
  - liste par année, parcours et statut, avec crédits et anomalies ;
  - fiche par semestre (UE, ECUE, crédits, heures, pondérations) ;
  - modification en brouillon ;
  - actions Valider et Rouvrir ;
  - import du fichier de maquettes.
- **Parcours** : codes, libellés, spécialité correspondante.
- **Règles d'évaluation** : une fiche par année.
- **Fiche auditeur** : parcours et nombre d'UE inscrites ; actions « Inscrire au parcours » et « Changer de parcours ».

Commandes :
- `lmd:importer-maquettes {fichier} [--appliquer]` ;
- `lmd:inscrire {--annee=} [--appliquer]`.

Sans `--appliquer`, elles ne font qu'un rapport.

## 5. Espace de l'auditeur

- Rubrique « Mon parcours » : semestres, UE et ECUE, crédits et heures. La maquette n'est visible qu'une fois validée.
- API : `GET /v1/applications/me/parcours`.

## 6. Permissions (module « Parcours LMD »)

- **Lecture** : `lmd.consulter`.
- **Gestion** :
  - `lmd.referentiel` : préparer les maquettes en brouillon et les parcours ;
  - `lmd.inscrire` : inscription pédagogique.
- **Actions sensibles** : `lmd.maquette_valider`, `lmd.regles`, `lmd.parcours_changer`.

| Rôles | Droits |
|---|---|
| `scolarite_responsable`, `admin`, `super_admin` | Tout |
| `scolarite_agent` | Lecture et gestion |

## 7. Critères d'acceptation

1. L'import des maquettes 2026-2027 crée 6 maquettes en brouillon, chacune de 60 crédits, sans anomalie bloquante.
2. La validation est refusée tant qu'un contrôle bloquant subsiste, et acceptée ensuite. Une maquette validée ne se modifie plus.
3. L'inscription pédagogique d'un auditeur de M1 2026-2027 crée une ligne par UE du tronc commun. Rejouée, elle ne crée aucun doublon.
4. Un auditeur de M2 est inscrit sur l'option de sa spécialité. Sans spécialité reconnue, il est signalé et n'est pas inscrit.
5. L'auditeur voit sa maquette dans « Mon parcours ». Un autre auditeur n'y a pas accès.
6. Un compte sans permission du module ne voit aucun écran « Parcours LMD ».
