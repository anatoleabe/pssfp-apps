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
 * Suites des relectures du 16/11/2026 (spec de la réinscription des anciens, §15) : migration additive, les migrations
 * 2026_12_10_1000xx étant en production.
 *
 * - `reinscriptions_acma` : confirmations données au geste (identité forcée et sa pièce, signaux de doublon confirmés,
 *   M1 déjà admis confirmé), pour que la trace en ajout seul dise ce que l'agent a forcé.
 * - Déclencheur « matricule figé » sur `candidatures` : un matricule attribué ne change plus, sauf dans la reprise du
 *   matricule ACMA (15m), qui pose `app.conversion_matricule` le temps de sa transaction (SET LOCAL).
 * - Permission `scolarite.derogation_m2` : dérogation au M1 admis, au responsable de scolarité et à l'administration.
 *
 * SQL brut autorisé par l'ADR-0014 (décision 8) : CHECK et déclencheur.
 */
return new class extends Migration
{
    private const DROITS = [
        'scolarite.derogation_m2' => ['super_admin', 'admin', 'scolarite_responsable'],
    ];

    public function up(): void
    {
        DB::statement("SET LOCAL lock_timeout = '5s'");
        // ADD COLUMN ne déclenche pas le déclencheur d'ajout seul (déclencheur de ligne).
        Schema::table('reinscriptions_acma', function (Blueprint $table): void {
            $table->boolean('identite_forcee')->default(false);
            $table->string('identite_piece', 100)->nullable();
            $table->boolean('signaux_confirmes')->default(false);
            $table->boolean('m1_admis_confirme')->default(false);
        });
        DB::statement("ALTER TABLE reinscriptions_acma ADD CONSTRAINT reinscriptions_acma_identite_forcee_check CHECK (NOT identite_forcee OR char_length(trim(coalesce(identite_piece, ''))) >= 1)");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION candidatures_matricule_fige() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
              IF OLD.matricule IS NOT NULL AND NEW.matricule IS DISTINCT FROM OLD.matricule
                 AND coalesce(current_setting('app.conversion_matricule', true), '') <> 'on' THEN
                RAISE EXCEPTION 'Matricule attribué : il ne change que par la reprise du matricule ACMA' USING ERRCODE = 'check_violation';
              END IF;
              RETURN NEW;
            END $$;
        SQL);
        DB::statement('CREATE TRIGGER candidatures_matricule_fige BEFORE UPDATE OF matricule ON candidatures FOR EACH ROW EXECUTE FUNCTION candidatures_matricule_fige()');

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
        if (DB::table('reinscriptions_acma')->where(fn ($q) => $q->where('identite_forcee', true)->orWhere('signaux_confirmes', true)->orWhere('m1_admis_confirme', true))->exists()) {
            throw new RuntimeException('Confirmations de réinscription enregistrées : défaire ce lot perdrait leur trace ; il demande une migration motivée.');
        }
        Permission::query()->where('guard_name', 'web')->whereIn('name', array_keys(self::DROITS))->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        DB::statement('DROP TRIGGER IF EXISTS candidatures_matricule_fige ON candidatures');
        DB::statement('DROP FUNCTION IF EXISTS candidatures_matricule_fige()');
        DB::statement('ALTER TABLE reinscriptions_acma DROP CONSTRAINT IF EXISTS reinscriptions_acma_identite_forcee_check');
        Schema::table('reinscriptions_acma', function (Blueprint $table): void {
            $table->dropColumn(['identite_forcee', 'identite_piece', 'signaux_confirmes', 'm1_admis_confirme']);
        });
    }
};
