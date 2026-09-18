# Plan d'implémentation — Passerelle TechSoft et diagnostic des envois (module 6)

> **Pour agents d'exécution :** SOUS-SKILL REQUISE — utiliser `superpowers:subagent-driven-development` (recommandé) ou `superpowers:executing-plans` pour exécuter ce plan tâche par tâche. Les étapes utilisent la syntaxe case à cocher (`- [ ]`).

**Objectif :** Basculer les SMS vers TechSoft Bulk SMS v3, rendre la configuration d'envoi visible et testable depuis l'admin, et suivre la livraison réelle de chaque SMS grâce à l'identifiant de message que TechSoft renvoie.

**Architecture :** `SmsServiceInterface` reste inchangé. Chaque capacité nouvelle arrive par une interface latérale optionnelle testée par `instanceof`, sur le modèle de `ReportsSmsDelivery` déjà en place — une passerelle qui ne sait pas faire est masquée dans l'UI plutôt que de casser la page. Le journal `candidature_relances` gagne trois colonnes nullables qui laissent l'historique intact.

**Stack :** Laravel 11 / PHP 8.3 / PostgreSQL 16 / Pest / Filament 3.

**Spec de référence :** `docs/specs/module-6-diagnostic-envois-2026-09.md`

**Branche :** `feat/m6-techsoft-diagnostic-envois`

## Contraintes globales

- Site **en production**. Les 464 lignes existantes de `candidature_relances` ne doivent être ni modifiées ni reclassées. Toute colonne ajoutée est **nullable**.
- **Migration réversible** : `down()` complet et testé.
- **Aucun appel réseau réel dans les tests** : `Http::fake()` systématique, `Mail::fake()` pour les e-mails.
- **Le jeton API ne sort jamais** : ni à l'écran, ni en log, ni dans un message d'exception, ni dans l'Activity Log. `GET /api/v3/user` renvoie le jeton en clair dans sa réponse — **ne jamais journaliser cette réponse brute**.
- **Numéros masqués** dans tout log via `App\Support\PhoneMasker::mask()`.
- **Aucune valeur de configuration modifiable depuis le web.** `.env` reste la seule source.
- **Commits Conventional Commits**, un par tâche minimum.
- Formatage : `./vendor/bin/pint` doit passer avant chaque commit.
- Tests verts avant de passer à la tâche suivante : `./vendor/bin/pest`.

## Contrat TechSoft v3 (vérifié en production le 2026-09-18)

Base `https://app.techsoft-sms.com/api/v3`, en-têtes `Authorization: Bearer {token}` et `Accept: application/json`.

| Action | Requête | Réponse utile |
|---|---|---|
| Envoyer | `POST /sms/send` JSON `{recipient, sender_id, type:"plain", message}` | `data[0]` : `uid`, `status`, `cost`, `sent_at` |
| Statut | `GET /sms/{uid}` | `data` : `uid`, `status`, `cost`, `sent_at` |
| Compte | `GET /user` | `first_name`, `last_name`, `sms_unit`, **`api_token` (à ignorer)** |

Erreur : `{"status":"error","code":104,"http_status":403,"message":"…","error_code":"balance_insufficient","details":{…}}`.

## Structure de fichiers

**Créés**

| Fichier | Responsabilité |
|---|---|
| `app/Services/Sms/TechSoftProvider.php` | Passerelle TechSoft v3 |
| `app/Services/Sms/TechSoftCodes.php` | Codes d'erreur TechSoft → libellé français |
| `app/Services/Sms/EchoSmsCodes.php` | Codes Echo SMS, extraits de son provider |
| `app/Services/Sms/TraceEnvoi.php` | Ce qu'on inscrit au journal après un envoi |
| `app/Services/Sms/DescribesConfiguration.php` | Interface : la passerelle se décrit |
| `app/Services/Sms/SmsConfigurationSummary.php` | Objet valeur de description |
| `app/Services/Sms/ChecksConnectivity.php` | Interface : connexion et solde |
| `app/Services/Sms/ConnectivityReport.php` | Objet valeur de connexion |
| `app/Services/Sms/QueriesMessageStatus.php` | Interface : statut d'un message |
| `app/Services/Sms/MessageStatus.php` | Objet valeur de statut |
| `app/Services/EnvoiTestService.php` | Envoi de test SMS et e-mail |
| `app/Mail/EnvoiTestMail.php` | E-mail de test |
| `resources/views/emails/envoi-test.blade.php` | Vue de l'e-mail de test |
| `database/migrations/2026_09_18_100000_add_livraison_to_candidature_relances.php` | `message_uid`, `statut_livraison`, `cout` |

**Modifiés**

| Fichier | Modification |
|---|---|
| `app/Services/Sms/SmsSendResult.php` | Ajoute `messageId` et `cout` nullables |
| `app/Services/Sms/EchoSmsProvider.php` | Implémente `DescribesConfiguration`, délègue à `EchoSmsCodes` |
| `app/Services/Sms/FakeSmsProvider.php` | Implémente `DescribesConfiguration` |
| `app/Services/Sms/AfricasTalkingProvider.php` | Implémente `DescribesConfiguration` |
| `app/Providers/AppServiceProvider.php` | Branche `techsoft` |
| `config/services.php` | Bloc `techsoft` |
| `app/Models/CandidatureRelance.php` | Trois champs au `$fillable` |
| `app/Services/NotificationCandidatsService.php` | Journalise `message_uid` et `cout` via `TraceEnvoi` |
| `app/Filament/Pages/Parametres.php` | Section « Envois » et ses actions |
| `app/Filament/Resources/CandidatureRelanceResource.php` | Colonne « Livraison », action « Actualiser », libellés |
| `.env.example` | Variables `TECHSOFT_*` |

---

### Tâche 1 : passerelle TechSoft

**Fichiers :**
- Créer : `app/Services/Sms/TechSoftCodes.php`, `app/Services/Sms/TechSoftProvider.php`
- Modifier : `config/services.php`, `app/Providers/AppServiceProvider.php`, `.env.example`
- Test : `tests/Unit/Sms/TechSoftProviderTest.php`

**Interfaces :**
- Consomme : `SmsServiceInterface::send()`, `ReportsSmsDelivery::sendAndReport()`, `SmsSendResult`, `App\Exceptions\NotConfiguredException`.
- Produit : `TechSoftProvider implements ReportsSmsDelivery, SmsServiceInterface` ; `TechSoftCodes::libelle(?string $code): ?string`.

> `SmsSendResult` ne porte pas encore `messageId` ni `cout` : la tâche 2 les ajoute. Cette tâche s'arrête au contrat existant `(?string $expediteur, ?string $codeFournisseur, bool $partiel)`.

- [ ] **Étape 1 : écrire le test de la table de codes**

Créer `tests/Unit/Sms/TechSoftProviderTest.php` :

```php
<?php

declare(strict_types=1);

use App\Exceptions\NotConfiguredException;
use App\Services\Sms\TechSoftCodes;
use App\Services\Sms\TechSoftProvider;
use Illuminate\Support\Facades\Http;

uses()->group('sms', 'techsoft');

it('traduit les codes TechSoft documentés', function (): void {
    expect(TechSoftCodes::libelle('104'))->toBe('Solde SMS insuffisant')
        ->and(TechSoftCodes::libelle('109'))->toBe('Le jeton API n\'a pas la permission demandée')
        ->and(TechSoftCodes::libelle('113'))->toBe('Paramètre de requête invalide');
});

it('rend null pour un code inconnu plutôt que de planter', function (): void {
    expect(TechSoftCodes::libelle('999'))->toBeNull()
        ->and(TechSoftCodes::libelle(null))->toBeNull();
});
```

- [ ] **Étape 2 : lancer, vérifier l'échec**

Run : `cd backend && ./vendor/bin/pest tests/Unit/Sms/TechSoftProviderTest.php`
Attendu : FAIL — `Class "App\Services\Sms\TechSoftCodes" not found`.

- [ ] **Étape 3 : créer la table de codes**

`app/Services/Sms/TechSoftCodes.php` :

```php
<?php

declare(strict_types=1);

namespace App\Services\Sms;

/**
 * Codes d'erreur applicatifs de TechSoft Bulk SMS v3.
 *
 * Extraits de la documentation authentifiée (section Error Reference) et
 * vérifiés contre l'API le 18 septembre 2026. Un code absent de cette table
 * rend `null` : la liste n'est pas garantie exhaustive, et un code inconnu
 * doit être affiché brut plutôt que masqué derrière un libellé inventé.
 */
final class TechSoftCodes
{
    /** @var array<string, string> */
    private const MESSAGES = [
        '104' => 'Solde SMS insuffisant',
        '109' => 'Le jeton API n\'a pas la permission demandée',
        '113' => 'Paramètre de requête invalide',
        '401' => 'Jeton API refusé',
        '403' => 'Accès refusé par la passerelle',
        '404' => 'Ressource introuvable chez la passerelle',
        '405' => 'Méthode HTTP non supportée par la passerelle',
        '422' => 'Requête rejetée par la validation de la passerelle',
        '500' => 'Erreur interne de la passerelle',
    ];

    public static function libelle(?string $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }

        return self::MESSAGES[$code] ?? null;
    }
}
```

