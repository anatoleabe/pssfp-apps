# Spec — Diagnostic des envois SMS et e-mail (module 6)

**Date** : 2026-09-17
**Demandeur** : M. ABE ETOUMOU Anatole (Chef USI, Chef de Projet)
**Modules** : Module 6 (admin Filament) principalement, Module 5 (notifications candidats) pour la lisibilité du journal.
**Statut** : validé en cadrage, prêt pour plan d'implémentation.

## Problème

Trois manques constatés en exploitation, en pleine campagne P14.

**1. Le fournisseur SMS actif est invisible.** `SMS_PROVIDER` ne vit que dans le `.env` du serveur. Un administrateur ne peut pas savoir depuis l'admin si les SMS partent par Echo SMS, par Africa's Talking, ou pas du tout — le mode `fake` journalise sans rien envoyer. Rien à l'écran ne distingue ces cas.

**2. Aucun moyen de tester un envoi.** Vérifier qu'un SMS ou un e-mail part réellement suppose aujourd'hui de déclencher une vraie notification vers un vrai candidat, ou d'ouvrir un shell sur le VPS. Avant une relance groupée, il n'existe aucun moyen sûr de s'assurer que la chaîne fonctionne.

**3. Le statut affiché n'est pas lisible.** Le journal des envois affiche `code 1016` brut. La table qui traduit ces codes existe, mais en `private const` dans `EchoSmsProvider` : elle n'est utilisable nulle part ailleurs.

### Constats relevés pendant le cadrage

Ces mesures ont été faites le 2026-09-17 sur la production, en lecture seule.

- `GET /api/sender-id?api_key=…` renvoie **`{"status":"success","data":[]}`** — aucun Sender ID enregistré sur le compte, alors que la production envoie avec `ECHOSMS_SENDER_ID=PSSFP`. Les envois passent malgré tout, mais rien ne garantit la pérennité d'un masking non déclaré.
- L'API n'expose **aucun endpoint de solde ou de crédit** (`/balance`, `/customer/balance`, `/customer/profile` → 404).
- `GET /api/sent/list` (authentification **Bearer**, contrairement à `/sent/compose` qui veut la clé en query) renvoie un statut réel par message : `succeed` ou `failed`. Pagination par `page=` ; `limit`, `per_page` et `count` sont ignorés (taille fixe : 20).
- Comparaison avec notre journal sur les deux échecs opérateur du 03/09 :
  - dossier **P14026-101** — journal `envoye`, opérateur `failed` ;
  - dossier **P14026-109** — opérateur `failed`, **aucune ligne au journal** (les SMS de création de compte ne sont pas journalisés).

Le journal trace donc ce que la passerelle **accepte**, pas ce qu'elle **livre**.

## Périmètre

### 1. Bloc « Envois » dans Paramètres — lecture seule

Nouvelle section dans `Filament/Pages/Parametres`. **Aucune valeur n'est modifiable depuis le web** : la configuration reste dans `.env`, cette section l'expose.

| Élément | Contenu |
|---|---|
| Fournisseur SMS | Libellé lisible : « Echo SMS », « Africa's Talking », « Simulation (aucun envoi réel) » |
| Alerte mode simulation | Bandeau `danger` si le fournisseur actif est `fake` |
| Expéditeur | Type (Sender ID / numéro émetteur) et valeur présentée au destinataire |
| Clé API | « Configurée » ou « Absente » — **jamais la valeur, ni tronquée, ni masquée partiellement** |
| E-mail | Transport (`MAIL_MAILER`), adresse et nom d'expédition |

Plus une action **« Vérifier auprès du fournisseur »** : appelle `/sender-id`, et rend un verdict explicite en trois états — le Sender ID configuré est déclaré chez le fournisseur ; il n'y figure pas (cas actuel) ; ou la vérification est impossible (passerelle injoignable, clé absente, fournisseur qui ne sait pas répondre).

### 2. Envoi de test

Dans la même section, deux champs indépendants avec leur bouton :

- **Numéro** → « Envoyer un SMS de test ». Accepte un numéro E.164 (`^\+[1-9]\d{6,14}$`) ou un numéro local camerounais à 9 chiffres, préfixé `+237` automatiquement. Le numéro est refusé avant tout appel réseau s'il est hors des indicatifs desservis (réutilise `NotificationCandidatsService::smsDesservi()`).
- **Adresse e-mail** → « Envoyer un e-mail de test ».

Le message de test est fixe, explicitement identifié comme test, et n'emprunte aucun gabarit de notification candidat.

**Le résultat est affiché tel quel** :
- succès SMS → expéditeur réellement présenté + code fournisseur traduit (« Message envoyé (1016) ») ;
- échec → le message exact de la passerelle (« Solde insuffisant (1007) », « Sender ID / masking invalide (1002) »…), jamais un « une erreur est survenue ».

### 3. Lisibilité du statut stocké

`EchoSmsProvider::MESSAGES` est extraite dans `App\Services\Sms\EchoSmsCodes`, avec une méthode de libellé. Le journal des envois affiche alors « Message envoyé (1016) » au lieu de « code 1016 ».

**Aucun appel à `/sent/list`.** Décision prise en connaissance de cause (voir Non-objectifs).

## Architecture

