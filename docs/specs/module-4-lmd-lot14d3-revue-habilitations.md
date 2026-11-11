# Module 4 — LMD, lot 14d-3 : revue annuelle des habilitations

**Date** : 2026-11-11. **Décideur** : M. ABE ETOUMOU Anatole, Chef USI. **ADR** : 0011.
**Sources** : dossier « PSSFP Système » : décision D30 du 30/10/2026 (« revue annuelle des habilitations ») ; `sql/19_schema_supervision.sql` (table `revue_habilitation`, vue `v_ctl_habilitations_non_revues`, contrôle `SEC_REVUE`).

## 1. Objectif

Qu'au moins une fois par an, quelqu'un d'habilité confirme que chaque compte du personnel a encore besoin de chacun de ses rôles, et retire ceux qui ne servent plus. Les droits ne s'accumulent pas au fil des changements de poste.

## 2. Règles

1. **Une revue porte sur un rôle d'un compte** du personnel (jamais le rôle « candidat »). Décisions possibles :
   - **maintenu** ;
   - **retiré** : le rôle est retiré du compte, avec un motif d'au moins 10 caractères.
2. **Qui revoit** : un titulaire de `update_user` qui peut modifier ce compte (règles de l'écran des comptes : seul un super administrateur touche un super administrateur), et **jamais ses propres rôles**. Le dernier super administrateur ne perd jamais son rôle.
3. **Écran** : action « Revue des habilitations » sur chaque compte. Elle présente ses rôles, cochés par défaut : décocher un rôle le retire. Un commentaire est obligatoire dès qu'un rôle est retiré. Chaque rôle revu donne une ligne de revue (ajout seul), et chaque retrait est journalisé (journal `roles`).
4. **Contrôle `SEC_REVUE`** (mineur, 30 jours, administrateur, mensuel en pratique : il tourne chaque jour mais ne change qu'à l'échéance) : chaque rôle d'un compte du personnel jamais revu, ou revu il y a plus d'un an, ouvre une anomalie. Elle se ferme seule à la revue.
5. **Première mise en service** : aucun rôle n'a encore été revu. Toutes les habilitations ouvrent donc une anomalie, avec 30 jours pour la première campagne.

## 3. Modèle de données

`revues_habilitations` : `user_id`, `role`, `decision` (`maintenu`, `retire`), `commentaire`, `revu_par`, `revu_le`. Ajout seul, par déclencheur.

## 4. Tests

1. Maintien et retrait ; motif obligatoire au retrait ; journal.
2. Refus : sans droit, sur soi-même, sur un super administrateur par un administrateur, retrait du dernier super administrateur.
3. `SEC_REVUE` : rôle jamais revu, revu il y a plus d'un an, revu récemment ; fermeture à la revue.
4. Revues en ajout seul.
