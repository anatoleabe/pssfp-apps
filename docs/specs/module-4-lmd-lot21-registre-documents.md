# Module 4 — LMD, lot 21 : registre des documents délivrés

**Date** : 2026-11-22. **Décideur** : M. ABE ETOUMOU Anatole, Chef USI. **ADR** : 0011.
**Source** : audit `docs/audits/lmd-gestion-academique-2026-11.md`, étape 8 du plan validé le 22/11/2026 (« registre des documents délivrés »).

## 1. Objectif

Voir en un écran tous les documents officiels délivrés, au lieu de les chercher fiche par fiche.

## 2. Règles

1. Écran « Registre des documents » (Évaluations et jurys), en lecture seule : numéro, type, titulaire, date, agent (ou « L'auditeur »), délibération, statut valide ou révoqué avec son motif, code de vérification.
2. Recherche par numéro et par titulaire ; filtres par type et par statut.
3. Visible avec le droit de consultation du parcours LMD, comme la liste des documents sur la fiche d'un auditeur.
4. Le fichier ne se télécharge qu'avec le droit de révoquer un document. C'est toujours le fichier scellé ; chaque téléchargement est journalisé.
5. La révocation se fait aussi depuis le registre, avec les règles existantes (motif, droit dédié). Un PV ne se révoque que par une rectification.

## 3. Modèle de données

Aucune migration.

## 4. Tests

Liste, téléchargement journalisé, révocation, filtre « révoqués », refus à un membre du jury sans droit sur le module.

## 5. Hors périmètre

Tableau de bord académique (effectifs, taux de réussite, recouvrement) : les indicateurs sont à choisir avec la direction.
