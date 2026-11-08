# Module 4 — LMD, lot 12d : programmation des enseignants

**Date** : 2026-11-08. **Décideur** : M. ABE ETOUMOU Anatole, Chef USI. **ADR** : 0011. Feuille de route : `module-4-lmd-feuille-de-route.md`. Suite des lots 12a (calendrier), 12b (actes) et 12c (emplois du temps).
**Sources** : dossier « PSSFP Système » : décision D22 du 29/10/2026 ; `sql/15_schema_programmation_enseignants.sql` (quotas, programmations, indisponibilités, vues de suivi, neuf contrôles, sous-barres du Gantt).

## 1. Objectif

Pour chaque période de cours du calendrier, la Scolarité fixe le quota horaire de chaque cours. Elle répartit ensuite ce quota entre les enseignants, qui reçoivent leur programmation et l'acceptent ou la déclinent. Les séances de l'emploi du temps consomment la programmation de leur enseignant. La plateforme suit les heures allouées, programmées et tenues. Elle signale les écarts.

L'attribution (lot 5) dit qui enseigne quoi dans l'année ; la programmation dit combien d'heures et quand, période par période.

## 2. Règles

1. **Quota horaire** :
   - il porte sur une période de cours (événement `periode_cours`) d'un calendrier signé et sur un ECUE de la maquette validée de l'année ;
   - il donne les heures de CM, de TD et de TP (au demi-point près) ;
   - il n'y a qu'un quota par période et par ECUE.

   Le parcours découle de la maquette de l'ECUE. Les volumes de la maquette sont portés par l'ECUE : le quota se fixe donc par ECUE et non par UE entière (écart assumé avec `sql/15`).

   Un quota est **proposé**, puis **validé**. Changer un quota validé exige un motif ; le quota passe alors à **modifié** et reste utilisable.

   **Proposition automatique** : pour une période, la Scolarité choisit les parcours et les semestres concernés. La plateforme propose, pour chaque ECUE qui n'a pas encore de quota dans la période, le volume de la maquette moins les quotas déjà fixés dans les autres périodes de l'année.
2. **Programmation d'un enseignant** :
   - elle porte sur la part d'un quota confiée à un enseignant (compte qui a le rôle d'enseignant) ;
   - l'enseignant est responsable ou intervenant ; il n'y a qu'un responsable par quota ;
   - elle donne les heures de CM, de TD et de TP, une fenêtre d'intervention facultative et une modalité ;
   - un même enseignant n'a qu'une programmation active par quota.

   **Circuit** : proposée → validée → notifiée → acceptée, ou déclinée avec un motif. Une programmation peut être annulée avec un motif à toute étape.

   Une programmation proposée, validée ou déclinée se modifie ; elle repasse alors à « proposée ». Une programmation notifiée ou acceptée ne se modifie plus : on l'annule, puis on en crée une nouvelle.
3. **Notification** : la Scolarité notifie les programmations validées, une à une ou pour toute la période. L'enseignant reçoit un courriel qui récapitule ses programmations de la période : cours, heures, fenêtre et modalité. Il répond depuis le back-office, rubrique « Mes programmations ».
4. **Indisponibilités** : un enseignant déclare ses indisponibilités (début et fin, motif) ; la Scolarité peut les déclarer pour lui. Une séance programmée pendant une indisponibilité de son enseignant est un **conflit bloquant** de l'emploi du temps.
5. **Consommation** : la plateforme rattache automatiquement chaque séance d'un emploi du temps à la programmation active de son enseignant pour son ECUE dans la période. Les heures sont décomptées ainsi :
   - les heures **programmées** sont celles des séances prévues et tenues ;
   - les heures **tenues** sont celles des séances tenues (pointage, lot 10) ;
   - le **reste** est égal aux heures allouées moins les heures tenues.
