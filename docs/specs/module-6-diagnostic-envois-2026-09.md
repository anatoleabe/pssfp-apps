# Spec — Passerelle TechSoft et diagnostic des envois (module 6)

**Date** : 2026-09-17, révisée le 2026-09-18 (changement de fournisseur SMS)
**Demandeur** : M. ABE ETOUMOU Anatole (Chef USI, Chef de Projet)
**Modules** : Module 6 (admin Filament), Module 5 (notifications candidats).
**Statut** : validé en cadrage, prêt pour plan d'implémentation.

## Problème

Trois manques constatés en exploitation, en pleine campagne P14, plus un changement de fournisseur imposé.

**1. Le fournisseur SMS actif est invisible.** `SMS_PROVIDER` ne vit que dans le `.env` du serveur. Rien à l'écran ne distingue un envoi réel d'un mode `fake` qui journalise sans rien envoyer.

**2. Aucun moyen de tester un envoi.** Vérifier que la chaîne fonctionne suppose de déclencher une vraie notification vers un vrai candidat, ou d'ouvrir un shell sur le VPS.

**3. Le statut affiché n'est pas lisible ni fiable.** Le journal affiche `code 1016` brut, et ne trace que ce que la passerelle **accepte**, jamais ce qu'elle **livre**.

**4. Le Sender ID Echo SMS a expiré**, et son renouvellement est facturé. Décision du 2026-09-18 : bascule vers **TechSoft Bulk SMS** (`app.techsoft-sms.com`).

### Constats relevés pendant le cadrage

Mesures faites en lecture seule sur les API réelles, les 2026-09-17 et 18.

**Echo SMS (sortant)** — `/sender-id` renvoyait une liste vide alors que la production envoyait avec le masking `PSSFP` ; aucun endpoint de solde ; `/sent/list` limité à 20 messages par page, **sans identifiant de message**. Comparaison avec notre journal, sur les deux échecs opérateur du 03/09 : dossier **P14026-101** marqué `envoye` chez nous, `failed` chez l'opérateur ; dossier **P14026-109** en échec opérateur sans aucune ligne au journal.

**TechSoft v3 (entrant)** — contrat vérifié contre l'API de production le 2026-09-18 :

| Besoin | Endpoint | Retour utile |
|---|---|---|
| Envoi | `POST /api/v3/sms/send` | `uid`, `status`, `cost`, `sent_at` |
| Statut d'un message | `GET /api/v3/sms/{uid}` | `status` à jour |
| Historique | `GET /api/v3/sms?start_date=&end_date=&direction=` | paginé, filtrable |
| Solde et compte | `GET /api/v3/user` | `sms_unit` (solde), identité du compte |

Authentification `Authorization: Bearer {token}` + `Accept: application/json`. Corps JSON : `recipient`, `sender_id`, `type` (`plain`), `message`, `schedule_time` optionnel. Erreurs normalisées : `{status, code, http_status, message, error_code, details}`.

Deux contraintes d'exploitation relevées :

- **Le compte TechSoft est partagé avec un autre projet** : l'historique contient des messages émis avec le Sender ID `Opprio`. Toute lecture de l'historique global doit filtrer sur notre Sender ID.
- **Coût observé : 12 par SMS**, pour un crédit de 3 363,48 — environ **280 SMS restants**. La relance des 84 candidats tient, mais le solde doit être visible.

## Périmètre

### 1. Passerelle TechSoft

Nouveau `TechSoftProvider`, branché par `SMS_PROVIDER=techsoft`. `EchoSmsProvider` est **conservé dans le code** : le binding se fait par configuration, le garder ne coûte rien et laisse une porte de retour si la bascule se passe mal.

Le provider implémente le contrat principal `SmsServiceInterface` plus trois interfaces latérales optionnelles (voir Architecture).

### 2. Bloc « Envois » dans Paramètres — lecture seule

Nouvelle section dans `Filament/Pages/Parametres`. **Aucune valeur n'est modifiable depuis le web** : la configuration reste dans `.env`.

| Élément | Contenu |
|---|---|
| Fournisseur SMS | « TechSoft Bulk SMS », « Echo SMS », « Simulation (aucun envoi réel) » |
| Alerte mode simulation | Bandeau `danger` si le fournisseur actif est `fake` |
| Expéditeur | Sender ID présenté au destinataire |
| Jeton API | « Configuré » ou « Absent » — **jamais sa valeur, ni tronquée** |
| E-mail | Transport, adresse et nom d'expédition |

