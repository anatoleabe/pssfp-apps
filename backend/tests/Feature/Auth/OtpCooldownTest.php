<?php

declare(strict_types=1);

use App\Exceptions\OtpCooldownException;
use App\Models\User;
use App\Services\OtpService;
use App\Services\Sms\SmsServiceInterface;
use Database\Seeders\RolePermissionSeeder;

uses()->group('auth', 'candidat', 'otp');

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

it('refuses a second OTP within the cooldown window', function (): void {
    config()->set('pssfp.otp.cooldown_seconds', 60);
    /** @var OtpService $otp */
    $otp = app(OtpService::class);

    $otp->generate('+237699000111', 'reset_pin');

    expect(fn () => $otp->generate('+237699000111', 'reset_pin'))
        ->toThrow(OtpCooldownException::class);
});

it('applies the hourly cap per phone even after the cooldown', function (): void {
    config()->set('pssfp.otp.cooldown_seconds', 0);
    config()->set('pssfp.otp.max_per_phone_per_hour', 3);
    /** @var OtpService $otp */
    $otp = app(OtpService::class);

    foreach (range(1, 3) as $i) {
        $otp->generate('+237699000222', 'reset_pin');
    }

    expect(fn () => $otp->generate('+237699000222', 'reset_pin'))
        ->toThrow(OtpCooldownException::class);
});

it('does not throttle two different phone numbers', function (): void {
    config()->set('pssfp.otp.cooldown_seconds', 60);
    /** @var OtpService $otp */
    $otp = app(OtpService::class);

    $otp->generate('+237699000333', 'reset_pin');
    $code = $otp->generate('+237699000444', 'reset_pin');

    expect($code)->toMatch('/^\d{6}$/');
});

it('keeps forgot-pin at 202 without SMS when the phone is in cooldown (anti-enumeration)', function (): void {
    config()->set('pssfp.otp.cooldown_seconds', 60);
    User::factory()->candidat()->create(['phone_e164' => '+237699000555']);

    $sms = $this->mock(SmsServiceInterface::class);
    $sms->shouldReceive('send')->once(); // 1er appel seulement

    $this->postJson('/v1/auth/candidat/forgot-pin', ['phone_e164' => '+237699000555'])
        ->assertStatus(202);

    // Rejeu immédiat : cooldown → aucun SMS, mais toujours 202 (pas de 429
    // ciblé qui révélerait que le numéro est enregistré).
    $this->postJson('/v1/auth/candidat/forgot-pin', ['phone_e164' => '+237699000555'])
        ->assertStatus(202);
});

it('keeps forgot-pin at 202 when the SMS provider fails', function (): void {
    User::factory()->candidat()->create(['phone_e164' => '+237699000666']);

    $sms = $this->mock(SmsServiceInterface::class);
    $sms->shouldReceive('send')->andThrow(new RuntimeException('provider down'));

    $this->postJson('/v1/auth/candidat/forgot-pin', ['phone_e164' => '+237699000666'])
        ->assertStatus(202);
});