6. **Charge et plafond** : la charge d'un enseignant pour une période est la somme des heures allouées, programmées et tenues sur ses programmations non annulées ni déclinées. Le plafond (`charge_max_enseignant_periode`, en heures) est un paramètre ; vide, il n'y a pas de plafond.
7. **Contrôles** (non bloquants, ils sont affichés au suivi) :
   1. quota non entièrement réparti entre les enseignants, ou réparti au-delà ;
   2. quotas de l'année d'un ECUE différents du volume de sa maquette (CM + TD + TP) ;
   3. enseignant programmé au-delà de ses heures allouées pour un cours ;
   4. charge d'un enseignant au-delà du plafond de la période ;
   5. fenêtre d'intervention hors de la période ;
   6. séance sans programmation de son enseignant pour ce cours ;
   7. séance pendant une indisponibilité de l'enseignant (bloquant dans l'emploi du temps) ;
   8. séance hors de la fenêtre d'intervention prévue ;
   9. programmation proposée ou validée, non notifiée alors que la période est ouverte.
8. **Emploi du temps** : le contrôle 7 s'ajoute aux conflits bloquants de l'emploi du temps. Le contrôle 6 s'ajoute à ses alertes, dès que la période a au moins un quota.
9. **Gantt** : sous chaque période de cours, une sous-barre par programmation. Elle couvre la fenêtre prévue, ou à défaut les séances réelles, ou à défaut la période, et montre son avancement (heures tenues / heures allouées).
10. **Note de programmation des enseignements** (acte `PROGRAMMATION_ENSEIGNANTS` du lot 12b, 15 jours avant l'ouverture de la période) : le texte proposé reprend la liste des programmations validées, notifiées ou acceptées de la période.
11. **Permissions** :
    - `lmd.programmation` (gestion) : quotas, programmations, indisponibilités de tous les enseignants. Rôles : agent et responsable de la scolarité, admin, super_admin ;
    - `lmd.programmation_valider` (sensible) : valider les quotas et les programmations, les notifier, fixer le plafond. Rôles : responsable de la scolarité, admin, super_admin ;
    - `lmd.programmation_repondre` (autonome) : voir ses programmations, les accepter ou les décliner, déclarer ses indisponibilités. Rôles : enseignant, super_admin.

## 3. Écrans

- **Administration**, Parcours LMD :
  - **Quotas horaires** :
    - liste par période (filtres : calendrier, période, parcours, état) ;
    - pour chaque quota : ECUE, heures CM/TD/TP, heures réparties, programmées et tenues, avancement, état ;
    - actions : proposer les quotas d'une période, ajouter, modifier (avec motif une fois validé), valider ;
    - fiche d'un quota : ses programmations ; programmer un enseignant, modifier, valider, notifier, annuler.
  - **Programmations** :
    - liste de toutes les programmations (filtres : période, enseignant, état) ;
    - action groupée « Notifier » ;
    - pour un enseignant, cette liste devient « Mes programmations » : il n'y voit que les siennes et répond « Accepter » ou « Décliner » (avec motif).
  - **Indisponibilités des enseignants** : un enseignant n'y voit et n'y déclare que les siennes.
  - **Suivi de la programmation** : choix d'une période, puis :
    - les neuf contrôles ;
    - la charge de chaque enseignant face au plafond, qui se modifie depuis cet écran ;
    - l'avancement de chaque quota.
- **Gantt** : sous-barres des enseignants sous chaque période de cours.

## 4. Critères d'acceptation

1. Un quota de 30 h réparti 20 h + 6 h produit le contrôle « quota non entièrement réparti ». Un second responsable sur le même quota est refusé.
2. Changer un quota validé sans motif est refusé ; avec un motif, le quota passe à « modifié ».
3. Le circuit proposée → validée → notifiée → acceptée fonctionne, et la notification envoie le courriel à l'enseignant. Décliner sans motif est refusé. Un enseignant ne voit pas la programmation d'un autre, et ne peut pas y répondre.
4. Une séance de l'enseignant pour l'ECUE se rattache à sa programmation. Avec 12 h tenues sur 30 h allouées, l'avancement est de 40 % au suivi et dans le Gantt.
5. Une séance pendant une indisponibilité de l'enseignant bloque la publication de l'emploi du temps.
6. Les contrôles se déclenchent sur leurs cas : charge au-delà du plafond, fenêtre hors de la période, séance hors de la fenêtre, séance sans programmation, programmation non notifiée alors que la période est ouverte, quotas de l'année différents de la maquette.
7. La proposition automatique crée, pour chaque ECUE retenu, le volume de la maquette moins les quotas des autres périodes.
