# Module 5 — Évolution « Diplôme requis & autres diplômes » (2026-08)

> Spec de changement. Complète `docs/specs/module-5-candidatures.md` sans le remplacer.
> Décidée avec M. ABE ETOUMOU Anatole le 11 août 2026.

## 1. Contexte et contrainte majeure

Le formulaire de candidature est **en production**. Des dossiers sont déjà soumis, d'autres
sont au statut `postulant` (brouillon en cours de saisie).

**Contrainte non négociable** : aucune candidature existante ne doit être invalidée,
bloquée ou modifiée par ce changement. Les nouvelles exigences ne s'appliquent
qu'aux dossiers créés après la mise en production.

## 2. Périmètre

Trois corrections sur le parcours de candidature :

- **C1** — « Lieu de naissance » remonte de l'étape 2 vers l'étape 1, sous « Date de naissance ».
- **C2** — L'étape 3 distingue le **diplôme le plus élevé obtenu** (existant) du
  **diplôme requis** pour l'admission (nouveau), chacun avec ses quatre attributs.
- **C3** — Nouveau bloc facultatif et répétable « Autres diplômes et formations ».

Hors périmètre : tout autre écran, la logique de paiement, les pièces jointes.

## 3. Décisions d'arbitrage

| # | Question | Décision |
|---|---|---|
| D1 | « Diplôme requis » remplace-t-il « Diplôme le plus élevé obtenu » ? | **Non.** Les deux coexistent, en deux blocs symétriques de quatre champs. |
| D2 | Les brouillons déjà créés doivent-ils remplir les nouveaux champs pour soumettre ? | **Non.** Ils sont exemptés via une colonne `form_version`. |
| D3 | « Spécialité du diplôme requis » réutilise-t-elle le champ existant ? | **Non.** Le bloc « diplôme requis » a sa propre spécialité et son propre établissement. Le champ existant `specialite_diplome` reste attaché au diplôme le plus élevé, inchangé. |
| D4 | Le champ « Domaine du diplôme requis » est-il conservé ? | **Oui**, avec sa règle conditionnelle sur la spécialité du diplôme requis. |
| D5 | Stockage des blocs répétables | **JSONB** sur `candidatures`, pas de table dédiée. |

## 4. Modèle de données

### 4.1 Nouvelles colonnes — table `candidatures`

| Colonne | Type | Null | Contenu |
|---|---|---|---|
| `diplome_requis` | `varchar(30)` | oui | slug : `licence-bachelor`, `master` |
| `annee_diplome_requis` | `smallint` | oui | année sur 4 chiffres |
| `domaine_diplome_requis` | `varchar(30)` | oui | slug : `droit`, `economie`, `gestion`, `autres` |
| `specialite_diplome_requis` | `varchar(100)` | oui | texte libre |
| `institut_diplome_requis` | `varchar(150)` | oui | texte libre (sélecteur assisté côté UI) |
| `autres_diplomes` | `jsonb` | oui | `[{intitule, etablissement, annee}]` |
| `formations_professionnelles` | `jsonb` | oui | `[{centre, qualification, annee}]` |
| `form_version` | `smallint` | non | `1` = ancien formulaire, `2` = nouveau |

Contraintes `CHECK` sur `diplome_requis` et `domaine_diplome_requis`. Elles tolèrent
`NULL` (sémantique Postgres) : **aucune ligne existante n'est invalidée**.

Les valeurs stockées sont des **slugs stables**, pas des libellés affichés. La traduction
en libellé se fait à l'affichage (front) et au rendu PDF (back), conformément à ADR-0006.

### 4.2 Stratégie `form_version`

Séquence de migration, dans cet ordre, en une transaction :

1. `ADD COLUMN form_version smallint NOT NULL DEFAULT 1` → toutes les lignes de
   production prennent `1`.
2. `ALTER COLUMN form_version SET DEFAULT 2` → toute ligne insérée ensuite prend `2`.

Aucun code applicatif ne fixe `form_version` : le défaut Postgres suffit. La colonne est
ajoutée à la liste noire de `CandidatureService::updateDraft`, donc **non assignable par
le client**.