- [ ] **Étape 4 : relancer, vérifier le vert**

Run : `cd backend && ./vendor/bin/pest tests/Unit/Sms/TechSoftProviderTest.php`
Attendu : 2 tests PASS.

- [ ] **Étape 5 : écrire les tests d'envoi**

Ajouter à `tests/Unit/Sms/TechSoftProviderTest.php` :

```php
function configurerTechSoft(): void
{
    config()->set('services.techsoft.base_url', 'https://app.techsoft-sms.com/api/v3');
    config()->set('services.techsoft.api_token', 'jeton-de-test-secret');
    config()->set('services.techsoft.sender_id', 'PSSFP');
}

it('envoie un SMS conformément au contrat v3', function (): void {
    configurerTechSoft();
    Http::fake([
        '*/sms/send' => Http::response([
            'status' => 'success',
            'data' => [[
                'uid' => '683831eda796e',
                'to' => '237691234567',
                'from' => 'PSSFP',
                'status' => 'Delivered',
                'cost' => '12',
                'sent_at' => '2026-09-18 10:00:00',
            ]],
        ], 200),
    ]);

    $resultat = app(TechSoftProvider::class)->sendAndReport('+237691234567', 'Bonjour');

    expect($resultat->expediteur)->toBe('PSSFP');

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://app.techsoft-sms.com/api/v3/sms/send'
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer jeton-de-test-secret')
            && $request->hasHeader('Accept', 'application/json')
            && $request['recipient'] === '+237691234567'
            && $request['sender_id'] === 'PSSFP'
            && $request['type'] === 'plain'
            && $request['message'] === 'Bonjour';
    });
});

it('lève une exception lisible quand la passerelle renvoie une erreur métier', function (): void {
    configurerTechSoft();
    Http::fake([
        '*/sms/send' => Http::response([
            'status' => 'error',
            'code' => 104,
            'http_status' => 403,
            'message' => 'Your SMS balance is insufficient to send this message.',
            'error_code' => 'balance_insufficient',
        ], 403),
    ]);

    expect(fn () => app(TechSoftProvider::class)->sendAndReport('+237691234567', 'Bonjour'))
        ->toThrow(RuntimeException::class, 'Solde SMS insuffisant');
});

it('ne laisse jamais fuiter le jeton dans le message d\'exception', function (): void {
    configurerTechSoft();
    Http::fake(['*/sms/send' => Http::response(['status' => 'error', 'code' => 500], 500)]);

    try {
        app(TechSoftProvider::class)->sendAndReport('+237691234567', 'Bonjour');
        $this->fail('Une exception était attendue.');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->not->toContain('jeton-de-test-secret');
    }
});

it('refuse d\'envoyer sans configuration plutôt que d\'appeler la passerelle', function (): void {
    config()->set('services.techsoft.base_url', 'https://app.techsoft-sms.com/api/v3');
    config()->set('services.techsoft.api_token', '');
    Http::fake();

    expect(fn () => app(TechSoftProvider::class)->sendAndReport('+237691234567', 'Bonjour'))
        ->toThrow(NotConfiguredException::class);

    Http::assertNothingSent();
});
```

- [ ] **Étape 6 : lancer, vérifier l'échec**

Run : `cd backend && ./vendor/bin/pest tests/Unit/Sms/TechSoftProviderTest.php`
Attendu : FAIL — `Class "App\Services\Sms\TechSoftProvider" not found`.

- [ ] **Étape 7 : créer la passerelle**

`app/Services/Sms/TechSoftProvider.php` :

```php
<?php

declare(strict_types=1);

namespace App\Services\Sms;

use App\Exceptions\NotConfiguredException;
use App\Support\PhoneMasker;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Passerelle SMS TechSoft Bulk SMS v3 (https://app.techsoft-sms.com).
 *
 * Contrat réel, vérifié contre l'API de production le 18 septembre 2026 :
 *
 * - `POST {base}/sms/send`, corps JSON, `Authorization: Bearer {token}`.
 * - `type` vaut `plain` pour un SMS texte.
 * - `recipient` accepte plusieurs numéros séparés par des virgules. On
 *   n'envoie qu'un numéro à la fois : un refus partiel sur un lot serait
 *   impossible à attribuer, et la traçabilité par candidat y perdrait.
 * - Succès : `{"status":"success","data":[{uid, status, cost, sent_at}]}`.
 *   `data` est un TABLEAU à l'envoi, un OBJET sur `GET /sms/{uid}`.
 * - Erreur : `{"status":"error","code":104,"message":"…"}` avec un code HTTP
 *   cohérent, contrairement à Echo SMS qui répondait 200 sur un refus métier.
 *
 * Le jeton voyage en en-tête et n'est jamais repris dans un message d'erreur
 * ni dans un log.
 */
final class TechSoftProvider implements ReportsSmsDelivery, SmsServiceInterface
{
    private const TIMEOUT_SECONDS = 20;

    public function send(string $phoneE164, string $message): void
    {
        $this->sendAndReport($phoneE164, $message);
    }

    public function sendAndReport(string $phoneE164, string $message): SmsSendResult
    {
        $senderId = $this->senderId();

        $response = $this->requete()->post($this->baseUrl().'/sms/send', [
            'recipient' => $phoneE164,
            'sender_id' => $senderId,
            'type' => 'plain',
            'message' => $message,
        ]);

        $this->refuserSiErreur($response);

        $premier = $response->json('data.0');
        $premier = is_array($premier) ? $premier : [];

        Log::channel('sms')->info('[techsoft] SMS envoyé', [
            'phone' => PhoneMasker::mask($phoneE164),
            'uid' => $premier['uid'] ?? null,
            'status' => $premier['status'] ?? null,
            'length' => mb_strlen($message),
        ]);

        return new SmsSendResult(
            expediteur: is_string($premier['from'] ?? null) ? $premier['from'] : $senderId,
            codeFournisseur: null,
        );
    }

    /**
     * Requête préconfigurée. Centralisée pour que l'en-tête d'authentification
     * ne soit écrit qu'à un seul endroit.
     */
    private function requete(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::asJson()
            ->timeout(self::TIMEOUT_SECONDS)
            ->withHeaders([
                'Authorization' => 'Bearer '.$this->apiToken(),
                'Accept' => 'application/json',
            ]);
    }

    /**
     * Traduit une réponse d'erreur en exception lisible.
     *
     * Ne reprend jamais l'URL ni les en-têtes : le jeton y figurerait.
     */
    private function refuserSiErreur(Response $response): void
    {
        $statut = $response->json('status');

        if ($response->successful() && $statut !== 'error') {
            return;
        }

        $code = $response->json('code');
        $code = is_scalar($code) ? (string) $code : (string) $response->status();

        $libelle = TechSoftCodes::libelle($code);
        $detail = $response->json('message');

        throw new RuntimeException(
            'TechSoft a refusé l\'envoi (code '.$code.' : '
            .($libelle ?? (is_string($detail) && $detail !== '' ? $detail : 'motif inconnu')).').'
        );
    }

    private function baseUrl(): string
    {
        $url = rtrim((string) config('services.techsoft.base_url', ''), '/');

        if ($url === '' || $this->apiToken() === '') {
            throw new NotConfiguredException(
                'TechSoft non configuré. Renseignez TECHSOFT_BASE_URL et TECHSOFT_API_TOKEN, '
                .'ou repassez SMS_PROVIDER=fake.'
            );
        }

        return $url;
    }

    private function apiToken(): string
    {
        return (string) config('services.techsoft.api_token', '');
    }

    private function senderId(): string
    {
        $senderId = (string) config('services.techsoft.sender_id', '');

        if ($senderId === '') {
            throw new NotConfiguredException(
                'TECHSOFT_SENDER_ID est requis pour envoyer un SMS via TechSoft.'
            );
        }

        // TechSoft tronque silencieusement au-delà de 11 caractères : mieux
        // vaut refuser que laisser partir un expéditeur amputé.
        if (mb_strlen($senderId) > 11) {
            throw new NotConfiguredException(
                'TECHSOFT_SENDER_ID dépasse 11 caractères — TechSoft le tronquerait.'
            );
        }

        return $senderId;
    }
}
```

- [ ] **Étape 8 : déclarer la configuration**

Dans `config/services.php`, après le bloc `echosms` :

```php
    // Passerelle SMS TechSoft Bulk SMS v3 (https://app.techsoft-sms.com).
    // Jeton en en-tête Bearer, corps JSON. Le Sender ID doit être déclaré
    // dans l'interface web de TechSoft : l'API n'expose aucun endpoint pour
    // le vérifier, un expéditeur non déclaré ne se découvre qu'à l'envoi.
    'techsoft' => [
        'base_url' => env('TECHSOFT_BASE_URL', 'https://app.techsoft-sms.com/api/v3'),
        'api_token' => env('TECHSOFT_API_TOKEN'),
        'sender_id' => env('TECHSOFT_SENDER_ID'),
    ],
```

Dans `app/Providers/AppServiceProvider.php`, ajouter la branche au `match` :

