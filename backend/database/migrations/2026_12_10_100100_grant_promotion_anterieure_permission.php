<?php

declare(strict_types=1);

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Lot 15j (spec de la réinscription des anciens, §5 et §12.1) : créer une promotion antérieure, réservé au responsable
 * de scolarité et à l'administration. Sans relancer RolePermissionSeeder en prod. Liste figée.
 */
return new class extends Migration
{
    private const DROITS = [
        'scolarite.promotion_anterieure' => ['super_admin', 'admin', 'scolarite_responsable'],
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (self::DROITS as $permission => $roles) {
            Permission::findOrCreate($permission, 'web');
            Role::query()->where('guard_name', 'web')->whereIn('name', $roles)->get()->each(fn (Role $r) => $r->givePermissionTo($permission));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->where('guard_name', 'web')->whereIn('name', array_keys(self::DROITS))->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
