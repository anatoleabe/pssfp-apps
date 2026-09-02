<?php

declare(strict_types=1);

use App\Exceptions\NotConfiguredException;
use App\Services\Sms\GatewayApiProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses()->group('sms', 'gateway');

beforeEach(function (): void {
    config()->set('services.sms_gateway.base_url', 'https://sms.exemple.test/api');
    config()->set('services.sms_gateway.token', 'jeton-de-test');
    config()->set('services.sms_gateway.from_type', 'sender_id');
    config()->set('services.sms_gateway.sender_id', '42');
    config()->set('services.sms_gateway.from_number', null);
});

it('appelle /sent/compose avec le jeton et la charge attendue', function (): void {
    Http::fake([
        '*/sent/compose' => Http::response(['status' => 'success', 'message' => 'Message sent successfully']),
    ]);

    app(GatewayApiProvider::class)->send('+237691234567', 'Bonjour');

    Http::assertSent(function (Request $request): bool {
        $corps = (string) $request->body();

        return $request->url() === 'https://sms.exemple.test/api/sent/compose'
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer jeton-de-test')
            && $request->hasHeader('Accept', 'application/json')
            && str_contains($corps, 'from_type')
            && str_contains($corps, 'sender_id')
            && str_contains($corps, '+237691234567')
            && str_contains($corps, 'Bonjour');
    });
});

it('transmet le destinataire au format JSON attendu par la passerelle', function (): void {
    Http::fake(['*' => Http::response(['status' => 'success'])]);

    app(GatewayApiProvider::class)->send('+237699887766', 'Test');

    Http::assertSent(fn (Request $r): bool => str_contains(
        (string) $r->body(),
        '{"value":"+237699887766"}',
    ));
});

it('échoue si la passerelle répond un statut non success malgré un HTTP 200', function (): void {
    // La passerelle renvoie 200 sur certains refus métier : le code HTTP seul
    // ne prouve pas l'envoi, c'est le champ `status` qui fait foi.
    Http::fake(['*' => Http::response(['status' => 'error', 'message' => 'Solde insuffisant'])]);

    expect(fn () => app(GatewayApiProvider::class)->send('+237691234567', 'Test'))
        ->toThrow(RuntimeException::class, 'Solde insuffisant');
});

it('échoue sur une erreur HTTP', function (): void {
    Http::fake(['*' => Http::response(['message' => 'Unauthenticated'], 401)]);

    expect(fn () => app(GatewayApiProvider::class)->send('+237691234567', 'Test'))
        ->toThrow(RuntimeException::class, 'HTTP 401');
});

it('refuse de partir si la passerelle n’est pas configurée', function (): void {
    config()->set('services.sms_gateway.base_url', '');
    Http::fake();

    expect(fn () => app(GatewayApiProvider::class)->send('+237691234567', 'Test'))
        ->toThrow(NotConfiguredException::class);

    Http::assertNothingSent();
});

it('exige un sender_id quand from_type vaut sender_id', function (): void {
    config()->set('services.sms_gateway.sender_id', '');
    Http::fake();

    expect(fn () => app(GatewayApiProvider::class)->send('+237691234567', 'Test'))
        ->toThrow(NotConfiguredException::class);

    Http::assertNothingSent();
});

it('bascule sur from_number quand from_type vaut phone_number', function (): void {
    config()->set('services.sms_gateway.from_type', 'phone_number');
    config()->set('services.sms_gateway.from_number', '+237600000000');
    Http::fake(['*' => Http::response(['status' => 'success'])]);

    app(GatewayApiProvider::class)->send('+237691234567', 'Test');

    Http::assertSent(function (Request $r): bool {
        $corps = (string) $r->body();

        return str_contains($corps, 'from_number')
            && str_contains($corps, '+237600000000')
            && ! str_contains($corps, 'name="sender_id"');
    });
});

it('tolère une URL de base avec slash final', function (): void {
    config()->set('services.sms_gateway.base_url', 'https://sms.exemple.test/api/');
    Http::fake(['*' => Http::response(['status' => 'success'])]);

    app(GatewayApiProvider::class)->send('+237691234567', 'Test');

    Http::assertSent(fn (Request $r): bool => $r->url() === 'https://sms.exemple.test/api/sent/compose');
});
