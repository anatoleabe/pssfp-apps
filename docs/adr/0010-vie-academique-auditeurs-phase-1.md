# ADR-0010 — Vie académique en Phase I, sur une fiche auditeur distincte de la candidature

**Statut** : Accepté
**Date** : 2026-11-05
**Décideur** : M. ABE ETOUMOU Anatole, Chef USI
**Référence CDC** : CDC v5 ; `CLAUDE.md` (Module 4 auparavant classé en Phase II) ; spec `docs/specs/module-4-scolarite-lot1-2026-11.md`

## Contexte

Le portail s'arrête aujourd'hui à la sélection. Après la décision, tout reste porté par la candidature : matricule, mot de passe FOAD, connexion par matricule, documents. Les droits d'inscription se résument à une case cochée, sans montant, alors que les auditeurs paient par versements libres et que le barème dépend du niveau (M1 : 185 000 + 500 000 + 500 000 ; M2 : 135 000 + 500 000 + 500 000).

Le Chef USI demande une vraie gestion des auditeurs, de type LMD, suivie depuis l'espace de l'auditeur. L'espace étudiant était jusqu'ici classé en Phase II.

La rentrée de la 14e promotion a lieu les 10 et 11 novembre 2026, et le matricule est utilisé dans une trentaine de fichiers.

## Décision

1. **Le périmètre change :** la vie académique des auditeurs passe en Phase I, en quatre lots (paiements, gestion des auditeurs, espace complet, LMD). Le SSO Moodle et le paiement en ligne restent en Phase II.
2. **Une nouvelle entité `auditeurs`, liée à la candidature :** les inscriptions annuelles et les versements s'y rattachent. Le matricule, le mot de passe FOAD et la connexion restent sur la candidature pendant le lot 1, et seront déplacés au moment du lot 4.
3. **La situation financière n'est pas stockée :** elle se recalcule toujours à partir des versements non annulés et de la remise. Un versement n'est jamais supprimé.
4. **Trois usages de SQL brut sont autorisés, par exception à la règle 9 du projet :**
   - l'index unique partiel sur les références des versements non annulés ;
   - les contraintes `CHECK` sur les montants (PostgreSQL ignore `unsigned`) ;
   - les expressions de filtre sur la situation financière de la liste des auditeurs, regroupées dans les scopes d'`InscriptionAnnuelle`.

   Eloquent n'exprime aucun des trois, et stocker la situation pour l'éviter réintroduirait une donnée en double sur de l'argent.

## Conséquences

### Positives

- Parcours et notes auront leur point d'ancrage au lot 4, sans avoir à déplacer de données de paiement.
- Le code existant continue de tourner à l'approche de la rentrée.
- Le cumul ne peut pas diverger des versements, et chaque correction reste tracée.

### Négatives ou trade-offs

- Pendant un temps, l'identité d'un auditeur se lit à travers sa candidature, et chaque auditeur en a forcément une. Les auditeurs de la P13, qui n'ont pas de dossier sur ce portail, entreront par un dossier hors PV créé par `AjoutRetenu`.
- Le matricule reste à déplacer au lot 4, sur une trentaine de fichiers.
- Le calcul à la volée demande plus de soin dans la liste pour éviter les N+1.

### Neutres ou à surveiller

- Le périmètre de Phase I s'allonge : le lot 4 aura sa propre spec et sa propre estimation.
- Le rôle `auditor`, prévu pour la Phase II et inutilisé, reste en l'état jusqu'au lot 3.

## Alternatives envisagées

- **Tout rattacher à la candidature, sans nouvelle entité :** le plus rapide, mais il faudrait déplacer plus tard des données financières réelles, et la candidature porterait durablement deux métiers.
- **Déplacer dès maintenant le matricule, la connexion et l'espace sur `auditeurs` :** c'est la base idéale, mais le risque est trop élevé à quelques jours de la rentrée.
- **Modéliser le LMD d'abord :** le besoin immédiat est le suivi des paiements, et la structure académique n'est pas nécessaire pour y répondre.

## Références

- `docs/specs/module-4-scolarite-lot1-2026-11.md`
- `docs/specs/module-5-candidatures.md`, règle du code d'accès
