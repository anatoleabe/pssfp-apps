<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Notes (spec module 4, lot 5 ; ADR-0011 ; D10, D11, D16).
 *
 * - Une note se saisit par ECUE, par session et par étape ; l'étape verrouillée la fige.
 * - L'historique des notes est en ajout seul, garanti par la base (déclencheur).
 *
 * Strictement additif.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('etapes_saisie', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('maquette_ecue_id')->constrained('maquette_ecues')->restrictOnDelete();
            $table->string('session', 12);
            $table->string('etape', 12);
            $table->string('statut', 12)->default('fermee');
            $table->foreignId('ouverte_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('ouverte_at')->nullable();
            $table->foreignId('verrouillee_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('verrouillee_at')->nullable();
            $table->foreignId('rouverte_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('rouverte_at')->nullable();
            $table->string('motif_reouverture', 500)->nullable();
            $table->timestampsTz();

            $table->unique(['maquette_ecue_id', 'session', 'etape']);
            $table->index('ouverte_par');
            $table->index('verrouillee_par');
            $table->index('rouverte_par');
        });

        Schema::create('notes_ecue', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inscription_pedagogique_id')->constrained('inscriptions_pedagogiques')->restrictOnDelete();
            $table->foreignId('maquette_ecue_id')->constrained('maquette_ecues')->restrictOnDelete();
            $table->string('session', 12);
            $table->decimal('cc', 5, 2)->nullable();
            $table->decimal('assiduite', 5, 2)->nullable();
            $table->decimal('examen', 5, 2)->nullable();
            $table->string('presence_examen', 24)->nullable();
            $table->foreignId('modifie_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['inscription_pedagogique_id', 'maquette_ecue_id', 'session']);
            $table->index('maquette_ecue_id');
            $table->index('modifie_par');
        });

        Schema::create('historique_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('note_ecue_id')->constrained('notes_ecue')->restrictOnDelete();
            $table->string('champ', 20);
            $table->string('ancienne_valeur', 30)->nullable();
            $table->string('nouvelle_valeur', 30)->nullable();
            $table->string('motif', 500);
            $table->foreignId('auteur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->index('note_ecue_id');
            $table->index('auteur_id');
        });

        Schema::create('derogations_rattrapage', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inscription_pedagogique_id')->unique()->constrained('inscriptions_pedagogiques')->restrictOnDelete();
            $table->string('accordee_par', 255);
            $table->string('reference_acte', 120);
            $table->date('date_acte');
            $table->string('motif', 500);
            $table->foreignId('saisie_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index('saisie_par');
        });

        Schema::create('attributions_ecue', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('maquette_ecue_id')->constrained('maquette_ecues')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('role', 12)->default('responsable');
            $table->timestampsTz();

            $table->unique(['maquette_ecue_id', 'user_id']);
            $table->index('user_id');
        });

        // SQL brut, ADR-0011 : états fermés, notes bornées, historique en ajout seul.
        DB::statement("ALTER TABLE etapes_saisie ADD CONSTRAINT etapes_saisie_valeurs_check CHECK (session IN ('normale', 'rattrapage') AND etape IN ('cc', 'assiduite', 'examen') AND statut IN ('fermee', 'ouverte', 'verrouillee'))");
        DB::statement("ALTER TABLE etapes_saisie ADD CONSTRAINT etapes_saisie_rattrapage_check CHECK (session = 'normale' OR etape = 'examen')");
        DB::statement('ALTER TABLE etapes_saisie ADD CONSTRAINT etapes_saisie_reouverture_check CHECK (rouverte_at IS NULL OR motif_reouverture IS NOT NULL)');
        DB::statement("ALTER TABLE notes_ecue ADD CONSTRAINT notes_ecue_valeurs_check CHECK (session IN ('normale', 'rattrapage') AND (cc IS NULL OR cc BETWEEN 0 AND 20) AND (assiduite IS NULL OR assiduite BETWEEN 0 AND 20) AND (examen IS NULL OR examen BETWEEN 0 AND 20))");
        DB::statement("ALTER TABLE notes_ecue ADD CONSTRAINT notes_ecue_presence_check CHECK (presence_examen IS NULL OR presence_examen IN ('present', 'absent_justifie', 'absent_non_justifie'))");
        DB::statement("ALTER TABLE notes_ecue ADD CONSTRAINT notes_ecue_absent_check CHECK (presence_examen IS NULL OR presence_examen = 'present' OR examen IS NULL)");
        DB::statement("ALTER TABLE historique_notes ADD CONSTRAINT historique_notes_champ_check CHECK (champ IN ('cc', 'assiduite', 'examen', 'presence_examen'))");
        DB::statement("ALTER TABLE attributions_ecue ADD CONSTRAINT attributions_ecue_role_check CHECK (role IN ('responsable', 'intervenant'))");
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION historique_notes_ajout_seul() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
              RAISE EXCEPTION 'L''historique des notes est en ajout seul : ni modification ni suppression' USING ERRCODE = 'check_violation';
            END $$
            SQL);
        DB::statement('CREATE TRIGGER historique_notes_ajout_seul BEFORE UPDATE OR DELETE ON historique_notes FOR EACH ROW EXECUTE FUNCTION historique_notes_ajout_seul()');
    }

    public function down(): void
    {
        Schema::dropIfExists('attributions_ecue');
        Schema::dropIfExists('derogations_rattrapage');
        DB::statement('DROP TRIGGER IF EXISTS historique_notes_ajout_seul ON historique_notes');
        Schema::dropIfExists('historique_notes');
        DB::statement('DROP FUNCTION IF EXISTS historique_notes_ajout_seul()');
        Schema::dropIfExists('notes_ecue');
        Schema::dropIfExists('etapes_saisie');
    }
};
