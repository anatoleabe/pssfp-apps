<?php

declare(strict_types=1);

use App\Filament\Resources\UserResource;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

uses()->group('filament', 'users');

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    config()->set('pssfp.filament.require_2fa', false);
});

function userWithRole(string $role, array $attributes = []): User
{
    $user = User::factory()->create(array_merge([
        'email' => $role.'-'.uniqid().'@pssfp.local',
    ], $attributes));
    $user->assignRole($role);

    return $user;
}

it('lets an admin list users', function (): void {
    $admin = userWithRole('admin');
    userWithRole('receptionniste');

    $this->actingAs($admin)
        ->get(UserResource::getUrl('index'))
        ->assertSuccessful();
});

it('denies the users screen to a receptionniste', function (): void {
    $this->actingAs(userWithRole('receptionniste'))
        ->get(UserResource::getUrl('index'))
        ->assertForbidden();
});

it('creates a back-office account with its roles and hashes the password', function (): void {
    $admin = userWithRole('admin');

    $this->actingAs($admin);
    $this->livewire(UserResource\Pages\CreateUser::class)
        ->fillForm([
            'name' => 'Agent Guichet',
            'email' => 'guichet.nouveau@pssfp.net',
            'password' => 'MotDePasseSolide2026',
            'roles' => [Role::findByName('receptionniste')->id],
            'email_verified_at' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $created = User::query()->where('email', 'guichet.nouveau@pssfp.net')->firstOrFail();

    expect($created->hasRole('receptionniste'))->toBeTrue()
        ->and($created->email_verified_at)->not->toBeNull()
        // Le cast `hashed` du modèle doit chiffrer une seule fois.
        ->and(Hash::check('MotDePasseSolide2026', $created->password))->toBeTrue();
});

it('keeps the current password when the field is left blank on edit', function (): void {
    $admin = userWithRole('admin');
    $agent = userWithRole('receptionniste');
    $hashAvant = $agent->password;

    $this->actingAs($admin);
    $this->livewire(UserResource\Pages\EditUser::class, ['record' => $agent->getKey()])
        ->fillForm(['name' => 'Nom Corrigé', 'password' => null])
        ->call('save')
        ->assertHasNoFormErrors();

    $agent->refresh();
    expect($agent->name)->toBe('Nom Corrigé')
        ->and($agent->password)->toBe($hashAvant);
});

it('forbids an admin from editing or deleting a super_admin', function (): void {
    $admin = userWithRole('admin');
    $superAdmin = userWithRole('super_admin');

    expect($admin->can('update', $superAdmin))->toBeFalse()
        ->and($admin->can('delete', $superAdmin))->toBeFalse();
});

it('forbids deleting your own account', function (): void {
    $superAdmin = userWithRole('super_admin');

    expect($superAdmin->can('delete', $superAdmin))->toBeFalse();
});

it('forbids deleting a candidat account from this screen', function (): void {
    $superAdmin = userWithRole('super_admin');
    $candidat = userWithRole('candidat');

    expect($superAdmin->can('delete', $candidat))->toBeFalse();
});

it('hides candidat accounts from the default listing', function (): void {
    $admin = userWithRole('admin');
    $agent = userWithRole('receptionniste', ['name' => 'Agent Visible']);
    $candidat = userWithRole('candidat', ['name' => 'Candidat Masqué']);

    $this->actingAs($admin);
    $this->livewire(UserResource\Pages\ListUsers::class)
        ->assertCanSeeTableRecords([$agent])
        ->assertCanNotSeeTableRecords([$candidat]);
});
