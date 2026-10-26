<?php

declare(strict_types=1);

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Matricules et comptes Moodle relèvent de l'administration seule (décision du
 * 26/10/2026) : attribution et classement EFP/EFSC, export des comptes Moodle,
 * consultation et envoi du mot de passe d'un auditeur. Accordée à super_admin
 * et admin ; le comité d'admission garde les exports sans mot de passe
 * (campagne.export_retenus).
 *
 * Migration plutôt que nouveau passage du RolePermissionSeeder en production :
 * le seeder resynchroniserait toutes les permissions des rôles.
 */
return new class extends Migration
{
    private const PERMISSION = 'candidature.matricules_moodle';

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
