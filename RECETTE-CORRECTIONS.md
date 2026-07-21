# Recette des corrections — portail apply.pssfp.org

Date de recette locale : 21 juillet 2026. Périmètre : application Next.js `candidature` et API/admin Laravel `backend`.

## Résultat

Les lots P0 et P1 sont implémentés et verts dans l'environnement local. Aucun accès de production n'a été utilisé et aucune candidature réelle n'a été modifiée. La vérification du dossier réel P14026-007 et la suppression du compte P14026-008 restent donc volontairement des opérations de recette post-déploiement à exécuter par un administrateur autorisé.

## Traçabilité audit → correction

| Référence | Correction livrée | Commit | Preuve | Statut |
|---|---|---|---|---|
| P0-1 / #1, #2, #24 | Cookie expiré avec le même domaine/path, révocation API, middleware validant réellement le token, redirection propre de toutes les routes dossier et header rafraîchi après authentification | `8ce107c`, `54040ea` | `auth-session.spec.ts`, `auth-session-live.spec.ts`, `dossier*.spec.ts` | ✅ |
| P0-2 | Pages 500/global-error et 404 institutionnelles, françaises, rassurantes, avec retour accueil et contact | `8ce107c` | build Next + inspection visuelle/code | ✅ |
| P0-3 / #3 | Erreurs inline françaises aux 4 étapes, attributs ARIA, focus et scroll sur le premier champ, validation conditionnelle employeur | `cd8fb48` | `inscription-wizard.spec.ts` | ✅ |
| P0-4 | Libellé éditable « Année académique 2026-2027 », migration ciblée et réversible, titre visible complet avec Promotion 14 | `19e4c4b`, `c1a989f` | `CurrentCampaignTest.php`, grep UI/PDF | ✅ |
| P0-5 / #5, #21, #22 | Épouse conditionnelle, promotion non dupliquée, UUID/SHA retirés du document, QR + code court conservés, pagination 2 pages | `19e4c4b` | `RecipisseGenerationTest.php`, rendu PDFKit et extraction texte | ✅ |
| P0-6 / #6 | « Créer mon compte candidat » distinct de « Certifier et soumettre ma candidature », modale d'irréversibilité | `cd8fb48`, `c1a989f` | `candidature-flow.spec.ts`, `inscription-wizard.spec.ts` | ✅ |
| P1-1 / #29 | Écran de bienvenue avec prénom, numéro, rappel PIN et trois prochaines étapes ; SMS de création | `8ce107c`, `19e4c4b` | test listener SMS + redirection `welcome=1` | ✅ |
| P1-2 / #7 | Checklist dynamique photo obligatoire / pièces recommandées ; soumission bloquée et expliquée sans photo, front et backend | `c1a989f`, `19e4c4b` | `SubmitTest.php` (photo manquante) | ✅ |
| P1-3 / #4 | Login avec sélecteur pays/indicatif partagé, numéro local normalisé E.164, erreur au blur | `c1a989f`, `cd8fb48` | `login-flow.spec.ts` | ✅ |
| P1-4 / #10, #11, #30 | Nom en lecture seule, certification sur l'honneur, PIN masqué avec œil, récapitulatif et liens Modifier | `cd8fb48` | `inscription-wizard.spec.ts` | ✅ |
| P1-5 / #13, #25 | Footer global MINFI/PSSFP, contacts et liens légaux ; domaine CGU harmonisé sur apply.pssfp.org | `8ce107c`, `c1a989f` | captures accueil/login/inscription | ✅ |
| P1-6 / #18 | Texte « SMS (et e-mail si renseigné) » et SMS garantis aux événements création, soumission, acceptation, refus | `19e4c4b` | `event:list`, `CandidatureSmsNotificationsTest.php` | ✅ |
| P1-7 / #14 | Fiches dépliables des cinq spécialités avec objectif, profil, débouchés, modalités et places | `c1a989f`, `cd8fb48` | capture accueil + test rendu accueil | ✅ |
| P1-8 / #15 | Recherche statique : aucun `api_secret`, `mp/collect` ou Measurement Protocol dans le code candidature ni le bundle produit | — (aucun code vulnérable présent) | grep source et `.next` | ✅ |
| P1-9 / #23 | Clôture explicite, bloc pièces à préparer, bandeau bilingue MINFI | `c1a989f`, `8ce107c` | capture accueil | ✅ |
| Admin | Année académique dans liste/export, noms longs avec retour à la ligne, suppression super_admin limitée aux Postulants jamais soumis + confirmation + soft-delete/purge MinIO | `19e4c4b` | `CandidatureResourceTest.php`, `TestCandidaturePurgeServiceTest.php` | ✅ local |

## Résultats automatisés

