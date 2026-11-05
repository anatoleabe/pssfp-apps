# Module 4 — Scolarité, lot 2 : gestion des auditeurs

**Date** : 2026-11-06
**Décideur** : M. ABE ETOUMOU Anatole, Chef USI
**Suite de** : [lot 1](module-4-scolarite-lot1-2026-11.md), déployé le 5 novembre 2026. ADR-0010.

## 1. Objectif

Séparer clairement deux métiers dans l'administration :

- **Candidatures** : la sélection (dépôt, frais de dossier, décision, matricule).
- **Vie académique** : tout ce qui suit l'admission. Réglages de la promotion, fiche complète de l'auditeur, accès eCampus, documents, statut, messages.

Aujourd'hui, ces réglages se font dans le formulaire de la campagne, au milieu de champs qui ne doivent pas bouger. Les gestes sur un auditeur, eux, sont dispersés dans les 28 actions de la liste des candidatures.

**Hors périmètre :**
- l'espace de l'auditeur en rubriques (lot 3) ;
- le LMD (lot 4) ;
- la correction de l'identité d'un auditeur, qui reste réservée au super administrateur depuis le dossier.

## 2. Écran « Promotions » (Vie académique)

Une ligne par campagne qui a des retenus. L'écran porte sur le même modèle que les campagnes, mais n'expose que ce qui concerne la promotion.

- **Formulaire :**
  - consignes aux retenus ;
  - documents des auditeurs : références, date des lettres, rentrée (date, fin, heure, lieu), copies, signature. Les montants et échéances viennent du barème.
- **Adresse :** `/admin/promotions-scolarite`, distincte de la future rubrique « Promotions » du CMS.

  Ces sections quittent le formulaire de la campagne, qui ne garde que la campagne elle-même et ses communiqués.