Action **« Vérifier la connexion »** : appelle `GET /user` et rend trois informations — jeton valide ou refusé, nom du compte, et **solde SMS restant**. Chez TechSoft il n'existe aucun endpoint de Sender ID ; un expéditeur non déclaré ne se découvre qu'à l'envoi, via le code d'erreur correspondant. La vérification de connexion remplace donc la vérification de Sender ID prévue à la version précédente de cette spec.

### 3. Envoi de test

Deux champs indépendants avec leur bouton :

- **Numéro** → « Envoyer un SMS de test ». Accepte l'E.164 (`^\+[1-9]\d{6,14}$`) ou un numéro local camerounais à 9 chiffres, préfixé `+237`. Refusé sans appel réseau si hors indicatifs desservis (`NotificationCandidatsService::smsDesservi()`).
- **Adresse e-mail** → « Envoyer un e-mail de test ».

Message de test fixe, explicitement identifié comme test, n'empruntant aucun gabarit de notification candidat.

Résultat affiché tel quel : en succès, l'expéditeur réellement présenté, le statut rendu par la passerelle et **le coût du message** ; en échec, le message exact de la passerelle, jamais un « une erreur est survenue ».

### 4. Statut de livraison par message

C'est le gain principal de la bascule. TechSoft renvoie un `uid` réutilisable, ce qu'Echo SMS ne faisait pas.

- À chaque envoi, le journal enregistre l'**identifiant du message**, son **statut** et son **coût**.
- Le journal des envois gagne une colonne « Livraison » et une action **« Actualiser le statut »** par ligne, qui appelle `GET /sms/{uid}`.
- Les statuts connus (`Delivered`, `Success`) sont traduits en français ; **un statut inconnu est affiché tel quel** plutôt que masqué — la liste des valeurs possibles n'est pas documentée exhaustivement et ne doit pas être devinée.

Pas de tâche planifiée : l'actualisation est déclenchée par l'administrateur. Décision prise au cadrage, réversible sans refonte puisque l'identifiant est stocké.

### 5. Lisibilité des codes fournisseur

Chaque passerelle traduit ses propres codes : `EchoSmsCodes` pour l'existant, `TechSoftCodes` pour le nouveau. Le journal affiche « Solde insuffisant (104) » plutôt que « code 104 ».

## Architecture

Le projet a déjà tranché comment étendre une passerelle sans casser les autres : `ReportsSmsDelivery` est une interface **latérale optionnelle**, testée par `instanceof`, que `SmsServiceInterface` n'absorbe pas. Cette spec suit le même idiome : chaque passerelle gagne les interfaces qu'elle sait honorer, aucune ne voit la signature de `send()` changer.

### Fichiers créés

| Fichier | Responsabilité |
|---|---|
| `app/Services/Sms/TechSoftProvider.php` | Passerelle TechSoft v3 |
| `app/Services/Sms/TechSoftCodes.php` | Table code → libellé TechSoft |
| `app/Services/Sms/EchoSmsCodes.php` | Table code → libellé Echo SMS, extraite de son provider |
| `app/Services/Sms/DescribesConfiguration.php` | Interface : la passerelle se décrit |
| `app/Services/Sms/SmsConfigurationSummary.php` | Objet valeur : libellé, expéditeur, jeton configuré, envoi réel ou simulé |
| `app/Services/Sms/ChecksConnectivity.php` | Interface : contrôle de connexion et solde |
| `app/Services/Sms/ConnectivityReport.php` | Objet valeur : verdict, nom du compte, solde |
| `app/Services/Sms/QueriesMessageStatus.php` | Interface : statut d'un message par identifiant |
| `app/Services/Sms/MessageStatus.php` | Objet valeur : statut brut, libellé, coût, horodatage |
| `app/Services/EnvoiTestService.php` | Envoi de test SMS et e-mail, écriture de l'Activity Log |
| `app/Mail/EnvoiTestMail.php` + vue Blade | E-mail de test |
| Migration | Ajoute `message_uid`, `statut_livraison`, `cout` à `candidature_relances` |

### Fichiers modifiés

| Fichier | Modification |
|---|---|
| `app/Services/Sms/SmsSendResult.php` | Porte désormais `messageId` et `cout`, tous deux nullables — une passerelle qui ne les fournit pas reste valide |
| `app/Services/Sms/EchoSmsProvider.php` | Implémente `DescribesConfiguration` ; délègue ses codes à `EchoSmsCodes` |
| `app/Services/Sms/FakeSmsProvider.php` | Implémente `DescribesConfiguration` — se déclare « Simulation », envoi réel = faux |
| `app/Services/Sms/AfricasTalkingProvider.php` | Implémente `DescribesConfiguration` uniquement |
| `app/Providers/AppServiceProvider.php` | Ajoute la branche `techsoft` |
| `config/services.php` | Bloc `techsoft` : `base_url`, `api_token`, `sender_id` |
| `app/Services/NotificationCandidatsService.php` | Enregistre `message_uid` et `cout` au journal |
| `app/Filament/Pages/Parametres.php` | Section et actions. Reste une coquille mince : la logique vit dans les services. Aucun dossier `Pages/Schemas/` n'est créé, cette convention n'existe pas ici |
| `app/Filament/Resources/CandidatureRelanceResource.php` | Colonne « Livraison », action « Actualiser le statut », libellé des codes |