```php
                'techsoft' => $app->make(TechSoftProvider::class),
```

avec l'import `use App\Services\Sms\TechSoftProvider;`.

Dans `.env.example`, sous les variables SMS :

```
TECHSOFT_BASE_URL=https://app.techsoft-sms.com/api/v3
TECHSOFT_API_TOKEN=
TECHSOFT_SENDER_ID=PSSFP
```

- [ ] **Étape 9 : relancer, vérifier le vert**

Run : `cd backend && ./vendor/bin/pest tests/Unit/Sms/TechSoftProviderTest.php`
Attendu : 6 tests PASS.

- [ ] **Étape 10 : formater et committer**

```bash
cd backend && ./vendor/bin/pint app/Services/Sms/ tests/Unit/Sms/ config/services.php app/Providers/AppServiceProvider.php
git add backend/app/Services/Sms/TechSoftProvider.php backend/app/Services/Sms/TechSoftCodes.php backend/config/services.php backend/app/Providers/AppServiceProvider.php backend/.env.example backend/tests/Unit/Sms/TechSoftProviderTest.php
git commit -m "feat(sms): passerelle TechSoft Bulk SMS v3"
```

---

### Tâche 2 : identifiant, coût et statut de livraison au journal

**Fichiers :**
- Créer : `app/Services/Sms/TraceEnvoi.php`, `database/migrations/2026_09_18_100000_add_livraison_to_candidature_relances.php`
- Modifier : `app/Services/Sms/SmsSendResult.php`, `app/Services/Sms/TechSoftProvider.php`, `app/Models/CandidatureRelance.php`, `app/Services/NotificationCandidatsService.php`
- Test : `tests/Feature/Applications/NotificationCandidatsTest.php`

**Interfaces :**
- Consomme : `TechSoftProvider::sendAndReport()` de la tâche 1.
- Produit : `SmsSendResult` avec `?string $messageId` et `?string $cout` ; `TraceEnvoi::sms(?string $expediteur, ?string $codeFournisseur, ?string $messageUid, ?string $cout)` et `TraceEnvoi::email(?string $expediteur)` ; colonnes `message_uid`, `statut_livraison`, `cout` sur `candidature_relances`.

- [ ] **Étape 1 : écrire le test d'enregistrement**

Ajouter à `tests/Feature/Applications/NotificationCandidatsTest.php` :

```php
it('enregistre l\'identifiant et le coût du message renvoyés par la passerelle', function (): void {
    config()->set('services.sms.provider', 'techsoft');
    config()->set('services.techsoft.base_url', 'https://app.techsoft-sms.com/api/v3');
    config()->set('services.techsoft.api_token', 'jeton-de-test-secret');
    config()->set('services.techsoft.sender_id', 'PSSFP');

    Http::fake([
        '*/sms/send' => Http::response([
            'status' => 'success',
            'data' => [[
                'uid' => '683831eda796e',
                'from' => 'PSSFP',
                'status' => 'Delivered',
                'cost' => '12',
            ]],
        ], 200),
    ]);

    $candidature = candidatureNotifiable();
    $auteur = auteurNotification();

    app(NotificationCandidatsService::class)->envoyer(
        collect([$candidature]),
        NotificationCandidatsService::CANAL_SMS,
        'Message de test',
        null,
        $auteur,
    );

    $ligne = CandidatureRelance::where('candidature_id', $candidature->id)->latest('id')->first();

    expect($ligne->message_uid)->toBe('683831eda796e')
        ->and($ligne->cout)->toBe('12')
        ->and($ligne->statut_livraison)->toBe('Delivered');
});
```

> Reprendre les helpers réellement utilisés par les tests voisins de ce fichier pour construire la candidature et l'auteur, ainsi que la signature exacte de `envoyer()` — ne pas en inventer.

- [ ] **Étape 2 : lancer, vérifier l'échec**

Run : `cd backend && ./vendor/bin/pest tests/Feature/Applications/NotificationCandidatsTest.php --filter="identifiant et le coût"`
Attendu : FAIL — colonne `message_uid` inexistante.

- [ ] **Étape 3 : créer la migration**

`database/migrations/2026_09_18_100000_add_livraison_to_candidature_relances.php` :

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suivi de livraison par message (ADR à venir, cf. spec module 6).
 *
 * Trois colonnes nullables : les lignes antérieures à la bascule TechSoft
 * n'ont pas d'identifiant de message et gardent un statut de livraison
 * inconnu. Aucune donnée existante n'est touchée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidature_relances', function (Blueprint $table): void {
            $table->string('message_uid', 64)->nullable();
            $table->string('statut_livraison', 32)->nullable();
            $table->string('cout', 20)->nullable();

            $table->index('message_uid', 'candidature_relances_message_uid_idx');
        });
    }

    public function down(): void
    {
        Schema::table('candidature_relances', function (Blueprint $table): void {
            $table->dropIndex('candidature_relances_message_uid_idx');
            $table->dropColumn(['message_uid', 'statut_livraison', 'cout']);
        });
    }
};
```

- [ ] **Étape 4 : étendre `SmsSendResult`**

Remplacer le constructeur de `app/Services/Sms/SmsSendResult.php` :

```php
    public function __construct(
        /** Sender ID ou numéro émetteur réellement présenté au destinataire. */
        public readonly ?string $expediteur,
        /** Code applicatif rendu par la passerelle (Echo SMS : 1016, 1015…). */
        public readonly ?string $codeFournisseur,
        /** La passerelle signale une livraison partielle (code 1015). */
        public readonly bool $partiel = false,
        /** Identifiant du message chez la passerelle, s'il en fournit un. */
        public readonly ?string $messageId = null,
        /** Statut rendu à l'envoi, brut — jamais normalisé ici. */
        public readonly ?string $statut = null,
        /** Coût facturé, tel que rendu par la passerelle. */
        public readonly ?string $cout = null,
    ) {}
```

- [ ] **Étape 5 : renseigner ces champs dans `TechSoftProvider`**

Dans `app/Services/Sms/TechSoftProvider.php`, remplacer le `return` de `sendAndReport()` :

```php
        return new SmsSendResult(
            expediteur: is_string($premier['from'] ?? null) ? $premier['from'] : $senderId,
            codeFournisseur: null,
            messageId: isset($premier['uid']) ? (string) $premier['uid'] : null,
            statut: isset($premier['status']) ? (string) $premier['status'] : null,
            cout: isset($premier['cost']) ? (string) $premier['cost'] : null,
        );
```

- [ ] **Étape 6 : créer l'objet de trace**

`app/Services/Sms/TraceEnvoi.php` :

```php
<?php

declare(strict_types=1);

namespace App\Services\Sms;

/**
 * Ce qu'on inscrit au journal après une tentative d'envoi.
 *
 * Regroupe des champs qui, passés un à un, porteraient `tracer()` à onze
 * paramètres positionnels. Deux constructeurs nommés plutôt qu'un seul :
 * un e-mail n'a ni identifiant de message ni coût, et rien ne doit laisser
 * croire le contraire.
 */
final class TraceEnvoi
{
    private function __construct(
        public readonly ?string $expediteur,
        public readonly ?string $codeFournisseur,
        public readonly ?string $messageUid,
        public readonly ?string $statutLivraison,
        public readonly ?string $cout,
    ) {}

    public static function sms(
        ?string $expediteur,
        ?string $codeFournisseur = null,
        ?string $messageUid = null,
        ?string $statutLivraison = null,
        ?string $cout = null,
    ): self {
        return new self($expediteur, $codeFournisseur, $messageUid, $statutLivraison, $cout);
    }

    public static function email(?string $expediteur): self
    {
        return new self($expediteur, null, null, null, null);
    }

    public static function depuisResultat(SmsSendResult $resultat): self
    {
        return new self(
            $resultat->expediteur,
            $resultat->codeFournisseur,
            $resultat->messageId,
            $resultat->statut,
            $resultat->cout,
        );
    }
}
```

- [ ] **Étape 7 : brancher le journal**

Dans `app/Models/CandidatureRelance.php`, ajouter au `$fillable` : `'message_uid'`, `'statut_livraison'`, `'cout'`.

Dans `app/Services/NotificationCandidatsService.php` :

1. Remplacer les deux derniers paramètres de `tracer()` — `?string $expediteur = null, ?string $codeFournisseur = null` — par `?TraceEnvoi $trace = null`, et dans le `CandidatureRelance::create([...])` remplacer les deux lignes correspondantes par :

```php
            'expediteur' => $trace?->expediteur,
            'code_fournisseur' => $trace?->codeFournisseur,
            'message_uid' => $trace?->messageUid,
            'statut_livraison' => $trace?->statutLivraison,
            'cout' => $trace?->cout,
```

2. Dans le chemin SMS en succès, remplacer les variables `$expediteur` / `$code` par une trace :

```php
            if ($this->sms instanceof ReportsSmsDelivery) {
                $trace = TraceEnvoi::depuisResultat($this->sms->sendAndReport($numero, $texte));
            } else {
                $this->sms->send($numero, $texte);
                $trace = TraceEnvoi::sms(null);
            }
            $this->tracer($candidature, CandidatureRelance::CANAL_SMS, CandidatureRelance::STATUT_ENVOYE, $texte, null, $auteur, null, $trace);
