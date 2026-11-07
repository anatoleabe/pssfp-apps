<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Anonymat des copies (spec module 4, lot 9 ; D36 ; RG-045 à RG-052).
 *
 * - La correspondance numéro ↔ inscription est chiffrée (AES-256-GCM) avec une clé dérivée
 *   de la phrase secrète du gestionnaire, jamais stockée : seuls le sel et un vérificateur le sont.
 * - Les saisies sont en ajout seul (déclencheur) ; aucun numéro n'est écrit avec les notes.
 *
 * Strictement additif.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('epreuves_anonymes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('maquette_ecue_id')->constrained('maquette_ecues')->restrictOnDelete();
            $table->string('session', 12);
            $table->string('statut', 12)->default('preparation');
            $table->string('sel', 64)->nullable();
            $table->char('verificateur', 64)->nullable();
            $table->foreignId('gestionnaire_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('anonymisee_at')->nullable();
            $table->timestampTz('verrouillee_at')->nullable();
            $table->foreignId('autorisee_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('autorisee_at')->nullable();
            $table->foreignId('levee_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('levee_at')->nullable();
            $table->char('pv_sha256', 64)->nullable();
            $table->timestampsTz();

            $table->unique(['maquette_ecue_id', 'session']);
            $table->index('gestionnaire_id');
            $table->index('autorisee_par');
            $table->index('levee_par');
        });

        Schema::create('copies_anonymes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('epreuve_anonyme_id')->constrained('epreuves_anonymes')->restrictOnDelete();
            $table->char('numero', 9);
            $table->text('correspondance');
            $table->unsignedInteger('ordre');
            $table->string('constat', 16)->default('attribuee');
            $table->string('constat_motif', 500)->nullable();
            $table->timestampsTz();

            $table->unique(['epreuve_anonyme_id', 'numero']);
            $table->unique(['epreuve_anonyme_id', 'ordre']);
        });

        Schema::create('saisies_anonymes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('copie_anonyme_id')->constrained('copies_anonymes')->restrictOnDelete();
            $table->unsignedSmallInteger('correction');
            $table->unsignedSmallInteger('saisie');
            $table->decimal('note', 5, 2);
            $table->foreignId('operateur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['copie_anonyme_id', 'correction', 'saisie']);
            $table->index('operateur_id');
        });

        // SQL brut, ADR-0011 : états fermés, format des numéros, saisies en ajout seul.
        DB::statement("ALTER TABLE epreuves_anonymes ADD CONSTRAINT epreuves_anonymes_valeurs_check CHECK (session IN ('normale', 'rattrapage') AND statut IN ('preparation', 'anonymisee', 'verrouillee', 'levee'))");
        DB::statement("ALTER TABLE copies_anonymes ADD CONSTRAINT copies_anonymes_numero_check CHECK (numero ~ '^[2-9A-HJ-NP-Z]{9}$')");
        DB::statement("ALTER TABLE copies_anonymes ADD CONSTRAINT copies_anonymes_constat_check CHECK (constat IN ('attribuee', 'presente', 'absente', 'blanche', 'fraude', 'egaree', 'egaree_traitee', 'hors_liste', 'ecartee'))");
        DB::statement('ALTER TABLE saisies_anonymes ADD CONSTRAINT saisies_anonymes_valeurs_check CHECK (correction BETWEEN 1 AND 3 AND saisie BETWEEN 1 AND 3 AND note BETWEEN 0 AND 20)');
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION saisies_anonymes_ajout_seul() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
              RAISE EXCEPTION 'Les saisies anonymes sont en ajout seul : un écart se tranche par une troisième saisie' USING ERRCODE = 'check_violation';
            END $$
            SQL);
        DB::statement('CREATE TRIGGER saisies_anonymes_ajout_seul BEFORE UPDATE OR DELETE ON saisies_anonymes FOR EACH ROW EXECUTE FUNCTION saisies_anonymes_ajout_seul()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS saisies_anonymes_ajout_seul ON saisies_anonymes');
        Schema::dropIfExists('saisies_anonymes');
        DB::statement('DROP FUNCTION IF EXISTS saisies_anonymes_ajout_seul()');
        Schema::dropIfExists('copies_anonymes');
        Schema::dropIfExists('epreuves_anonymes');
    }
};
