<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

final class RolePermissionSeeder extends Seeder
{
    /**
     * Crée les rôles principaux du projet.
     * Les permissions fines sont générées par filament-shield au fur et à mesure
     * que les Resources sont ajoutées (cf. modules 3, 5, 6).
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
    }
}
