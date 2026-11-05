<?php

declare(strict_types=1);

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Le pointage des droits d'inscription (case cochée) est remplacé par le registre
 * des versements (spec module 4, lot 1) : la permission ne commande plus rien et
 * ne doit plus apparaître dans l'écran des rôles. `down()` la rend à super_admin et
 * admin, comme la migration 2026_11_01_100100 qui l'avait accordée.
 */
return new class extends Migration
{
    private const PERMISSION = 'candidature.frais_inscription';

    public function up(): void
    {
        Permission::query()->where('name', self::PERMISSION)->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $permission = Permission::findOrCreate(self::PERMISSION, 'web');
        Role::query()->where('guard_name', 'web')->whereIn('name', ['super_admin', 'admin'])->get()
            ->each(fn (Role $role) => $role->givePermissionTo($permission));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
