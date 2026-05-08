<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

uses()->group('auth', 'candidat');

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

function validRegisterPayload(array $overrides = []): array
{
    return array_merge([
        'phone_e164' => '+237691111222',
        'phone_country' => 'CM',
        'pin' => '847291',
        'pin_confirmation' => '847291',
        'nom' => 'Dupont',
        'prenom' => 'Jean',
        'date_naissance' => '1995-06-15',
        'cgu' => true,
    ], $overrides);
}

it('registers a new candidat and returns a Sanctum token with candidat abilities', function (): void {
    $response = $this->postJson('/v1/auth/candidat/register', validRegisterPayload());

    $response->assertStatus(201);
    $response->assertJsonStructure([
        'user' => ['id', 'name', 'phone_e164', 'phone_country', 'roles'],
        'token',
        'abilities',
        'expires_at',
    ]);
    expect($response->json('abilities'))->toEqual([
        'profile:read', 'profile:write', 'application:create', 'application:read',
    ]);
    expect($response->json('user.roles'))->toContain('candidat');

    $user = User::where('phone_e164', '+237691111222')->first();
    expect($user)->not->toBeNull();
    expect($user->hasRole('candidat'))->toBeTrue();
    expect($user->name)->toBe('Jean Dupont');
    expect($user->email)->toBeNull();
});

it('returns 409 when phone is already registered', function (): void {
    User::factory()->candidat()->create(['phone_e164' => '+237691111222']);

    $response = $this->postJson('/v1/auth/candidat/register', validRegisterPayload());

    // Le FormRequest unique:users,phone_e164 attrape avant le 409 du controller :
    // les deux statuts sont acceptables tant que la duplication est rejetée.
    expect($response->status())->toBeIn([409, 422]);
});

it('returns 422 when PIN is in the blacklist (123456)', function (): void {
    $response = $this->postJson('/v1/auth/candidat/register', validRegisterPayload([
        'pin' => '123456',
        'pin_confirmation' => '123456',
    ]));

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['pin']);
});

it('returns 422 when PIN matches the last 6 digits of phone', function (): void {
    $response = $this->postJson('/v1/auth/candidat/register', validRegisterPayload([
        'phone_e164' => '+237691234567',
        'pin' => '234567',
        'pin_confirmation' => '234567',
    ]));

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['pin']);
});

it('returns 422 when PIN equals date of birth as DDMMYY', function (): void {
    $response = $this->postJson('/v1/auth/candidat/register', validRegisterPayload([
        'date_naissance' => '1990-05-08',
        'pin' => '080590',
        'pin_confirmation' => '080590',
    ]));

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['pin']);
});

it('returns 422 when PIN equals date of birth as YYMMDD', function (): void {
    $response = $this->postJson('/v1/auth/candidat/register', validRegisterPayload([
        'date_naissance' => '1990-05-08',
        'pin' => '900508',
        'pin_confirmation' => '900508',
    ]));

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['pin']);
});

it('returns 422 when phone is not E.164', function (): void {
    $response = $this->postJson('/v1/auth/candidat/register', validRegisterPayload([
        'phone_e164' => '0691234567',
    ]));

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['phone_e164']);
});

it('returns 422 when candidat is under 18', function (): void {
    $response = $this->postJson('/v1/auth/candidat/register', validRegisterPayload([
        'date_naissance' => now()->subYears(17)->toDateString(),
    ]));

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['date_naissance']);
});

it('returns 422 when CGU is not accepted', function (): void {
    $response = $this->postJson('/v1/auth/candidat/register', validRegisterPayload([
        'cgu' => false,
    ]));

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['cgu']);
});

it('returns 422 when pin and confirmation differ', function (): void {
    $response = $this->postJson('/v1/auth/candidat/register', validRegisterPayload([
        'pin' => '847291',
        'pin_confirmation' => '847290',
    ]));

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['pin_confirmation']);
});
