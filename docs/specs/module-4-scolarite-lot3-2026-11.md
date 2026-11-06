# Module 4 — Scolarité, lot 3 : espace de l'auditeur en rubriques

**Date** : 2026-11-06
**Suite de** : [lot 1](module-4-scolarite-lot1-2026-11.md) et [lot 2](module-4-scolarite-lot2-2026-11.md). ADR-0010.

## 1. Objectif

Aujourd'hui, tout tient sur une seule page `/dossier`, avec des cartes empilées. Ce lot donne à l'auditeur un espace organisé, où il retrouve chaque information en un clic : sa scolarité, ses accès, ses documents, ses messages, son profil.

Le candidat qui n'est pas auditeur garde la page actuelle : candidature en cours, non retenu ou retenu sans matricule.

## 2. Rubriques

Toutes les rubriques sont sous `/dossier`, déjà protégé par le middleware. Une barre de navigation les relie. Elle n'apparaît que si `GET /me/scolarite` répond.

| Rubrique | Route | Contenu |
|---|---|---|
| **Accueil** | `/dossier` | Annonce d'admission et consignes, synthèse (reste à payer et prochaine échéance, état de l'accès, statut s'il n'est pas « Actif »), dernier message, raccourcis vers les rubriques |
| **Ma scolarité** | `/dossier/scolarite` | Paliers, versements, relevé PDF (carte du lot 1) |
| **Mes accès** | `/dossier/acces` | Identifiant, mot de passe à la demande, lien eCampus (carte du lot 1) |
| **Mes documents** | `/dossier/documents` | Lettre d'admission signée, relevé de situation, récépissé de candidature, communiqué des résultats |
| **Mes messages** | `/dossier/messages` | Tous les messages reçus de l'administration |
| **Mon profil** | `/dossier/profil` | Récapitulatif du dossier, en lecture |

- La rubrique active est signalée par `aria-current="page"`.
- Sur mobile, la barre défile horizontalement, avec des cibles d'au moins 44 px.
- Une route de rubrique ouverte par un non-auditeur le renvoie vers `/dossier`.
- Un agent qui consulte l'espace voit les mêmes rubriques, en lecture, sans le mot de passe.

## 3. API

`GET /me/scolarite` ajoute `statut` (`actif`, `suspendu`, `abandon`, `exclu`, `diplome`). L'accueil affiche un bandeau si le statut n'est pas « Actif ». Aucune autre route n'est ajoutée.

## 4. Tests

- **Playwright :** chaque rubrique sans session renvoie à la connexion.
- **Pest :** `statut` est présent dans la réponse.
- **Navigateur, sur des données fictives :**
  - navigation entre les rubriques à 320, 768 et 1440 px, sans débordement ;
  - un candidat non auditeur garde l'ancienne page ;
  - l'aperçu en consultation par un agent.
- **Relectures :** accessibilité et i18n.
