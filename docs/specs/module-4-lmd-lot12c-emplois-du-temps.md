# Module 4 — LMD, lot 12c : emplois du temps

**Date** : 2026-11-07. **Décideur** : M. ABE ETOUMOU Anatole, Chef USI. **ADR** : 0011. Feuille de route : `module-4-lmd-feuille-de-route.md`. Suite des lots 10 (séances) et 12a (calendrier).
**Sources** : dossier « PSSFP Système » : décision D21 ; `sql/14_schema_planning_gantt.sql`, §3 et §6 (salles, plages horaires, emplois du temps, conflits, volume horaire, avancement d'une période, sous-barres du Gantt).

La programmation des enseignants, avec les quotas horaires par cours et par période (D22, `sql/15`), fait l'objet du lot 12d.

## 1. Objectif

La Scolarité construit l'emploi du temps de chaque période de cours du calendrier, parcours par parcours. La plateforme signale les conflits avant la publication. Une fois publié, l'auditeur voit son emploi du temps semaine par semaine dans son espace. Les séances sont celles du module d'assiduité (lot 10) : elles sont programmées ici, puis pointées.

## 2. Règles

1. **Salles** : code unique, libellé, capacité, nature (présentiel ou virtuelle, avec lien de réunion permanent), active ou non.
2. **Plages horaires** : grille paramétrable (code, heure de début, heure de fin, ordre). Trois plages sont livrées :
   - 08:00 – 11:00 ;
   - 11:30 – 14:30 ;
   - 15:00 – 18:00.
3. **Emploi du temps** :
   - il porte sur une période de cours (événement `periode_cours`) d'un calendrier signé, et sur un parcours (le M1 est le parcours de tronc commun) ;
   - si la période indique un niveau, le parcours est de ce niveau ;
   - il n'y a qu'un emploi du temps par période et par parcours ;
   - il est en brouillon, puis publié.

   Un emploi du temps publié se modifie séance par séance : ajout, report ou annulation, avec motif. Il n'est pas versionné, car ses séances peuvent déjà être pointées (écart assumé avec D21).
4. **Séance programmée**. Elle porte :
   - un ECUE de la maquette validée du parcours pour l'année ;
   - une date et une plage horaire (ou des heures libres) ;
   - une salle ;
   - l'enseignant (compte utilisateur) ;
   - une nature (CM, TD, TP ou évaluation) et une modalité (présentiel, distanciel, hybride).

   **Répétition hebdomadaire** : on peut créer une même séance chaque semaine jusqu'à une date. Les jours non ouvrés, les interruptions et les examens du niveau sont sautés, et le nombre de séances sautées est indiqué.
5. **Report** : la séance passe à « reportée », avec motif, et une séance de remplacement est créée à la nouvelle date. Elle est liée à la séance d'origine. Une séance tenue ne se reporte pas.
6. **Contrôles** (séances non annulées ni reportées, emplois du temps du même calendrier).
   - **Bloquants** (ils empêchent la publication) :
     - enseignant programmé deux fois au même moment ;
     - salle occupée deux fois au même moment ;
     - parcours programmé deux fois au même moment ;
     - séance hors de la période de cours ;
     - séance pendant une interruption, pendant les examens du niveau ou un jour non ouvré.
   - **Cours commun** : deux séances du même ECUE (même code), à la même heure, dans deux parcours du même niveau, forment un cours commun et ne sont pas en conflit pour l'enseignant ni pour la salle.
   - **Alertes** :
     - salle trop petite pour les inscrits de l'UE (séance non distancielle, salle en présentiel) ;
     - enseignant sans attribution sur l'ECUE (lot 5) ;
     - volume programmé supérieur au volume de la maquette (CM + TD + TP).
7. **Volume horaire par ECUE** : volume de la maquette, heures programmées (séances prévues et tenues), heures tenues, reste à programmer.
8. **Avancement d'une période de cours** = heures tenues / heures programmées dans ses emplois du temps publiés. Il remplace l'avancement saisi dans le Gantt (D21).
9. **Permission** `lmd.emplois_du_temps` (gestion) : salles, plages, emplois du temps, séances, publication. Rôles : agent et responsable de la scolarité, admin, super_admin.

## 3. Écrans

- **Administration**, Parcours LMD :
  - **Emplois du temps** :
    - liste avec période, parcours, état, nombre de séances et de conflits ;
    - création à partir d'une période d'un calendrier signé ;
    - fiche : conflits et alertes, volume horaire par ECUE, séances (filtre par semaine, regroupement par jour) ;
    - actions sur les séances : ajouter (avec répétition), modifier, reporter, annuler ;
    - publication.
  - **Salles**.
  - **Plages horaires**.
- **Gantt** : l'avancement des périodes de cours vient des séances tenues.
- **Espace auditeur**, rubrique « Emploi du temps » : semaine par semaine, avec navigation, les séances de son parcours (jour, heures, ECUE, nature, enseignant, salle ou lien, modalité, report ou annulation signalés). Seuls les emplois du temps publiés y figurent.

## 4. API

- `GET /v1/applications/me/emploi-du-temps?semaine=AAAA-MM-JJ` : semaine contenant la date (lundi au dimanche), par défaut la semaine en cours. Réponse :
  - la semaine affichée, la précédente et la suivante ;
  - `premiere` et `derniere` : premier et dernier jour couverts par un emploi du temps publié ;
  - les séances.

  Les séances viennent des emplois du temps publiés du parcours de l'inscription en cours. Sans emploi du temps publié, la liste est vide.

## 5. Critères d'acceptation

1. Un enseignant, une salle ou un parcours programmés deux fois au même moment produisent un conflit bloquant, et la publication est refusée. Le même ECUE à la même heure dans deux options du M2 n'en produit pas.
2. Une séance pendant l'interruption, pendant les examens du niveau, un jour non ouvré ou hors de la période est un conflit bloquant.
3. La répétition hebdomadaire saute les jours non ouvrés et l'interruption, et indique combien de séances elle a sautées.
4. Le report crée la séance de remplacement liée. Il est refusé sans motif, et pour une séance tenue.
5. Volume : 12 h tenues sur 30 h programmées donnent 40 % d'avancement pour la période, repris dans le Gantt.
6. L'auditeur ne voit que les séances publiées de son parcours, semaine par semaine. Un brouillon reste invisible.
