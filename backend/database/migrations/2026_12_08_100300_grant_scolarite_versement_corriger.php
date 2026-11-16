<?php

declare(strict_types=1);

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Correction des versements, sous-lot F4a (spec module-4-scolarite-espace-financier §9, ADR-0015) :
 * `scolarite.versement_corriger` (demander une correction ou une annulation) pour le service financier, et le super
 * administrateur qui a tout. La décision reste à `scolarite.versement_annuler`. Sans relancer RolePermissionSeeder en
 * prod. Rejouable ; `down()` retire la permission.
 */
return new class extends Migration
{
    private const CORRIGER = 'scolarite.versement_corriger';

    private const ROLES = ['super_admin', 'service_financier'];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate(self::CORRIGER, 'web');
        Role::query()->where('guard_name', 'web')->whereIn('name', self::ROLES)->get()
            ->each(fn (Role $r) => $r->givePermissionTo(self::CORRIGER));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->where('guard_name', 'web')->where('name', self::CORRIGER)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
