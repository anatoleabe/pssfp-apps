# /plan-module

Démarre une session de développement sur un module avec lecture de la spec.

## Usage

```
/plan-module <numero> [<scope optionnel>]
```

Exemples :

- `/plan-module 1` — démarre une session module 1 (site institutionnel).
- `/plan-module 3 page-accueil-biblio` — focalise sur la page d'accueil biblio.
- `/plan-module 5 wizard-candidature` — focalise sur le wizard de création candidature.

## Comportement attendu

Quand cette commande est lancée, tu (Claude Code) dois :

1. **Lire la spec correspondante** dans `docs/specs/module-{numero}-*.md`.
2. **Lire le `CLAUDE.md`** du dossier app concerné.
3. **Lire les fichiers transverses pertinents** : `docs/data-model.md` et `docs/api-contract.md` si la tâche touche à ces éléments.
4. **Lire les ADR pertinents** dans `docs/adr/`.
5. **Produire un plan numéroté** avec : fichiers à créer/modifier, ordre, tests, risques, critères d'acceptation.
6. **NE LANCER AUCUNE COMMANDE** ni édition avant validation explicite du plan.

## Règle d'or

Le plan doit faire **8 étapes maximum**. Sinon découper.