```

3. Adapter les trois autres appels à `tracer()` : SMS en échec → `TraceEnvoi::sms($this->expediteurSmsConfigure())` ; e-mail succès et échec → `TraceEnvoi::email($this->expediteurEmailConfigure())`.

Ajouter l'import `use App\Services\Sms\TraceEnvoi;`.

- [ ] **Étape 8 : migrer et relancer**

Run : `cd backend && php artisan migrate && ./vendor/bin/pest tests/Feature/Applications/`
Attendu : migration appliquée, tous les tests du dossier verts.

- [ ] **Étape 9 : vérifier la réversibilité**

Run : `cd backend && php artisan migrate:rollback --step=1 && php artisan migrate`
Attendu : aucun échec — le `down()` retire index et colonnes, le `up()` les repose.

- [ ] **Étape 10 : formater et committer**

```bash
cd backend && ./vendor/bin/pint app/ tests/ database/migrations/
git add backend/
git commit -m "feat(sms): journaliser identifiant, statut et cout de chaque SMS"
```

---

### Tâche 3 : chaque passerelle se décrit

**Fichiers :**
- Créer : `app/Services/Sms/DescribesConfiguration.php`, `app/Services/Sms/SmsConfigurationSummary.php`, `app/Services/Sms/EchoSmsCodes.php`
- Modifier : `app/Services/Sms/TechSoftProvider.php`, `EchoSmsProvider.php`, `FakeSmsProvider.php`, `AfricasTalkingProvider.php`
- Test : `tests/Unit/Sms/DescriptionPasserelleTest.php`

**Interfaces :**
- Consomme : les quatre passerelles existantes.
- Produit : `DescribesConfiguration::decrire(): SmsConfigurationSummary` ; `SmsConfigurationSummary(string $libelle, ?string $expediteur, bool $jetonConfigure, bool $envoiReel)` ; `EchoSmsCodes::libelle(?string $code): ?string`.

- [ ] **Étape 1 : écrire le test**

Créer `tests/Unit/Sms/DescriptionPasserelleTest.php` :

```php
<?php

declare(strict_types=1);

use App\Services\Sms\AfricasTalkingProvider;
use App\Services\Sms\EchoSmsProvider;
use App\Services\Sms\FakeSmsProvider;
use App\Services\Sms\TechSoftProvider;

uses()->group('sms');

it('décrit TechSoft avec son expéditeur et son jeton', function (): void {
    config()->set('services.techsoft.api_token', 'jeton-de-test-secret');
    config()->set('services.techsoft.sender_id', 'PSSFP');

    $description = app(TechSoftProvider::class)->decrire();

    expect($description->libelle)->toBe('TechSoft Bulk SMS')
        ->and($description->expediteur)->toBe('PSSFP')
        ->and($description->jetonConfigure)->toBeTrue()
        ->and($description->envoiReel)->toBeTrue();
});

it('signale un jeton absent sans jamais exposer sa valeur', function (): void {
    config()->set('services.techsoft.api_token', '');
    config()->set('services.techsoft.sender_id', 'PSSFP');

    $description = app(TechSoftProvider::class)->decrire();

    expect($description->jetonConfigure)->toBeFalse()
        ->and(json_encode($description))->not->toContain('jeton');
});

it('déclare la simulation comme n\'envoyant rien', function (): void {
    $description = app(FakeSmsProvider::class)->decrire();

    expect($description->envoiReel)->toBeFalse()
        ->and($description->libelle)->toBe('Simulation (aucun envoi réel)');
});

it('décrit aussi Echo SMS et Africa\'s Talking', function (): void {
    expect(app(EchoSmsProvider::class)->decrire()->libelle)->toBe('Echo SMS')
        ->and(app(AfricasTalkingProvider::class)->decrire()->libelle)->toBe('Africa\'s Talking');
});

it('conserve la traduction des codes Echo SMS après extraction', function (): void {
    expect(\App\Services\Sms\EchoSmsCodes::libelle('1016'))->toBe('Message envoyé')
        ->and(\App\Services\Sms\EchoSmsCodes::libelle('1007'))->toBe('Solde insuffisant')
        ->and(\App\Services\Sms\EchoSmsCodes::libelle('9999'))->toBeNull();
});
```

- [ ] **Étape 2 : lancer, vérifier l'échec**

Run : `cd backend && ./vendor/bin/pest tests/Unit/Sms/DescriptionPasserelleTest.php`
Attendu : FAIL — `Call to undefined method … ::decrire()`.

- [ ] **Étape 3 : créer l'interface et l'objet valeur**

`app/Services/Sms/DescribesConfiguration.php` :

```php
<?php

declare(strict_types=1);

namespace App\Services\Sms;

/**
 * Contrat optionnel : une passerelle capable de décrire sa configuration.
 *
 * Même raison d'être que ReportsSmsDelivery — élargir SmsServiceInterface
 * obligerait chaque implémentation à changer de signature pour un besoin
 * qui ne concerne que l'affichage. Les appelants testent `instanceof`.
 */
interface DescribesConfiguration
{
    public function decrire(): SmsConfigurationSummary;
}
```

`app/Services/Sms/SmsConfigurationSummary.php` :

```php
<?php

declare(strict_types=1);

namespace App\Services\Sms;

/**
 * Description affichable d'une passerelle.
 *
 * Ne contient DÉLIBÉRÉMENT aucun secret : `jetonConfigure` est un booléen,
 * jamais la valeur du jeton ni un fragment de celle-ci. Cet objet est rendu
 * tel quel dans l'admin.
 */
final class SmsConfigurationSummary
{
    public function __construct(
        /** Nom lisible de la passerelle, destiné à l'écran. */
        public readonly string $libelle,
        /** Expéditeur présenté au destinataire, ou null si non configuré. */
        public readonly ?string $expediteur,
        /** Un jeton ou une clé est présent en configuration. */
        public readonly bool $jetonConfigure,
        /** La passerelle envoie réellement, par opposition à la simulation. */
        public readonly bool $envoiReel,
    ) {}
}
```

- [ ] **Étape 4 : extraire les codes Echo SMS**

Créer `app/Services/Sms/EchoSmsCodes.php` en y déplaçant **à l'identique** la table `MESSAGES` de `EchoSmsProvider` (21 entrées, de `1001` à `1021`), sur le modèle exact de `TechSoftCodes` : constante `private const MESSAGES`, méthode `public static function libelle(?string $code): ?string` rendant `null` pour un code inconnu ou nul.

Dans `EchoSmsProvider`, supprimer la constante `MESSAGES` et remplacer son unique usage `self::MESSAGES[$code] ?? 'motif inconnu'` par `EchoSmsCodes::libelle($code) ?? 'motif inconnu'`.

- [ ] **Étape 5 : implémenter `decrire()` sur les quatre passerelles**

`TechSoftProvider` — ajouter `DescribesConfiguration` à la liste des interfaces et la méthode :

```php
    public function decrire(): SmsConfigurationSummary
    {
        $senderId = (string) config('services.techsoft.sender_id', '');

        return new SmsConfigurationSummary(
            libelle: 'TechSoft Bulk SMS',
            expediteur: $senderId === '' ? null : $senderId,
            jetonConfigure: $this->apiToken() !== '',
            envoiReel: true,
        );
    }
```

`EchoSmsProvider` :

```php
    public function decrire(): SmsConfigurationSummary
    {
        $fromType = (string) config('services.echosms.from_type', 'sender_id');
        $expediteur = $fromType === 'sender_id'
            ? (string) config('services.echosms.sender_id', '')
            : (string) config('services.echosms.from_number', '');

        return new SmsConfigurationSummary(
            libelle: 'Echo SMS',
            expediteur: $expediteur === '' ? null : $expediteur,
            jetonConfigure: (string) config('services.echosms.api_key', '') !== '',
            envoiReel: true,
        );
    }
```

`FakeSmsProvider` :

```php
    public function decrire(): SmsConfigurationSummary
    {
        return new SmsConfigurationSummary(
            libelle: 'Simulation (aucun envoi réel)',
            expediteur: 'fake',
            jetonConfigure: true,
            envoiReel: false,
        );
    }
```

`AfricasTalkingProvider` :

```php
    public function decrire(): SmsConfigurationSummary
    {
        $senderId = (string) config('services.africas_talking.sender_id', '');

        return new SmsConfigurationSummary(
            libelle: 'Africa\'s Talking',
            expediteur: $senderId === '' ? null : $senderId,
            jetonConfigure: (string) config('services.africas_talking.api_key', '') !== '',
            envoiReel: true,
        );
    }
