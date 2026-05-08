<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

final class RolePermissionSeeder extends Seeder
{
    /**
     * Crée les rôles principaux du projet et attache les permissions de base.
     *
     * Les permissions fines des Resources Filament sont générées par
     * filament-shield au fur et à mesure (cf. modules 3, 5, 6).
     *
     * Pour le rôle `candidat` (PR B M5) : permissions explicites sur les
     * scopes Sanctum candidat (profile + application).
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $roles = [
            'super_admin',
            'admin',
            'editor',
            'librarian',
            'admission_committee',
            'teacher',
            'auditor',
            'candidat',
        ];

        foreach ($roles as $role) {
            Role::findOrCreate($role, 'web');
        }

        $candidatPermissions = [
            'profile.read',
            'profile.write',
            'application.create',
            'application.read',
        ];

        foreach ($candidatPermissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        Role::findOrCreate('candidat', 'web')->syncPermissions($candidatPermissions);
    }
}
