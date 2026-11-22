# Module 4 — LMD, lot 16a : gel des notes après délibération, PV archivé et vérifiable

**Date** : 2026-11-22. **Décideur** : M. ABE ETOUMOU Anatole, Chef USI. **ADR** : 0011.
**Source** : audit `docs/audits/lmd-gestion-academique-2026-11.md`, points bloquants B1 et B2, plan validé le 22/11/2026 (étape 1).

## 1. Objectif

Qu'après l'arrêt d'une délibération, ni les notes ni le procès-verbal ne puissent changer en silence.

- Une note couverte par une délibération arrêtée ne se modifie plus que par une voie tracée.
- Le PV est enregistré une fois, à l'arrêt. Tout téléchargement sert ce même fichier, vérifiable par un QR code.

## 2. Règles

### 2.1 Gel des notes

1. **Couverture.** Une délibération arrêtée couvre les ECUE des maquettes de son année, de son niveau et de son parcours (tous les parcours du niveau si elle n'en désigne pas). Une délibération de session normale couvre les notes de session normale ; une délibération de rattrapage couvre les deux sessions.
2. **Gel.** Tant qu'une délibération arrêtée couvre un ECUE et une session, l'étape de saisie correspondante ne se rouvre pas et aucune note ne s'y saisit. Le message indique la délibération en cause et la marche à suivre.
3. **Voies de correction autorisées**, toutes deux tracées :
   - la **requête** d'un auditeur, appliquée après validation (lot 8) : inchangée, elle marque la délibération « à rectifier » ;
   - la **rectification** (lot 6) : tant qu'une délibération rectificative est en préparation, le gel est levé pour les ECUE couverts. Il reprend à l'arrêt de la rectification.
4. Une délibération rectifiée ne gèle plus rien par elle-même : c'est la délibération qui la remplace qui gèle.

### 2.2 PV archivé

5. **À l'arrêt**, le PV est produit, enregistré et scellé (SHA-256 du fichier) dans le registre des documents délivrés, sous un nouveau type « PV de délibération ». Son numéro est le numéro du PV (`PVD-AAAA-AAAA-NNNN`).
6. Le PV porte un **code de vérification** et un **QR code** vers la page publique `/verifier/{code}`, qui affiche le type, le numéro, la date, l'intitulé de la délibération et le statut. Aucun nom d'auditeur ni résultat n'y figure.
7. **Téléchargement** : une délibération arrêtée ou rectifiée sert le fichier archivé, après contrôle de son empreinte. Il n'est jamais régénéré. Avant l'arrêt, le PV reste un projet, produit à la demande et non archivé.
8. **Rectification** : à l'arrêt de la délibération rectificative, le PV remplacé est révoqué avec le motif « Délibération rectifiée par le PVD-… » (mécanisme du lot 7).
9. **Signatures.** Le PV archivé est celui que les membres signent. Les signatures électroniques restent enregistrées et visibles à l'écran. Si une signature a été constatée sur papier, la publication exige le **scan du PV signé** (PDF, 10 Mo au plus), conservé avec son empreinte.
10. **Reprise** : la commande `lmd:archiver-pv` archive le PV des délibérations arrêtées avant ce lot. Essai à blanc par défaut. Le PV d'une délibération déjà rectifiée est archivé puis révoqué.

### 2.3 Contrôle interne

11. Nouveau contrôle quotidien `NOT_APRES_ARRET` (lot 14a), bloquant, délai 2 jours, responsable : responsable de la scolarité. Il ouvre une anomalie par délibération arrêtée dont au moins une note couverte a été modifiée après l'arrêt, hors requête appliquée (délibération déjà marquée « à rectifier ») et hors rectification en préparation. Le détail donne le numéro du PV et le nombre de notes, sans nom.

## 3. Modèle de données

Migration additive et réversible.

- `documents_delivres` : `auditeur_id` et `inscription_annuelle_id` deviennent facultatifs ; le type `pv_deliberation` est admis. Contrainte : un PV a une délibération, tout autre document a un auditeur et une inscription. Index unique : un seul PV valide par délibération.
- `deliberations` : `pv_signe_chemin`, `pv_signe_sha256` (scan du PV signé).
- `deliberations.pv_pdf_sha256` n'est plus alimentée (l'empreinte vit dans le registre) ; la colonne reste en place.

SQL brut limité aux contraintes et à l'index partiel, comme au lot 7 (ADR-0011).

## 4. Tests

- Réouverture et saisie refusées sous délibération arrêtée ; acceptées avant l'arrêt et pendant une rectification en préparation.
- Une délibération de session normale ne gèle pas le rattrapage ; une délibération de rattrapage gèle les deux sessions.
- La requête appliquée corrige toujours la note et marque la délibération « à rectifier ».
- Le PV archivé est identique à chaque téléchargement, même après signature ; un fichier altéré est refusé.
- Le code du PV répond « authentique » sur l'API publique, puis « révoqué » après rectification.
- Publication refusée sans scan quand une signature a été constatée sur papier.
- Le contrôle signale une note modifiée après l'arrêt, et se tait dans les deux voies autorisées.
- La commande de reprise est rejouable sans doublon.

## 5. Hors périmètre

- Gel de la maquette et des règles d'évaluation après délibération (étape 3 du plan).
- Vérification du contenu par dépôt du PDF sur la page publique (étape 2).
- Verrou en base sur `notes_ecue` : écarté pour ce lot, le contrôle `NOT_APRES_ARRET` en tient lieu.