```

Chacune reçoit `DescribesConfiguration` dans son `implements`.

- [ ] **Étape 6 : relancer et vérifier l'ensemble**

Run : `cd backend && ./vendor/bin/pest tests/Unit/Sms/`
Attendu : tous verts, y compris les tests Echo SMS existants après extraction de la table.

- [ ] **Étape 7 : formater et committer**

```bash
cd backend && ./vendor/bin/pint app/Services/Sms/ tests/Unit/Sms/
git add backend/app/Services/Sms/ backend/tests/Unit/Sms/
git commit -m "feat(sms): chaque passerelle decrit sa configuration"
```

---

### Tâche 4 : contrôle de connexion et solde

**Fichiers :**
- Créer : `app/Services/Sms/ChecksConnectivity.php`, `app/Services/Sms/ConnectivityReport.php`
- Modifier : `app/Services/Sms/TechSoftProvider.php`
- Test : `tests/Unit/Sms/TechSoftConnectiviteTest.php`

**Interfaces :**
- Consomme : `TechSoftProvider` des tâches 1 et 3.
- Produit : `ChecksConnectivity::verifierConnexion(): ConnectivityReport` ; `ConnectivityReport(bool $joignable, ?string $compte, ?string $solde, ?string $erreur)`.

- [ ] **Étape 1 : écrire le test**

Créer `tests/Unit/Sms/TechSoftConnectiviteTest.php` :

```php
<?php

declare(strict_types=1);

use App\Services\Sms\TechSoftProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses()->group('sms', 'techsoft');

beforeEach(function (): void {
    config()->set('services.techsoft.base_url', 'https://app.techsoft-sms.com/api/v3');
    config()->set('services.techsoft.api_token', 'jeton-de-test-secret');
    config()->set('services.techsoft.sender_id', 'PSSFP');
});

it('remonte le compte et le solde', function (): void {
    Http::fake(['*/user' => Http::response([
        'id' => 339,
        'first_name' => 'Anatole',
        'last_name' => null,
        'api_token' => 'jeton-de-test-secret',
        'sms_unit' => '3363.48',
    ], 200)]);

    $rapport = app(TechSoftProvider::class)->verifierConnexion();

    expect($rapport->joignable)->toBeTrue()
        ->and($rapport->compte)->toBe('Anatole')
        ->and($rapport->solde)->toBe('3363.48')
        ->and($rapport->erreur)->toBeNull();
});

it('n\'expose jamais le jeton que la passerelle renvoie dans sa réponse', function (): void {
    Http::fake(['*/user' => Http::response([
        'first_name' => 'Anatole',
        'api_token' => 'jeton-de-test-secret',
        'sms_unit' => '3363.48',
    ], 200)]);

    Log::spy();

    $rapport = app(TechSoftProvider::class)->verifierConnexion();

    expect(json_encode($rapport))->not->toContain('jeton-de-test-secret');

    // Aucun log ne doit contenir la réponse brute : elle porte le jeton.
    Log::shouldNotHaveReceived('info', function (...$args): bool {
        return str_contains(json_encode($args), 'jeton-de-test-secret');
    });
});

it('rend un rapport en échec quand la configuration est incomplète, sans lever', function (): void {
    config()->set('services.techsoft.api_token', '');
    Http::fake();

    $rapport = app(TechSoftProvider::class)->verifierConnexion();

    expect($rapport->joignable)->toBeFalse()
        ->and($rapport->erreur)->toContain('TechSoft non configuré');

    Http::assertNothingSent();
});

it('rend un rapport en échec plutôt que de lever quand la passerelle refuse', function (): void {
    Http::fake(['*/user' => Http::response(['status' => 'error', 'code' => 401], 401)]);

    $rapport = app(TechSoftProvider::class)->verifierConnexion();

    expect($rapport->joignable)->toBeFalse()
        ->and($rapport->erreur)->toContain('Jeton API refusé')
        ->and($rapport->solde)->toBeNull();
});
```

- [ ] **Étape 2 : lancer, vérifier l'échec**

Run : `cd backend && ./vendor/bin/pest tests/Unit/Sms/TechSoftConnectiviteTest.php`
Attendu : FAIL — `Call to undefined method … ::verifierConnexion()`.

- [ ] **Étape 3 : créer l'interface et l'objet valeur**

`app/Services/Sms/ChecksConnectivity.php` :

```php
<?php

declare(strict_types=1);

namespace App\Services\Sms;

/**
 * Contrat optionnel : une passerelle capable de confirmer qu'on l'atteint.
 *
 * `verifierConnexion()` ne lève jamais : un diagnostic doit pouvoir dire
 * « injoignable » sans faire tomber la page qui l'affiche.
 */
interface ChecksConnectivity
{
    public function verifierConnexion(): ConnectivityReport;
}
```

`app/Services/Sms/ConnectivityReport.php` :

```php
<?php

declare(strict_types=1);

namespace App\Services\Sms;

/**
 * Résultat d'un contrôle de connexion à la passerelle.
 *
 * Ne contient DÉLIBÉRÉMENT aucun secret. TechSoft renvoie le jeton API en
 * clair dans `GET /user` : seuls le nom du compte et le solde sont extraits,
 * la réponse brute n'est ni conservée ni journalisée.
 */
final class ConnectivityReport
{
    public function __construct(
        public readonly bool $joignable,
        public readonly ?string $compte,
        public readonly ?string $solde,
        public readonly ?string $erreur,
    ) {}
}
```

- [ ] **Étape 4 : implémenter sur TechSoft**

Ajouter `ChecksConnectivity` aux interfaces de `TechSoftProvider` et la méthode :

```php
    public function verifierConnexion(): ConnectivityReport
    {
        // Tout est dans le try, y compris baseUrl() qui lève quand la
        // configuration est incomplète : un diagnostic doit pouvoir dire
        // « injoignable » sans faire tomber la page qui l'affiche.
        try {
            $response = $this->requete()->get($this->baseUrl().'/user');
        } catch (NotConfiguredException $e) {
            return new ConnectivityReport(false, null, null, $e->getMessage());
        } catch (\Throwable $e) {
            return new ConnectivityReport(false, null, null, 'Passerelle injoignable.');
        }

        if (! $response->successful()) {
            $code = (string) $response->status();

            return new ConnectivityReport(
                false, null, null,
                TechSoftCodes::libelle($code) ?? 'La passerelle a refusé la requête (code '.$code.').',
            );
        }

        // ATTENTION : la réponse contient `api_token` en clair. On n'extrait
        // que ces deux champs et on ne journalise jamais le corps complet.
        $prenom = $response->json('first_name');
        $nom = $response->json('last_name');
        $solde = $response->json('sms_unit');

        $compte = trim(
            (is_string($prenom) ? $prenom : '').' '.(is_string($nom) ? $nom : '')
        );

        return new ConnectivityReport(
            joignable: true,
            compte: $compte === '' ? null : $compte,
            solde: is_scalar($solde) ? (string) $solde : null,
            erreur: null,
        );
    }
```

- [ ] **Étape 5 : relancer, vérifier le vert**

Run : `cd backend && ./vendor/bin/pest tests/Unit/Sms/`
Attendu : tous verts.

- [ ] **Étape 6 : formater et committer**

```bash
cd backend && ./vendor/bin/pint app/Services/Sms/ tests/Unit/Sms/
git add backend/app/Services/Sms/ backend/tests/Unit/Sms/
git commit -m "feat(sms): controle de connexion et solde TechSoft"
```

---

### Tâche 5 : statut d'un message par identifiant

**Fichiers :**
- Créer : `app/Services/Sms/QueriesMessageStatus.php`, `app/Services/Sms/MessageStatus.php`
- Modifier : `app/Services/Sms/TechSoftProvider.php`
- Test : `tests/Unit/Sms/TechSoftStatutMessageTest.php`

**Interfaces :**
- Consomme : `TechSoftProvider` des tâches précédentes.
- Produit : `QueriesMessageStatus::statutMessage(string $uid): MessageStatus` ; `MessageStatus(string $brut, string $libelle, bool $livre, ?string $cout)`.

- [ ] **Étape 1 : écrire le test**

Créer `tests/Unit/Sms/TechSoftStatutMessageTest.php` :

```php
<?php

declare(strict_types=1);

use App\Services\Sms\TechSoftProvider;
use Illuminate\Support\Facades\Http;

uses()->group('sms', 'techsoft');

beforeEach(function (): void {
    config()->set('services.techsoft.base_url', 'https://app.techsoft-sms.com/api/v3');
    config()->set('services.techsoft.api_token', 'jeton-de-test-secret');
    config()->set('services.techsoft.sender_id', 'PSSFP');
});

it('traduit un message livré', function (): void {
    Http::fake(['*/sms/683831eda796e' => Http::response([
        'status' => 'success',
        'data' => ['uid' => '683831eda796e', 'status' => 'Delivered', 'cost' => '12'],
    ], 200)]);

    $statut = app(TechSoftProvider::class)->statutMessage('683831eda796e');

    expect($statut->brut)->toBe('Delivered')
        ->and($statut->libelle)->toBe('Livré')
        ->and($statut->livre)->toBeTrue()
        ->and($statut->cout)->toBe('12');
});

it('affiche un statut inconnu tel quel plutôt que de le masquer', function (): void {
    Http::fake(['*/sms/abc' => Http::response([
        'status' => 'success',
        'data' => ['uid' => 'abc', 'status' => 'Queued'],
    ], 200)]);

    $statut = app(TechSoftProvider::class)->statutMessage('abc');

    expect($statut->brut)->toBe('Queued')
        ->and($statut->libelle)->toBe('Queued')
        ->and($statut->livre)->toBeFalse();
});

