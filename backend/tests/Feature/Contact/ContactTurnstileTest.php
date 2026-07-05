<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

uses()->group('contact', 'turnstile');

function validContactPayload(array $overrides = []): array
{
    return array_merge([
        'nom' => 'Jean Test',
        'email' => 'jean@example.com',
        'message' => 'Bonjour, je souhaite des informations sur la formation continue.',
        'cgu' => true,
    ], $overrides);
}

it('rejects the contact message when Cloudflare refuses the token', function (): void {
    config()->set('services.turnstile.secret', 'test-secret');
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => false])]);
    Mail::fake();

    $this->postJson('/v1/contact', validContactPayload(['cf_turnstile_response' => 'bad']))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['cf_turnstile_response']);

    Mail::assertNothingSent();
});

it('accepts the contact message when Cloudflare validates the token', function (): void {
    config()->set('services.turnstile.secret', 'test-secret');
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true])]);
    Mail::fake();

    $this->postJson('/v1/contact', validContactPayload(['cf_turnstile_response' => 'good']))
        ->assertStatus(201);
});

it('rejects a missing token when a secret is configured', function (): void {
    config()->set('services.turnstile.secret', 'test-secret');
    Mail::fake();

    $this->postJson('/v1/contact', validContactPayload())
        ->assertStatus(422)
        ->assertJsonValidationErrors(['cf_turnstile_response']);
});

it('bypasses turnstile when no secret is configured (dev / tests)', function (): void {
    config()->set('services.turnstile.secret', null);
    Mail::fake();

    $this->postJson('/v1/contact', validContactPayload())
        ->assertStatus(201);
});
