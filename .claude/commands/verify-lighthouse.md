# /verify-lighthouse

Lance un audit Lighthouse sur les routes critiques et reporte les écarts par rapport à la cible CDC §9.3.

## Usage

```
/verify-lighthouse [app]
```

- `/verify-lighthouse` — frontend (par défaut).
- `/verify-lighthouse library` — biblio.
- `/verify-lighthouse candidature` — candidature.

## Comportement

1. Lance `pnpm build && pnpm start` sur l'app cible.
2. Lance Lighthouse mobile sur les routes critiques :
   - frontend : `/`, `/formations/specialites/fiscalite-finance-comptabilite-publique`, `/actualites`, `/contact`.
   - library : `/`, `/recherche`, `/theses/{uuid_test}`.
   - candidature : `/`, `/profil`, `/candidature/{numero_test}`.
3. Compare les scores aux cibles : ≥ 90 frontend, ≥ 85 library/candidature.
4. Identifie les pires offenders avec recommandations.
5. Produit un rapport dans `docs/journal/lighthouse-YYYY-MM-DD.md`.

## Si seuils non atteints

Pour chaque route en échec : score actuel, top 3 audits Lighthouse en échec, recommandation, gain estimé. Ne pas modifier le code automatiquement — produire un rapport.
