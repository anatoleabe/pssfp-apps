<?php

declare(strict_types=1);

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Lot 15k (spec de la réinscription des anciens, §6.2, §7 et §12.1) : trace, en ajout seul, de chaque ancien auditeur
 * réinscrit depuis son dossier d'archive ACMA (mode `creation`) ; les modes `conversion` et `attribution` servent au
 * sous-lot 15m (Q2 : le matricule ACMA fait foi pour un ancien revenu en P14). Un dossier d'archive ne se réinscrit
 * qu'une fois. Permission `scolarite.inscrire_ancien` au responsable de scolarité et à l'administration.
 *
 * SQL brut autorisé par l'ADR-0014 (décision 8) : CHECK et déclencheur d'ajout seul. Strictement additive.
 */
return new class extends Migration
{
    private const DROITS = [
        'scolarite.inscrire_ancien' => ['super_admin', 'admin', 'scolarite_responsable'],
    ];

    public function up(): void
    {
        DB::statement("SET LOCAL lock_timeout = '5s'");
        Schema::create('reinscriptions_acma', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('dossier_acma_id')->unique()->constrained('acma_dossiers')->restrictOnDelete();
            $table->foreignId('candidature_id')->unique()->constrained('candidatures')->restrictOnDelete();
            $table->foreignId('auditeur_id')->unique()->constrained('auditeurs')->restrictOnDelete();
            $table->foreignId('inscription_annuelle_id')->nullable()->constrained('inscriptions_annuelles')->restrictOnDelete();
            $table->foreignId('campagne_id')->constrained('campagnes_candidature')->restrictOnDelete();
            $table->foreignId('rapprochement_id')->constrained('acma_rapprochements')->restrictOnDelete();
            $table->string('mode', 12);
            $table->string('ancien_matricule', 20)->nullable();
            $table->string('niveau', 2)->nullable();
            $table->string('parcours', 10)->nullable();
            $table->boolean('derogation_m2')->default(false);
            $table->string('derogation_piece', 100)->nullable();
            $table->jsonb('coordonnees_modifiees')->nullable();
            $table->string('motif', 500);
            $table->foreignId('auteur_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at');

            $table->index('inscription_annuelle_id');
            $table->index('campagne_id');
            $table->index('rapprochement_id');
            $table->index('auteur_id');
            $table->index('ancien_matricule');
        });

        $checks = [
            'mode' => "mode IN ('creation', 'conversion', 'attribution')",
            'motif' => 'char_length(trim(motif)) >= 10',
            'creation' => "mode <> 'creation' OR (inscription_annuelle_id IS NOT NULL AND niveau IN ('M1', 'M2') AND parcours IS NOT NULL)",
            'ancien_matricule' => "(mode = 'conversion') = (ancien_matricule IS NOT NULL)",
            'derogation' => "NOT derogation_m2 OR (niveau = 'M2' AND char_length(trim(coalesce(derogation_piece, ''))) >= 1)",
        ];
        foreach ($checks as $nom => $expression) {
            DB::statement("ALTER TABLE reinscriptions_acma ADD CONSTRAINT reinscriptions_acma_{$nom}_check CHECK ({$expression})");
        }
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION reinscriptions_acma_ajout_seul() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
              RAISE EXCEPTION 'Réinscriptions ACMA en ajout seul' USING ERRCODE = 'check_violation';
            END $$;
        SQL);
        DB::statement('CREATE TRIGGER reinscriptions_acma_ajout_seul BEFORE UPDATE OR DELETE ON reinscriptions_acma FOR EACH ROW EXECUTE FUNCTION reinscriptions_acma_ajout_seul()');
        DB::statement('CREATE TRIGGER reinscriptions_acma_sans_truncate BEFORE TRUNCATE ON reinscriptions_acma FOR EACH STATEMENT EXECUTE FUNCTION reinscriptions_acma_ajout_seul()');

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (self::DROITS as $permission => $roles) {
            Permission::findOrCreate($permission, 'web');
            Role::query()->where('guard_name', 'web')->whereIn('name', $roles)->get()->each(fn (Role $r) => $r->givePermissionTo($permission));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        DB::statement("SET LOCAL lock_timeout = '5s'");
        if (Schema::hasTable('reinscriptions_acma') && DB::table('reinscriptions_acma')->exists()) {
            throw new RuntimeException('Anciens auditeurs réinscrits : défaire ce lot perdrait leur trace ; il demande une migration motivée.');
        }
        Permission::query()->where('guard_name', 'web')->whereIn('name', array_keys(self::DROITS))->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        DB::statement('DROP TRIGGER IF EXISTS reinscriptions_acma_ajout_seul ON reinscriptions_acma');
        DB::statement('DROP TRIGGER IF EXISTS reinscriptions_acma_sans_truncate ON reinscriptions_acma');
        Schema::dropIfExists('reinscriptions_acma');
        DB::statement('DROP FUNCTION IF EXISTS reinscriptions_acma_ajout_seul()');
    }
};
