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

    $rapport = app(TechSoftProvider::class)->verifierConnexion();

    expect(json_encode($rapport))->not->toContain('jeton-de-test-secret');
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
