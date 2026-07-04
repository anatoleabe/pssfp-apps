<?php

declare(strict_types=1);

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Http;

uses()->group('auth', 'candidat', 'turnstile');

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

function turnstileRegisterPayload(array $overrides = []): array
{
    return array_merge([
        'phone_e164' => '+237699887766',
        'phone_country' => 'CM',
        'pin' => '847291',
        'pin_confirmation' => '847291',
        'nom' => 'Mbarga',
        'prenom' => 'Alice',
        'date_naissance' => '1994-03-10',
        'cgu' => true,
    ], $overrides);
}

it('rejects a malformed turnstile_token (array) with 422, not 500', function (): void {
    config()->set('services.turnstile.secret', 'test-secret');

    $response = $this->postJson('/v1/auth/candidat/register', turnstileRegisterPayload([
        'turnstile_token' => ['x'],
    ]));

    $response->assertStatus(422)->assertJsonValidationErrors(['turnstile_token']);
});

it('rejects a malformed turnstile_token on forgot-pin with 422, not 500', function (): void {
    config()->set('services.turnstile.secret', 'test-secret');

    $response = $this->postJson('/v1/auth/candidat/forgot-pin', [
        'phone_e164' => '+237699887766',
        'turnstile_token' => ['x'],
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors(['turnstile_token']);
});

it('rejects registration when Cloudflare refuses the token', function (): void {
    config()->set('services.turnstile.secret', 'test-secret');
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => false])]);

    $response = $this->postJson('/v1/auth/candidat/register', turnstileRegisterPayload([
        'turnstile_token' => 'bad-token',
    ]));

    $response->assertStatus(422)->assertJsonValidationErrors(['turnstile_token']);
});

it('accepts registration when Cloudflare validates the token', function (): void {
    config()->set('services.turnstile.secret', 'test-secret');
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true])]);

    $response = $this->postJson('/v1/auth/candidat/register', turnstileRegisterPayload([
        'turnstile_token' => 'good-token',
    ]));

    $response->assertStatus(201);
});

it('rejects registration without token when a secret is configured', function (): void {
    config()->set('services.turnstile.secret', 'test-secret');

    $response = $this->postJson('/v1/auth/candidat/register', turnstileRegisterPayload());

    $response->assertStatus(422)->assertJsonValidationErrors(['turnstile_token']);
});

it('bypasses turnstile when no secret is configured (dev / tests)', function (): void {
    config()->set('services.turnstile.secret', null);

    $response = $this->postJson('/v1/auth/candidat/register', turnstileRegisterPayload());

    $response->assertStatus(201);
});
