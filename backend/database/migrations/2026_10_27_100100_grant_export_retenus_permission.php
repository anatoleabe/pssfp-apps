<?php

declare(strict_types=1);

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Ajoute la permission d'attribuer les matricules des retenus et de télécharger
 * leurs exports (fiche complète, import ACMA, comptes Moodle), et l'accorde à
 * super_admin, admin et admission_committee.
 *
 * Migration plutôt que nouveau passage du RolePermissionSeeder en production :
 * le seeder resynchronise toutes les permissions des rôles et écraserait les
 * réglages faits depuis l'écran Rôles. Ici on ne fait qu'ajouter.
 */
return new class extends Migration
{
    private const PERMISSION = 'campagne.export_retenus';

    private const ROLES = ['super_admin', 'admin', 'admission_committee'];

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