- **Actions :**
  - documents des auditeurs (accès FOAD, lettres d'admission) : ils quittent la campagne ;
  - attribution des matricules et exports des retenus (données complètes, ACMA, Moodle) : ajoutés ici, mais **ils restent aussi sur la campagne**, car le comité d'admission y télécharge les données complètes sans avoir accès au module Scolarité ;
  - les résultats de sélection (communiqué, PV, liste) restent sur la campagne.
- **Droits :** nouvelle permission `scolarite.promotion`, pour modifier les réglages de la promotion. Elle est donnée au responsable de scolarité, à l'admin et au super admin. Chaque action garde sa permission actuelle : exports, matricules, documents.

## 3. Fiche auditeur complète

Elle ajoute au lot 1 les gestes suivants, regroupés en menus :

| Menu | Actions (permission inchangée) |
|---|---|
| **Accès eCampus** | Créer le compte, consulter et envoyer les identifiants (`candidature.matricules_moodle`), voir l'espace, nouveau code (`candidature.espace_candidat`) |
| **Documents** | Lettre d'admission et lettre d'accès en Word (permissions des documents), lettre signée : déposer, voir, retirer (`candidature.lettres_signees`) |
| **Dossier** | Corriger le téléphone d'un retenu ajouté (`candidature.ajouter_retenu`), ouvrir le dossier de candidature |
| (dans **Dossier**) | Changer le statut (section 4) |

Ces actions s'appliquent à la candidature de l'auditeur : leur code, leurs contrôles et leurs journaux ne changent pas. Comme depuis les candidatures, elles exigent en plus l'accès au module des admissions et le droit de voir le dossier.

**Fichiers :** les champs de signature et de bordereau ne signent jamais un chemin qui ne vient pas d'eux (même bucket que les pièces des candidats).

**Historique :** section listant les entrées du journal de l'auditeur, de ses inscriptions, de ses versements, en lecture seule. Les entrées du dossier de candidature n'y figurent que pour qui peut ouvrir ce dossier.

## 4. Statut de l'auditeur

| Statut | Sens |
|---|---|
| **Actif** (par défaut) | Suit la formation |
| **Suspendu** | Formation interrompue temporairement |
| **Abandon** | A quitté la formation |
| **Exclu** | Exclu par décision de l'établissement |
| **Diplômé** | Formation achevée |

- Chaque changement exige un motif et une date d'effet. Il est journalisé avec l'ancien et le nouveau statut, ainsi que l'agent.
- Nouvelle permission `scolarite.statut`, donnée au responsable, à l'admin et au super admin.
- Dans ce lot, le statut est **informatif** : badge, colonne et filtre dans la liste. Il ne bloque ni les versements, ni l'accès eCampus, puisqu'un auditeur qui abandonne peut rester redevable. Ses effets seront décidés au lot 4.
- Le statut n'est pas le retrait du dossier, qui reste une décision des admissions.

## 5. Messages aux auditeurs (relance des impayés)

Action groupée dans la liste des auditeurs : « Envoyer un message ».

- **Canal :** SMS, e-mail ou les deux, avec un aperçu du texte, du nombre de destinataires et du nombre de SMS.
- **Variables propres à chaque auditeur :** `{prenom}`, `{nom}`, `{matricule}`, `{reste}` (reste à payer), `{reste_inscription}`, `{prochaine_echeance}`.
- **Modèle proposé :** « PSSFP : {prenom}, il reste {reste} FCFA à régler pour votre scolarité. Prochaine échéance : {prochaine_echeance}. » Il reste modifiable.
- **Envoi :** par le service des notifications existant. Il reste donc tracé dans le journal des envois, et apparaît dans les messages de l'espace de l'auditeur.
- **Garde-fous :** au plus 100 destinataires par envoi (l'envoi est synchrone), 6 envois par heure et par agent, dossiers retirés, exclus et en abandon écartés, aucun mot de passe dans les variables. L'aperçu donne le nombre de destinataires, le coût total en SMS et les numéros hors couverture.
- **Droits :** nouvelle permission `scolarite.messages`, donnée au responsable, à l'admin et au super admin.

## 6. Liste des candidatures allégée

**Quittent la liste et la fiche d'une candidature** (désormais sur la fiche ou la liste des auditeurs). Ce sont les gestes réservés à l'administration :
- création du compte eCampus (ligne et groupe) ;
- consultation et envoi des identifiants (ligne et groupe) ;
- lettre Word d'accès FOAD (ligne et groupe) ;
- lettres signées : déposer, voir, retirer, et le dépôt en lot.

**Restent, et sont aussi sur la fiche auditeur :**
- la lettre d'admission en Word (ligne et groupe), parce que le comité d'admission la produit sans avoir accès au module Scolarité ;
- la correction du téléphone d'un retenu ajouté, souvent faite avant l'attribution du matricule, donc avant la fiche.

**Restent :**
- dépôt physique, frais de dossier, décision, retrait, notifications des candidats, exports ;
- attribution du matricule et catégorie EFP/EFSC, qui précèdent la création de l'auditeur ;
- voir l'espace, nouveau code (utiles aussi aux candidats) ;
- lien « Fiche auditeur ».

**Liste des auditeurs, actions groupées :** créer les comptes eCampus, envoyer les identifiants, lettres d'accès et d'admission, message (section 5), dépôt en lot des lettres signées.

## 7. Tests

- Tous les tests existants qui appelaient ces actions depuis les candidatures les appellent depuis les auditeurs ou les promotions, avec les mêmes attentes.
- Nouveaux tests :
  - droits de chaque nouvelle permission, dans les deux sens ;
  - statut avec motif et journal ;
  - messages : variables, plafond, absence de mot de passe ;
  - écran Promotions : enregistrement des réglages sans toucher à la campagne ;
  - historique : propriété des entrées.
- Les tests d'isolation des modules et de l'écran des rôles sont étendus.
- Essai réel au navigateur : fiche, promotion, envoi d'un message avec SMS simulé.

## 8. Déploiement

- Migration additive : colonnes de statut sur `auditeurs`, nouvelles permissions.
- Pas de reprise de données : tous les auditeurs passent « Actif ».
- Séquence (cf. mémoire de déploiement) : `config:clear`, puis `migrate`, `permission:cache-reset`, `optimize`, et le build du portail si besoin.
