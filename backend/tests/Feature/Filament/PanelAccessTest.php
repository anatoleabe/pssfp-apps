<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

uses()->group('filament', 'panel');

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

function makeUser(string $role): User
{
    $user = User::factory()->create([
        'email' => $role.'@pssfp.local',
    ]);
    $user->assignRole($role);

    return $user;
}

it('allows admission_committee to access the admin panel (when 2FA disabled)', function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    $u = makeUser('admission_committee');
    $panel = filament()->getPanel('admin');
    expect($u->canAccessPanel($panel))->toBeTrue();
});

it('allows super_admin', function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    expect(makeUser('super_admin')->canAccessPanel(filament()->getPanel('admin')))->toBeTrue();
});

it('allows admin / editor / librarian', function (string $role): void {
    config()->set('pssfp.filament.require_2fa', false);
    expect(makeUser($role)->canAccessPanel(filament()->getPanel('admin')))->toBeTrue();
})->with(['admin', 'editor', 'librarian']);

it('denies access to candidat role', function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    expect(makeUser('candidat')->canAccessPanel(filament()->getPanel('admin')))->toBeFalse();
});

it('denies access to teacher / auditor (V1 not in matrix)', function (string $role): void {
    config()->set('pssfp.filament.require_2fa', false);
    expect(makeUser($role)->canAccessPanel(filament()->getPanel('admin')))->toBeFalse();
})->with(['teacher', 'auditor']);

it('denies access when 2FA is required and the user has not enabled it', function (): void {
    config()->set('pssfp.filament.require_2fa', true);
    $u = makeUser('admission_committee');
    expect($u->canAccessPanel(filament()->getPanel('admin')))->toBeFalse();
});
