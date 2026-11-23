# Module 4 — LMD, lot 20 : rapport d'import des notes ligne à ligne

**Date** : 2026-11-22. **Décideur** : M. ABE ETOUMOU Anatole, Chef USI. **ADR** : 0011.
**Source** : audit `docs/audits/lmd-gestion-academique-2026-11.md`, § 4.3 (« import tout ou rien, erreurs tronquées à 15, pas de rapport ligne à ligne ») et étape 6 du plan validé le 22/11/2026.

## 1. Objectif

Qu'un import Excel de notes refusé dise, pour chaque ligne du fichier, ce qui ne va pas.

## 2. Règles

1. L'import reste « tout ou rien » : une ligne en erreur et rien n'est enregistré.
2. Tout le fichier est contrôlé d'un coup. Sont signalés, avec le numéro de la ligne dans Excel et le matricule : matricule non inscrit à l'UE, matricule présent deux fois, note hors bornes ou illisible, présence inconnue, motif manquant pour une note déjà saisie.
3. À l'écran, le message donne le nombre de lignes en erreur et les trois premières ; un classeur « rapport d'import » se télécharge aussitôt, avec toutes les lignes.
4. Un matricule en double n'écrase plus en silence la première ligne : c'est une erreur.

## 3. Modèle de données

Aucune migration.

## 4. Tests

Fichier avec une note hors bornes, un matricule inconnu et un doublon : trois lignes au rapport, numéros exacts, aucune note enregistrée ; le classeur du rapport se relit.

## 5. Hors périmètre

Les écrans de l'enseignant sur téléphone (mes cours, mes notes, mes heures), second volet de l'étape 6 : à cadrer avec les enseignants et les points focaux.
