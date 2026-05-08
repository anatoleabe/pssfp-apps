<?php

declare(strict_types=1);

use App\Models\SmsOtp;

uses()->group('otp', 'console');

it('deletes OTPs older than 7 days', function (): void {
    SmsOtp::factory()->create([
        'phone_e164' => '+237691111111',
        'expires_at' => now()->subDays(8),
    ]);
    SmsOtp::factory()->create([
        'phone_e164' => '+237691111222',
        'expires_at' => now()->subDays(10),
    ]);

    $this->artisan('otp:purge')->assertExitCode(0);

    expect(SmsOtp::count())->toBe(0);
});

it('preserves still-valid OTPs and recently-expired ones', function (): void {
    SmsOtp::factory()->create([
        'phone_e164' => '+237691111111',
        'expires_at' => now()->addMinutes(10), // valide
    ]);
    SmsOtp::factory()->create([
        'phone_e164' => '+237691111222',
        'expires_at' => now()->subDays(2), // expiré mais < 7 jours
    ]);
    SmsOtp::factory()->create([
        'phone_e164' => '+237691111333',
        'expires_at' => now()->subDays(8), // expiré > 7 jours
    ]);

    $this->artisan('otp:purge')->assertExitCode(0);

    expect(SmsOtp::count())->toBe(2);
});

it('respects the --days option', function (): void {
    SmsOtp::factory()->create([
        'phone_e164' => '+237691111111',
        'expires_at' => now()->subDays(3),
    ]);

    $this->artisan('otp:purge', ['--days' => 1])->assertExitCode(0);

    expect(SmsOtp::count())->toBe(0);
});
