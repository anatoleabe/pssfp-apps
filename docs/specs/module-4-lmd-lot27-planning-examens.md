# Module 4 — LMD, lot 27 : planning d'examens

> Étape 7 de l'audit `docs/audits/lmd-gestion-academique-2026-11.md`. Choix validés par M. ABE le 24/11/2026.
> Les notifications (publication, report) font l'objet du lot 28.

## 1. Besoin

La scolarité dresse aujourd'hui le planning des examens sur tableur : aucune détection des salles ou des
surveillants pris deux fois, des listes d'émargement refaites à la main pour chaque salle. La plateforme
connaît déjà les sessions d'examens du calendrier, les salles, les inscrits et leur situation financière.

## 2. Règles

1. **Un planning par session d'examens du calendrier** (événement de type `examens` ou `rattrapage` d'un
   calendrier signé). Il couvre le niveau et le parcours de l'événement ; sans niveau, toute l'année.
2. **Épreuve** : un code d'ECUE, une date, une heure de début et de fin (plage de la grille ou heures libres).
   Un ECUE partagé par plusieurs options (même code dans plusieurs maquettes validées) donne **une seule
   épreuve commune**. Un code ne figure qu'une fois par planning.
3. **Candidats** : les inscrits à l'UE (statut « inscrit ») de chaque ECUE portant ce code ; en rattrapage, les
   seuls éligibles au rattrapage. Rien n'est stocké : la liste se calcule à la lecture.
4. **Salles** : une ou plusieurs par épreuve, dans un ordre. **Répartition calculée à la lecture** : candidats
   par ordre alphabétique (nom, prénom), salles remplies l'une après l'autre jusqu'à leur capacité. Une salle
   sans capacité renseignée reçoit tous les candidats restants.
5. **Surveillants** : des comptes de la plateforme (hors candidats), affectés à une salle de l'épreuve.
6. **Contrôles bloquants** (la publication est refusée) : épreuve hors des dates de la session ; jour non
   ouvré ; épreuve sans salle ; capacité insuffisante ; salle prise par deux épreuves qui se chevauchent ;
   surveillant placé dans deux salles à la même heure ; candidat convoqué à deux épreuves à la même heure.
   Les doubles se cherchent dans tous les plannings.
7. **Alertes** (non bloquantes) : salle sans surveillant ; salle occupée par une séance de cours à la même heure.
8. **Publication** : brouillon → publié. Un planning publié reste modifiable (report d'une épreuve), mais une
   modification qui crée un conflit bloquant est refusée. Une épreuve ne se supprime que dans un brouillon.
9. **Situation financière** (décision D5, lot 19) : l'auditeur en retard sans levée de restriction reste sur la
   liste, avec la mention « Non autorisé à composer (situation financière) ».
10. **Droit** : `lmd.emplois_du_temps` (celui des emplois du temps) pour écrire ; lecture ouverte à
    `lmd.consulter`. Aucun droit nouveau, aucune migration de rôles.

## 3. Livrables

- Tables `plannings_examens`, `epreuves_examen`, `epreuve_examen_salle`, `epreuve_examen_surveillant`.
- Services `PlanningsExamens` (création, épreuves, candidats, répartition, publication) et
  `ConflitsPlanningExamens`.
- Écran « Planning d'examens » (Planification) : liste, fiche avec conflits et répartition, épreuves.
- PDF : planning de la session ; listes d'émargement, une page par salle (surveillants en pied).
- API `GET /v1/applications/me/examens` : épreuves publiées de l'auditeur connecté, avec sa salle.
- Portail : section « Mes examens » en tête de la page « Mon emploi du temps » (affichée dès qu'une épreuve
  publiée le concerne), avec l'avertissement « non autorisé à composer » le cas échéant.

## 4. Hors périmètre

Placement numéroté dans la salle, convocation individuelle, surveillants sans compte, notifications (lot 28).

## 5. Tests

`tests/Feature/Lmd/PlanningExamensTest.php` : épreuve commune et répartition par capacité ; contrôles
bloquants et refus de publication ; modification refusée d'un planning publié si elle crée un conflit ;
rattrapage limité aux éligibles ; émargement par salle avec mention financière ; API de l'auditeur
(propriété stricte, planning non publié invisible) ; accès à l'écran selon le rôle.
