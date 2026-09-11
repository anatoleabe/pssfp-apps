# ADR-0009 — La photo d'identité cesse de bloquer la soumission de candidature

**Statut** : Accepté
**Date** : 2026-09-11
**Décideur** : M. ABE ETOUMOU Anatole (Chef USI, Chef de Projet)
**Référence CDC** : assouplit la règle « photo obligatoire à la soumission » du CDC v5 module 5. La photo reste **obligatoire pour la recevabilité** du dossier ; seul le moment de sa collecte change.
**Modules impactés** : Module 5 (candidatures), Module 6 (CMS Filament).

## Contexte

Mesures relevées en production le 11/09/2026, campagne P14 en cours, à J-quelques jours de la clôture.

### Le constat

| | |
|---|---|
| Dossiers total | 169 |
| Soumis | 82 |
| Postulants **sans** photo | **50** |
| → dont dossier **intégralement valide**, seule la photo manque | **47** |
| → dont encore incomplets par ailleurs | 3 |
| → dont abandons précoces | 0 |

**47 candidats ont rempli l'intégralité de leur dossier et sont retenus par un unique fichier.** Aucun d'entre eux n'a abandonné en cours de route.

### Pourquoi un correctif technique ne suffira pas

Le 02/09/2026, la compression automatique des photos trop lourdes a été déployée (commit `91e0c5c`), en réponse aux candidats bloqués par la limite de 2 Mo. Le taux de décrochage n'a pas bougé :

| Période | Sans photo | Avec photo | Taux |
|---|---|---|---|
| Avant le 02/09 | 28 | 20 | 58 % |
| Depuis le 02/09 | 22 | 17 | 56 % |

Le poids du fichier n'était donc pas la cause réelle. Les candidats **ne disposent simplement pas d'une photo d'identité numérique au moment où le formulaire la leur réclame** — contrainte matérielle, non technique. Aucune optimisation du téléversement ne la lèvera.

### Ce qui a déjà été construit et qui rend le changement peu coûteux

Trois briques existent déjà et n'ont pas été conçues pour cette décision, mais la servent directement :

1. Le récépissé PDF gère **déjà** l'absence de photo, avec une mention explicite et bilingue : « Photo à déposer auprès de la scolarité / Photo to be submitted to the Academic Office » (`backend/resources/views/pdf/candidature-recipisse.blade.php:444` et `:618`). Un récépissé sans photo est donc un état **déjà dessiné**, pas une régression visuelle.
2. `CandidatureService::DRAFT_PHOTO_ONLY` classe déjà les dossiers bloqués par la seule photo, et l'admin Filament expose le filtre « Bloqués par la photo ».
3. Le module de relance SMS / e-mail sait déjà cibler ce segment.

## Décision

**1. La photo cesse d'être une condition de soumission.** `checkSubmittable()` ne renvoie plus d'erreur `photo`. Le candidat peut certifier et soumettre son dossier sans photo.

**2. La photo reste obligatoire pour la recevabilité.** L'absence de photo n'est plus un blocage *technique* du portail ; elle demeure un manquement *administratif* que la scolarité doit traiter avant admission. L'admin doit donc pouvoir isoler les dossiers soumis sans photo.

**3. Le dépôt de la photo reste ouvert après soumission**, tant que la campagne est ouverte, et **uniquement pour combler un vide** :

- `POST /applications/me/photo` est accepté après soumission **si et seulement si** `photo_path` est `null`.
- Le **remplacement** d'une photo existante reste interdit après soumission (409). Autoriser la substitution d'une pièce sur un dossier déjà certifié ouvrirait un trou dans la certification.
- `DELETE /applications/me/photo` reste interdit après soumission (409, inchangé).

**4. Le récépissé n'est PAS régénéré automatiquement au dépôt tardif de la photo.**

C'est le point le plus contre-intuitif de cette ADR, et il est délibéré. Le récépissé est une **preuve de soumission horodatée**, pas un miroir vivant du dossier. Trois raisons :

- Son QR pointe vers `/v1/c/{uuid}/qr`, qui affiche le `recipisse_hash_sha256` **courant en base**. Régénérer le PDF change ce hash — et **invalide la vérification de tout exemplaire papier déjà imprimé et remis au candidat**. Un agent au comptoir verrait une divergence entre le hash imprimé et le hash affiché.
- La mention « Photo à déposer auprès de la scolarité » figurant sur le récépissé **reste exacte** : elle décrit fidèlement l'état du dossier à l'instant de la soumission.
- Le rattrapage reste possible **à la main** : l'action Filament de régénération du récépissé existe déjà et est réservée à la scolarité, qui décide en connaissance de cause.

**5. `classifyDraft()` doit classer sur `photo_path`, non sur les erreurs de `checkSubmittable()`.** Sans cela, la photo n'étant plus une erreur, les 47 dossiers basculeraient silencieusement en `DRAFT_READY` et le filtre « Bloqués par la photo », le widget d'avancement et la relance SMS perdraient leur segment.

## Conséquences

### Positives

- 47 dossiers complets débloqués immédiatement, sans attendre quoi que ce soit du candidat.
- Le point de friction le plus coûteux du tunnel disparaît, à quelques jours de la clôture.
- Le candidat obtient son numéro de dossier et son récépissé dès que ses informations sont complètes — la photo devient une formalité de suivi et non un mur.

### Négatives, assumées

- Des dossiers soumis circuleront sans photo. La scolarité doit les relancer : c'est un **transfert de charge du candidat vers l'administration**, consenti parce que l'administration, elle, sait relancer.
- Un récépissé émis sans photo restera marqué « Photo à déposer » même après dépôt, sauf régénération manuelle par la scolarité. C'est le prix de la non-invalidation des exemplaires papier.
- Le taux de soumission va mécaniquement augmenter : les statistiques d'avancement d'avant et d'après le 11/09 ne sont pas comparables sans retraitement.

### Neutres

- Aucune migration de base. Aucune colonne ajoutée. La règle change, pas le schéma.
- Aucun dossier existant n'est modifié : les 82 déjà soumis gardent leur photo et leur récépissé.

## Alternatives écartées

**Rendre la photo optionnelle pour accélérer la soumission.** C'était l'hypothèse de départ. Elle est fausse : mesuré sur le VPS, la lecture de la photo depuis MinIO coûte 0,86 s sur les 3,93 s de génération du récépissé (22 %), le double rendu DomPDF en représentant 2,90 s (74 %). Et depuis le passage du budget réseau des écritures à 30 s (commit `a19a68a`), ces 4 s ne posent plus de problème. La photo est un enjeu de **conversion**, pas de **latence**.

**Continuer d'optimiser le téléversement.** Déjà tenté le 02/09 avec la compression automatique : sans effet mesurable sur le décrochage.

**Régénérer le récépissé automatiquement au dépôt de la photo.** Écarté : invalide les exemplaires papier déjà remis (cf. décision 4).

**Autoriser le remplacement de la photo après soumission.** Écarté : une pièce d'un dossier certifié ne doit pas pouvoir être substituée sans trace ni contrôle.

## Suivi

Plan d'implémentation : `docs/plans/2026-09-11-m5-photo-non-bloquante.md`.
