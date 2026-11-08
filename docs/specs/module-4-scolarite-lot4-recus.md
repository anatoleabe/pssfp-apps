# Module 4 — Scolarité, lot 4 : reçus des versements

**Date** : 2026-11-08. **Décideur** : M. ABE ETOUMOU Anatole, Chef USI (choix du 08/11 : reçus des versements de scolarité, émis par le service financier, téléchargeables par l'auditeur ; réponses de la Scolarité du 08/11, questions 19 à 23). Suite des lots 1 à 3 de la Scolarité ; lève l'exclusion « reçus officiels numérotés » du lot 1 (§1). Décision D7 du dossier « PSSFP Système » (reçus, caisse).

## 1. Objectif

Le service financier émet un reçu officiel numéroté pour chaque versement de scolarité enregistré (droits d'inscription, tranches). Le reçu est un PDF ; l'auditeur le télécharge dans son espace.

## 2. Règles

1. **Un reçu par versement**, émis par le service financier (`scolarite.recu_emettre`). Le versement doit être enregistré après la mise en service des reçus (pas de reçu pour les versements antérieurs, question 22) et non annulé. Un versement n'a qu'un reçu en vigueur.
2. **Numéro** : `RCPT-AAAA-NNNNNN` (« receipt », question 19), continu sur l'année civile de l'émission, attribué à l'émission, jamais réutilisé (verrou sur le compteur de l'année).
3. **Contenu figé à l'émission** : numéro, date d'émission, auditeur (nom, matricule), année académique et niveau, montant en chiffres et en lettres, mode, référence du bordereau, date du versement, payeur, objet, total versé et reste à payer de l'année après ce versement, nom de l'agent émetteur (sans cachet, question 20). Le PDF se régénère à l'identique à partir de ces données. Le reçu est en français.
4. **Objet** (question 21) : déduit de l'imputation du versement sur le barème de l'année, de l'inscription vers la dernière tranche (droits d'inscription, 1re tranche, 2e tranche, avec la part de chacun ; un excédent est un trop-perçu).
5. **Annulation par une écriture d'annulation** (question 23) : un reçu émis ne se modifie jamais. Son annulation est une écriture distincte, numérotée `<numéro du reçu>-A`, avec son motif, sa date et son auteur ; elle a son propre PDF. Le reçu annulé reste consultable, avec la mention ANNULÉ et la référence de l'écriture. Un nouveau reçu peut ensuite être émis pour le versement. L'annulation d'un versement passe une écriture d'annulation sur son reçu (motif : « Versement annulé : <motif> »).
6. **Espace auditeur** : rubrique des versements, lien « Reçu » sur chaque versement qui en a un en vigueur ; téléchargement réservé au titulaire (même contrôle que le relevé de scolarité).
7. **Permissions** :
   - `scolarite.recu_emettre` (sensible) : émettre et annuler un reçu. Rôles : `service_financier`, super_admin ;
   - le rôle `service_financier` reçoit aussi la consultation des auditeurs et l'enregistrement des versements (`scolarite.versement_saisir`).
8. **Hors périmètre** : reçus des frais de dossier des candidats ; reçus des versements antérieurs à la mise en service (question 22) ; compte unique de l'auditeur sur tout le parcours (D7, question 4).

## 3. Écrans

- **Fiche auditeur**, onglet Versements : colonne « Reçu » (numéro en vigueur, ou mention de l'écriture d'annulation) ; actions « Émettre le reçu », « Reçu (PDF) », « Annuler le reçu ».
- **Reçus des versements** (liste, groupe Vie académique) : tous les reçus, filtres (année d'émission, état, émetteur), téléchargement du reçu et de son écriture d'annulation, annulation.
- **Espace auditeur** (`candidature`) : colonne « Reçu » dans « Mes versements », lien vers le PDF (`/dossier/recus/{uuid}`, relayé vers `GET /v1/applications/me/scolarite/recus/{uuid}`).

## 4. Critères d'acceptation

1. Deux reçus émis de suite reçoivent RCPT-2026-000001 puis RCPT-2026-000002 ; deux émissions simultanées n'obtiennent jamais le même numéro.
2. Un versement annulé, ou qui a déjà un reçu en vigueur, ne reçoit pas de reçu.
3. Annuler un reçu exige un motif et crée l'écriture RCPT-2026-000001-A sans modifier le reçu ; le PDF du reçu porte ANNULÉ ; un nouveau reçu prend un nouveau numéro. Un versement enregistré avant la mise en service ne reçoit pas de reçu.
4. L'auditeur télécharge son reçu ; un autre auditeur reçoit 404.
5. Un agent de scolarité sans `scolarite.recu_emettre` ne voit pas les actions d'émission.
6. Le montant en lettres est juste (ex. 185 000 → « cent quatre-vingt-cinq mille francs CFA »).
