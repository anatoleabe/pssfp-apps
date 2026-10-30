<?php

declare(strict_types=1);

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Accès d'un agent à l'espace d'un candidat (décision du 30/10/2026) : donner
 * un nouveau code de connexion à un candidat qui a oublié le sien, et voir son
 * espace tel qu'il le voit, en lecture seule. Accordée à super_admin et admin.
 *
 * Migration plutôt que nouveau passage du RolePermissionSeeder en production :
 * le seeder resynchroniserait toutes les permissions des rôles.
 */
return new class extends Migration
{
    private const PERMISSION = 'candidature.espace_candidat';

    private const ROLES = ['super_admin', 'admin'];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permission = Permission::findOrCreate(self::PERMISSION, 'web');

        Role::query()
            ->where('guard_name', 'web')
            ->whereIn('name', self::ROLES)
            ->get()
            ->each(fn (Role $role) => $role->givePermissionTo($permission));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->where('name', self::PERMISSION)->where('guard_name', 'web')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
