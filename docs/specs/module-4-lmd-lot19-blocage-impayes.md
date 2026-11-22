# Module 4 — LMD, lot 19 : relevé de notes et examens bloqués sur impayé, avec dérogation

**Date** : 2026-11-22. **Décideur** : M. ABE ETOUMOU Anatole, Chef USI. **ADR** : 0011.
**Source** : audit `docs/audits/lmd-gestion-academique-2026-11.md`, décision D5 du 22/11/2026 (« Impayé : bloquer aussi le relevé de notes et l'accès aux examens, avec dérogation ») et étape 5 du plan.

## 1. Objectif

Qu'un auditeur en retard de paiement n'obtienne ni son relevé de notes ni l'accès aux examens, sauf dérogation tracée.

## 2. Règles

1. **Une seule règle « en règle »**, celle déjà appliquée à l'attestation de réussite et au certificat de scolarité : aucune échéance en retard sur l'argent confirmé, ou restriction levée pour l'inscription.
2. **Relevé de notes** (de l'année ou d'un semestre) : refusé à l'auditeur qui n'est pas en règle, avec le même message que les autres documents. Les résultats restent visibles dans son espace : seul le document est retenu. Un relevé déjà délivré reste valide (il n'est pas révoqué), mais son téléchargement suit la même règle, comme l'attestation.
3. **Examens** : sur la liste d'émargement d'une épreuve, l'auditeur qui n'est pas en règle porte la mention « Non autorisé à composer (situation financière) » à la place de la case de signature, et l'en-tête en donne le nombre.
4. **Dérogation** : la « levée de restriction » existante (motif obligatoire, droit dédié, journal). Elle vaut pour les documents et pour les examens de l'inscription.

## 3. Modèle de données

Aucune migration.

## 4. Tests

- Relevé refusé avec une tranche en retard, délivré après la levée ; l'API de l'espace auditeur donne le motif.
- La liste des auditeurs non autorisés suit la même règle et la même levée.

## 5. Hors périmètre

- Fusion des trois dérogations (déblocage d'accès, moratoire, levée de restriction) en une seule : à cadrer avec le service financier, les trois sont en service.
- Relance automatique des tranches échues par SMS : elle écrit à de vrais auditeurs et coûte des SMS, à décider avant de l'activer.
- Liste d'émargement générale des examens (étape 7) : elle reprendra la même mention.