it('lève quand la passerelle ne connaît pas l\'identifiant', function (): void {
    Http::fake(['*/sms/inconnu' => Http::response(['status' => 'error', 'code' => 404], 404)]);

    expect(fn () => app(TechSoftProvider::class)->statutMessage('inconnu'))
        ->toThrow(RuntimeException::class);
});
```

- [ ] **Étape 2 : lancer, vérifier l'échec**

Run : `cd backend && ./vendor/bin/pest tests/Unit/Sms/TechSoftStatutMessageTest.php`
Attendu : FAIL — `Call to undefined method … ::statutMessage()`.

- [ ] **Étape 3 : créer l'interface et l'objet valeur**

`app/Services/Sms/QueriesMessageStatus.php` :

```php
<?php

declare(strict_types=1);

namespace App\Services\Sms;

use RuntimeException;

/**
 * Contrat optionnel : une passerelle qui sait dire ce qu'est devenu un
 * message, à partir de l'identifiant qu'elle a rendu à l'envoi.
 */
interface QueriesMessageStatus
{
    /** @throws RuntimeException si la passerelle refuse ou ignore l'identifiant. */
    public function statutMessage(string $uid): MessageStatus;
}
```

`app/Services/Sms/MessageStatus.php` :

```php
<?php

declare(strict_types=1);

namespace App\Services\Sms;

/**
 * État d'un message chez la passerelle.
 *
 * `brut` est toujours conservé : la liste des statuts possibles n'est pas
 * documentée exhaustivement, et un statut inconnu doit s'afficher tel quel
 * plutôt que d'être écrasé par un libellé inventé.
 */
final class MessageStatus
{
    public function __construct(
        public readonly string $brut,
        public readonly string $libelle,
        public readonly bool $livre,
        public readonly ?string $cout,
    ) {}
}
```

- [ ] **Étape 4 : implémenter sur TechSoft**

Ajouter `QueriesMessageStatus` aux interfaces et la méthode :

```php
    /** @var array<string, string> Statuts observés en production. */
    private const STATUTS = [
        'delivered' => 'Livré',
        'success' => 'Envoyé',
        'failed' => 'Échec',
        'pending' => 'En attente',
    ];

    public function statutMessage(string $uid): MessageStatus
    {
        $response = $this->requete()->get($this->baseUrl().'/sms/'.urlencode($uid));

        $this->refuserSiErreur($response);

        $brut = $response->json('data.status');
        $brut = is_scalar($brut) ? (string) $brut : '';
        $cout = $response->json('data.cost');

        $normalise = mb_strtolower($brut);

        return new MessageStatus(
            brut: $brut,
            // Statut inconnu : on rend le brut, jamais un libellé inventé.
            libelle: self::STATUTS[$normalise] ?? ($brut === '' ? 'Inconnu' : $brut),
            livre: $normalise === 'delivered',
            cout: is_scalar($cout) ? (string) $cout : null,
        );
    }
```

- [ ] **Étape 5 : relancer, vérifier le vert**

Run : `cd backend && ./vendor/bin/pest tests/Unit/Sms/`
Attendu : tous verts.

- [ ] **Étape 6 : formater et committer**

```bash
cd backend && ./vendor/bin/pint app/Services/Sms/ tests/Unit/Sms/
git add backend/app/Services/Sms/ backend/tests/Unit/Sms/
git commit -m "feat(sms): statut de livraison d un message par identifiant"
```

---

### Tâche 6 : bloc « Envois » et envois de test dans Paramètres

**Fichiers :**
- Créer : `app/Services/EnvoiTestService.php`, `app/Mail/EnvoiTestMail.php`, `resources/views/emails/envoi-test.blade.php`
- Modifier : `app/Filament/Pages/Parametres.php`
- Test : `tests/Feature/Admin/DiagnosticEnvoisTest.php`

**Interfaces :**
- Consomme : `DescribesConfiguration::decrire()`, `ChecksConnectivity::verifierConnexion()`, `SmsServiceInterface`, `NotificationCandidatsService::smsDesservi()`.
- Produit : `EnvoiTestService::envoyerSms(string $destinataire, User $auteur): SmsSendResult` et `EnvoiTestService::envoyerEmail(string $destinataire, User $auteur): void`, tous deux levant `RuntimeException` en cas de refus.

- [ ] **Étape 1 : écrire les tests**

Créer `tests/Feature/Admin/DiagnosticEnvoisTest.php` :

```php
<?php

declare(strict_types=1);

use App\Mail\EnvoiTestMail;
use App\Models\User;
use App\Services\EnvoiTestService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Spatie\Activitylog\Models\Activity;

uses()->group('admin', 'envois');

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    config()->set('services.sms.provider', 'techsoft');
    config()->set('services.techsoft.base_url', 'https://app.techsoft-sms.com/api/v3');
    config()->set('services.techsoft.api_token', 'jeton-de-test-secret');
    config()->set('services.techsoft.sender_id', 'PSSFP');
});

function auteurDiagnostic(): User
{
    $user = User::factory()->create();
    $user->assignRole('super_admin');

    return $user;
}

it('normalise un numéro local camerounais avant d\'appeler la passerelle', function (): void {
    Http::fake(['*/sms/send' => Http::response([
        'status' => 'success',
        'data' => [['uid' => 'u1', 'from' => 'PSSFP', 'status' => 'Delivered', 'cost' => '12']],
    ], 200)]);

    app(EnvoiTestService::class)->envoyerSms('691234567', auteurDiagnostic());

    Http::assertSent(fn ($request): bool => $request['recipient'] === '+237691234567');
});

it('refuse un numéro hors indicatifs desservis sans appeler la passerelle', function (): void {
    Http::fake();

    expect(fn () => app(EnvoiTestService::class)->envoyerSms('+15551234567', auteurDiagnostic()))
        ->toThrow(RuntimeException::class);

    Http::assertNothingSent();
});

it('remonte intact le message d\'erreur de la passerelle', function (): void {
    Http::fake(['*/sms/send' => Http::response([
        'status' => 'error', 'code' => 104,
    ], 403)]);

    expect(fn () => app(EnvoiTestService::class)->envoyerSms('691234567', auteurDiagnostic()))
        ->toThrow(RuntimeException::class, 'Solde SMS insuffisant');
});

it('envoie un e-mail de test', function (): void {
    Mail::fake();

    app(EnvoiTestService::class)->envoyerEmail('destinataire@example.test', auteurDiagnostic());

    Mail::assertSent(EnvoiTestMail::class);
});

it('trace chaque test dans l\'Activity Log avec le numéro masqué', function (): void {
    Http::fake(['*/sms/send' => Http::response([
        'status' => 'success',
        'data' => [['uid' => 'u1', 'from' => 'PSSFP', 'status' => 'Delivered']],
    ], 200)]);

    $auteur = auteurDiagnostic();
    app(EnvoiTestService::class)->envoyerSms('691234567', $auteur);

    $log = Activity::where('event', 'envoi_test')->latest()->first();

    expect($log)->not->toBeNull()
        ->and($log->causer_id)->toBe($auteur->id)
        ->and(json_encode($log->properties))->not->toContain('691234567')
        ->and(json_encode($log->properties))->not->toContain('jeton-de-test-secret');
});

it('bloque le sixième envoi de test dans la minute', function (): void {
    Http::fake(['*/sms/send' => Http::response([
        'status' => 'success',
        'data' => [['uid' => 'u1', 'from' => 'PSSFP', 'status' => 'Delivered']],
    ], 200)]);

    $auteur = auteurDiagnostic();
    $service = app(EnvoiTestService::class);

    for ($i = 0; $i < 5; $i++) {
        $service->envoyerSms('691234567', $auteur);
    }

    expect(fn () => $service->envoyerSms('691234567', $auteur))
        ->toThrow(RuntimeException::class, 'Trop d\'envois de test');
});
```

- [ ] **Étape 2 : lancer, vérifier l'échec**

Run : `cd backend && ./vendor/bin/pest tests/Feature/Admin/DiagnosticEnvoisTest.php`
Attendu : FAIL — `Class "App\Services\EnvoiTestService" not found`.

- [ ] **Étape 3 : créer l'e-mail de test**

`app/Mail/EnvoiTestMail.php` — sur le modèle exact de `app/Mail/NotificationCandidatMail.php` (mêmes conventions `Envelope` / `Content`), avec le sujet `[Test] Vérification de la configuration e-mail — PSSFP` et la vue `emails.envoi-test`.

`resources/views/emails/envoi-test.blade.php` :

```blade
<p>Bonjour,</p>

<p>
    Cet e-mail confirme que la configuration d'envoi du site PSSFP fonctionne.
    Il a été déclenché manuellement depuis l'administration à des fins de test.
</p>

<p>Aucune action n'est attendue de votre part.</p>

