<?php

declare(strict_types=1);

use App\Exceptions\NotConfiguredException;
use App\Services\Sms\TechSoftCodes;
use App\Services\Sms\TechSoftProvider;
use Illuminate\Support\Facades\Http;

uses()->group('sms', 'techsoft');

function configurerTechSoft(): void
{
    config()->set('services.techsoft.base_url', 'https://app.techsoft-sms.com/api/v3');
    config()->set('services.techsoft.api_token', 'jeton-de-test-secret');
    config()->set('services.techsoft.sender_id', 'PSSFP');
}

it('traduit les codes TechSoft documentés', function (): void {
    expect(TechSoftCodes::libelle('104'))->toBe('Solde SMS insuffisant')
        ->and(TechSoftCodes::libelle('109'))->toBe('Le jeton API n\'a pas la permission demandée')
        ->and(TechSoftCodes::libelle('113'))->toBe('Paramètre de requête invalide');
});

it('rend null pour un code inconnu plutôt que de planter', function (): void {
    expect(TechSoftCodes::libelle('999'))->toBeNull()
        ->and(TechSoftCodes::libelle(null))->toBeNull();
});

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

it('refuse un Sender ID de plus de 11 caractères que TechSoft tronquerait', function (): void {
    configurerTechSoft();
    config()->set('services.techsoft.sender_id', 'PSSFP-CAMEROUN-2026');
    Http::fake();

    expect(fn () => app(TechSoftProvider::class)->sendAndReport('+237691234567', 'Bonjour'))
        ->toThrow(NotConfiguredException::class, '11 caractères');

    Http::assertNothingSent();
});

// Constaté en production : TechSoft stocke « créé » mais livre « cr¿¿ » quand
// le type déclaré est `plain`.
it('déclare le type unicode dès que le message porte un accent', function (): void {
    configurerTechSoft();
    Http::fake(['*/sms/send' => Http::response([
        'status' => 'success',
        'data' => [['uid' => 'u1', 'from' => 'PSSFP', 'status' => 'Delivered']],
    ], 200)]);

    app(TechSoftProvider::class)->sendAndReport('+237691234567', 'Votre compte est créé, complétez vos pièces.');

    Http::assertSent(fn ($request): bool => $request['type'] === 'unicode');
});

it('reste en plain pour un message strictement ASCII', function (): void {
    configurerTechSoft();
    Http::fake(['*/sms/send' => Http::response([
        'status' => 'success',
        'data' => [['uid' => 'u1', 'from' => 'PSSFP', 'status' => 'Delivered']],
    ], 200)]);

    app(TechSoftProvider::class)->sendAndReport('+237691234567', 'PSSFP : test de configuration SMS.');

    Http::assertSent(fn ($request): bool => $request['type'] === 'plain');
});
