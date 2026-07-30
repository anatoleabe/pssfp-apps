<?php

declare(strict_types=1);

use App\Filament\Pages\Parametres;
use App\Models\User;
use App\Support\AppSettings;
use Database\Seeders\RolePermissionSeeder;

uses()->group('filament', 'settings');

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    config()->set('pssfp.filament.require_2fa', false);
});

function settingsUser(string $role): User
{
    $user = User::factory()->create(['email' => $role.'-settings@pssfp.local']);
    $user->assignRole($role);

    return $user;
}

it('stores and reads back the bcc recipients', function (): void {
    AppSettings::set(
        AppSettings::KEY_CANDIDATURE_NOTIFICATION_BCC,
        ['direction@pssfp.net', 'scolarite@pssfp.net'],
    );

    expect(AppSettings::candidatureNotificationBcc())
        ->toBe(['direction@pssfp.net', 'scolarite@pssfp.net']);
});

it('drops malformed entries and de-duplicates addresses', function (): void {
    AppSettings::set(AppSettings::KEY_CANDIDATURE_NOTIFICATION_BCC, [
        'Direction@PSSFP.net',
        '  scolarite@pssfp.net  ',
        'pas-une-adresse',
        '',
        'direction@pssfp.net',
        null,
    ]);

    expect(AppSettings::candidatureNotificationBcc())
        ->toBe(['direction@pssfp.net', 'scolarite@pssfp.net']);
});

it('returns an empty list when nothing has been configured', function (): void {
    expect(AppSettings::candidatureNotificationBcc())->toBe([]);
});

it('saves the recipients from the Filament settings page', function (): void {
    $admin = settingsUser('admin');

    $this->actingAs($admin);
    $this->livewire(Parametres::class)
        ->fillForm(['notification_bcc' => ['comite@pssfp.net']])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(AppSettings::candidatureNotificationBcc())->toBe(['comite@pssfp.net']);
});

it('reserves the settings page to holders of settings.manage', function (): void {
    expect(settingsUser('admin')->can('settings.manage'))->toBeTrue()
        ->and(settingsUser('super_admin')->can('settings.manage'))->toBeTrue()
        ->and(settingsUser('receptionniste')->can('settings.manage'))->toBeFalse()
        ->and(settingsUser('admission_committee')->can('settings.manage'))->toBeFalse();
});

it('denies the settings page to a receptionniste', function (): void {
    $this->actingAs(settingsUser('receptionniste'))
        ->get(Parametres::getUrl())
        ->assertForbidden();
});