<p>— PSSFP</p>
```

- [ ] **Étape 4 : créer le service**

`app/Services/EnvoiTestService.php` :

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\EnvoiTestMail;
use App\Models\User;
use App\Services\Sms\ReportsSmsDelivery;
use App\Services\Sms\SmsSendResult;
use App\Services\Sms\SmsServiceInterface;
use App\Support\PhoneMasker;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;

/**
 * Envois de vérification déclenchés depuis l'administration.
 *
 * Volontairement distinct de NotificationCandidatsService : un test ne vise
 * aucun candidat, n'écrit pas au journal des envois — qui sert de preuve —
 * et ne doit jamais emprunter un gabarit de notification candidat.
 */
final class EnvoiTestService
{
    /** Envois de test autorisés par utilisateur et par minute, tous canaux confondus. */
    private const QUOTA_PAR_MINUTE = 5;

    public function __construct(
        private readonly SmsServiceInterface $sms,
        private readonly NotificationCandidatsService $notifications,
    ) {}

    public function envoyerSms(string $destinataire, User $auteur): SmsSendResult
    {
        $this->consommerQuota($auteur);

        $numero = $this->normaliser($destinataire);

        if (! $this->notifications->smsDesservi($numero)) {
            throw new RuntimeException(
                'Ce numéro n\'est pas dans les indicatifs desservis — envoi refusé avant appel à la passerelle.'
            );
        }

        $message = 'PSSFP : test de configuration SMS. Aucune action requise.';

        $resultat = $this->sms instanceof ReportsSmsDelivery
            ? $this->sms->sendAndReport($numero, $message)
            : (function () use ($numero, $message): SmsSendResult {
                $this->sms->send($numero, $message);

                return new SmsSendResult(expediteur: null, codeFournisseur: null);
            })();

        $this->tracer($auteur, 'sms', PhoneMasker::mask($numero), 'succes');

        return $resultat;
    }

    public function envoyerEmail(string $destinataire, User $auteur): void
    {
        $this->consommerQuota($auteur);

        Mail::to($destinataire)->send(new EnvoiTestMail);

        $this->tracer($auteur, 'email', $destinataire, 'succes');
    }

    /**
     * Normalise un numéro local camerounais en E.164.
     *
     * Un numéro déjà en E.164 passe tel quel ; neuf chiffres commençant par 6
     * sont préfixés +237. Tout le reste est rendu inchangé et sera refusé par
     * le contrôle des indicatifs desservis.
     */
    private function normaliser(string $saisie): string
    {
        $saisie = preg_replace('/\s+/', '', trim($saisie)) ?? '';

        if (preg_match('/^\+[1-9]\d{6,14}$/', $saisie) === 1) {
            return $saisie;
        }

        if (preg_match('/^\d{9}$/', $saisie) === 1) {
            return '+237'.$saisie;
        }

        return $saisie;
    }

    private function consommerQuota(User $auteur): void
    {
        $cle = 'envoi-test:'.$auteur->id;

        if (RateLimiter::tooManyAttempts($cle, self::QUOTA_PAR_MINUTE)) {
            throw new RuntimeException(
                'Trop d\'envois de test — patientez une minute avant de réessayer.'
            );
        }

        RateLimiter::hit($cle, 60);
    }

    private function tracer(User $auteur, string $canal, string $destinataire, string $issue): void
    {
        activity('envois')
            ->causedBy($auteur)
            ->withProperties([
                'canal' => $canal,
                // Masqué : cette trace est consultable par tout administrateur.
                'destinataire' => $destinataire,
                'issue' => $issue,
            ])
            ->event('envoi_test')
            ->log('Envoi de test déclenché depuis l\'administration');
    }
}
```

- [ ] **Étape 5 : relancer, vérifier le vert**

Run : `cd backend && ./vendor/bin/pest tests/Feature/Admin/DiagnosticEnvoisTest.php`
Attendu : 6 tests PASS.

- [ ] **Étape 6 : ajouter la section à la page Paramètres**

Dans `app/Filament/Pages/Parametres.php`, ajouter au `->schema([...])`, après la section existante :

```php
                Section::make('Envois')
                    ->description('Configuration des passerelles SMS et e-mail. Ces valeurs vivent dans le fichier .env du serveur et ne sont pas modifiables ici.')
                    ->schema([
                        Placeholder::make('sms_fournisseur')
                            ->label('Fournisseur SMS')
                            ->content(fn (): string => $this->descriptionSms()?->libelle ?? 'Fournisseur non documenté'),
                        Placeholder::make('sms_expediteur')
                            ->label('Expéditeur présenté')
                            ->content(fn (): string => $this->descriptionSms()?->expediteur ?? 'Non configuré'),
                        Placeholder::make('sms_jeton')
                            ->label('Jeton API')
                            ->content(fn (): string => ($this->descriptionSms()?->jetonConfigure ?? false)
                                ? 'Configuré'
                                : 'Absent'),
                        Placeholder::make('sms_simulation')
                            ->label('Attention')
                            ->content('Mode simulation : aucun SMS ne part réellement.')
                            ->visible(fn (): bool => ($this->descriptionSms()?->envoiReel ?? true) === false),
                        Placeholder::make('mail_transport')
                            ->label('Transport e-mail')
                            ->content(fn (): string => (string) config('mail.default')),
                        Placeholder::make('mail_expediteur')
                            ->label('Expéditeur e-mail')
                            ->content(fn (): string => (string) config('mail.from.address')
                                .' ('.(string) config('mail.from.name').')'),
                        TextInput::make('test_telephone')
                            ->label('Numéro pour un SMS de test')
                            ->helperText('Format international (+237…) ou numéro local à 9 chiffres.')
                            ->tel()
                            ->dehydrated(false),
                        TextInput::make('test_email')
                            ->label('Adresse pour un e-mail de test')
                            ->email()
                            ->dehydrated(false),
                    ]),
```

avec les imports `Filament\Forms\Components\Placeholder`, `Filament\Forms\Components\TextInput`, `App\Services\Sms\ChecksConnectivity`, `App\Services\Sms\DescribesConfiguration`, `App\Services\Sms\SmsConfigurationSummary`, `App\Services\Sms\SmsServiceInterface`, `App\Services\EnvoiTestService`, et cette méthode privée sur la page :

```php
    /**
     * Description de la passerelle active, ou null si elle ne sait pas se
     * décrire. La page doit rester affichable dans ce cas.
     */
    private function descriptionSms(): ?SmsConfigurationSummary
    {
        $passerelle = app(SmsServiceInterface::class);

        return $passerelle instanceof DescribesConfiguration ? $passerelle->decrire() : null;
    }
```

Puis trois actions dans `getHeaderActions()` :

```php
            Action::make('verifier_connexion')
                ->label('Vérifier la connexion')
                ->visible(fn (): bool => app(SmsServiceInterface::class) instanceof ChecksConnectivity
                    && (bool) auth()->user()?->can('candidature.notify'))
                ->action(function (): void {
                    $rapport = app(SmsServiceInterface::class)->verifierConnexion();

                    if (! $rapport->joignable) {
                        Notification::make()->danger()
                            ->title('Passerelle injoignable')
                            ->body($rapport->erreur ?? 'Motif inconnu.')
                            ->send();

                        return;
                    }

                    Notification::make()->success()
                        ->title('Passerelle joignable')
                        ->body('Compte : '.($rapport->compte ?? 'inconnu')
                            .' — solde : '.($rapport->solde ?? 'inconnu'))
                        ->send();
                }),

            Action::make('tester_sms')
                ->label('Envoyer un SMS de test')
                ->visible(fn (): bool => (bool) auth()->user()?->can('candidature.notify'))
                ->action(function (): void {
                    $destinataire = (string) ($this->form->getRawState()['test_telephone'] ?? '');

                    if (trim($destinataire) === '') {
                        Notification::make()->warning()
                            ->title('Renseignez un numéro')->send();

                        return;
                    }

                    try {
                        $resultat = app(EnvoiTestService::class)
                            ->envoyerSms($destinataire, auth()->user());
                    } catch (\RuntimeException $e) {
                        // Message de la passerelle affiché tel quel : c'est
                        // toute l'utilité d'un bouton de test.
                        Notification::make()->danger()
                            ->title('Envoi refusé')->body($e->getMessage())->send();

                        return;
                    }

                    Notification::make()->success()
                        ->title('SMS de test envoyé')
                        ->body('Expéditeur : '.($resultat->expediteur ?? 'inconnu')
                            .($resultat->cout === null ? '' : ' — coût : '.$resultat->cout))
                        ->send();
                }),

            Action::make('tester_email')
                ->label('Envoyer un e-mail de test')
                ->visible(fn (): bool => (bool) auth()->user()?->can('candidature.notify'))
                ->action(function (): void {
                    $destinataire = (string) ($this->form->getRawState()['test_email'] ?? '');

                    if (trim($destinataire) === '') {
                        Notification::make()->warning()
                            ->title('Renseignez une adresse')->send();

                        return;
                    }

                    try {
                        app(EnvoiTestService::class)->envoyerEmail($destinataire, auth()->user());
                    } catch (\Throwable $e) {
                        Notification::make()->danger()
                            ->title('Envoi refusé')->body($e->getMessage())->send();

                        return;
                    }

                    Notification::make()->success()
                        ->title('E-mail de test envoyé')->send();
                }),
```

