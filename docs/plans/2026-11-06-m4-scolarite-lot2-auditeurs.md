# Module 4, lot 2 : gestion des auditeurs — plan d'implémentation

**Spec :** `docs/specs/module-4-scolarite-lot2-2026-11.md`.
**Approche :** réutiliser les constructeurs d'actions existants (`ComptesEcampusActions`, `IdentifiantsMoodleActions`, `LettresSigneesActions`, `EspaceCandidatActions`, `DocumentsAuditeursActions`, `ExportsRetenusActions`). Sur la fiche auditeur, `ViewAuditeur::configureAction()` leur passe la candidature comme enregistrement. Sur la liste, de petits adaptateurs convertissent la sélection d'auditeurs en candidatures.

## Contraintes transverses

Celles du lot 1 : TDD, permissions par migration additive, `validationMessages`, pas de N+1, journal de chaque geste sensible.

## Étapes

1. **Permissions et statut.** Permissions `scolarite.promotion`, `scolarite.statut` et `scolarite.messages` (carte, seeder, migration additive). Colonnes `statut`, `statut_motif`, `statut_depuis`, `statut_par` sur `auditeurs`, avec contrainte `CHECK`. Service `StatutAuditeur`. Tests.
2. **Écran Promotions.** `PromotionResource` sur `CampagneCandidature`, avec le formulaire de la promotion et les actions déplacées. Sections retirées du formulaire de campagne. Tests déplacés et nouveaux.
3. **Fiche auditeur.** Menus Accès, Documents, Dossier, Statut, avec `configureAction`. Onglet Historique. Tests.
4. **Liste des auditeurs.** Actions groupées (comptes, identifiants, lettres, dépôt en lot) et colonne et filtre de statut. Tests.
5. **Messages.** Service `MessagesAuditeurs` (variables, plafond, délégation à `NotificationCandidatsService`) et action groupée avec aperçu. Tests.
6. **Candidatures allégées.** Retrait des actions déplacées de la liste et de la fiche. Migration des tests existants.
7. **Vérification.** Suite complète, essai au navigateur, relectures Filament, sécurité et candidature, documentation, PR.
