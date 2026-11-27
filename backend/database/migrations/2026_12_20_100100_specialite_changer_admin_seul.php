<?php

declare(strict_types=1);

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Changer la spécialité d'un auditeur (27/11/2026) : réservé à l'administrateur. Le geste remplace « Changer de
 * parcours » (`lmd.parcours_changer`, que détenait aussi le responsable de scolarité) : l'ancienne permission est
 * retirée. Sans relancer RolePermissionSeeder en prod. Rejouable ; `down()` rétablit l'ancienne répartition.
 */
return new class extends Migration
{
    private const NOUVELLE = 'lmd.specialite_changer';

    private const ANCIENNE = 'lmd.parcours_changer';

    private const ROLES = ['super_admin', 'admin'];

    private const ROLES_ANCIENNE = ['super_admin', 'admin', 'scolarite_responsable'];

    public function up(): void
    {
        $this->donner(self::NOUVELLE, self::ROLES);
        Permission::query()->where('guard_name', 'web')->where('name', self::ANCIENNE)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $this->donner(self::ANCIENNE, self::ROLES_ANCIENNE);
        Permission::query()->where('guard_name', 'web')->where('name', self::NOUVELLE)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** @param  list<string>  $roles */
    private function donner(string $permission, array $roles): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate($permission, 'web');
        Role::query()->where('guard_name', 'web')->whereIn('name', $roles)->get()
            ->each(fn (Role $r) => $r->givePermissionTo($permission));
    }
};
