# Revue CAMES LOT D — arbitrages requis avant go-live du site

> Source : sous-agent `cames-reviewer`, 2026-07-04, branche `feat/prod-d-site-contenu`.
> Corrigé immédiatement (mécanique) : note interne « PR K » retirée de la page
> conformité, `privacy@pssfp.net`/`pssfp.net` → `.org` (fr.json), `remotePatterns`
> next.config → `.org`. **Le reste ci-dessous est éditorial/institutionnel : à
> trancher par Anatole (PCP/UAAF/UPAAS/UDCFC), pas par le dev.**
>
> ⚠️ Ces points bloquent le go-live du **site** (LOT D), pas celui de la
> **candidature** (LOTs A/B/C, indépendants).

## Bloquants (recette CAMES échouerait)

| # | Sujet | Contradiction publiée | Décision attendue |
|---|---|---|---|
| 1 | **Statut CAMES** | « Master reconnu CAMES » (hero home, FormationsPagesSeeder:133-134, candidature fr.json:19) **vs** « accréditation CAMES en cours » (a-propos, fr.json:121) — aucune source « CAMES » dans `docs/sources/catalogue-formations-extracted.txt` | Formulation officielle unique (ex. « diplôme délivré par l'Université de Yaoundé II-Soa » ou « accréditation en cours ») à appliquer partout, y compris « grille indiciaire catégorie A » |
| 2 | **Tarif M2** | 1 185 000 FCFA/an M1 *et* M2 (index, 5 fiches, séminaires — conforme catalogue) **vs** M2 = 1 135 000 / cycle 2 320 000 (master, admission, frais, article P14) | Tarif M2 officiel UAAF ; corriger la série perdante + total cycle |
| 3 | **Placeholder publié** | « Tél. : (à compléter — UAAF) » sur `/a-propos/infrastructure` (AProposPagesSeeder:447) | Numéro réel ou suppression de la ligne |
| 4 | **Partenaire Basil Fuleihan** | « Basil Fuleihan (Rabat) » (faux — institut libanais, Beyrouth) vs « Institut des Finances du Maroc (Basil Fuleihan) » vs « Institut des Finances de Beyrouth » ; catalogue : « institut des finances du Maroc » seul | Nom exact du/des partenaire(s) avec convention à l'appui |
| 5 | **ENA (France)** | Partenariat « ENA » affirmé (dissoute en 2021 → INSP) | Confirmer INSP ou retirer |

## À valider (haute priorité)

6. **Calendrier P14** : contenu publié (clôture 15 août 2026, résultats 30 sept.) ≠ campagne seedée dev (30 sept. / 31 oct.). Source de vérité = les dates `CAMPAGNE_P14_*` que tu dois fournir (LOT B) — j'alignerai ensuite pages + article + bandeau home.
7. **Tarifs annexes non sourcés** : mémoire/soutenance 100 000 FCFA ; certifications CPAFP 250k / CMPC 200k / CGTL 180k « reconnues MINFI/ARMP ».
8. **Statistiques précises non sourcées** : effectifs par grade, diplômés par promotion, « taux d'emploi 89 % », « 70 % service public » ; « 13 promotions diplômées » vs « P13 en soutenance » ; « Conseil » vs « Comité » Scientifique.
9. **Téléphone contact** « +237 222 23 45 67 » (allure de placeholder) + existence réelle des 5 boîtes : contact@, usi@, coop@, admissions@, privacy@pssfp.org.
10. **Mentions légales** à compléter : statut juridique (institut MINFI, convention tripartite 2024), responsable de publication nommé.

## Techniques (dev, à traiter au prochain passage)

11. `CamesGrid` : aligner les 12 libellés sur la table 37 du CDC + lien exigence 7 → `/formations/frais-de-scolarite`.
12. Redirect `/pssfp/conventions` → `/a-propos/convention-tripartite` (le générique produit un 404).
13. Maquette ECTS tronc commun : sommes 51 ECTS affichées vs « ~60 » annoncés (norme LMD 30/semestre) — après validation UPAAS.