### Dégradation

Une passerelle sans `DescribesConfiguration` affiche « Fournisseur non documenté » plutôt que de casser la page. Sans `ChecksConnectivity`, le bouton de vérification est masqué. Sans `QueriesMessageStatus`, ou pour une ligne sans `message_uid` — tout l'historique existant —, l'action « Actualiser » est masquée. Aucun `instanceof` manquant ne doit produire d'erreur écran.

## Sécurité

- **`GET /user` renvoie le jeton API en clair** dans sa réponse. Le provider en extrait uniquement les champs nécessaires (nom du compte, solde) et **ne journalise jamais la réponse brute**. Un test Pest l'assert.
- **Le jeton ne sort jamais** : ni dans la page, ni dans une notification Filament, ni dans un message d'exception affiché, ni dans l'Activity Log.
- **Numéros masqués dans les logs** via `PhoneMasker`, y compris pour un numéro de test saisi à la main.
- **Double garde d'autorisation** : la page `Parametres` reste derrière `settings.manage` ; les actions d'envoi exigent **en plus** `candidature.notify`. Consulter la configuration et dépenser du crédit ne sont pas le même droit.
- **Limitation de cadence** : 5 envois de test par utilisateur et par minute, tous canaux confondus.
- **Activity Log** sur chaque test : auteur, horodatage, canal, destinataire masqué, issue.
- **Le jeton actuel doit être régénéré** : il a été divulgué dans les exemples de la documentation authentifiée, et le compte est partagé avec un autre projet. Hors périmètre code, mais bloquant avant la mise en production.

## Tests

Pest, dans `tests/Feature/Admin/DiagnosticEnvoisTest.php` et `tests/Unit/Sms/` :

- `TechSoftProvider` construit la requête conforme au contrat (Bearer, `type: plain`, corps JSON) et remonte `uid`, `status` et `cost` — via `Http::fake()`, jamais d'appel réseau réel ;
- chaque code d'erreur documenté produit un message lisible ; un code inconnu produit un libellé neutre sans planter ;
- un statut de livraison inconnu est affiché brut ;
- `GET /user` : le solde est extrait, **et le jeton présent dans la réponse n'apparaît ni en log ni en sortie** ;
- chaque fournisseur se décrit correctement, `fake` compris (envoi réel = faux) ;
- envoi de test SMS en succès et en échec — le message d'erreur de la passerelle remonte intact ;
- envoi de test e-mail via `Mail::fake()` ;
- numéro local camerounais normalisé en `+237…` ; numéro hors indicatifs desservis refusé sans appel réseau ;
- un utilisateur ayant `settings.manage` mais pas `candidature.notify` voit la section et ne peut pas envoyer ;
- la limitation de cadence bloque le 6ᵉ envoi ;
- l'action « Actualiser le statut » est masquée sur une ligne sans `message_uid` ;
- la migration est réversible et n'altère aucune des lignes existantes.

## Non-objectifs

**Aucune réconciliation automatique.** L'actualisation du statut est déclenchée à la main, ligne par ligne. Le `message_uid` étant stocké, ajouter une tâche planifiée plus tard ne demandera aucune refonte.

**Les SMS non journalisés le restent.** Les SMS de création de compte et les OTP n'écrivent pas dans `candidature_relances` (cf. P14026-109). Cette spec ne comble pas ce trou.

**Aucun rattrapage de l'historique.** Les lignes antérieures à la bascule n'ont pas de `message_uid` : leur statut de livraison restera inconnu, y compris le cas P14026-101. Seuls les envois postérieurs bénéficient du suivi.

**Aucune modification de configuration depuis le web.** `SMS_PROVIDER`, jetons et Sender ID restent dans `.env`.

**Pas d'usage des campagnes ni des listes de contacts TechSoft.** L'API les propose ; notre ciblage vit dans PostgreSQL et doit y rester — dupliquer les destinataires chez le prestataire créerait deux sources de vérité et exporterait des données personnelles de candidats.
