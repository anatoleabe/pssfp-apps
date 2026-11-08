<?php

declare(strict_types=1);

use App\Models\AppSetting;
use App\Models\Role;
use App\Support\AppSettings;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Reçus des versements (Scolarité lot 4), sans relancer RolePermissionSeeder en prod. Listes figées.
 * Le service financier émet les reçus ; il consulte les auditeurs et enregistre les versements.
 * La date de mise en service borne les versements qui reçoivent un reçu (réponse de la Scolarité, question 22).
 */
return new class extends Migration
{
    private const EMETTRE = 'scolarite.recu_emettre';

    private const CONSULTATION = ['view_any_auditeur', 'view_auditeur', 'scolarite.versement_saisir'];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate(self::EMETTRE, 'web');
        foreach (self::CONSULTATION as $p) {
            Permission::findOrCreate($p, 'web');
        }
        Role::query()->where('guard_name', 'web')->where('name', 'super_admin')->get()->each(fn (Role $r) => $r->givePermissionTo(self::EMETTRE));
        Role::query()->where('guard_name', 'web')->where('name', 'service_financier')->get()
            ->each(fn (Role $r) => $r->givePermissionTo([self::EMETTRE, ...self::CONSULTATION]));
        if (AppSettings::get('scolarite.recus_depuis') === null) {
            AppSettings::set('scolarite.recus_depuis', now()->toIso8601String());
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->where('guard_name', 'web')->where('name', self::EMETTRE)->delete();
        Role::query()->where('guard_name', 'web')->where('name', 'service_financier')->get()
            ->each(fn (Role $r) => $r->revokePermissionTo(self::CONSULTATION));
        AppSetting::query()->where('key', 'scolarite.recus_depuis')->delete();
        AppSettings::forget('scolarite.recus_depuis');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
