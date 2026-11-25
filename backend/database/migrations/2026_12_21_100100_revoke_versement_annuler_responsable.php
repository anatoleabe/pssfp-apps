<?php

declare(strict_types=1);

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Règle du 25/11/2026 : toute modification ou annulation d'un versement est validée par un administrateur. Le
 * responsable de scolarité perd `scolarite.versement_annuler` (annulation directe, décision des demandes de correction
 * et de réémission d'un reçu, annulation d'un règlement d'organisme) ; l'administrateur et le super administrateur le
 * gardent. Remplace la décision Q11 du 16/11/2026. Sans relancer RolePermissionSeeder en prod ; la description du rôle
 * n'est mise à jour que si elle est encore celle du seeder (une description retouchée depuis l'écran Rôles est gardée).
 */
return new class extends Migration
{
    private const DROIT = 'scolarite.versement_annuler';

    private const ROLE = 'scolarite_responsable';

    private const AVANT = 'Droits de l\'agent, plus l\'annulation des versements, les remises, les barèmes, les réglages de promotion, le statut des auditeurs, les messages, la validation des maquettes, les règles d\'évaluation et les changements de parcours.';

    private const APRES = 'Droits de l\'agent, plus les remises, les barèmes, les réglages de promotion, le statut des auditeurs, les messages, la validation des maquettes, les règles d\'évaluation et les parcours ; annuler un versement revient à un administrateur.';

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate(self::DROIT, 'web');
        Role::query()->where('guard_name', 'web')->where('name', self::ROLE)->get()
            ->filter(fn (Role $r): bool => $r->hasPermissionTo(self::DROIT))
            ->each(fn (Role $r) => $r->revokePermissionTo(self::DROIT));
        Role::query()->where('guard_name', 'web')->where('name', self::ROLE)->where('description', self::AVANT)->update(['description' => self::APRES]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::query()->where('guard_name', 'web')->where('name', self::ROLE)->get()
            ->each(fn (Role $r) => $r->givePermissionTo(Permission::findOrCreate(self::DROIT, 'web')));
        Role::query()->where('guard_name', 'web')->where('name', self::ROLE)->where('description', self::APRES)->update(['description' => self::AVANT]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
