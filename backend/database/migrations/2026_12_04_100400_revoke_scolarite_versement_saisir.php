<?php

declare(strict_types=1);

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Encaissement par le service financier (décision du 15/11/2026, spec
 * module-4-scolarite-encaissement-confirmation §2.1 et §3.1) : seul le service financier enregistre les
 * versements (le super administrateur garde tout). Sans relancer RolePermissionSeeder en prod : on retire la
 * saisie aux deux rôles de scolarité et à l'administrateur, on s'assure que le service financier saisit et
 * émet, et l'on met à jour les descriptions restées celles des migrations (une description retouchée depuis
 * l'écran Rôles est gardée).
 *
 * Après la reprise des confirmations et l'octroi de la confirmation (2026_12_04_100200 et 100300) : un échec
 * de la reprise ne laisse pas la scolarité sans saisie.
 */
return new class extends Migration
{
    private const SAISIE = 'scolarite.versement_saisir';

    private const EMETTRE = 'scolarite.recu_emettre';

    private const SANS_SAISIE = ['scolarite_agent', 'scolarite_responsable', 'admin'];

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

        Role::query()->where('guard_name', 'web')->whereIn('name', self::SANS_SAISIE)->get()
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
        Role::query()->where('guard_name', 'web')->whereIn('name', self::SANS_SAISIE)->get()
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
