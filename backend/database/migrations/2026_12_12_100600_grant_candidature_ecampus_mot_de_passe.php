<?php

declare(strict_types=1);

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Compte eCampus de l'auditeur (19/11/2026) : vérifier le compte, réinitialiser le mot de passe à sa demande et ouvrir
 * sa fiche sur eCampus, réservés à l'administrateur. Sans relancer RolePermissionSeeder en prod. Rejouable ; `down()`
 * retire la permission.
 */
return new class extends Migration
{
    private const PERMISSION = 'candidature.ecampus_mot_de_passe';

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