### 4.3 Forme des blocs JSONB

```json
{
  "autres_diplomes": [
    { "intitule": "DESS Finances publiques", "etablissement": "ENAM", "annee": 2019 }
  ],
  "formations_professionnelles": [
    { "centre": "ISMP Douala", "qualification": "Certificat contrôle de gestion", "annee": 2021 }
  ]
}
```

Les lignes sont **normalisées côté serveur** avant écriture (`App\Support\CandidatureDiplomeBlocks`) :
seules les trois clés attendues sont conservées, les valeurs sont typées, les lignes
entièrement vides sont supprimées. Aucun JSON arbitraire venu du client n'atteint la base.

Plafond de **10 lignes par bloc**, garde-fou anti-abus appliqué côté API.

### 4.4 Ce qui ne change pas

`diplome_obtenu`, `annee_diplome`, `specialite_diplome`, `institut` conservent leur
colonne, leur type, leur nullabilité et leur caractère obligatoire à la soumission.
Seuls leurs **libellés affichés** évoluent pour lever l'ambiguïté avec le nouveau bloc.

## 5. Écran — étape 3

```
Diplôme le plus élevé obtenu *
Année d'obtention du diplôme le plus élevé *
Spécialité du diplôme le plus élevé *
Établissement de délivrance du diplôme le plus élevé *

─────────────────────────────────────────────────────

Diplôme requis *                              (Licence / Bachelor · Master)
Année d'obtention du diplôme requis *
Domaine du diplôme requis *                   (Droit · Économie · Gestion · Autres)
Spécialité du diplôme requis *                ← si et seulement si domaine = Autres
Établissement de délivrance du diplôme requis *

─────────────────────────────────────────────────────

Autres diplômes et formations (facultatif)

  Diplôme académique complémentaire                    [+ Ajouter]
    Intitulé du diplôme · Établissement d'obtention · Année d'obtention

  Formation professionnelle                            [+ Ajouter]
    Centre de formation · Qualification obtenue · Année de formation

─────────────────────────────────────────────────────

Situation professionnelle actuelle *
…
```

Règles :

- Domaine ≠ `Autres` → « Spécialité du diplôme requis » est **masquée et non exigée**.
  Bascule depuis `Autres` vers un autre domaine → la valeur saisie est effacée, pour
  qu'aucune valeur fantôme ne se retrouve sur le récépissé.
- Dans les blocs répétables, une ligne créée doit avoir ses **trois champs remplis**.
  Chaque ligne porte un bouton « Supprimer ».
- Les deux blocs répétables sont **facultatifs** : zéro ligne est un état valide.

## 6. Écran — étapes 1 et 2

`lieu_naissance` quitte l'étape 2 et se place à l'étape 1, sur une ligne pleine largeur
directement sous la ligne contenant « Date de naissance ». Le champ garde sa colonne,
sa validation et son caractère obligatoire.

## 7. Validation

### 7.1 Répartition

| Couche | Rôle |
|---|---|
| zod, `lib/validation/schemas.ts` | validation immédiate par étape du wizard |
| Server Action `submitInscription` | revalidation intégrale (défense en profondeur) |
| `UpdateCandidatureRequest` (PUT `/applications/me`) | **laxiste** : format seulement |
| `CandidatureService::checkSubmittable` | **stricte**, au moment de la soumission |
| `lib/validation/submittable.ts` | miroir TypeScript du précédent, pour l'UI |

La validation du PUT reste laxiste — c'est l'architecture en place, et elle est nécessaire
à l'auto-save 2 s : une ligne à moitié saisie ne doit pas faire échouer l'enregistrement.
La complétude des lignes est vérifiée à la soumission.

### 7.2 Règles strictes à la soumission

Applicables **uniquement si `form_version >= 2`** :

- `diplome_requis`, `annee_diplome_requis`, `domaine_diplome_requis`,
  `institut_diplome_requis` obligatoires.
- `specialite_diplome_requis` obligatoire **si et seulement si**
  `domaine_diplome_requis === 'autres'`.
