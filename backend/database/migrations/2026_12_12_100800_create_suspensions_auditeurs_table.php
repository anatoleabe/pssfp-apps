<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Suspension d'un auditeur pour l'année en cours, sur sa demande écrite (décision du 20/11/2026, spec
 * module-4-scolarite-suspension). Une ligne par suspension : la demande scannée, sa date, le motif et l'agent sont
 * fixés à l'écriture ; seules la levée et la synchronisation avec eCampus se complètent ensuite. La base refuse la
 * suppression et toute modification de la demande. Une seule suspension en cours par auditeur.
 *
 * `inscriptions_annuelles.suspendue_at` marque l'année dont le reste n'est plus réclamé (ni en retard, ni échu) :
 * posé à la suspension, retiré seulement si la suspension est levée dans la même année.
 *
 * SQL brut autorisé par l'ADR-0010 (contraintes CHECK, index partiel, déclencheur).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suspensions_auditeurs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('auditeur_id')->constrained('auditeurs')->restrictOnDelete();
            $table->foreignId('inscription_annuelle_id')->constrained('inscriptions_annuelles')->restrictOnDelete();
            $table->string('annee_academique', 9);
            $table->string('demande_path');
            $table->date('demande_du');
            $table->string('motif', 500);
            $table->date('effet_le');
            $table->foreignId('suspendu_par')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
            // eCampus : compte suspendu (ou constat qu'il n'y en a pas), puis rouvert à la levée.
            $table->timestamp('ecampus_suspendu_at')->nullable();
            $table->timestamp('ecampus_rouvert_at')->nullable();
            $table->string('ecampus_detail', 255)->nullable();
            $table->timestamp('levee_at')->nullable();
            $table->foreignId('levee_par')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('levee_motif', 500)->nullable();
            $table->boolean('reprise_meme_annee')->default(false);
            $table->index('inscription_annuelle_id');
        });

        DB::statement('CREATE UNIQUE INDEX suspensions_auditeurs_une_en_cours ON suspensions_auditeurs (auditeur_id) WHERE levee_at IS NULL');
        DB::statement('ALTER TABLE suspensions_auditeurs ADD CONSTRAINT suspensions_auditeurs_levee_check CHECK ((levee_at IS NULL) = (levee_par IS NULL) AND (levee_at IS NULL) = (levee_motif IS NULL) AND (levee_at IS NOT NULL OR NOT reprise_meme_annee))');
        DB::statement("ALTER TABLE suspensions_auditeurs ADD CONSTRAINT suspensions_auditeurs_demande_check CHECK (demande_path <> '' AND motif <> '')");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION suspensions_auditeurs_immuables() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Une suspension ne se supprime pas : levez-la.';
                END IF;
                IF NEW.auditeur_id IS DISTINCT FROM OLD.auditeur_id
                    OR NEW.inscription_annuelle_id IS DISTINCT FROM OLD.inscription_annuelle_id
                    OR NEW.annee_academique IS DISTINCT FROM OLD.annee_academique
                    OR NEW.demande_path IS DISTINCT FROM OLD.demande_path
                    OR NEW.demande_du IS DISTINCT FROM OLD.demande_du
                    OR NEW.motif IS DISTINCT FROM OLD.motif
                    OR NEW.effet_le IS DISTINCT FROM OLD.effet_le
                    OR NEW.suspendu_par IS DISTINCT FROM OLD.suspendu_par
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at
                    OR NEW.uuid IS DISTINCT FROM OLD.uuid THEN
                    RAISE EXCEPTION 'La demande de suspension ne se modifie pas.';
                END IF;
                IF OLD.levee_at IS NOT NULL AND (NEW.levee_at IS DISTINCT FROM OLD.levee_at
                    OR NEW.levee_par IS DISTINCT FROM OLD.levee_par
                    OR NEW.levee_motif IS DISTINCT FROM OLD.levee_motif
                    OR NEW.reprise_meme_annee IS DISTINCT FROM OLD.reprise_meme_annee) THEN
                    RAISE EXCEPTION 'Une levée de suspension ne se modifie pas.';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER suspensions_auditeurs_immuables
              BEFORE UPDATE OR DELETE ON suspensions_auditeurs
              FOR EACH ROW EXECUTE FUNCTION suspensions_auditeurs_immuables();
            SQL);

        Schema::table('inscriptions_annuelles', function (Blueprint $table): void {
            $table->timestamp('suspendue_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('inscriptions_annuelles', function (Blueprint $table): void {
            $table->dropColumn('suspendue_at');
        });
        DB::unprepared('DROP TRIGGER IF EXISTS suspensions_auditeurs_immuables ON suspensions_auditeurs; DROP FUNCTION IF EXISTS suspensions_auditeurs_immuables();');
        Schema::dropIfExists('suspensions_auditeurs');
    }
};
