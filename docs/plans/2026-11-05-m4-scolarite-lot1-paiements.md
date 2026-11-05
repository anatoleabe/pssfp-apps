# Module 4, lot 1 : paiements des auditeurs — plan d'implémentation

**Objectif :** remplacer la case « droits payés » par un registre des versements, avec cumul, paliers, déblocage automatique du code d'accès et suivi dans l'espace de l'auditeur.

**Architecture :** services métier dans `backend/app/Services/Scolarite`, calcul pur dans un objet `SituationFinanciere`, écrans Filament dans le module `scolarite`, trois routes API, cartes dédiées dans `/dossier`. Le matricule reste sur la candidature (ADR-0010).

**Références :** `docs/specs/module-4-scolarite-lot1-2026-11.md`, `docs/adr/0010-vie-academique-auditeurs-phase-1.md`.

## Contraintes transverses

- TDD : le test d'abord, puis le code. Pest côté Laravel, Playwright côté portail.
- Montants en FCFA entiers. La situation n'est jamais stockée.
- Une nouvelle permission passe par `ModulePermissionMap`, le seeder **et** une migration additive. Le seeder n'est jamais relancé en prod.
- Formulaires Filament avec `validationMessages` ; MinIO sans `HeadObject` (`fetchFileInformation(false)`).
- Aucun texte en dur dans le JSX : `t('key')`, en `fr.json` et `en.json`.
- Le mot de passe FOAD n'est écrit dans aucun journal.
- Pas de N+1 : chargement anticipé sur les listes.

## Précisions par rapport à la spec

Découvertes en lisant le code :

1. **Pas de `user_id` sur `auditeurs`.** `AjoutRetenu::corrigerTelephone` peut changer le compte d'un dossier : une copie du `user_id` se désynchroniserait. Le compte se lit à travers la candidature.
2. **Les échéances ne sont pas copiées sur l'inscription.** Seuls les montants le sont. Les échéances se lisent en direct via `bareme_id`, pour que le responsable puisse les corriger après la migration.
3. **« Compte eCampus créé »** = `ecampus_statut` parmi `cree`, `existant`, `cohorte_a_finir` : dans ces trois états, le compte existe et le mot de passe fonctionne.
4. **Un seul point de création de l'auditeur :** `AttributionMatricules::attribuer`, seul chemin qui pose un matricule (`AjoutRetenu` n'en pose pas).
5. **La colonne « Inscription » de la liste des candidatures** disparaît aussi, avec le filtre et les actions. `DocumentsAuditeurs::auditeurs()` passe au scope « inscription soldée ».

## Étapes

### 1. Données et calcul

**Crée :** migration `create_scolarite_tables` ; modèles `BaremeScolarite`, `Auditeur`, `InscriptionAnnuelle`, `Versement` ; `App\Services\Scolarite\SituationFinanciere` et `Palier` ; factories.
**Modifie :** `Candidature` (relation `auditeur`).
**Tests :** `tests/Unit/Scolarite/SituationFinanciereTest.php` (cas de la spec §4), `tests/Feature/Scolarite/ModeleScolariteTest.php` (unicités, référence réutilisable après annulation).

### 2. Registre, accès, SMS

**Crée :** `OuvertureAuditeur`, `RegistreVersements`, `AccesEcampus`, `NotificationAcces`, `ResultatOperation`.
**Modifie :** `AttributionMatricules`, `ComptesEcampus`, `FraisInscription`, `IdentifiantsMoodle`, `DocumentsAuditeurs`.
**Tests :** `tests/Feature/Scolarite/RegistreVersementsTest.php`, `AccesEcampusTest.php`, `NotificationAccesTest.php` ; adaptation de `RemiseCodeAccesTest`, `DocumentsAuditeursTest`, `IdentifiantsMoodleTest`, `AjoutRetenuTest`, `LettresSigneesTest`.

### 3. Droits et reprise de l'existant

**Crée :** `RepriseScolarite` ; migrations `grant_scolarite_permissions`, `reprise_scolarite` ; `config/scolarite.php`.
**Modifie :** `ModulePermissionMap`, `RolePermissionSeeder`.
**Tests :** `tests/Feature/Scolarite/RepriseScolariteTest.php` (mêmes personnes avec code avant et après, idempotence, pas de SMS), `DroitsScolariteTest.php`.

### 4. Administration

**Crée :** `AuditeurResource` (liste, fiche), `VersementsRelationManager`, `AuditeurActions`, `BaremeScolariteResource`, `SituationFinanciereExcel`, `AuditeurPolicy`, `BaremeScolaritePolicy`.
**Tests :** `tests/Feature/Filament/AuditeurResourceTest.php`, `BaremeScolariteResourceTest.php`.

### 5. Écrans existants et lettres

**Modifie :** `CandidatureResource` (retrait de la colonne, du filtre, des 4 actions ; lien vers la fiche ; chargement anticipé), `DocumentsAuditeursActions`, `CampagneCandidatureResource` (retrait des montants et échéances), `ParametresLettres` (lecture du barème M1).
**Tests :** `tests/Feature/DocumentsAuditeurs/LettreBaremeTest.php`, adaptation des tests existants.

### 6. API de l'auditeur

**Crée :** `ScolariteController`, `ScolariteResource`, vue `pdf/releve-scolarite.blade.php`, limiteur `acces-ecampus`.
**Modifie :** `routes/api.php`, `docs/api-contract.md`.
**Tests :** `tests/Feature/Applications/ScolariteApiTest.php` (propriété des données, 403 consultation et non soldé, limite, `no-store`, mot de passe absent de `/me`).

### 7. Espace de l'auditeur

**Crée :** `DossierScolariteCard`, `DossierAccesCard`, action serveur `revelerAccesAction`, route `/dossier/releve`.
**Modifie :** `dossier/page.tsx`, `lib/api/client.ts`, `messages/fr.json`, `messages/en.json`.
**Tests :** `candidature/tests/playwright/scolarite.spec.ts` (états, 320 / 768 / 1440 px, consultation).

### 8. Vérification et livraison

- Suite Pest complète, `tsc`, lint, Playwright candidature.
- Essai réel des formulaires d'admin dans le navigateur.
- Relectures : `candidature-reviewer`, `security-reviewer`, `filament-reviewer`, `a11y-reviewer`, `i18n-reviewer`.
- Mise à jour de `CLAUDE.md` (périmètre Phase I), `docs/data-model.md`, spec module 5 (règle du code d'accès).
- PR. Le déploiement en prod reste à demander à part.
