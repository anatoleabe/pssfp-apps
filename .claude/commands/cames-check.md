# /cames-check

Vérifie que les 12 exigences CAMES (CDC v5 Annexe B et table 37) sont satisfaites par les pages publiées.

## Usage

```
/cames-check
```

## Les 12 exigences

| # | Exigence | Page attendue |
|---|---|---|
| 1 | Dénomination + statut juridique | /pssfp/presentation + /mentions-legales |
| 2 | Accréditations + conventions | /pssfp/conventions + /pssfp/conformite-cames |
| 3 | Programme complet formations | /formations/master + /formations/specialites |
| 4 | UE détaillées par spécialité | /formations/specialites/[slug] × 5 |
| 5 | Corps enseignant qualifications | /vie-academique/corps-enseignant |
| 6 | Conditions d'admission | /formations/admission |
| 7 | Frais de scolarité | /formations/frais-de-scolarite |
| 8 | Débouchés par spécialité | /formations/specialites/[slug] |
| 9 | Infrastructures | /pssfp/infrastructure |
| 10 | Partenariats | /pssfp/partenaires |
| 11 | Publications scientifiques | bibliotheque.pssfp.net + /vie-academique |
| 12 | Coordonnées + localisation | /contact + footer |

## Méthode automatisée

Script `infra/scripts/cames-check.sh` crawle les 12 URLs, vérifie 200 + mots-clés attendus, produit rapport Markdown avec ✓/✗.

## Sortie

Score X/12 + liste des exigences en échec avec recommandation.

## Quand l'utiliser

- Avant chaque jalon Phase 7 (recette CAMES).
- À chaque PR touchant aux pages CAMES-pertinentes.
- Avant la mise en production V1.
