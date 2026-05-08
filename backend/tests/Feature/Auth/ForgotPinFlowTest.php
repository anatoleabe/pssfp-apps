<?php

declare(strict_types=1);

use App\Models\SmsOtp;
use App\Models\User;
use App\Services\OtpService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

uses()->group('auth', 'candidat', 'otp');

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    Cache::flush();
});

it('accepts forgot-pin and triggers SMS via FakeSmsProvider for known phone', function (): void {
    User::factory()->candidat()->create(['phone_e164' => '+237691111222']);

    Log::shouldReceive('channel')->with('sms')->andReturnSelf();
    Log::shouldReceive('info')->once()->withArgs(fn ($msg, $ctx) => str_contains($msg, 'fake-sms'));

    $response = $this->postJson('/v1/auth/candidat/forgot-pin', [
        'phone_e164' => '+237691111222',
    ]);

    $response->assertStatus(202);
    expect(SmsOtp::where('phone_e164', '+237691111222')->where('purpose', 'reset_pin')->count())->toBe(1);
});

it('always returns 202 even for unknown phone (anti-enumeration)', function (): void {
    $response = $this->postJson('/v1/auth/candidat/forgot-pin', [
        'phone_e164' => '+237699999999',
    ]);

    $response->assertStatus(202);
    expect(SmsOtp::where('phone_e164', '+237699999999')->count())->toBe(0);
});

it('verify-otp returns 401 with wrong code and increments attempts', function (): void {
    $user = User::factory()->candidat()->create(['phone_e164' => '+237691111222']);
    /** @var OtpService $otp */
    $otp = app(OtpService::class);
    $otp->generate('+237691111222', 'reset_pin');

    $response = $this->postJson('/v1/auth/candidat/verify-otp', [
        'phone_e164' => '+237691111222',
        'code' => '000000',
    ]);

    $response->assertStatus(401);
    expect(SmsOtp::where('phone_e164', '+237691111222')->latest('id')->first()->attempts)->toBe(1);
});

it('verify-otp returns 410 when OTP is expired', function (): void {
    $user = User::factory()->candidat()->create(['phone_e164' => '+237691111222']);
    SmsOtp::factory()->expired()->create([
        'phone_e164' => '+237691111222',
        'purpose' => 'reset_pin',
        'code_hash' => Hash::make('123987'),
    ]);

    $response = $this->postJson('/v1/auth/candidat/verify-otp', [
        'phone_e164' => '+237691111222',
        'code' => '123987',
    ]);

    $response->assertStatus(410);
    expect($response->json('kind'))->toBe('expired');
});

it('verify-otp returns 401 after 3 wrong attempts (exhausted)', function (): void {
    $user = User::factory()->candidat()->create(['phone_e164' => '+237691111222']);
    /** @var OtpService $otp */
    $otp = app(OtpService::class);
    $otp->generate('+237691111222', 'reset_pin');

    for ($i = 0; $i < 3; $i++) {
        $this->postJson('/v1/auth/candidat/verify-otp', [
            'phone_e164' => '+237691111222',
            'code' => '000000',
        ]);
    }
    // 4e tentative : OTP exhausted
    $response = $this->postJson('/v1/auth/candidat/verify-otp', [
        'phone_e164' => '+237691111222',
        'code' => '000000',
    ]);

    $response->assertStatus(401);
});

it('verify-otp returns 409 when OTP is reused after success', function (): void {
    $user = User::factory()->candidat()->create(['phone_e164' => '+237691111222']);
    SmsOtp::factory()->used()->create([
        'phone_e164' => '+237691111222',
        'purpose' => 'reset_pin',
        'code_hash' => Hash::make('123987'),
    ]);

    $response = $this->postJson('/v1/auth/candidat/verify-otp', [
        'phone_e164' => '+237691111222',
        'code' => '123987',
    ]);

    $response->assertStatus(409);
    expect($response->json('kind'))->toBe('already_used');
});

