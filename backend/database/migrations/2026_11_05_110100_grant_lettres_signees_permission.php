<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Déposer, remplacer ou retirer une lettre d'admission signée relève de
 * l'administration seule : une erreur la montre aussitôt à un auditeur.
 *
 * Migration plutôt que nouveau passage du RolePermissionSeeder en production :
 * le seeder resynchroniserait toutes les permissions des rôles.
 */
return new class extends Migration
{
    private const PERMISSION = 'candidature.lettres_signees';

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
