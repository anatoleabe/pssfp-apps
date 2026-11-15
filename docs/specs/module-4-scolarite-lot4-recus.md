# Module 4 — Scolarité, lot 4 : reçus des versements

**Date** : 2026-11-08. **Décideur** : M. ABE ETOUMOU Anatole, Chef USI (choix du 08/11 : reçus des versements de scolarité, émis par le service financier, téléchargeables par l'auditeur ; réponses de la Scolarité du 08/11, questions 19 à 23). Suite des lots 1 à 3 de la Scolarité ; lève l'exclusion « reçus officiels numérotés » du lot 1 (§1). Décision D7 du dossier « PSSFP Système » (reçus, caisse).

## 1. Objectif

Le service financier émet un reçu officiel numéroté pour chaque versement de scolarité enregistré (droits d'inscription, tranches). Le reçu est un PDF ; l'auditeur le télécharge dans son espace.

## 2. Règles

1. **Un reçu par versement**, émis par le service financier (`scolarite.recu_emettre`). Le versement doit être enregistré après la mise en service des reçus (pas de reçu pour les versements antérieurs, question 22) et non annulé. Un versement n'a qu'un reçu en vigueur. ~~**Séparation des tâches** (Chef USI, 08/11/2026) : l'agent qui a enregistré le versement n'en émet pas le reçu.~~ **Levée le 15/11/2026** : le service financier enregistre le versement et émet le reçu en un seul geste ; le contrôle passe à la confirmation par la scolarité (`module-4-scolarite-encaissement-confirmation.md`).
2. **Numéro** : `RCPT-AAAA-NNNNNN` (« receipt », question 19), continu sur l'année civile de l'émission, attribué à l'émission, jamais réutilisé (verrou sur le compteur de l'année).
3. **Contenu figé à l'émission** : numéro, date d'émission, auditeur (nom, matricule), année académique et niveau, montant en chiffres et en lettres, mode, référence du bordereau, date du versement, payeur, objet, total versé et reste à payer de l'année après ce versement, nom de l'agent émetteur (sans cachet, question 20). Le PDF se régénère à l'identique à partir de ces données. Le reçu est en français.
4. **Objet** (question 21) : déduit de l'imputation du versement sur le barème de l'année, de l'inscription vers la dernière tranche (droits d'inscription, 1re tranche, 2e tranche, avec la part de chacun ; un excédent est un trop-perçu).
5. **Annulation par une écriture d'annulation** (question 23) : un reçu émis ne se modifie jamais. Son annulation est une écriture distincte, numérotée `<numéro du reçu>-A`, avec son motif, sa date et son auteur ; elle a son propre PDF. Le reçu annulé reste consultable, avec la mention ANNULÉ et la référence de l'écriture. Un nouveau reçu peut ensuite être émis pour le versement. L'annulation d'un versement passe une écriture d'annulation sur son reçu (motif : « Versement annulé : <motif> »).
6. **Espace auditeur** : rubrique des versements, lien « Reçu » sur chaque versement qui en a un en vigueur ; téléchargement réservé au titulaire (même contrôle que le relevé de scolarité).
7. **Permissions** :
   - `scolarite.recu_emettre` (sensible) : émettre et annuler un reçu. Rôles : `service_financier`, super_admin ;
   - le rôle `service_financier` reçoit aussi la consultation des auditeurs et l'enregistrement des versements (`scolarite.versement_saisir`). Depuis le 15/11/2026, il est seul à enregistrer les versements parmi les rôles métier (`module-4-scolarite-encaissement-confirmation.md` §6).
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

## 5. Bascule ACMA et avis à l'auditeur (15/11/2026)

**Décideur** : M. ABE ETOUMOU Anatole, Chef USI (décisions du 15/11/2026). La scolarité cesse d'encaisser dans ACMA dès la mise en ligne de ce changement. Les quelque 140 versements saisis sur la plateforme depuis le 08/11 ont déjà un reçu ACMA : ils ne reçoivent pas de reçu rétroactif. Un reçu reste possible à l'unité, depuis la fiche, si l'auditeur le demande.

