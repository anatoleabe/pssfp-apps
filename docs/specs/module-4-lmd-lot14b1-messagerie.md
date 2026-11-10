# Module 4 — LMD, lot 14b-1 : messagerie interne

**Date** : 2026-11-10. **Décideur** : M. ABE ETOUMOU Anatole, Chef USI. **ADR** : 0011.
**Sources** : dossier « PSSFP Système » : décision D31 du 30/10/2026 (demande de M. MBIANA) ; `sql/20_schema_messagerie_demandes.sql` (tables `message`, `message_destinataire`, `message_piece`, `file_envoi`, fonction `envoyer_message`).

Le lot 14b est découpé en deux :
- **14b-1** (ce lot) : la messagerie interne, socle des relances et des demandes ;
- **14b-2** : les relances graduées du contrôle interne (D30), envoyées par cette messagerie.

## 1. Objectif

Que le personnel échange dans la plateforme, sans passer par des courriels personnels, et que le système y dépose ses propres messages : relances (14b-2), demandes et décisions (14c), notifications. Le corps d'un message peut contenir des données personnelles : il reste dans la plateforme.

## 2. Règles

1. **Qui** : tout compte du personnel, c'est-à-dire tout compte qui ouvre le back-office. Les candidats et les auditeurs n'y ont pas accès. On écrit à un ou plusieurs comptes du personnel, jamais à soi-même.
2. **Message** :
   - un objet (200 caractères au plus) et un corps (10 000 caractères au plus) ;
   - une catégorie : message, relance, demande, décision, notification. Les quatre dernières sont réservées au système ;
   - une priorité : normale, haute ou urgente ;
   - un expéditeur, ou aucun pour un message du système.
3. **Fil de discussion** : une réponse rejoint le fil du message auquel elle répond. Elle va à l'expéditeur et aux autres destinataires (« répondre à tous »), sauf à soi-même. On ne répond pas à un message du système.
4. **Pièces jointes** : PDF, JPEG, PNG, Excel (.xlsx) ou Word (.docx) ; 10 Mo par fichier, 5 fichiers au plus.
   - Le service reçoit le fichier déposé, jamais un chemin : un chemin glissé dans le formulaire est refusé.
   - Le type se lit dans le contenu du fichier ; l'extension du nom de téléchargement suit ce type, et non le nom donné par l'expéditeur.
   - Chaque pièce passe par l'antivirus (le scanner de la plateforme ; aucun n'est branché en production à ce jour).
   - Le service range les pièces dans le stockage privé, dans la même opération que le message, et efface ses fichiers si l'enregistrement échoue. On enregistre leur nom, leur type, leur taille et leur empreinte SHA-256.
   - Seuls l'expéditeur et les destinataires les téléchargent.
   - Un compte envoie 30 messages par heure au plus, à 100 destinataires au plus ; le système n'est pas limité.
5. **Lecture et classement** :
   - ouvrir un message le marque lu pour soi ;
   - l'expéditeur voit, pour chaque destinataire, s'il a lu le message et quand (accusé de lecture) ;
   - un destinataire peut archiver un message, puis le désarchiver.
6. **Ajout seul** : un message envoyé ne se modifie ni ne se supprime ; ses pièces non plus. Seuls changent, pour chaque destinataire, la date de lecture et la date d'archivage.
7. **Copie externe** (courriel, SMS) :
   - elle ne porte que l'objet et l'invitation à se connecter, avec le lien ; jamais le corps ;
   - à l'envoi, l'expéditeur choisit de prévenir ou non par courriel. Le SMS est réservé aux messages du système (relances bloquantes, 14b-2) ;
   - un destinataire sans adresse est noté « sans adresse » ;
   - la file est traitée toutes les cinq minutes, avec trois tentatives au plus ; l'erreur est conservée, sans adresse ni numéro.
8. **Journal** : chaque envoi est journalisé (journal `messagerie`), avec la catégorie, le nombre de destinataires, de pièces et les canaux. L'objet et le corps n'y figurent pas.
9. **Écran « Messagerie »**, en tête du menu, hors groupe :
   - quatre onglets : reçus, non lus, envoyés, archivés ;
   - « Nouveau message » ;
   - la lecture du fil, avec les actions répondre, archiver, désarchiver et télécharger une pièce.

   Pastille : messages reçus non lus et non archivés, reprise dans l'encart « À traiter ». Une enveloppe dans la barre du haut affiche le même nombre.

## 3. Modèle de données

- `messages` : uuid, `fil` (uuid du premier message du fil), `expediteur_id`, `categorie`, `priorite`, `objet`, `corps`, `reponse_a`, `envoye_le`. Un déclencheur refuse la suppression et toute modification, sauf la mise à vide de l'expéditeur quand son compte est supprimé.
- `messages_destinataires` : `message_id`, `user_id`, `lu_le`, `archive_le` ; unique (`message_id`, `user_id`).
- `messages_pieces` : `message_id`, `nom_fichier`, `type_mime`, `taille_octets` (de 1 octet à 10 Mo), `sha256`, `chemin`. Ajout seul, par déclencheur.
- `envois_messages` : `message_id`, `user_id`, `canal` (`email`, `sms`), `adresse`, `texte`, `statut` (`a_envoyer`, `envoye`, `echec`, `sans_adresse`), `tentatives`, `derniere_erreur`, `envoye_le` ; unique (`message_id`, `user_id`, `canal`).

## 4. Tests

1. Envoi : destinataires dédoublonnés, sans l'expéditeur, du personnel seulement ; refus sans destinataire, d'un objet vide ou trop long, d'une catégorie inconnue.
2. Fil : la réponse rejoint le fil et va à tous les participants sauf soi ; pas de réponse à un message du système ni à un fil dont on ne fait pas partie.
3. Pièces : type et taille contrôlés, empreinte enregistrée ; téléchargement refusé hors participants.
4. Ajout seul : modification et suppression d'un message refusées par la base.
5. Copie externe : texte sans le corps ; statut « sans adresse » ; envoi par courriel et par SMS ; échec retenté puis abandonné après trois tentatives.
6. Écran : accès du personnel seulement, onglets, lecture qui marque lu, accusé de lecture, message d'autrui refusé (403), pastille et encart « À traiter ».
