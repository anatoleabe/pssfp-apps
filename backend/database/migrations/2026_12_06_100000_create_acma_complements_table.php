<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lot 15d (spec du lot 15, §7.3 et §7.5 ; ADR-0014) : compléments de l'archive ACMA, en ajout seul. La scolarité ne
 * modifie jamais l'archive : elle la complète d'après une pièce (dossier papier, PV signé), avec un motif. Le dernier
 * complément d'un champ l'emporte, tous restent visibles.
 *
 * Aussi : `acma_rapprochements.cas` devient facultatif, pour un arbitrage sans proposition automatique (par exemple
 * écarter une fiche de test).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acma_complements', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('dossier_id')->constrained('acma_dossiers')->restrictOnDelete();
            $table->foreignId('inscription_id')->nullable()->constrained('acma_inscriptions')->restrictOnDelete();
            $table->foreignId('resultat_id')->nullable()->constrained('acma_resultats_ue')->restrictOnDelete();
            $table->string('champ', 20);
            $table->string('valeur', 30);
            $table->string('motif', 500);
            $table->string('piece_reference', 100);
            $table->foreignId('auteur_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at');

            $table->index('dossier_id');
            $table->index('inscription_id');
            $table->index('resultat_id');
            $table->index('auteur_id');
        });

        // SQL brut autorisé par l'ADR-0014 (décision 8) : CHECK et déclencheur d'ajout seul.
        $checks = [
            'champ' => "champ IN ('date_naissance', 'decision', 'moyenne', 'note_retenue')",
            'motif' => 'char_length(trim(motif)) >= 10 AND char_length(trim(piece_reference)) >= 1',
            'cible' => "(champ = 'date_naissance' AND inscription_id IS NULL AND resultat_id IS NULL AND valeur ~ '^[0-9]{4}-[0-9]{2}-[0-9]{2}$')"
                ." OR (champ = 'decision' AND inscription_id IS NOT NULL AND resultat_id IS NULL AND valeur IN ('admis', 'non_admis'))"
                ." OR (champ = 'moyenne' AND inscription_id IS NOT NULL AND resultat_id IS NULL AND valeur ~ '^[0-9]{1,2}(\\.[0-9]{1,2})?$' AND valeur::numeric BETWEEN 0 AND 20)"
                ." OR (champ = 'note_retenue' AND inscription_id IS NOT NULL AND resultat_id IS NOT NULL AND valeur = 'retenue')",
        ];
        foreach ($checks as $nom => $expression) {
            DB::statement("ALTER TABLE acma_complements ADD CONSTRAINT acma_complements_{$nom}_check CHECK ({$expression})");
        }
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION acma_complements_ajout_seul() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
              RAISE EXCEPTION 'Compléments ACMA en ajout seul' USING ERRCODE = 'check_violation';
            END $$;
        SQL);
        DB::statement('CREATE TRIGGER acma_complements_ajout_seul BEFORE UPDATE OR DELETE ON acma_complements FOR EACH ROW EXECUTE FUNCTION acma_complements_ajout_seul()');
        DB::statement('CREATE TRIGGER acma_complements_sans_truncate BEFORE TRUNCATE ON acma_complements FOR EACH STATEMENT EXECUTE FUNCTION acma_complements_ajout_seul()');

        DB::statement('ALTER TABLE acma_rapprochements ALTER COLUMN cas DROP NOT NULL');
    }

    public function down(): void
    {
        if (DB::table('acma_complements')->exists() || DB::table('acma_rapprochements')->whereNull('cas')->exists()) {
            throw new RuntimeException('Compléments ou arbitrages ACMA enregistrés : défaire ce lot demande une migration motivée.');
        }
        DB::statement('ALTER TABLE acma_rapprochements ALTER COLUMN cas SET NOT NULL');
        DB::statement('DROP TRIGGER IF EXISTS acma_complements_ajout_seul ON acma_complements');
        DB::statement('DROP TRIGGER IF EXISTS acma_complements_sans_truncate ON acma_complements');
        Schema::dropIfExists('acma_complements');
        DB::statement('DROP FUNCTION IF EXISTS acma_complements_ajout_seul()');
    }
};
