# Module 4 — LMD, lot 24b : documents académiques sous la bannière de l'Université

**Date** : 2026-11-23. **Décideur** : M. ABE ETOUMOU Anatole, Chef USI. **ADR** : 0011.
**Source** : décision du 23/11/2026 : « les PV et documents académiques doivent être sous la bannière de l'Université, seuls les documents administratifs restent sous l'en-tête du PSSFP ». Modèles ACMA signés (PV de synthèse, PV de soutenance).

## 1. Règle

1. **Académiques, bannière de l'Université de Yaoundé II-Soa** (République, Université, Faculté des sciences économiques et de gestion, PSSFP, logo de l'Université au centre) : PV de délibération, liste d'affichage des résultats, relevé de notes, attestation de réussite, PV des requêtes, bordereau et émargement d'anonymat, autorisation de dépôt du mémoire, PV de soutenance, certificat de scolarité.
2. **Administratifs, en-tête inchangé** (Ministère des finances, PSSFP) : projets d'acte, états et bordereaux de paie, reçus, extraits de compte, listes de la scolarité, lettres.

## 2. Réalisation

Une bannière unique (`pdf/lmd/_banniere`) pour tous les documents du LMD ; le projet d'acte demande explicitement celle du Ministère. Aucune migration, aucun changement de contenu.

## 3. Tests

- L'en-tête par défaut porte l'Université et son logo, pas le Ministère.
- Le projet d'acte est le seul gabarit du LMD sous la bannière du Ministère.

## 4. À confirmer

- Certificat de scolarité : rangé parmi les documents académiques.
- Les documents déjà archivés (PV arrêtés) gardent leur en-tête d'origine.
