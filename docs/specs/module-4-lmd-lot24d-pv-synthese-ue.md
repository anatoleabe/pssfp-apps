# Module 4 — LMD, lot 24d : synthèse par UE au procès-verbal

**Date** : 2026-11-23. **Décideur** : M. ABE ETOUMOU Anatole, Chef USI. **ADR** : 0011.
**Source** : PV annuels de synthèse et PV finaux signés (promotions 11 à 13) : un tableau par option, une colonne par UE, puis crédits, moyenne et décision.

## 1. Règles

1. Le PV de délibération (semestre, année, jury d'admission) se termine par une **synthèse par UE**, un tableau par parcours : rang, matricule, nom, une colonne par UE de la portée (dans l'ordre de la maquette), crédits acquis, moyenne, décision.
2. La note affichée est celle retenue par le jury (reprise, relèvement et repêchage compris). Une UE non acquise est suivie d'un astérisque ; une UE sans note d'un tiret.
3. Une légende donne le code, l'intitulé et les crédits de chaque UE.
4. Au jury d'admission, le taux affiché est le taux d'admission (admis sur effectif).
5. Les PV déjà arrêtés sont archivés : ils ne changent pas.

## 2. Modèle de données

Aucune migration.

## 3. Tests

- Synthèse du jury d'admission : un tableau pour l'option, 60 crédits de colonnes, la note de soutenance dans sa colonne, le PDF produit.

## 4. À décider

- Colonne « total des points » et mode de calcul de la moyenne (ACMA : total des notes divisé par le nombre d'UE ; plateforme : pondération par les crédits).
