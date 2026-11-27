# Module 4 — Scolarité, lot 32 : fiche de l'auditeur en onglets et recherche unique

Demande du Chef de projet du 25/11/2026 : la fiche d'un auditeur est touffue, avec une multitude de boutons en haut qui
débordent même sur un ordinateur ; retrouver un autre auditeur depuis la fiche doit être simple ; la recherche du haut
et celle des listes ne font pas la même chose. Choix validés le 25/11/2026 : onglets, un bouton principal et un menu
« Actions », une recherche unique.

## Constat

- En-tête de la fiche vue par un administrateur : neuf boutons et menus sur une ligne. À 1440 px, les derniers sortent
  de l'écran et le nom de l'auditeur est écrasé sur trois lignes.
- La fiche est une longue page de dix rubriques, suivie de la liste des versements.
- La barre de recherche du haut ne trouve pas les auditeurs (la fiche n'y était pas déclarée) ; elle trouve en revanche
  des pages, articles, fichiers et rôles. Seule la liste des auditeurs cherche par matricule, nom ou téléphone.

## Ce que le lot change

1. **Bandeau résumé** sous le nom : année et niveau, parcours, statut, reste à payer, accès eCampus.
2. **Un bouton principal** (« Enregistrer et émettre le reçu », pour qui encaisse) et **un seul menu « Actions »**,
   rangé par rubrique, séparées par un trait : finances (remise, extrait de compte, moratoire, accès anticipé),
   documents, scolarité et parcours, accès et messages, dossier (étiquettes, statut, suspension, corrections). Les gestes
   gardent leurs noms, leurs contrôles et leurs journaux.
3. **Onglets** : Identité (avec le statut), Finances (situation et liste des versements et reçus, avec leurs gestes),
   Parcours et notes, Documents, Accès eCampus, Historique. Chacun ne s'affiche que pour qui en a le droit. L'onglet ouvert
   reste dans l'adresse (`?onglet=…`).
4. **Recherche unique** : la barre du haut trouve les auditeurs par la même règle que la liste (matricule, nom, prénom,
   téléphone) et ouvre leur fiche ; les auditeurs passent en premier, puis les dossiers de candidature, les comptes et les
   demandes. Pages, articles, fichiers et rôles en sont retirés (ils gardent leur recherche de liste). Raccourci
   Ctrl+K (⌘K sur Mac), partout dans le panneau.

## Hors lot

- Gestes repris dans chaque onglet (ils restent dans le menu « Actions »).
- Revue des autres fiches et du menu par profil (staff, auditeur, enseignant, point focal) : chantier suivant.

## Tests

`backend/tests/Feature/Filament/AuditeurResourceTest.php`, bloc « fiche en onglets et recherche unique (lot 32) ».
Rendu contrôlé par capture à 1280 et 1440 px pour un administrateur et le service financier.
