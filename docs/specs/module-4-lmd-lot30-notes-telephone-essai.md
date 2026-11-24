# Module 4 — LMD, lot 30 : saisie des notes sur téléphone et essai à blanc ligne à ligne

Étape 6 de l'audit LMD du 22/11/2026, second lot (ordre validé par le Chef de projet le 24/11/2026). Le premier lot
(« Mes cours », « Mes heures ») est le lot 29.

## Constat

- L'écran de saisie des notes est un tableau de cinq ou six colonnes : sur un téléphone, il se fait défiler de côté et
  les champs sont trop petits.
- L'essai à blanc d'un import Excel ne rend qu'un nombre (« n notes à enregistrer ») quand le fichier est valide, et
  seulement les lignes en erreur sinon. L'agent ne voit pas, ligne par ligne, ce que l'import ferait.

## Ce que le lot change

### Saisie sur téléphone

Sous 640 px de large, chaque auditeur devient une carte : matricule, nom, note actuelle, champ de note, absence
(examen), motif. Sans note déjà saisie, la carte ne montre ni « note actuelle » ni motif. Les champs sont agrandis. Au-delà
de 640 px, le tableau est inchangé. Même balisage, mêmes champs, mêmes règles : seule la mise en page change.

### Essai à blanc ligne à ligne

L'essai à blanc (« Contrôler seulement, sans enregistrer ») rend toujours un rapport Excel, une ligne par ligne remplie
du fichier : ligne du fichier, matricule, nom, note actuelle, note du fichier, résultat, problème. Résultats :

| Résultat | Sens |
|---|---|
| Nouvelle note | aucune note n'existait |
| Note modifiée | une note existait, le motif est fourni |
| Inchangée | la note du fichier est celle déjà enregistrée |
| Erreur | matricule non inscrit ou en double, note hors bornes, absent avec note, motif manquant |

La notification résume les comptes. Les lignes laissées sans note sont comptées à part, pas listées.

## Règles

- R1. L'essai n'écrit rien, erreurs ou non.
- R2. L'essai passe par les mêmes contrôles que la saisie (`SaisieNotes::analyser`, partagé par `saisir` et `examiner`) :
  ce que l'essai annonce est ce que l'import fera.
- R3. L'import réel est inchangé : une seule ligne en erreur et rien n'est enregistré, avec le rapport des erreurs (lot 20).
- R4. Pas de migration, pas de droit nouveau.

## Tests

`backend/tests/Feature/Lmd/SaisieNotesTest.php` : essai à blanc (nouvelle, inchangée, erreur, modifiée avec et sans
motif, rien d'écrit, rapport Excel) ; les tests existants de la saisie et de l'import restent verts.
Rendu contrôlé par capture à 390 px et à 1280 px.
