<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

uses()->group('filament', 'roles');

function migrationRetraitRolesApi(): object
{
    return require database_path('migrations/2026_11_18_100000_retire_roles_api_inutilises.php');
}

it('retire teacher et auditor quand personne ne les porte', function (): void {
    Role::findOrCreate('teacher', 'web');
    Role::findOrCreate('auditor', 'web');

    migrationRetraitRolesApi()->up();

    expect(Role::query()->whereIn('name', ['teacher', 'auditor'])->exists())->toBeFalse();
});

it('refuse le retrait si un compte porte encore le rôle', function (): void {
    Role::findOrCreate('teacher', 'web');
    User::factory()->create()->assignRole('teacher');

    expect(fn () => migrationRetraitRolesApi()->up())->toThrow(RuntimeException::class, 'teacher (1)');
    expect(Role::query()->where('name', 'teacher')->exists())->toBeTrue();
});

it('recrée les deux rôles au retour arrière', function (): void {
    migrationRetraitRolesApi()->down();

    expect(Role::findByName('teacher', 'web')->label)->toBe('Enseignant')
        ->and(Role::findByName('auditor', 'web')->label)->toBe('Auditeur');
});

it('n\'installe plus teacher ni auditor sur une base neuve', function (): void {
    $this->seed(RolePermissionSeeder::class);

    expect(Role::query()->whereIn('name', ['teacher', 'auditor'])->exists())->toBeFalse();
});

it('écrit la matrice des rôles depuis la base, sans les rôles hors back-office', function (): void {
    $this->seed(RolePermissionSeeder::class);
    User::factory()->create()->assignRole('library_manager');

    $this->artisan('roles:matrice')
        ->expectsOutputToContain('| Rôle | Comptes | Site institutionnel | Admissions | Scolarité | Parcours LMD | Bibliothèque | Administration |')
        ->expectsOutputToContain('| Responsable de la bibliothèque (`library_manager`) | 1 | — | — | — | — | Gestion | — |')
        ->doesntExpectOutputToContain('`candidat`')
        ->assertSuccessful();
});
