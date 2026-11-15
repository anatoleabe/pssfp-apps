<?php

declare(strict_types=1);

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Étiquettes sur les auditeurs : poser, retirer, créer, renommer et supprimer
 * une étiquette, sans relancer RolePermissionSeeder en prod. Liste figée.
 */
return new class extends Migration
{
    private const PERMISSION = 'scolarite.etiquettes';

    private const ROLES = ['super_admin', 'admin', 'scolarite_agent', 'scolarite_responsable'];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate(self::PERMISSION, 'web');
        Role::query()->where('guard_name', 'web')->whereIn('name', self::ROLES)->get()->each(fn (Role $r) => $r->givePermissionTo(self::PERMISSION));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->where('guard_name', 'web')->where('name', self::PERMISSION)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
