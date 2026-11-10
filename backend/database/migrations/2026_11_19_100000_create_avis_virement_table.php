<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lot 13c-1 : avis de virement des enseignants, rapprochés des états de paie (D38).
 * Ajout seul : seule la pose unique de l'annulation est admise (déclencheur, ADR-0011).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('avis_virement', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('etat_id')->nullable()->constrained('etats_paie')->restrictOnDelete();
            $table->foreignId('enseignant_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('montant');
            $table->date('date_valeur');
            $table->string('reference', 100);
            $table->string('source', 16);
            $table->string('piece_path', 255)->nullable();
            $table->string('piece_sha256', 64)->nullable();
            $table->foreignId('saisi_par')->constrained('users')->restrictOnDelete();
            $table->timestampTz('annule_at')->nullable();
            $table->foreignId('annule_par')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('motif_annulation', 500)->nullable();
            $table->timestampsTz();

            $table->index('etat_id');
            $table->index('enseignant_id');
            $table->index('saisi_par');
            $table->index('annule_par');
        });

        // SQL brut, ADR-0011 : contraintes de valeur, unicité parmi les avis en vigueur, ajout seul.
        DB::statement("ALTER TABLE avis_virement ADD CONSTRAINT avis_virement_check CHECK (
            montant > 0
            AND length(trim(reference)) > 0
            AND source IN ('releve_bancaire', 'avis_tresor', 'saisie')
            AND (piece_sha256 IS NULL OR piece_sha256 ~ '^[0-9a-f]{64}$')
            AND ((annule_at IS NULL AND annule_par IS NULL AND motif_annulation IS NULL)
                 OR (annule_at IS NOT NULL AND annule_par IS NOT NULL AND length(trim(motif_annulation)) >= 10))
        )");
        DB::statement('CREATE UNIQUE INDEX avis_virement_reference_en_vigueur ON avis_virement (enseignant_id, reference) WHERE annule_at IS NULL');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION avis_virement_ajout_seul() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
              IF TG_OP = 'DELETE' THEN
                RAISE EXCEPTION 'Avis de virement en ajout seul : passez une annulation' USING ERRCODE = 'check_violation';
              END IF;
              IF OLD.annule_at IS NULL AND NEW.annule_at IS NOT NULL
                 AND NEW.etat_id IS NOT DISTINCT FROM OLD.etat_id AND NEW.enseignant_id = OLD.enseignant_id
                 AND NEW.montant = OLD.montant AND NEW.date_valeur = OLD.date_valeur AND NEW.reference = OLD.reference
                 AND NEW.source = OLD.source AND NEW.piece_path IS NOT DISTINCT FROM OLD.piece_path
                 AND NEW.piece_sha256 IS NOT DISTINCT FROM OLD.piece_sha256 AND NEW.saisi_par = OLD.saisi_par
                 AND NEW.uuid = OLD.uuid THEN
                RETURN NEW;
              END IF;
              RAISE EXCEPTION 'Avis de virement en ajout seul : seule l''annulation est admise, une fois' USING ERRCODE = 'check_violation';
            END $$;
            CREATE TRIGGER avis_virement_ajout_seul BEFORE UPDATE OR DELETE ON avis_virement FOR EACH ROW EXECUTE FUNCTION avis_virement_ajout_seul();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('avis_virement');
        DB::unprepared('DROP FUNCTION IF EXISTS avis_virement_ajout_seul()');
    }
};
