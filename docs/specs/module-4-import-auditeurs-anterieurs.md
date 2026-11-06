# Module 4 — Import des auditeurs des promotions antérieures (P13)

**Date** : 2026-11-06. **Décideur** : M. ABE ETOUMOU Anatole, Chef USI. Suite du lot 1 §11.

## Décisions

- **Tout importer**, admis en M2 comme recalés : chaque ligne dotée d'un matricule du fichier « Full data » de la scolarité devient un auditeur.
- **Les admis en M2** (liste de la scolarité) reçoivent l'inscription M2 2026-2027 au barème M2. **Les autres** sont importés sans inscription, en attendant la décision sur leur année.
- **Dossiers :**
  - retenus, rattachés à la campagne close de leur promotion (déduite du matricule : 25EFP… = 13e promotion), créée si besoin ;
  - hors PV et hors statistiques : `submitted_at` nul, `ajoute_par` = l'agent de l'USI qui lance l'import.
- **Compte :** compte candidat au téléphone du fichier. L'auditeur entre dans son espace par téléphone et code (« code oublié » par SMS).
- **eCampus :** compte existant, mot de passe inconnu du portail. L'espace affiche l'identifiant et invite à garder le mot de passe habituel. Aucun mot de passe n'est repris de fichiers anciens.
- **Aucun SMS :** l'accès est noté déjà notifié.

## Commande

`php artisan scolarite:importer-auditeurs --source=<full data.xlsx> --admis=<liste des admis.docx> --auteur=<e-mail> [--appliquer]`

- Essai à blanc par défaut.
- Idempotente : un matricule déjà présent est ignoré.
- Le rapport ne cite que des matricules. Il distingue les auditeurs sans téléphone exploitable, les téléphones en double, ceux d'un compte du personnel ou d'une autre personne (date de naissance différente), et les admis absents des sources.

## Après l'import

1. `php artisan ecampus:rapprocher --campagne=<id P13>` : relève les comptes eCampus existants, en lecture seule.
2. Les cas écartés se complètent à la main par « Ajouter un retenu ».
3. La Scolarité saisit les versements déjà reçus.
