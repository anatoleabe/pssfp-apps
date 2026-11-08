<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Reçus des versements de scolarité (spec module 4, Scolarité lot 4 ; D7, réponses de la Scolarité du 08/11/2026).
 * Un reçu ne se modifie jamais : son annulation est une écriture distincte. Le contenu est figé à l'émission.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compteurs_recus', function (Blueprint $table): void {
            $table->unsignedSmallInteger('annee')->primary();
            $table->unsignedInteger('dernier')->default(0);
        });

        Schema::create('recus_versements', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('numero', 20)->unique();
            $table->unsignedSmallInteger('annee_numero');
            $table->unsignedInteger('sequence');
            $table->foreignId('versement_id')->constrained('versements')->restrictOnDelete();
            $table->foreignId('inscription_annuelle_id')->constrained('inscriptions_annuelles')->restrictOnDelete();
            $table->unsignedInteger('montant');
            $table->string('objet', 255);
            $table->string('auditeur_nom', 255);
            $table->string('matricule', 30)->nullable();
            $table->string('annee_academique', 9);
            $table->string('niveau', 2);
            $table->string('mode', 30);
            $table->string('reference', 100);
            $table->date('date_versement');
            $table->string('payeur', 20);
            $table->unsignedInteger('total_verse');
            $table->unsignedInteger('reste_a_payer');
            $table->foreignId('emis_par')->constrained('users')->restrictOnDelete();
            $table->string('emetteur_nom', 255);
            $table->timestampTz('emis_at');
            $table->timestampsTz();

            $table->unique(['annee_numero', 'sequence']);
            $table->index('versement_id');
            $table->index('inscription_annuelle_id');
            $table->index('emis_par');
        });

        Schema::create('annulations_recus', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('recu_id')->unique()->constrained('recus_versements')->restrictOnDelete();
            $table->string('numero', 22)->unique();
            $table->string('motif', 500);
            $table->foreignId('annule_par')->constrained('users')->restrictOnDelete();
            $table->string('annuleur_nom', 255);
            $table->timestampTz('annule_at');
            $table->timestampsTz();

            $table->index('annule_par');
        });

        // SQL brut, ADR-0011 : montant positif, numéro au format, motif non vide.
        DB::statement("ALTER TABLE recus_versements ADD CONSTRAINT recus_versements_check CHECK (montant > 0 AND numero ~ '^RCPT-[0-9]{4}-[0-9]{6}$' AND niveau IN ('M1', 'M2'))");
        DB::statement("ALTER TABLE annulations_recus ADD CONSTRAINT annulations_recus_check CHECK (length(trim(motif)) >= 5 AND numero ~ '^RCPT-[0-9]{4}-[0-9]{6}-A$')");

        // Pièces comptables immuables, même hors de l'application (requête directe, script de reprise) : ADR-0011.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION recus_immuables() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
              RAISE EXCEPTION 'Pièce comptable immuable (%) : passez une écriture d''annulation', TG_TABLE_NAME USING ERRCODE = 'check_violation';
            END $$;
            CREATE TRIGGER recus_versements_immuables BEFORE UPDATE OR DELETE ON recus_versements FOR EACH ROW EXECUTE FUNCTION recus_immuables();
            CREATE TRIGGER annulations_recus_immuables BEFORE UPDATE OR DELETE ON annulations_recus FOR EACH ROW EXECUTE FUNCTION recus_immuables();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('annulations_recus');
        Schema::dropIfExists('recus_versements');
        Schema::dropIfExists('compteurs_recus');
        DB::unprepared('DROP FUNCTION IF EXISTS recus_immuables()');
    }
};