Le projet a déjà tranché comment étendre une passerelle sans casser les autres : `ReportsSmsDelivery` est une interface **latérale optionnelle**, testée par `instanceof`, que `SmsServiceInterface` n'absorbe pas. Cette spec suit le même idiome plutôt que d'élargir le contrat principal : chaque passerelle gagne une interface latérale, aucune ne voit la signature de `send()` changer — le stub Africa's Talking se contente de se décrire.

### Fichiers créés

| Fichier | Responsabilité |
|---|---|
| `app/Services/Sms/DescribesConfiguration.php` | Interface optionnelle : la passerelle se décrit |
| `app/Services/Sms/SmsConfigurationSummary.php` | Objet valeur : libellé fournisseur, type et valeur d'expéditeur, clé configurée (booléen), envoi réel ou simulé |
| `app/Services/Sms/VerifiesSenderIdentity.php` | Interface optionnelle : contrôle du Sender ID |
| `app/Services/Sms/SenderIdentityReport.php` | Objet valeur : verdict à trois états + liste des Sender ID déclarés |
| `app/Services/Sms/EchoSmsCodes.php` | Table code → libellé, extraite de `EchoSmsProvider` |
| `app/Services/EnvoiTestService.php` | Envoi de test SMS et e-mail, écriture de l'Activity Log |
| `app/Mail/EnvoiTestMail.php` + vue Blade | E-mail de test |

### Fichiers modifiés

| Fichier | Modification |
|---|---|
| `app/Services/Sms/EchoSmsProvider.php` | Implémente les deux interfaces ; délègue la table de codes à `EchoSmsCodes` |
| `app/Services/Sms/FakeSmsProvider.php` | Implémente `DescribesConfiguration` — se déclare « Simulation », envoi réel = faux |
| `app/Services/Sms/AfricasTalkingProvider.php` | Implémente `DescribesConfiguration` uniquement |
| `app/Filament/Pages/Parametres.php` | Ajoute la section et les trois actions. Reste une coquille Filament mince : toute la logique vit dans `EnvoiTestService` et les descripteurs de passerelle. Environ 280 lignes au total, dans la fourchette usuelle du projet — aucun dossier `Pages/Schemas/` n'est créé, cette convention n'existe pas ici. |
| `app/Filament/Resources/CandidatureRelanceResource.php` | Affiche le libellé du code au lieu du code brut |

### Dégradation

Une passerelle qui n'implémente pas `DescribesConfiguration` affiche « Fournisseur non documenté » plutôt que de faire échouer la page. Une passerelle sans `VerifiesSenderIdentity` masque le bouton de vérification. Aucun `instanceof` manquant ne doit produire d'erreur écran.

## Sécurité

- **La clé API ne sort jamais** : ni dans la page, ni dans une notification Filament, ni dans un message d'exception remonté à l'écran, ni dans l'Activity Log. Un test Pest l'assert explicitement.
- **Numéros masqués dans les logs** via `PhoneMasker`, y compris pour un numéro de test saisi à la main.
- **Double garde d'autorisation** : la page `Parametres` reste derrière `settings.manage` ; les deux actions d'envoi exigent **en plus** `candidature.notify`, la même permission que l'envoi groupé réel. Consulter la configuration et dépenser du crédit ne sont pas le même droit.
- **Limitation de cadence** : 5 envois de test par utilisateur et par minute, tous canaux confondus. Un bouton qui part en boucle ne doit pas vider le crédit SMS.
- **Activity Log** sur chaque test : auteur, horodatage, canal, destinataire masqué, issue.

## Tests

Pest, dans `tests/Feature/Admin/DiagnosticEnvoisTest.php` et `tests/Unit/Sms/` :

- chaque fournisseur se décrit correctement, `fake` compris (envoi réel = faux) ;
- `EchoSmsCodes` traduit les codes connus et rend un libellé neutre pour un code inconnu ;
- vérification Sender ID via `Http::fake()` : déclaré, non déclaré, passerelle injoignable ;
- envoi de test SMS en succès et en échec — le message d'erreur de la passerelle remonte intact ;
- envoi de test e-mail via `Mail::fake()` ;
- numéro local camerounais normalisé en `+237…` ; numéro hors indicatifs desservis refusé sans appel réseau ;
- un utilisateur ayant `settings.manage` mais pas `candidature.notify` voit la section et ne peut pas envoyer ;
- la limitation de cadence bloque le 6ᵉ envoi ;
- l'Activity Log est écrit, avec le numéro masqué ;
- **la clé API n'apparaît dans aucune sortie** : rendu de page, notification, log.

## Non-objectifs

**Le statut réel de livraison n'est pas récupéré.** Aucun appel à `/sent/list`, aucune tâche de réconciliation. Conséquence assumée et connue : un cas comme **P14026-101** — journal `envoye`, opérateur `failed` — reste invisible dans l'admin. Trois options avaient été posées au cadrage (réconciliation planifiée, vérification à la demande, affichage de l'existant) ; l'affichage de l'existant a été retenu. Revenir sur ce choix supposerait une correspondance par numéro, texte et horodatage, faute d'identifiant de message dans la réponse de l'API.

**Les SMS non journalisés le restent.** Les SMS de création de compte et les OTP n'écrivent pas dans `candidature_relances` (cf. P14026-109). Cette spec ne comble pas ce trou.

**Aucune modification de configuration depuis le web.** `SMS_PROVIDER`, les clés et les Sender ID restent dans `.env`. Les rendre éditables déplacerait un secret vers la base et le panneau d'administration.

**Aucun affichage de solde ou de crédit** : l'API Echo SMS n'expose pas cette information.
