<?php

declare(strict_types=1);

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Module Scolarité, sans relancer RolePermissionSeeder en prod (il écraserait les
 * réglages faits depuis l'écran Rôles). Listes figées à la date de la migration.
 *
 * Un rôle de même nom déjà présent est complété sans être renommé ; `down()` ne
 * supprime que les rôles portant encore la description posée ici.
 */
return new class extends Migration
{
    private const LECTURE = ['view_any_auditeur', 'view_auditeur'];

    private const SAISIE = 'scolarite.versement_saisir';

    private const EXPORT = 'scolarite.export';

    private const SENSIBLES = ['scolarite.versement_annuler', 'scolarite.remise', 'scolarite.bareme'];

    private const ROLES = [
        'scolarite_agent' => [
            'label' => 'Agent de scolarité',
            'description' => 'Consultation des auditeurs, enregistrement des versements et export de la situation financière.',
        ],
        'scolarite_responsable' => [
            'label' => 'Responsable de scolarité',
            'description' => 'Droits de l\'agent, plus l\'annulation des versements, les remises et les barèmes.',
        ],
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $agent = [...self::LECTURE, self::SAISIE, self::EXPORT];
        $tout = [...$agent, ...self::SENSIBLES];
        foreach ($tout as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        Role::query()->where('guard_name', 'web')->whereIn('name', ['super_admin', 'admin'])->get()
            ->each(fn (Role $role) => $role->givePermissionTo($tout));

        foreach (self::ROLES as $nom => $definition) {
            $role = Role::findOrCreate($nom, 'web');
            if ($role->wasRecentlyCreated) {
                $role->forceFill($definition)->save();
            }
            $role->givePermissionTo($nom === 'scolarite_agent' ? $agent : $tout);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        foreach (self::ROLES as $nom => $definition) {
            Role::query()->where('guard_name', 'web')->where('name', $nom)->where('description', $definition['description'])->delete();
        }

        Permission::query()->where('guard_name', 'web')
            ->whereIn('name', [...self::LECTURE, self::SAISIE, self::EXPORT, ...self::SENSIBLES])
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