### 5.1 Décisions

**A. Liste d'attente.**

1. La liste des reçus en attente (pastille du menu, tuile « À traiter » du tableau de bord) ne compte que les versements **enregistrés à partir de la date de bascule** (arrêt d'ACMA).
2. `raisonRefus()` ne change pas : l'émission à l'unité reste possible pour tout versement enregistré depuis `scolarite.recus_depuis` (mise en service du 08/11).
3. La date de bascule est posée **une fois**, au déploiement, par la commande `scolarite:bascule-recus`. La commande est idempotente. Elle refuse de déplacer une date déjà posée, sauf `--force` avec un motif. Elle écrit au journal `scolarite`.
4. Tant que la date n'est pas posée, le comportement d'avant est conservé.
5. L'écran indique la date : « Versements enregistrés depuis le JJ/MM/AAAA ».

**B. Avis à l'auditeur.**

1. Quand un reçu est émis, l'auditeur reçoit **un SMS et un e-mail** qui lui annoncent que son reçu est disponible dans son espace.
2. SMS en ASCII, **un seul segment**, sans numéro de téléphone, **sans ponctuation collée après l'adresse** (un point final après l'URL a provoqué des erreurs 500 sur mobile, PR #114).
3. L'e-mail reprend le numéro, le montant, l'année académique et le lien vers l'espace.
4. L'envoi part **après la validation de la transaction** de l'émission : jamais d'avis pour un reçu qui n'existe pas.
5. Un échec d'envoi ne défait jamais le reçu. Il est tracé comme les autres envois aux auditeurs.
6. Pas d'avis à l'annulation d'un reçu.
7. On réutilise l'infrastructure d'envoi existante, sans nouveau fournisseur.

### 5.2 Points tranchés

1. **Réglage distinct.** La bascule est un nouveau réglage, `scolarite.recus_bascule`. `scolarite.recus_depuis` garde son rôle : c'est la borne de l'émission. La liste d'attente part de la plus tardive des deux dates, si bien qu'une bascule posée par erreur avant la mise en service n'y fait rien entrer de plus.
2. **Commande.** Elle s'écrit `php artisan scolarite:bascule-recus [--date=…] [--force --motif=…]`.
   - Sans `--date`, la date posée est l'instant présent.
   - Une date sans fuseau se lit à l'heure de Yaoundé.
   - Une date illisible ou située dans le futur est refusée.
   - Rejouée sans `--date`, ou avec la date déjà posée, la commande ne change rien et réussit.
   - Avec une autre date et sans `--force`, elle refuse (code de sortie 1) et affiche la date en place.
   - `--force` exige un motif d'au moins 5 caractères.
   - Au journal `scolarite`, elle écrit `recus_bascule_posee` (date) ou `recus_bascule_deplacee` (ancienne date, nouvelle date, motif).
3. **Écran.**
   - La liste « Reçus des versements », vue par le service financier, porte en sous-titre « Versements enregistrés depuis le JJ/MM/AAAA : N en attente de reçu. »
   - Une fois la bascule posée, le sous-titre ajoute : « Un versement plus ancien reçoit son reçu à la demande de l'auditeur, depuis sa fiche. »
   - L'infobulle de la pastille, reprise par la tuile « À traiter », devient « Versements sans reçu (enregistrés depuis le JJ/MM/AAAA) ».
   - La date affichée est celle qui borne la liste. Elle est donnée à l'heure de Yaoundé.
4. **Circuit des avis.** Les avis suivent le circuit des messages aux auditeurs (`NotificationCandidatsService`, comme `MessagesAuditeurs` et les relances des accès anticipés).
   - Chaque envoi est tracé au journal des envois avec le motif `recu` (« Reçu émis »). Un échec y figure avec le statut « échec ».
   - L'avis est réaffiché dans les messages de l'espace de l'auditeur.
   - L'e-mail passe par la file bridée (`SendThrottledMail`). Le SMS part par la passerelle configurée.
   - Pourquoi pas `LiensLettreAcces::prevenir` : ses messages portent un lien personnel vers la lettre d'accès, si bien que leur texte n'est jamais conservé. L'avis de reçu ne porte rien de secret.
5. **Textes.**
   - SMS : `PSSFP : votre recu RCPT-2026-000012 de 500000 FCFA est disponible dans votre espace https://apply.pssfp.org`.
   - Le montant s'écrit en chiffres sans espace, comme dans l'exemple validé. L'adresse est `pssfp.candidature_app_url`, sans barre finale, en fin de message.
   - E-mail, sujet : « PSSFP — Votre reçu RCPT-2026-000012 ».
   - E-mail, corps : « Le service financier du PSSFP a émis le reçu RCPT-2026-000012 pour votre versement de 500 000 FCFA (frais de scolarité, année académique 2026-2027). » Puis : « Téléchargez-le dans votre espace, rubrique « Mes versements » : https://apply.pssfp.org ». Le message est habillé par le gabarit des messages aux candidats.
6. **Moment de l'envoi.** `emettre()` programme l'avis avec `DB::afterCommit`. Une transaction annulée, y compris celle d'un appelant, n'envoie rien.
7. **Échec.** Toute erreur de l'avis est rattrapée : passerelle, file ou base.
   - Le reçu reste émis, et l'agent voit « Reçu émis ».
   - L'erreur va au journal applicatif. Ce que le circuit a pu tracer est au journal des envois.
   - Il n'y a ni renvoi automatique ni bouton « Renvoyer » : le reçu est de toute façon dans l'espace.
8. **Journal `scolarite`.** L'événement `recu_avis` est posé sur le reçu, avec le numéro et le bilan des envois (SMS et e-mail envoyés, en échec, ignorés).
9. **Annulation.** Ni l'écriture d'annulation ni l'annulation du versement n'envoient d'avis. Un nouveau reçu émis ensuite est un autre reçu : il reçoit son avis.
10. **Destinataire.** Le destinataire est le dossier de l'auditeur. Le téléphone utilisé est celui de connexion, ou à défaut le téléphone 1. L'e-mail est celui du dossier. Sans coordonnée sur un canal, rien ne part sur ce canal.
11. **Versements antérieurs à la bascule.** Ils ne reçoivent pas d'avis, puisqu'ils ne reçoivent pas de reçu. Si un reçu est émis à l'unité, sur demande, il est annoncé comme tout reçu.

### 5.3 Critères d'acceptation

1. Sans date de bascule, la liste d'attente est celle d'avant. Une fois la date posée, elle ne compte plus que les versements enregistrés depuis cette date. Un versement plus ancien reçoit toujours son reçu à l'unité.
2. `scolarite:bascule-recus` pose la date et l'écrit au journal. Rejouée, elle ne change rien. Elle refuse une autre date sans `--force`, et `--force` sans motif. Avec `--force` et un motif, elle déplace la date et l'écrit au journal.
3. Une émission envoie un SMS et un e-mail, une seule fois, après la validation de la transaction. Une émission annulée par la transaction n'envoie rien.
4. Le SMS est en ASCII, tient en un segment, ne contient pas de numéro de téléphone et finit sur l'adresse.
5. Une passerelle en panne ne défait pas le reçu : l'échec est au journal des envois.
6. L'annulation d'un reçu, ou celle de son versement, n'envoie aucun avis.
7. La liste des reçus affiche « Versements enregistrés depuis le JJ/MM/AAAA ».

### 5.4 Déploiement

1. Déployer comme d'habitude. Il n'y a pas de migration : le réglage est écrit par la commande.
2. Vérifier que le service de file (`pssfp-queue`) tourne, car il envoie les e-mails.
3. Au moment de l'arrêt d'ACMA, en utilisateur `pssfp`, lancer `php artisan scolarite:bascule-recus`. Si l'arrêt a eu lieu plus tôt, ajouter `--date="AAAA-MM-JJ HH:MM"`.
4. Contrôler que la pastille « Reçus des versements » ne compte plus les versements antérieurs, et que le sous-titre de la liste affiche la date.
