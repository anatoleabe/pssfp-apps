<?php

declare(strict_types=1);

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Confirmation des versements par la scolarité (décision du 15/11/2026, spec
 * module-4-scolarite-encaissement-confirmation §2.2 et §6), sans relancer RolePermissionSeeder en prod.
 * Jamais au service financier : il enregistre, la scolarité contrôle. Listes figées.
 */
return new class extends Migration
{
    private const CONFIRMER = 'scolarite.versement_confirmer';

    private const ROLES = ['super_admin', 'admin', 'scolarite_agent', 'scolarite_responsable'];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate(self::CONFIRMER, 'web');
        Role::query()->where('guard_name', 'web')->whereIn('name', self::ROLES)->get()
            ->each(fn (Role $r) => $r->givePermissionTo(self::CONFIRMER));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->where('guard_name', 'web')->where('name', self::CONFIRMER)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
