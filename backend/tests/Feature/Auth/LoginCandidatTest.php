<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\CandidatAuthThrottle;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Spatie\Activitylog\Models\Activity;

uses()->group('auth', 'candidat');

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    Cache::flush(); // reset RateLimiter counters between tests
});

it('logs in a candidat with correct phone and PIN', function (): void {
    User::factory()->candidat('472816')->create(['phone_e164' => '+237691111222']);

    $response = $this->postJson('/v1/auth/candidat/login', [
        'phone_e164' => '+237691111222',
        'pin' => '472816',
    ]);

    $response->assertStatus(200);
    $response->assertJsonStructure(['user', 'token', 'abilities', 'expires_at']);
    expect($response->json('abilities'))->toContain('application:create');
});

it('returns 401 with wrong PIN (no leak about phone existence)', function (): void {
    User::factory()->candidat('472816')->create(['phone_e164' => '+237691111222']);

    $response = $this->postJson('/v1/auth/candidat/login', [
        'phone_e164' => '+237691111222',
        'pin' => '999999',
    ]);

    $response->assertStatus(401);
    expect($response->json('message'))->toContain('incorrect');
});

it('returns 401 when phone is unknown (same response as wrong PIN)', function (): void {
    $response = $this->postJson('/v1/auth/candidat/login', [
        'phone_e164' => '+237699999999',
        'pin' => '472816',
    ]);

    $response->assertStatus(401);
});

it('returns 423 after 10 failed attempts on the same phone (lockout)', function (): void {
    User::factory()->candidat('472816')->create(['phone_e164' => '+237691111222']);

    // Note : le throttle IP (3/10min) bloque avant les 10 essais sur phone si même IP.
    // Pour isoler le compteur phone, on simule directement les 10 hits du compteur phone.
    /** @var CandidatAuthThrottle $throttle */
    $throttle = app(CandidatAuthThrottle::class);
    for ($i = 0; $i < 10; $i++) {
        $throttle->recordLoginFailure('+237691111222', 'fake-ip');
    }

    // Maintenant la requête est bloquée par le compteur phone, peu importe l'IP du client.
    $response = $this->postJson('/v1/auth/candidat/login', [
        'phone_e164' => '+237691111222',
        'pin' => '472816',
    ]);

    $response->assertStatus(423);
    expect($response->json('kind'))->toBe('phone_locked');
});

it('returns 429 after 3 attempts in 10 minutes from same IP', function (): void {
    User::factory()->candidat('472816')->create(['phone_e164' => '+237691111222']);

    // 3 échecs successifs depuis la même IP (la fixture par défaut Pest = 127.0.0.1)
    for ($i = 0; $i < 3; $i++) {
        $this->postJson('/v1/auth/candidat/login', [
            'phone_e164' => '+237691111222',
            'pin' => '999999',
        ]);
    }

    // 4e tentative -> middleware throttle bloque AVANT le controller.
    $response = $this->postJson('/v1/auth/candidat/login', [
        'phone_e164' => '+237691111222',
        'pin' => '472816',
    ]);

    $response->assertStatus(429);
    expect($response->json('kind'))->toBeIn(['ip_throttled', 'ip_banned']);
});

it('clears IP and phone counters on successful login', function (): void {
    User::factory()->candidat('472816')->create(['phone_e164' => '+237691111222']);

    /** @var CandidatAuthThrottle $throttle */
    $throttle = app(CandidatAuthThrottle::class);
    // Simule 1 échec puis 1 succès — les compteurs login.ip + login.phone doivent être nets.
    $throttle->recordLoginFailure('+237691111222', '127.0.0.1');

    $response = $this->postJson('/v1/auth/candidat/login', [
        'phone_e164' => '+237691111222',
        'pin' => '472816',
    ]);
    $response->assertStatus(200);

    expect($throttle->checkLogin('+237691111222', '127.0.0.1'))->toBeNull();
});

it('rejects login on a non-candidat account', function (): void {
    // Un admin (rôle non-candidat) ne doit pas pouvoir se logger via cet endpoint.
    $admin = User::factory()->create([
        'phone_e164' => '+237692222333',
        'phone_country' => 'CM',
        'password' => Hash::make('472816'),
    ]);
    $admin->assignRole('admin');

    $response = $this->postJson('/v1/auth/candidat/login', [
        'phone_e164' => '+237692222333',
        'pin' => '472816',
    ]);

    $response->assertStatus(401);
});

it('writes a masked activity_log on each failed attempt (no PIN, masked phone)', function (): void {
    User::factory()->candidat('472816')->create(['phone_e164' => '+237691234567']);

    $this->postJson('/v1/auth/candidat/login', [
        'phone_e164' => '+237691234567',
        'pin' => '999999',
    ]);

    $log = Activity::query()
        ->where('log_name', 'auth.candidat')
        ->latest()
        ->first();

    expect($log)->not->toBeNull();
    expect($log->event)->toBe('login_failed');
    expect($log->properties->get('phone_e164_masked'))->toBe('+237***4567');
    expect($log->properties->toArray())->not->toHaveKey('pin');
    expect($log->properties->get('reason'))->toBe('wrong_pin');
});
