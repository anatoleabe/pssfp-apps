<?php

declare(strict_types=1);

use App\Exceptions\NotConfiguredException;
use App\Services\Sms\EchoSmsProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses()->group('sms', 'echosms');

beforeEach(function (): void {
    config()->set('services.echosms.base_url', 'https://account.echosms.io/api');
    config()->set('services.echosms.api_key', '9|cle-de-test');
    config()->set('services.echosms.from_type', 'sender_id');
    config()->set('services.echosms.sender_id', 'PSSFP');
    config()->set('services.echosms.from_number', null);
});

it('authentifie par api_key en query et non par Bearer', function (): void {
    // Contrat vérifié en production : un Bearer sur /sent/compose répond 1003.
    Http::fake(['*' => Http::response(['response' => '1016'])]);

    app(EchoSmsProvider::class)->send('+237691234567', 'Bonjour');

    Http::assertSent(function (Request $request): bool {
        return str_starts_with($request->url(), 'https://account.echosms.io/api/sent/compose')
            && str_contains($request->url(), 'api_key=9%7Ccle-de-test')
            && ! $request->hasHeader('Authorization');
    });
});

it('envoie les champs attendus en formulaire', function (): void {
    Http::fake(['*' => Http::response(['response' => '1016'])]);

    app(EchoSmsProvider::class)->send('+237691234567', 'Bonjour');

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return $data['from_type'] === 'sender_id'
            && $data['sender_id'] === 'PSSFP'
            && $data['to_numbers'] === '+237691234567'
            && $data['body'] === 'Bonjour';
    });
});

it('accepte le code 1016 comme succès', function (): void {
    Http::fake(['*' => Http::response(['response' => '1016'])]);

    app(EchoSmsProvider::class)->send('+237691234567', 'Test');
})->throwsNoExceptions();

it('accepte le code 1015 (envoi partiel) sans lever', function (): void {
    Http::fake(['*' => Http::response(['response' => '1015'])]);

    app(EchoSmsProvider::class)->send('+237691234567', 'Test');
})->throwsNoExceptions();

it('traduit un solde insuffisant en message lisible', function (): void {
    Http::fake(['*' => Http::response(['response' => '1007'])]);

    expect(fn () => app(EchoSmsProvider::class)->send('+237691234567', 'Test'))
        ->toThrow(RuntimeException::class, 'Solde insuffisant');
});

it('traduit un sender id invalide', function (): void {
    Http::fake(['*' => Http::response(['response' => '1002'])]);

    expect(fn () => app(EchoSmsProvider::class)->send('+237691234567', 'Test'))
        ->toThrow(RuntimeException::class, 'Sender ID / masking invalide');
});

it('échoue sur un refus métier malgré un HTTP 200', function (): void {
    // Echo SMS répond 200 sur ses refus : seul le code applicatif fait foi.
    Http::fake(['*' => Http::response(['response' => '1010'], 200)]);

    expect(fn () => app(EchoSmsProvider::class)->send('+237691234567', 'Test'))
        ->toThrow(RuntimeException::class, 'Numéro invalide');
});

it('remonte une erreur de validation Laravel de la passerelle', function (): void {
    Http::fake(['*' => Http::response(['message' => ['body' => ['The body field is required.']]])]);

    expect(fn () => app(EchoSmsProvider::class)->send('+237691234567', ''))
        ->toThrow(RuntimeException::class, 'refusé la requête');
});

it('n’expose jamais la clé API dans le message d’erreur', function (): void {
    Http::fake(['*' => Http::response(['response' => '1007'])]);

    try {
        app(EchoSmsProvider::class)->send('+237691234567', 'Test');
        expect(false)->toBeTrue('une exception était attendue');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->not->toContain('cle-de-test')
            ->and($e->getMessage())->not->toContain('api_key');
    }
});

it('échoue sur une erreur HTTP', function (): void {
    Http::fake(['*' => Http::response(['message' => 'Unauthenticated.'], 401)]);

    expect(fn () => app(EchoSmsProvider::class)->send('+237691234567', 'Test'))
        ->toThrow(RuntimeException::class, 'HTTP 401');
});

it('refuse de partir si la clé n’est pas configurée', function (): void {
    config()->set('services.echosms.api_key', '');
    Http::fake();

    expect(fn () => app(EchoSmsProvider::class)->send('+237691234567', 'Test'))
        ->toThrow(NotConfiguredException::class);

    Http::assertNothingSent();
});

it('exige un sender_id quand from_type vaut sender_id', function (): void {
    config()->set('services.echosms.sender_id', '');
    Http::fake();

    expect(fn () => app(EchoSmsProvider::class)->send('+237691234567', 'Test'))
        ->toThrow(NotConfiguredException::class);

    Http::assertNothingSent();
});

it('bascule sur from_number quand from_type vaut phone_number', function (): void {
    config()->set('services.echosms.from_type', 'phone_number');
    config()->set('services.echosms.from_number', '+237600000000');
    Http::fake(['*' => Http::response(['response' => '1016'])]);

    app(EchoSmsProvider::class)->send('+237691234567', 'Test');

    Http::assertSent(function (Request $r): bool {
        $data = $r->data();

        return ($data['from_number'] ?? null) === '+237600000000'
            && ! array_key_exists('sender_id', $data);
    });
});

it('n’envoie qu’un destinataire à la fois', function (): void {
    // Un lot rendrait un refus partiel inattribuable, et la traçabilité par
    // candidat impossible.
    Http::fake(['*' => Http::response(['response' => '1016'])]);

    app(EchoSmsProvider::class)->send('+237691234567', 'Test');

    Http::assertSent(fn (Request $r): bool => ! str_contains((string) ($r->data()['to_numbers'] ?? ''), ','));
});
