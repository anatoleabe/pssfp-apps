<?php

declare(strict_types=1);

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Encaissement par le service financier (décision du 15/11/2026, spec
 * module-4-scolarite-encaissement-confirmation §2.1 et §3.1) : seul le service financier enregistre les
 * versements parmi les rôles métier. Sans relancer RolePermissionSeeder en prod : on retire la saisie aux
 * deux rôles de scolarité, on s'assure que le service financier saisit et émet, et l'on met à jour les
 * descriptions restées celles des migrations (une description retouchée depuis l'écran Rôles est gardée).
 */
return new class extends Migration
{
    private const SAISIE = 'scolarite.versement_saisir';

    private const EMETTRE = 'scolarite.recu_emettre';

    private const SCOLARITE = ['scolarite_agent', 'scolarite_responsable'];

    private const AGENT = 'Consultation des auditeurs et export de la situation financière ; lettre d\'accès FOAD d\'un auditeur soldé et renvoi de ses accès ; préparation des maquettes et inscriptions pédagogiques.';

    /** Ancienne description => nouvelle, par rôle. */
    private const DESCRIPTIONS = [
        'scolarite_agent' => [
            'Consultation des auditeurs, enregistrement des versements et export de la situation financière.' => self::AGENT,
        ],
        'service_financier' => [
            'Dossiers de paie des enseignants : NIU, banque et compte de virement.' => 'Encaissement des versements de scolarité et émission de leurs reçus ; dossiers de paie des enseignants : NIU, banque et compte de virement.',
        ],
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate(self::SAISIE, 'web');
        Permission::findOrCreate(self::EMETTRE, 'web');

        Role::query()->where('guard_name', 'web')->whereIn('name', self::SCOLARITE)->get()
            ->filter(fn (Role $r): bool => $r->hasPermissionTo(self::SAISIE))
            ->each(fn (Role $r) => $r->revokePermissionTo(self::SAISIE));
        Role::query()->where('guard_name', 'web')->where('name', 'service_financier')->get()
            ->each(fn (Role $r) => $r->givePermissionTo([self::SAISIE, self::EMETTRE]));

        $this->decrire(false);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::query()->where('guard_name', 'web')->whereIn('name', self::SCOLARITE)->get()
            ->each(fn (Role $r) => $r->givePermissionTo(self::SAISIE));
        $this->decrire(true);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function decrire(bool $retour): void
    {
        foreach (self::DESCRIPTIONS as $nom => $remplacements) {
            $table = $retour ? array_flip($remplacements) : $remplacements;
            $role = Role::query()->where('guard_name', 'web')->where('name', $nom)->first();
            $nouvelle = $role === null ? null : ($table[(string) $role->description] ?? null);
            if ($nouvelle !== null) {
                $role->forceFill(['description' => $nouvelle])->save();
            }
        }
    }
};
