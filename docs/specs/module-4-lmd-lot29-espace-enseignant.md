# Module 4 — LMD, lot 29 : « Mes cours » et « Mes heures » de l'enseignant

Étape 6 de l'audit LMD du 22/11/2026 (`docs/audits/lmd-gestion-academique-2026-11.md`), premier lot. Ordre validé par
le Chef de projet le 24/11/2026 : « mes cours » et « mes heures » d'abord, en lecture seule ; la saisie des notes sur
téléphone et le rapport d'import ligne à ligne font l'objet du lot suivant.

## Constat

L'enseignant n'a pas de portail : c'est un compte du panneau d'administration, de rôle `enseignant`. Il y trouve ses
programmations, ses émargements, ses indisponibilités et la saisie des notes, mais ni son emploi du temps, ni ses
surveillances, ni le récapitulatif de ses heures et de leur mise en paie.

## Ce que le lot ajoute

Deux écrans en lecture seule, qui ne montrent que les données du compte connecté, lisibles sur un téléphone (cartes
empilées, pas de tableau large).

### Mes cours (`/admin/lmd/mes-cours`, groupe Planification)

1. **Séances à venir** : séances dont il est l'enseignant, d'un emploi du temps publié, à partir d'aujourd'hui. Date,
   heures, cours, nature, classe, salle ou lien de réunion. Une séance annulée ou reportée reste affichée avec son
   statut et son motif.
2. **Surveillances à venir** : épreuves d'un planning d'examens publié dont il est surveillant, avec sa salle.
3. **Séances des 30 derniers jours** : avec l'état de son émargement (à émarger, à valider, validé, rejeté) et un lien
   vers « Mes émargements ».

### Mes heures (`/admin/lmd/mes-heures`, groupe Enseignants et paie)

1. **Par cours et par période** : heures allouées par la programmation, heures programmées à l'emploi du temps publié,
   heures validées, heures en attente de validation, émargements rejetés, heures déjà portées sur un état de paie.
2. **Mes paiements** : ses lignes par état de paie (mois, statut, heures, montant net). Les états en brouillon et les
   états rejetés ne sont pas montrés : leurs montants ne sont pas arrêtés.

## Règles

- R1. Aucune écriture : les deux écrans lisent, ils ne modifient rien.
- R2. Périmètre : uniquement `enseignant_id` (ou surveillant) égal au compte connecté ; aucun paramètre d'URL ne
  désigne un autre enseignant.
- R3. Accès : droit `lmd.pointage_emarger` (porté par le rôle `enseignant`). Un compte qui a aussi un droit de gestion
  (`lmd.consulter` ou `lmd.pointage_enseignants`) ne voit ces écrans que s'il a lui-même une séance ou une
  programmation : le menu de la Scolarité n'est pas encombré.
- R4. Un brouillon d'emploi du temps ou de planning d'examens n'est jamais montré.
- R5. Pas de droit nouveau, pas de migration.

## Hors lot

- Saisie des notes adaptée au téléphone, rapport d'import ligne à ligne (lot suivant).
- Surveillants qui ne sont pas enseignants : ils reçoivent la notification du planning (lot 28) mais n'ont pas cet écran.
- Tableau de bord académique (étape 8).

## Tests

`backend/tests/Feature/Lmd/EspaceEnseignantTest.php` : périmètre (un autre enseignant ne voit rien), brouillon masqué,
séance annulée affichée, surveillance avec sa salle, heures et mise en paie, accès par rôle.
