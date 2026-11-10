# ADR-0012 — Travail hors ligne : périmètre, architecture et conventions à appliquer dès maintenant

**Statut** : Proposé
**Date** : 2026-11-10
**Décideur** : M. ABE ETOUMOU Anatole, Chef USI (demande du 09/11/2026 : « à terme il y aura du offline/online, qu'il soit possible de travailler hors ligne, mais ce n'est pas encore ça »)
**Référence** : ADR-0001 (pile), ADR-0005 (Sanctum), ADR-0010 et ADR-0011 (vie académique, LMD) ; plan `docs/plans/2026-11-09-socle-plateforme-objectifs.md`, étape 8

## Contexte

La connexion est instable dans les salles de cours et pendant les examens. Elle manque surtout aux moments où l'on saisit en série : émargement des enseignants, appel des auditeurs, notes.

Aujourd'hui, tout se fait en ligne :

- le back-office est en Filament 3. Ses pages, dont la saisie des notes, la saisie anonyme et le pointage, sont rendues par le serveur (Livewire). **Elles ne peuvent pas fonctionner hors ligne**, et aucun réglage n'y changera rien ;
- l'espace de l'auditeur est une application Next.js (`candidature`) qui appelle l'API `/v1`.

Le hors ligne n'est pas à construire maintenant. Mais certaines décisions prises dès aujourd'hui le rendraient ensuite simple, ou au contraire très coûteux.

## Décision

1. **Périmètre hors ligne, limité aux saisies de terrain répétitives** :
   - émargement et cahier de textes des enseignants (lot 13a) ;
   - appel des auditeurs et assiduité (lot 10) ;
   - saisie des notes : contrôle continu et assiduité par l'enseignant, examen sur numéros d'anonymat par l'opérateur (lots 5 et 9) ;
   - consultation : emploi du temps, listes d'appel, maquette.

   **Restent en ligne seulement** tout ce qui numérote, fige ou engage :
   - encaissement des versements et émission des reçus (numéros `RCPT` consécutifs) ;
   - délibérations et PV ;
   - documents officiels ;
   - circuit de paie ;
   - décisions sur les candidatures.

2. **Architecture : une application web installable (PWA) dédiée au terrain**, en Next.js comme les autres applications (ADR-0001), qui parle à l'API `/v1` avec des jetons Sanctum à droits limités (ADR-0005).
   - Données de la séance mises en cache sur l'appareil (IndexedDB).
   - Saisies placées dans une **file locale**, rejouée au retour du réseau.
   - Le back-office Filament reste en ligne.

3. **Règles de synchronisation** :
   - **Le serveur fait foi.** Il revalide chaque écriture rejouée avec les mêmes services (`App\Services\…`) et les mêmes règles qu'en ligne. Une étape de saisie verrouillée entre-temps refuse la note, et la PWA le dit à l'utilisateur.
   - **Pas de « dernière écriture gagnante » sur une note.** Si la valeur a changé sur le serveur depuis la lecture, il répond 409 avec la valeur en place, et l'utilisateur tranche.
   - L'heure de la saisie sur l'appareil est conservée (`saisi_le`) à côté de l'heure serveur, et visible dans le journal.

## Conventions à appliquer dès maintenant (sans attendre le hors ligne)

1. **Identifiant fourni par le client.** Toute nouvelle API d'écriture accepte un UUID créé par le client pour l'élément créé. Un rejeu ne crée donc jamais de doublon.
2. **Clé d'idempotence.** Toute nouvelle API d'écriture accepte un en-tête `Idempotency-Key`. Une même clé rejouée renvoie la même réponse sans réécrire.
3. **Logique métier dans les services, jamais dans une page Filament.** C'est déjà la règle ; elle devient indispensable, puisque la PWA appellera les mêmes services par l'API.
4. **Version des enregistrements modifiables** (notes, pointages) : `updated_at` ou un compteur de version est renvoyé par l'API et exigé à la modification (verrou optimiste).
5. **Aucun numéro d'ordre attribué par le client.** Les numéros consécutifs (reçus, PV, documents) restent attribués par le serveur, en ligne.
6. **Chaque écriture est journalisée dans son domaine** (`backend/docs/journaux.md`), avec l'identifiant de requête (`X-Request-Id`).

## Conséquences

### Positives

- Le hors ligne s'ajoutera par une application de plus, sans refondre l'API ni le back-office.
- Les conventions 1, 2 et 4 renforcent dès aujourd'hui l'API en ligne : un double clic ou une connexion qui coupe pendant un envoi ne crée plus de doublon.

### Négatives ou trade-offs

- Une quatrième application Next.js à maintenir et à déployer.
- Les écrans de terrain existeront deux fois : en Filament pour le bureau, dans la PWA pour la salle.
- Des données personnelles (listes d'appel, notes) resteront un temps sur l'appareil. Il faudra les chiffrer, les effacer après synchronisation et réserver la PWA aux comptes du personnel. C'est à préciser dans la spec du lot.

### Neutres ou à surveiller

- Le volume de données mises en cache par séance (une promotion compte environ 300 auditeurs) reste faible.
- La PWA dépend du support de l'API Service Worker par les navigateurs des téléphones des enseignants. À vérifier sur le parc réel.

## Alternatives envisagées

- **Rendre Filament utilisable hors ligne** : impossible, le rendu Livewire a besoin du serveur à chaque interaction.
- **Application mobile native** (Android) : distribution et mises à jour plus lourdes, deuxième langage, pour un besoin qu'une PWA couvre.
- **Tout le back-office hors ligne** : les opérations qui numérotent ou figent (reçus, PV) ne peuvent pas être réconciliées sans risque de doublon ou de trou dans la numérotation.
- **Ne rien décider avant le lot** : les API écrites entre-temps sans identifiant client ni idempotence devraient être reprises.

## Références

- Lots 5, 9, 10 et 13a : `docs/specs/module-4-lmd-lot5-notes.md`, `…lot9-anonymat.md`, `…lot10-assiduite.md`, `…lot13a-pointage-enseignants.md`.
- Convention des journaux : `backend/docs/journaux.md`.
- Draft IETF « The Idempotency-Key HTTP Header Field » (httpapi WG).
