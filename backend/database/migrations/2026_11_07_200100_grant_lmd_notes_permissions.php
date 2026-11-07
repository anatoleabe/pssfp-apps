<?php

declare(strict_types=1);

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Notes (lot 5), sans relancer RolePermissionSeeder en prod. Listes figées ; les
 * rôles existants sont complétés. Nouveau rôle `enseignant` : saisie du CC et de
 * l'assiduité de ses ECUE, rien d'autre.
 */
return new class extends Migration
{
    private const SAISIE = 'lmd.notes_saisir';

    private const SENSIBLES = ['lmd.notes_verrouiller', 'lmd.notes_rouvrir', 'lmd.derogation_rattrapage'];

    private const ENSEIGNANT = 'lmd.notes_enseignant';

    private const ROLE_ENSEIGNANT = [
        'label' => 'Enseignant',
        'description' => 'Saisie du contrôle continu et de l\'assiduité des ECUE qui lui sont attribués.',
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach ([self::SAISIE, ...self::SENSIBLES, self::ENSEIGNANT] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        Role::query()->where('guard_name', 'web')->whereIn('name', ['super_admin', 'admin', 'scolarite_responsable'])->get()
            ->each(fn (Role $role) => $role->givePermissionTo([self::SAISIE, ...self::SENSIBLES]));
        Role::query()->where('guard_name', 'web')->where('name', 'super_admin')->get()
            ->each(fn (Role $role) => $role->givePermissionTo(self::ENSEIGNANT));
        Role::query()->where('guard_name', 'web')->where('name', 'scolarite_agent')->get()
            ->each(fn (Role $role) => $role->givePermissionTo(self::SAISIE));

        $enseignant = Role::findOrCreate('enseignant', 'web');
        if ($enseignant->wasRecentlyCreated) {
            $enseignant->forceFill(self::ROLE_ENSEIGNANT)->save();
        }
        $enseignant->givePermissionTo(self::ENSEIGNANT);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Role::query()->where('guard_name', 'web')->where('name', 'enseignant')->where('description', self::ROLE_ENSEIGNANT['description'])->delete();
        Permission::query()->where('guard_name', 'web')->whereIn('name', [self::SAISIE, ...self::SENSIBLES, self::ENSEIGNANT])->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