```text
pnpm --filter @pssfp/candidature typecheck
→ succès (tsc --noEmit)

pnpm --filter @pssfp/candidature lint
→ succès, No ESLint warnings or errors

pnpm --filter @pssfp/candidature build
→ succès, Compiled successfully, 16/16 pages générées

pnpm exec playwright test --workers=1
→ 53 passed, 1 test d'intégration live ignoré sans API dédiée (34.1s)

PSSFP_LIVE_E2E_API_URL=http://127.0.0.1:8001/v1 pnpm exec playwright test tests/playwright/auth-session-live.spec.ts --workers=1
→ 1 passed : login → logout → re-login, trois cycles complets contre Laravel + PostgreSQL pssfp_test (4.7s)

php artisan test --compact
→ 296 passed (861 assertions, 58.73s)

php artisan test --compact tests/Feature/Applications/RecipisseGenerationTest.php
→ 3 passed (6 assertions)

php artisan event:list
→ listeners SMS découverts pour Created, Submitted, Accepted et Refused
```

Le build local a journalisé des échecs réseau `fetch failed` pendant la génération statique parce que l'API n'était pas exposée dans le sandbox ; ils sont récupérés par les fallbacks et le build termine avec le code 0. Aucun avertissement bloquant ni erreur TypeScript/ESLint. Les deux comptes synthétiques créés par les essais du test live ont ensuite été purgés de `pssfp_test` (`comptes_test_supprimes=2`).

## Preuves visuelles

- [Accueil après correction](recette-corrections/captures/01-accueil-apres.png)
- [Inscription — étape 1 après correction](recette-corrections/captures/02-inscription-etape-1-apres.png)
- [Connexion après correction](recette-corrections/captures/03-connexion-apres.png)
- [Récépissé page 1](recette-corrections/captures/04-recipisse-page-1-apres.png)
- [Récépissé page 2](recette-corrections/captures/05-recipisse-page-2-apres.png)
- [PDF de recette à 2 pages](output/pdf/recipisse-recette-p14.pdf)

Les captures « avant » sont celles référencées dans l'annexe du rapport d'audit fourni ; les fichiers image originaux n'étaient pas présents dans le workspace. Les captures ci-dessus documentent l'état corrigé reproductible.

## Vérifications manuelles post-déploiement obligatoires

Ces opérations n'ont pas été effectuées localement afin de respecter l'interdiction de toucher aux données réelles :

1. Ouvrir P14026-007 en lecture seule, comparer ses données au contrôle préalable et vérifier le nouveau libellé.
2. Régénérer son récépissé, sans supprimer l'ancien avant validation ; contrôler Épouse, titre, 2 pages, QR et absence UUID/SHA.
3. Supprimer P14026-008 uniquement via l'action « Supprimer le compte de test », après vérification du numéro saisi et du statut Postulant jamais soumis.
4. Créer un compte marqué TEST en staging, vérifier accueil → compte → photo → pièces → modale de soumission, puis le purger via la même action.
5. Exporter la liste admin et vérifier l'année académique, les accents et les noms longs.

## P2 reporté

Les neuf fiches prêtes à publier sont dans [recette-corrections/ISSUES-P2.md](recette-corrections/ISSUES-P2.md) : éligibilité, dossier enrichi, OTP, photo, brouillon serveur, état civil, accessibilité, checklist pièces et compte MinIO dédié. Elles n'ont pas été publiées dans un tracker externe car aucun connecteur de tickets n'était configuré.

## Déploiement

1. Sauvegarder PostgreSQL et le bucket `pssfp-candidatures`; relever le hash/metadata de P14026-007 avant toute action.
2. Déployer les commits `8ce107c`, `cd8fb48`, `c1a989f`, `19e4c4b`, `54040ea` et le commit de recette qui contient ce document.
3. Dans `backend`, exécuter `php artisan migrate --force`, puis `php artisan db:seed --class=RolePermissionSeeder --force` pour ajouter la permission super_admin.
4. Exécuter `php artisan optimize:clear`, puis reconstruire/recharger les caches applicatifs selon la procédure d'exploitation.
5. Construire `candidature` avec les variables de production (`NEXT_PUBLIC_API_URL`, Turnstile, URLs publiques), puis déployer l'artefact Next.js.
6. Redémarrer les workers de queue et vérifier `php artisan event:list`.
7. Exécuter la recette smoke post-déploiement ci-dessus avant ouverture aux candidats.

## Rollback

1. Mettre le portail de candidature en maintenance applicative sans arrêter la consultation admin.
2. Revenir au code précédent avec `git revert` des commits, dans l'ordre inverse.
3. Si nécessaire seulement, annuler la migration ciblée avec `php artisan migrate:rollback --path=database/migrations/2026_07_21_120000_rename_p14_campaign_academic_year.php --force`. Le `down()` ne modifie que le slug P14 portant encore exactement le nouveau libellé.
4. Reconstruire Next.js, vider les caches Laravel et redémarrer les workers.
5. Vérifier P14026-007 et les fichiers MinIO. La migration ne change aucune clé de dossier et la fonctionnalité de purge n'agit qu'après action super_admin explicite.