- [ ] **Étape 7 : écrire le test de permission**

Ajouter à `tests/Feature/Admin/DiagnosticEnvoisTest.php` :

```php
it('cache les actions d\'envoi à qui n\'a pas candidature.notify', function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    $user = User::factory()->create();
    $user->givePermissionTo('settings.manage');
    $this->actingAs($user);

    $this->livewire(\App\Filament\Pages\Parametres::class)
        ->assertActionHidden('tester_sms')
        ->assertActionHidden('tester_email');
});
```

- [ ] **Étape 8 : vérifier l'ensemble**

Run : `cd backend && ./vendor/bin/pest tests/Feature/Admin/ && ./vendor/bin/pest`
Attendu : tout vert.

- [ ] **Étape 9 : formater et committer**

```bash
cd backend && ./vendor/bin/pint app/ tests/ resources/views/emails/
git add backend/
git commit -m "feat(admin): bloc Envois et envois de test dans les parametres"
```

---

### Tâche 7 : livraison visible dans le journal des envois

**Fichiers :**
- Modifier : `app/Filament/Resources/CandidatureRelanceResource.php`
- Test : `tests/Feature/Admin/JournalLivraisonTest.php`

**Interfaces :**
- Consomme : `QueriesMessageStatus::statutMessage()`, colonnes `message_uid` / `statut_livraison` / `cout` de la tâche 2, `EchoSmsCodes` et `TechSoftCodes` des tâches 1 et 3.
- Produit : rien pour la suite — tâche terminale.

- [ ] **Étape 1 : écrire le test**

Créer `tests/Feature/Admin/JournalLivraisonTest.php` :

```php
<?php

declare(strict_types=1);

use App\Filament\Resources\CandidatureRelanceResource\Pages\ListCandidatureRelances;
use App\Models\CandidatureRelance;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Http;

uses()->group('admin', 'envois');

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    config()->set('pssfp.filament.require_2fa', false);
    config()->set('services.sms.provider', 'techsoft');
    config()->set('services.techsoft.base_url', 'https://app.techsoft-sms.com/api/v3');
    config()->set('services.techsoft.api_token', 'jeton-de-test-secret');
    config()->set('services.techsoft.sender_id', 'PSSFP');

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    $this->actingAs($admin);
});

it('masque l\'actualisation sur une ligne sans identifiant de message', function (): void {
    $ligne = relanceJournalisee(['message_uid' => null]);

    $this->livewire(ListCandidatureRelances::class)
        ->assertTableActionHidden('actualiser_statut', $ligne);
});

it('actualise le statut de livraison depuis la passerelle', function (): void {
    Http::fake(['*/sms/u-123' => Http::response([
        'status' => 'success',
        'data' => ['uid' => 'u-123', 'status' => 'Delivered', 'cost' => '12'],
    ], 200)]);

    $ligne = relanceJournalisee(['message_uid' => 'u-123', 'statut_livraison' => 'Success']);

    $this->livewire(ListCandidatureRelances::class)
        ->callTableAction('actualiser_statut', $ligne);

    expect($ligne->refresh()->statut_livraison)->toBe('Delivered');
});
```

> Écrire le helper `relanceJournalisee(array $attributs)` dans ce fichier : il crée une candidature via sa factory puis une `CandidatureRelance` de canal `sms` et de statut `envoye`, en fusionnant `$attributs`. Reprendre les factories réellement disponibles.

- [ ] **Étape 2 : lancer, vérifier l'échec**

Run : `cd backend && ./vendor/bin/pest tests/Feature/Admin/JournalLivraisonTest.php`
Attendu : FAIL — action `actualiser_statut` inexistante.

- [ ] **Étape 3 : ajouter colonne et action**

Dans `app/Filament/Resources/CandidatureRelanceResource.php` :

Colonne, après celle du statut :

```php
                Tables\Columns\TextColumn::make('statut_livraison')
                    ->label('Livraison')
                    ->badge()
                    ->color(fn (?string $state): string => match (mb_strtolower((string) $state)) {
                        'delivered' => 'success',
                        'failed' => 'danger',
                        '' => 'gray',
                        default => 'warning',
                    })
                    ->formatStateUsing(fn (?string $state): string => match (mb_strtolower((string) $state)) {
                        'delivered' => 'Livré',
                        'success' => 'Envoyé',
                        'failed' => 'Échec',
                        '' => 'Inconnu',
                        // Statut non documenté : affiché tel quel.
                        default => (string) $state,
                    })
                    ->description(fn (CandidatureRelance $record): ?string => $record->cout === null
                        ? null
                        : 'coût '.$record->cout),
```

Action de ligne :

```php
                    Tables\Actions\Action::make('actualiser_statut')
                        ->label('Actualiser le statut')
                        ->icon('heroicon-o-arrow-path')
                        // Masquée sans identifiant : tout l'historique antérieur
                        // à la bascule TechSoft est dans ce cas.
                        ->visible(fn (CandidatureRelance $record): bool => $record->message_uid !== null
                            && app(SmsServiceInterface::class) instanceof QueriesMessageStatus)
                        ->action(function (CandidatureRelance $record): void {
                            try {
                                $statut = app(SmsServiceInterface::class)->statutMessage($record->message_uid);
                            } catch (\RuntimeException $e) {
                                Notification::make()->danger()
                                    ->title('Statut indisponible')->body($e->getMessage())->send();

                                return;
                            }

                            $record->update([
                                'statut_livraison' => $statut->brut,
                                'cout' => $statut->cout ?? $record->cout,
                            ]);

                            Notification::make()->success()
                                ->title('Statut actualisé')->body($statut->libelle)->send();
                        }),
```

Et remplacer l'affichage brut du code fournisseur (ligne ~140, `'code '.$record->code_fournisseur`) par le libellé :

```php
                    ->description(function (CandidatureRelance $record): ?string {
                        if ($record->code_fournisseur === null) {
                            return null;
                        }

                        $libelle = EchoSmsCodes::libelle($record->code_fournisseur)
                            ?? TechSoftCodes::libelle($record->code_fournisseur);

                        return ($libelle ?? 'code').' ('.$record->code_fournisseur.')';
                    })
```

Ajouter les imports nécessaires.

- [ ] **Étape 4 : relancer, vérifier le vert**

Run : `cd backend && ./vendor/bin/pest tests/Feature/Admin/JournalLivraisonTest.php`
Attendu : 2 tests PASS.

- [ ] **Étape 5 : vérification complète**

```bash
cd backend && ./vendor/bin/pint --test app/ tests/ && ./vendor/bin/pest
```

Attendu : Pint `passed`, suite Pest entièrement verte.

- [ ] **Étape 6 : revue par sous-agents**

Lancer en parallèle `security-reviewer` (fuite du jeton, garde des permissions, limitation de cadence) et `filament-reviewer` (conformité des Resources et Pages, Activity Log) sur le diff complet de la branche. Traiter tout point CRITICAL ou HIGH avant la PR.

- [ ] **Étape 7 : committer et pousser**

```bash
cd backend && ./vendor/bin/pint app/ tests/
git add backend/
git commit -m "feat(admin): statut de livraison consultable dans le journal des envois"
git push -u origin feat/m6-techsoft-diagnostic-envois
```

---

## Déploiement — à faire par un humain, pas par ce plan

La bascule ne peut pas être déployée tant que ces trois points ne sont pas réglés :

1. **Régénérer le jeton TechSoft.** L'actuel a été divulgué dans les exemples de la documentation authentifiée et le compte est partagé avec un autre projet.
2. **Déclarer le Sender ID `PSSFP` dans l'interface TechSoft.** L'API n'expose aucun moyen de le vérifier : un expéditeur non déclaré ne se découvrira qu'au premier envoi raté. C'est exactement ce qui vient de coûter le masking Echo SMS.
3. **Renseigner `TECHSOFT_BASE_URL`, `TECHSOFT_API_TOKEN`, `TECHSOFT_SENDER_ID` et `SMS_PROVIDER=techsoft` dans le `.env` de production** — ces variables n'y existent pas aujourd'hui, la clé n'est que dans le `.env` local.

Séquence, une fois ces points faits : `git pull`, `composer install --no-dev --optimize-autoloader`, `php artisan migrate --force`, `php artisan optimize`, `systemctl restart pssfp-queue.service`. Puis, depuis l'admin, « Vérifier la connexion » et un SMS de test vers un numéro maîtrisé **avant** toute relance groupée.

## Ce que ce plan ne fait pas

- **Aucune réconciliation automatique** : l'actualisation du statut reste déclenchée à la main. Le `message_uid` étant stocké, ajouter une tâche planifiée plus tard ne demandera aucune refonte.
- **Aucun rattrapage de l'historique** : les lignes sans `message_uid` gardent un statut de livraison inconnu, y compris le cas P14026-101.
- **Les SMS de création de compte et les OTP restent non journalisés** (cf. P14026-109).
- **`EchoSmsProvider` reste en place**, inactif, pour permettre un retour arrière par simple changement de `SMS_PROVIDER`.
