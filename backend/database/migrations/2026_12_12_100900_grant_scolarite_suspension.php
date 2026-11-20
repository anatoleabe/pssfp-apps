<?php

declare(strict_types=1);

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Suspension d'un auditeur pour l'année en cours, sur sa demande écrite (20/11/2026) : réservée à l'administrateur.
 * Sans relancer RolePermissionSeeder en prod. Rejouable ; `down()` retire la permission.
 */
return new class extends Migration
{
    private const PERMISSION = 'scolarite.suspension';

    private const ROLES = ['super_admin', 'admin'];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate(self::PERMISSION, 'web');
        Role::query()->where('guard_name', 'web')->whereIn('name', self::ROLES)->get()
            ->each(fn (Role $r) => $r->givePermissionTo(self::PERMISSION));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->where('guard_name', 'web')->where('name', self::PERMISSION)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