- `annee_diplome_requis` : entier, entre 1950 et l'année courante.
- Chaque ligne présente dans `autres_diplomes` ou `formations_professionnelles` a ses
  trois champs renseignés, année comprise entre 1950 et l'année courante.

Pour `form_version = 1`, `checkSubmittable` se comporte **exactement comme aujourd'hui**.

### 7.3 Non-régression production

- Un dossier **déjà soumis** ne repasse ni par `updateDraft` (qui refuse hors statut
  `postulant`) ni par `checkSubmittable`. Il est intouchable par construction.
- Un **brouillon antérieur** (`form_version = 1`) reste soumissible avec l'ancien jeu de
  champs, et son écran d'édition reste strictement identique à celui d'aujourd'hui.

## 8. Interface — dossier existant

Sur `/dossier/edition`, les nouveaux champs et les deux blocs répétables ne s'affichent
**que si `form_version >= 2`**. Un dossier `v1` voit le formulaire d'aujourd'hui, inchangé.

`CandidatureResource` expose `form_version` pour permettre ce pilotage côté client.

`DossierCompleteness`, `DossierProfileSummary` et l'étape 5 de récapitulation intègrent
les nouveaux champs sous la même condition.

## 9. Administration et récépissé

- **Filament** `CandidatureResource` : les cinq nouveaux champs plus deux `Repeater` dans
  la section académique.
- **Récépissé PDF** : chaque nouveau champ est enveloppé dans une condition sur sa valeur.
  Un dossier `v1` produit donc un PDF **identique au PDF actuel**.

## 10. Accessibilité

- Chaque bloc répétable est un `<fieldset>` avec `<legend>`.
- Chaque cellule de ligne porte son propre `<label>`.
- Les boutons de suppression sont nommés sans ambiguïté (« Supprimer le diplôme 2 »).
- L'ajout d'une ligne déplace le focus sur son premier champ ; la suppression est
  annoncée via une région `aria-live`.
- Contraste et navigation clavier conformes WCAG 2.1 AA, comme le reste du formulaire.

## 11. Internationalisation

Toutes les étiquettes passent par `next-intl` (`messages/fr.json`, `messages/en.json`).
Aucun texte en dur dans le JSX.

Les libellés d'options suivent la convention existante :
`candidature/lib/dossier/options.ts` côté front, `backend/config/diplome_requis.php` et
`backend/config/domaines_diplome.php` côté back (rendu PDF et admin).

## 12. Tests

### Pest (backend)

- Migration réversible ; `form_version` vaut `1` sur les lignes préexistantes et `2` par
  défaut sur les nouvelles.
- `form_version` n'est pas assignable via PUT `/applications/me`.
- Soumission d'un dossier `v1` **sans** les nouveaux champs → succès (non-régression).
- Soumission d'un dossier `v2` sans les nouveaux champs → 422.
- `specialite_diplome_requis` exigée si domaine `autres`, ignorée sinon.
- Ligne JSONB incomplète → 422 à la soumission, tolérée au PUT.
- Normalisation : les clés inconnues sont écartées, les lignes vides supprimées, le
  plafond de 10 lignes est appliqué.

### Playwright (candidature)

- Parcours complet du wizard avec les nouveaux champs.
- Bascule du domaine Droit ↔ Autres : apparition et disparition de la spécialité.
- Ajout et suppression de lignes dans les deux blocs répétables.
- `lieu_naissance` est bien présent à l'étape 1 et absent de l'étape 2.

### Accessibilité

- Passage `a11y-reviewer` sur les composants nouveaux et modifiés.

## 13. Dette traitée au passage

`candidature/components/DossierEditionForm/index.tsx` fait 887 lignes, au-dessus du
plafond projet de 800. La section « diplôme » — celle que ce changement modifie — est
extraite dans son propre fichier. Aucun autre refactoring n'est entrepris.

## 14. Séquence de mise en production

1. Migration backend (additive, réversible, sans verrou long).
2. Déploiement backend.
3. Déploiement frontend candidature.

L'ordre importe : le backend doit accepter les nouveaux champs avant que le front ne les
envoie. Entre les deux étapes, l'ancien front reste pleinement fonctionnel — toutes les
nouvelles colonnes sont nullables.
