<?php

declare(strict_types=1);

use App\Exceptions\OtpCooldownException;
use App\Models\User;
use App\Services\OtpService;
use App\Services\Sms\FakeSmsProvider;
use App\Services\Sms\SmsServiceInterface;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

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

it('refuse un second envoi simultané pour le même numéro (double clic)', function (): void {
    config()->set('pssfp.otp.cooldown_seconds', 0);
    // Un premier envoi est encore en cours : il détient le verrou du numéro.
    $enCours = Cache::lock('otp:generate:+237699000333', 10);
    $enCours->get();

    try {
        expect(fn () => app(OtpService::class)->generate('+237699000333', 'reset_pin'))
            ->toThrow(OtpCooldownException::class);
    } finally {
        $enCours->release();
    }

    expect(app(OtpService::class)->generate('+237699000333', 'reset_pin'))->toMatch('/^\d{6}$/');
});

it('annonce le délai avant un nouvel envoi, identique que le numéro existe ou non', function (): void {
    config()->set('pssfp.otp.cooldown_seconds', 90);
    config()->set('services.turnstile.secret', null);

    $this->postJson('/v1/auth/candidat/forgot-pin', ['phone_e164' => '+237699000444'])
        ->assertStatus(202)
        ->assertJsonPath('resend_after', 90);
});

it('ne journalise pas le texte des SMS factices hors local et test', function (): void {
    $this->app['env'] = 'staging';
    Log::shouldReceive('channel')->with('sms')->andReturnSelf();
    Log::shouldReceive('info')->once()
        ->withArgs(fn (string $msg, array $ctx): bool => ! str_contains((string) $ctx['message'], 'Secret123@'));

    (new FakeSmsProvider)->send('+237699000555', 'mot de passe Secret123@');
});