it('verify-otp returns short-lived Sanctum token with pin:reset ability only on success', function (): void {
    $user = User::factory()->candidat()->create(['phone_e164' => '+237691111222']);
    /** @var OtpService $otp */
    $otp = app(OtpService::class);
    $code = $otp->generate('+237691111222', 'reset_pin');

    $response = $this->postJson('/v1/auth/candidat/verify-otp', [
        'phone_e164' => '+237691111222',
        'code' => $code,
    ]);

    $response->assertStatus(200);
    $response->assertJsonStructure(['pin_reset_token', 'expires_at']);

    $token = $user->tokens()->latest('id')->first();
    expect($token->name)->toBe('pin-reset');
    expect($token->abilities)->toBe(['pin:reset']);
    expect($token->expires_at?->diffInMinutes(now()))->toBeLessThanOrEqual(11);
});

it('verify-otp throttles after 5 attempts in 60 minutes (rate limit)', function (): void {
    User::factory()->candidat()->create(['phone_e164' => '+237691111222']);
    /** @var OtpService $otp */
    $otp = app(OtpService::class);

    // 5 tentatives consécutives — chaque OTP regénéré pour ne pas tomber sur exhausted.
    for ($i = 0; $i < 5; $i++) {
        $otp->generate('+237691111222', 'reset_pin');
        $this->postJson('/v1/auth/candidat/verify-otp', [
            'phone_e164' => '+237691111222',
            'code' => '000000',
        ]);
    }

    $response = $this->postJson('/v1/auth/candidat/verify-otp', [
        'phone_e164' => '+237691111222',
        'code' => '000000',
    ]);

    $response->assertStatus(429);
});

it('reset-pin requires the pin:reset ability (rejects regular candidat token)', function (): void {
    $user = User::factory()->candidat()->create(['phone_e164' => '+237691111222']);
    $regularToken = $user->createToken('candidat', ['profile:read'])->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$regularToken}")
        ->postJson('/v1/auth/candidat/reset-pin', [
            'pin' => '847291',
            'pin_confirmation' => '847291',
        ]);

    expect($response->status())->toBeIn([401, 403]);
});

it('reset-pin updates user PIN and revokes ALL user tokens', function (): void {
    $user = User::factory()->candidat('111222')->create(['phone_e164' => '+237691111222']);

    // Token actif d'une session précédente du candidat
    $oldCandidatToken = $user->createToken('candidat', ['profile:read'])->plainTextToken;
    // Token court issu de verify-otp
    $resetToken = $user->createToken('pin-reset', ['pin:reset'], now()->addMinutes(10))->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$resetToken}")
        ->postJson('/v1/auth/candidat/reset-pin', [
            'pin' => '847291',
            'pin_confirmation' => '847291',
        ]);

    $response->assertStatus(204);

    $user->refresh();
    expect(Hash::check('847291', $user->password))->toBeTrue();

    // Tous les tokens (pas juste le reset token) ont été supprimés.
    expect($user->tokens()->count())->toBe(0);
});

it('reset-pin invalidates pending OTPs of same phone after success', function (): void {
    $user = User::factory()->candidat('111222')->create(['phone_e164' => '+237691111222']);
    /** @var OtpService $otp */
    $otp = app(OtpService::class);
    $otp->generate('+237691111222', 'reset_pin'); // OTP encore actif (pas validé)

    $resetToken = $user->createToken('pin-reset', ['pin:reset'], now()->addMinutes(10))->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$resetToken}")
        ->postJson('/v1/auth/candidat/reset-pin', [
            'pin' => '847291',
            'pin_confirmation' => '847291',
        ])->assertStatus(204);

    $pending = SmsOtp::where('phone_e164', '+237691111222')
        ->whereNull('used_at')
        ->whereNull('cancelled_at')
        ->count();
    expect($pending)->toBe(0);
});

it('reset-pin enforces PinService rules (rejects 123456)', function (): void {
    $user = User::factory()->candidat('111222')->create(['phone_e164' => '+237691111222']);
    $resetToken = $user->createToken('pin-reset', ['pin:reset'], now()->addMinutes(10))->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$resetToken}")
        ->postJson('/v1/auth/candidat/reset-pin', [
            'pin' => '123456',
            'pin_confirmation' => '123456',
        ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['pin']);
});
