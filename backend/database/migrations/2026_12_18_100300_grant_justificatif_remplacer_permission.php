<?php

declare(strict_types=1);

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Remplacement d'un bordereau joint à un versement (décision du 24/11/2026), réservé à
 * l'administration (spec virements-bordereaux §3). Sans relancer RolePermissionSeeder en prod. Liste figée.
 */
return new class extends Migration
{
    private const DROITS = [
        'scolarite.justificatif_remplacer' => ['super_admin', 'admin'],
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
