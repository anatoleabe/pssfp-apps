# /ship

Checklist de pré-PR — exécute toutes les vérifications avant push pour merge.

## Usage

```
/ship
```

## Étapes

1. **Diff** : `git diff main...HEAD` — relire avant tout.
2. **Tests** : `make test` doit passer.
3. **Lint** : `make lint` doit passer.
4. **Build** : `make build` doit réussir.
5. **Lighthouse** : `/verify-lighthouse` selon les apps modifiées.
6. **CAMES** si site institutionnel : `/cames-check`.
7. **Spec à jour** dans `docs/specs/` si comportement différent.
8. **ADR** si décision architecturale majeure.
9. **Sous-agent de revue** lancé selon domaine :
   - Module 1 → a11y-reviewer + cames-reviewer
   - Module 3 → biblio-reviewer + security-reviewer
   - Module 5 → candidature-reviewer + security-reviewer
   - Module 6 → filament-reviewer + security-reviewer
   - i18n → i18n-reviewer
10. **PR description** : titre Conventional Commits, référence tâche, bullet changements, captures avant/après si visuel.

## Refus de merge

- `make test` ou `make lint` rouge → interdit.
- Lighthouse < seuil sans justification → refus.
- CAMES en échec si touché → refus.

## Une fois validé

Push branche, créer PR GitHub, demander revue à M. ABE ETOUMOU. Merge après approbation.
