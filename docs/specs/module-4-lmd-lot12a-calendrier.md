# Module 4 — LMD, lot 12a : calendrier académique et diagramme de Gantt

**Date** : 2026-11-07. **Décideur** : M. ABE ETOUMOU Anatole, Chef USI. **ADR** : 0011. Feuille de route : `module-4-lmd-feuille-de-route.md`.
**Sources** : dossier « PSSFP Système » : décisions D18 à D21 ; `sql/13_schema_calendrier_actes.sql`, `sql/14_schema_planning_gantt.sql` ; `reprise/25_calendrier_2026_2027.sql` ; prototype `prototype_gantt_2026_2027.html`.

Le lot 12 est découpé en trois parties :

| Partie | Contenu |
|---|---|
| **12a** (ce document) | Calendrier académique, circuit de signature, exécution, réajustement, contrôles, Gantt |
| 12b | Actes produits à partir du calendrier |
| 12c | Emplois du temps, quotas horaires et programmation des enseignants (D22) |

## 1. Objectif

La scolarité prépare le calendrier de l'année (planning synoptique) et le fait signer par le chef de la structure. Elle suit ensuite son exécution et le réajuste au besoin. Elle le pilote sur un diagramme de Gantt. Les auditeurs voient le calendrier publié dans leur espace.

## 2. Règles

1. **Calendrier** d'une année académique, versionné :
   - circuit : brouillon (scolarité) → soumis → signé (chef de la structure, pièce signée numérisée en PDF) → publié ;
   - une nouvelle version remplace la précédente à sa signature ; l'ancienne passe à « remplacé » et reste consultable ;
   - la soumission et la signature sont refusées tant qu'il reste une anomalie bloquante ;
   - un calendrier soumis peut être renvoyé en brouillon, avec un motif.
2. **Événement** :
   - type (examens, publication, rattrapage, jury, période de cours, interruption, mémoires…), libellé, niveau, parcours, promotion, session, vague ;
   - dates prévues ; dates réelles ; dates de référence ;
   - statut d'exécution : prévu, en cours, réalisé, reporté (motif), annulé (motif) ;
   - responsable et avancement.

   Les dates restent dans les bornes de l'année : un millésime erroné est refusé. Un événement d'un seul jour est un jalon.
3. **Phases** : regroupements du Gantt. Leurs dates sont l'enveloppe de leurs événements. Le parent d'un événement est une phase du même calendrier.
4. **Référence** (ligne de base) : à la signature, les dates prévues sont figées. Le glissement est l'écart en jours entre les dates prévues et la référence.
5. **Réajustement** d'un calendrier signé ou publié :
   - toute modification de date exige un motif et la permission `lmd.calendrier_reajuster` (responsable de la scolarité) ;
   - chaque modification de date ou de statut est journalisée en ajout seul : anciennes et nouvelles valeurs, motif, auteur.
6. **Dépendances** : fin-début, début-début, fin-fin ou début-fin, avec un écart minimal et maximal en jours. Un lien est soit bloquant, soit simple alerte. Le système génère les liens suivants (rejouable) :
   - examens → publication de la même session, au plus tard 21 jours après en M2 (D20) ;
   - publication de la session normale → rattrapage (D11) ;
   - examens → jury → publication (D20) ;
   - étapes successives des mémoires d'une promotion ;
   - inscriptions → première période de cours (non bloquant).
7. **Contrôles** :
   - publication sans jury prévu avant ;
   - publication tardive ;
   - rattrapage avant la publication de la session normale ;
   - dépendances non respectées ;
   - événement échu non déclaré réalisé (retard d'exécution).

   Sont **bloquants** : les dépendances bloquantes violées, la publication sans jury et le rattrapage avant la publication.
8. **Jours non ouvrés** : fêtes légales et fermetures, utilisés par les contrôles des emplois du temps (12c).
9. **Reprise** : le planning synoptique 2026-2027 (PROMO 14 en M1, PROMO 13 en M2) et le planning des mémoires de la PROMO 12 sont chargés par une commande :
   - 41 événements, 6 phases ;
   - le calendrier est déjà signé par le chef de la structure : il est chargé signé, avec sa référence figée ;
   - les anomalies de ce calendrier restent signalées.

## 3. Écrans

- **Administration**, Parcours LMD › Calendrier académique :
  - liste des versions ;
  - fiche avec le diagramme de Gantt : barre prévue, barre de référence, réalisé, jalons, retards, aujourd'hui ;
  - tableau des événements, contrôles et anomalies, dépendances avec leur marge, journal des modifications ;
  - actions : événement, phase, dépendance, génération des dépendances, soumission, renvoi, signature (pièce), publication, nouvelle version, exécution d'un événement, réajustement.
- **Jours non ouvrés**.
- **Espace auditeur**, « Mon parcours » : calendrier publié de l'année, limité aux événements de son niveau et aux événements communs.

## 4. Permissions

| Permission | Niveau | Titulaires |
|---|---|---|
| `lmd.calendrier_preparer` | gestion | agent et responsable de la scolarité |
| `lmd.calendrier_reajuster` | sensible | responsable de la scolarité |
| `lmd.calendrier_signer` | sensible | chef de la structure (nouveau rôle, autonome), admin |

## 5. Critères d'acceptation

1. Une date hors de l'année académique est refusée (essai : 2025 dans le calendrier 2026-2027).
2. La soumission est refusée avec les anomalies bloquantes listées, puis acceptée après correction.
3. La signature est refusée sans pièce signée, puis acceptée : référence figée, version précédente remplacée.
4. Sur un calendrier signé, une modification de date sans motif est refusée. Avec motif, elle est journalisée et le glissement est calculé (+3 jours).
5. Les dépendances générées sur le calendrier 2026-2027 signalent :
   - la publication et le rattrapage du M1 dans la même semaine que les examens (juin 2027) ;
   - des publications sans jury prévu avant.
6. L'auditeur ne voit que le calendrier publié, et seulement les événements de son niveau ou communs.
