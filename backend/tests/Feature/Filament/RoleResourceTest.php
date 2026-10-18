<?php

declare(strict_types=1);

use App\Filament\Resources\RoleResource;
use App\Models\Role;
use App\Models\User;
use App\Support\ModulePermissionMap;
use Database\Seeders\RolePermissionSeeder;

uses()->group('filament', 'roles');

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    config()->set('pssfp.filament.require_2fa', false);
});

function roleUser(string $role): User
{
    $user = User::factory()->create(['email' => $role.'-'.uniqid().'@pssfp.local']);
    $user->assignRole($role);

    return $user;
}

it('lets an admin open the roles screen', function (): void {
    $this->actingAs(roleUser('admin'))
        ->get(RoleResource::getUrl('index'))
        ->assertSuccessful();
});

it('denies the roles screen to a receptionniste', function (): void {
    $this->actingAs(roleUser('receptionniste'))
        ->get(RoleResource::getUrl('index'))
        ->assertForbidden();
});

it('creates a role from module levels and translates them into permissions', function (): void {
    $this->actingAs(roleUser('super_admin'));

    $this->livewire(RoleResource\Pages\CreateRole::class)
        ->fillForm([
            'label' => 'Secrétariat pédagogique',
            'name' => 'secretariat_pedagogique',
            'description' => 'Saisit les actualités, consulte les dossiers.',
            'modules' => [
                'site' => ModulePermissionMap::LEVEL_MANAGE,
                'admissions' => ModulePermissionMap::LEVEL_READ,
                'administration' => ModulePermissionMap::LEVEL_NONE,
            ],
            'sensitive_actions' => ['admissions' => ['candidature.export_csv']],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $role = Role::query()->where('name', 'secretariat_pedagogique')->firstOrFail();
    $permissions = $role->permissions->pluck('name')->all();

    expect($permissions)
        ->toContain('create_article')          // site : gestion
        ->toContain('view_any_candidature')    // admissions : lecture
        ->toContain('candidature.export_csv')  // action sensible cochée
        ->not->toContain('update_candidature') // admissions : pas de gestion
        ->not->toContain('view_any_user')      // administration : aucun accès
        ->not->toContain('candidature.accept');
});

it('reopens an existing role on the levels it was saved with', function (): void {
    $role = Role::query()->where('name', 'admission_committee')->firstOrFail();

    $this->actingAs(roleUser('super_admin'));

    $this->livewire(RoleResource\Pages\EditRole::class, ['record' => $role->getKey()])
        ->assertFormSet([
            'modules.admissions' => ModulePermissionMap::LEVEL_MANAGE,
            'modules.administration' => ModulePermissionMap::LEVEL_NONE,
        ]);
});

it('revokes the permissions dropped from a role', function (): void {
    $role = Role::query()->where('name', 'admission_committee')->firstOrFail();
    expect($role->permissions->pluck('name'))->toContain('candidature.accept');

    $this->actingAs(roleUser('super_admin'));

    $this->livewire(RoleResource\Pages\EditRole::class, ['record' => $role->getKey()])
        ->fillForm([
            'modules' => [
                'site' => ModulePermissionMap::LEVEL_NONE,
                'admissions' => ModulePermissionMap::LEVEL_READ,
                'administration' => ModulePermissionMap::LEVEL_NONE,
            ],
            'sensitive_actions' => ['admissions' => []],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $permissions = $role->fresh()->permissions->pluck('name')->all();

    expect($permissions)
        ->toContain('view_any_candidature')
        ->not->toContain('candidature.accept')
        ->not->toContain('update_candidature');
});

it('never lets the super_admin role be edited or deleted', function (): void {
    $superAdmin = Role::query()->where('name', 'super_admin')->firstOrFail();
    $user = roleUser('super_admin');

    expect($user->can('update', $superAdmin))->toBeFalse()
        ->and($user->can('delete', $superAdmin))->toBeFalse();
});

it('forbids an admin from widening a role they hold themselves', function (): void {
    $admin = roleUser('admin');
    $adminRole = Role::query()->where('name', 'admin')->firstOrFail();
    $editorRole = Role::query()->where('name', 'editor')->firstOrFail();

    expect($admin->can('update', $adminRole))->toBeFalse()
        ->and($admin->can('update', $editorRole))->toBeTrue();
});

it('refuses to delete a role still attached to an account', function (): void {
    $role = Role::query()->where('name', 'editor')->firstOrFail();
    roleUser('editor');

    expect(roleUser('super_admin')->can('delete', $role))->toBeFalse();
});

it('opens the panel to a freshly created role without touching the code', function (): void {
    $role = Role::create(['name' => 'scolarite_appui', 'label' => 'Appui scolarité', 'guard_name' => 'web']);
    RoleResource::applyPerimeter(
        $role,
        ['site' => ModulePermissionMap::LEVEL_NONE, 'admissions' => ModulePermissionMap::LEVEL_READ],
        [],
    );

    $agent = User::factory()->create(['email' => 'appui@pssfp.local']);
    $agent->assignRole('scolarite_appui');

    expect($agent->fresh()->hasBackOfficePerimeter())->toBeTrue();
});

it('closes the panel to a role emptied of its perimeter', function (): void {
    $role = Role::create(['name' => 'role_vide', 'label' => 'Rôle vide', 'guard_name' => 'web']);
    $agent = User::factory()->create(['email' => 'vide@pssfp.local']);
    $agent->assignRole('role_vide');

    expect($agent->fresh()->hasBackOfficePerimeter())->toBeFalse();
});

it('round-trips levels through permissions without drift', function (): void {
    $levels = [
        'site' => ModulePermissionMap::LEVEL_READ,
        'admissions' => ModulePermissionMap::LEVEL_MANAGE,
        'bibliotheque' => ModulePermissionMap::LEVEL_NONE,
        'administration' => ModulePermissionMap::LEVEL_NONE,
    ];

    $permissions = ModulePermissionMap::toPermissions($levels, ['candidature.accept']);

    expect(ModulePermissionMap::toLevels($permissions))->toBe($levels);
});

it('garde document.publish sur un rôle bibliothèque sans accès aux admissions', function (): void {
    $this->actingAs(roleUser('super_admin'));

    $this->livewire(RoleResource\Pages\CreateRole::class)
        ->fillForm([
            'label' => 'Documentaliste',
            'name' => 'documentaliste',
            'modules' => [
                'site' => ModulePermissionMap::LEVEL_NONE,
                'admissions' => ModulePermissionMap::LEVEL_NONE,
                'bibliotheque' => ModulePermissionMap::LEVEL_MANAGE,
                'administration' => ModulePermissionMap::LEVEL_NONE,
            ],
            'sensitive_actions' => ['bibliotheque' => ['document.publish']],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $role = Role::query()->where('name', 'documentaliste')->firstOrFail();
    expect($role->permissions->pluck('name'))->toContain('document.publish')->toContain('update_document');

    // Aller-retour : rouvrir puis enregistrer sans rien changer ne perd rien.
    $this->livewire(RoleResource\Pages\EditRole::class, ['record' => $role->getKey()])
        ->assertFormSet([
            'modules.admissions' => ModulePermissionMap::LEVEL_NONE,
            'sensitive_actions.bibliotheque' => ['document.publish'],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($role->fresh()->permissions->pluck('name'))->toContain('document.publish')->toContain('update_document');
});

it('garde les actions sensibles de chaque module à l\'enregistrement', function (): void {
    $committee = Role::query()->where('name', 'admission_committee')->firstOrFail();
    $librarian = Role::query()->where('name', 'librarian')->firstOrFail();
    $before = [
        'committee' => $committee->permissions->pluck('name')->sort()->values()->all(),
        'librarian' => $librarian->permissions->pluck('name')->sort()->values()->all(),
    ];

    $this->actingAs(roleUser('super_admin'));

    $this->livewire(RoleResource\Pages\EditRole::class, ['record' => $committee->getKey()])
        ->call('save')->assertHasNoFormErrors();
    $this->livewire(RoleResource\Pages\EditRole::class, ['record' => $librarian->getKey()])
        ->assertFormSet([
            'sensitive_actions.admissions' => ['candidature.mark_paid'],
            'sensitive_actions.bibliotheque' => ['document.publish'],
        ])
        ->call('save')->assertHasNoFormErrors();

    expect($committee->fresh()->permissions->pluck('name')->sort()->values()->all())->toBe($before['committee'])
        ->and($before['committee'])->toContain('candidature.accept')
        ->and($librarian->fresh()->permissions->pluck('name')->sort()->values()->all())->toBe($before['librarian']);
});

it('retire les actions sensibles d\'un module passé à « aucun accès »', function (): void {
    $librarian = Role::query()->where('name', 'librarian')->firstOrFail();
    $this->actingAs(roleUser('super_admin'));

    $this->livewire(RoleResource\Pages\EditRole::class, ['record' => $librarian->getKey()])
        ->fillForm(['modules.admissions' => ModulePermissionMap::LEVEL_NONE])
        ->call('save')->assertHasNoFormErrors();

    expect($librarian->fresh()->permissions->pluck('name'))
        ->not->toContain('candidature.mark_paid')
        ->toContain('document.publish');
});
