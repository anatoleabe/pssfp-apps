# Module 4 — LMD, lot 16b : points focaux de classe

**Date** : 2026-11-22. **Décideur** : M. ABE ETOUMOU Anatole, Chef USI. **ADR** : 0011.
**Source** : demande du 22/11/2026. Les points focaux sont dans les salles de classe et servent de relais entre l'administration, les auditeurs et les enseignants.

## 1. Objectif

Donner aux points focaux un compte limité à leur classe, pour ce que la plateforme sait déjà faire : l'assiduité des auditeurs (en salle et à distance) et le contrôle continu.

Leurs autres tâches ne sont pas dans ce lot et auront chacune leur spécification : contrats des enseignants, création des cours Moodle, évaluations des enseignants (contenu à préciser par le chef de projet).

## 2. Règles

1. **Rôle** `point_focal` (« Point focal de classe »), porteur d'un seul droit, `lmd.point_focal`. Aucun accès aux menus de la scolarité, des finances, des évaluations ni de l'administration.
2. **Classe** : un parcours pour une année académique (le tronc commun de M1, ou une option de M2). Un point focal est affecté à une ou plusieurs classes ; une classe peut avoir plusieurs points focaux.
3. **Affectation** : écran « Points focaux » du menu Référentiel pédagogique. Lecture pour qui consulte le module ; création et retrait pour qui prépare le référentiel (`lmd.referentiel`), comme les attributions d'ECUE. Seuls les comptes portant le rôle sont proposés. Tout changement est journalisé.
4. **Périmètre** : les cours d'un point focal sont les ECUE de la maquette de ses classes. Une seule règle (`PerimetreCours`) sert aussi au cloisonnement des enseignants (ECUE attribués), et s'applique à tous les écrans et services ci-dessous.
5. **Assiduité** : sur les cours de sa classe, le point focal planifie une séance, remplit la feuille de présence, importe le rapport Zoom, annule une séance avec motif, et reporte l'assiduité calculée dans l'étape « Assiduité » ouverte.
6. **Contrôle continu** : il saisit le CC et l'assiduité des cours de sa classe quand l'étape est ouverte. Il ne saisit jamais l'examen. Ouvrir, verrouiller et rouvrir une étape restent à la scolarité. L'enseignant attributaire garde le même droit sur ses ECUE.
7. **Ma classe** : écran en lecture seule listant les auditeurs inscrits dans ses classes (matricule, nom, prénoms, classe, statut). Aucune action, aucun lien vers la fiche de l'auditeur, ni finances, ni coordonnées, ni notes d'examen.
8. **Comptes** : créés par un administrateur dans « Utilisateurs », avec le rôle, puis affectés à leur classe. Double authentification comme tout compte du back-office. Aucun compte nominatif n'est créé par le code.

9. **Examen et rattrapage fermés à la lecture** : pour un point focal comme pour un enseignant, l'écran de saisie ne charge ni l'étape « Examen » ni la session de rattrapage, même si le filtre est forcé. Seules les maquettes validées entrent dans le périmètre.
10. **Messagerie et demandes d'autorisation** : ouvertes au point focal comme à tout compte du back-office (module commun). C'est voulu : il relaie les demandes de la salle.

## 3. Modèle de données

- `affectations_points_focaux` : `user_id`, `parcours_id`, `annee_academique`, horodatage ; unique sur les trois.
- Permission `lmd.point_focal` et rôle `point_focal`, créés par migration additive (pas de relance du seeder en production) et ajoutés au seeder pour une base neuve.

## 4. Tests

- Sans affectation, le point focal ne voit ni cours ni auditeur.
- Affecté au tronc commun de M1 : il saisit le CC et l'assiduité d'un ECUE de cette maquette, pas l'examen, pas un ECUE d'une option de M2.
- Il planifie et pointe une séance de sa classe, pas celle d'une autre ; il reporte l'assiduité calculée.
- Il ne peut ni ouvrir, ni verrouiller, ni rouvrir une étape.
- « Ma classe » ne liste que ses auditeurs ; l'écran des auditeurs de la scolarité lui est fermé.
- L'écran « Points focaux » est fermé au point focal ; le responsable y affecte un compte.
- L'examen et le rattrapage ne s'affichent pas, même en forçant les filtres ; une maquette non validée sort du périmètre.
- Le cloisonnement des enseignants reste inchangé.

## 5. Points ouverts

- **Plusieurs salles pour un même parcours** : si le M1 est réparti en groupes avec un point focal chacun, il faudra un découpage en groupes (non prévu ici).
- **Pointage des enseignants** par le point focal (heure d'arrivée et de départ) : non inclus.
- **Évaluations des enseignants** : en attente des éléments du chef de projet.
- **Années passées** : une affectation n'expire pas d'elle-même ; elle se retire à la main en fin d'année.
