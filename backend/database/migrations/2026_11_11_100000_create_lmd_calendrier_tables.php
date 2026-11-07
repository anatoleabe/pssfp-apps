<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Calendrier académique et diagramme de Gantt (spec module 4, lot 12a ; D18 à D21). Strictement additif.
 */
return new class extends Migration
{
    private const TYPES = ['phase', 'rentree', 'inscriptions', 'semestre', 'periode_cours', 'saisie_cc', 'saisie_assiduite', 'examens',
        'deliberation', 'publication', 'reclamations', 'rattrapage', 'deliberation_rattrapage', 'redaction_memoires', 'reunion',
        'depot_memoires', 'pre_soutenance', 'soutenances', 'synthese_memoires', 'vacances', 'publication_admis', 'paiement', 'ceremonie',
        'activite_periacademique', 'conference', 'appel_candidatures', 'stages', 'production_documents', 'autre'];

    public function up(): void
    {
        Schema::create('calendriers_academiques', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('annee_academique', 9);
            $table->unsignedSmallInteger('version');
            $table->date('date_debut');
            $table->date('date_fin');
            $table->string('statut', 10)->default('brouillon');
            $table->string('acte_validation', 500)->nullable();
            $table->foreignId('prepare_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('soumis_at')->nullable();
            $table->foreignId('soumis_par')->nullable()->constrained('users')->nullOnDelete();
            $table->string('renvoi_motif', 500)->nullable();
            $table->string('signataire', 255)->nullable();
            $table->date('signe_le')->nullable();
            $table->foreignId('signe_par')->nullable()->constrained('users')->nullOnDelete();
            $table->string('piece_signee_path')->nullable();
            $table->char('piece_signee_sha256', 64)->nullable();
            $table->timestampTz('reference_figee_at')->nullable();
            $table->timestampTz('publie_at')->nullable();
            $table->foreignId('publie_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['annee_academique', 'version']);
            $table->index('prepare_par');
            $table->index('soumis_par');
            $table->index('signe_par');
            $table->index('publie_par');
        });

        Schema::create('evenements_calendrier', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('calendrier_id')->constrained('calendriers_academiques')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('evenements_calendrier')->nullOnDelete();
            $table->unsignedSmallInteger('ordre')->nullable();
            $table->string('type', 24);
            $table->string('libelle', 255);
            $table->string('niveau', 2)->nullable();
            $table->foreignId('parcours_id')->nullable()->constrained('parcours')->nullOnDelete();
            $table->unsignedSmallInteger('promotion')->nullable();
            $table->string('session', 12)->nullable();
            $table->unsignedSmallInteger('vague')->nullable();
            $table->date('debut_prevu');
            $table->date('fin_prevue');
            $table->date('debut_reel')->nullable();
            $table->date('fin_reelle')->nullable();
            $table->date('debut_signe')->nullable();
            $table->date('fin_signe')->nullable();
            $table->string('statut', 10)->default('prevu');
            $table->string('motif', 500)->nullable();
            $table->unsignedSmallInteger('avancement_pct')->default(0);
            $table->foreignId('responsable_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['calendrier_id', 'debut_prevu']);
            $table->index('parent_id');
            $table->index('parcours_id');
            $table->index('responsable_id');
        });

        Schema::create('dependances_evenement', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('predecesseur_id')->constrained('evenements_calendrier')->cascadeOnDelete();
            $table->foreignId('successeur_id')->constrained('evenements_calendrier')->cascadeOnDelete();
            $table->string('type_lien', 12)->default('fin_debut');
            $table->smallInteger('decalage_min_jours')->default(0);
            $table->smallInteger('decalage_max_jours')->nullable();
            $table->string('regle', 120)->default('Lien saisi par la scolarité');
            $table->boolean('bloquant')->default(true);
            $table->timestampsTz();

            $table->unique(['predecesseur_id', 'successeur_id']);
            $table->index('successeur_id');
        });

        Schema::create('modifications_evenement', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('evenement_id')->constrained('evenements_calendrier')->restrictOnDelete();
            $table->date('ancien_debut')->nullable();
            $table->date('ancienne_fin')->nullable();
            $table->string('ancien_statut', 10)->nullable();
            $table->date('nouveau_debut')->nullable();
            $table->date('nouvelle_fin')->nullable();
            $table->string('nouveau_statut', 10)->nullable();
            $table->string('motif', 500);
            $table->foreignId('auteur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->index('evenement_id');
            $table->index('auteur_id');
        });

        Schema::create('jours_non_ouvres', function (Blueprint $table): void {
            $table->id();
            $table->date('jour')->unique();
            $table->string('libelle', 255);
            $table->timestampsTz();
        });

        // SQL brut, ADR-0011 : états fermés, dates cohérentes, motifs obligatoires, journal en ajout seul.
        DB::statement("ALTER TABLE calendriers_academiques ADD CONSTRAINT calendriers_academiques_check CHECK (statut IN ('brouillon', 'soumis', 'signe', 'publie', 'remplace') AND date_fin > date_debut AND (statut NOT IN ('signe', 'publie') OR (signataire IS NOT NULL AND reference_figee_at IS NOT NULL)))");
        DB::statement("ALTER TABLE evenements_calendrier ADD CONSTRAINT evenements_calendrier_valeurs_check CHECK (type IN ('".implode("', '", self::TYPES)."') AND statut IN ('prevu', 'en_cours', 'realise', 'reporte', 'annule') AND (niveau IS NULL OR niveau IN ('M1', 'M2')) AND (session IS NULL OR session IN ('normale', 'rattrapage')) AND avancement_pct <= 100)");
        DB::statement('ALTER TABLE evenements_calendrier ADD CONSTRAINT evenements_calendrier_dates_check CHECK (fin_prevue >= debut_prevu AND (fin_reelle IS NULL OR debut_reel IS NULL OR fin_reelle >= debut_reel) AND (parent_id IS NULL OR parent_id <> id))');
        DB::statement("ALTER TABLE evenements_calendrier ADD CONSTRAINT evenements_calendrier_motif_check CHECK (statut NOT IN ('reporte', 'annule') OR motif IS NOT NULL)");
        DB::statement("ALTER TABLE dependances_evenement ADD CONSTRAINT dependances_evenement_check CHECK (predecesseur_id <> successeur_id AND type_lien IN ('fin_debut', 'debut_debut', 'fin_fin', 'debut_fin') AND (decalage_max_jours IS NULL OR decalage_max_jours >= decalage_min_jours))");
        // Les dates d'un événement restent dans l'année académique de son calendrier (D20 : millésimes erronés).
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION evenements_calendrier_bornes() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE d1 date; d2 date;
            BEGIN
              SELECT date_debut, date_fin INTO d1, d2 FROM calendriers_academiques WHERE id = NEW.calendrier_id;
              IF NEW.debut_prevu < d1 OR NEW.fin_prevue > d2 THEN
                RAISE EXCEPTION 'Événement « % » hors de l''année académique (% au %)', NEW.libelle, d1, d2 USING ERRCODE = 'check_violation';
              END IF;
              RETURN NEW;
            END $$
            SQL);
        DB::statement('CREATE TRIGGER evenements_calendrier_bornes BEFORE INSERT OR UPDATE ON evenements_calendrier FOR EACH ROW EXECUTE FUNCTION evenements_calendrier_bornes()');
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION modifications_evenement_ajout_seul() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
              RAISE EXCEPTION 'Le journal des modifications du calendrier est en ajout seul : ni modification ni suppression' USING ERRCODE = 'check_violation';
            END $$
            SQL);
        DB::statement('CREATE TRIGGER modifications_evenement_ajout_seul BEFORE UPDATE OR DELETE ON modifications_evenement FOR EACH ROW EXECUTE FUNCTION modifications_evenement_ajout_seul()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS modifications_evenement_ajout_seul ON modifications_evenement');
        DB::statement('DROP TRIGGER IF EXISTS evenements_calendrier_bornes ON evenements_calendrier');
        Schema::dropIfExists('jours_non_ouvres');
        Schema::dropIfExists('modifications_evenement');
        Schema::dropIfExists('dependances_evenement');
        Schema::dropIfExists('evenements_calendrier');
        Schema::dropIfExists('calendriers_academiques');
        DB::statement('DROP FUNCTION IF EXISTS modifications_evenement_ajout_seul()');
        DB::statement('DROP FUNCTION IF EXISTS evenements_calendrier_bornes()');
    }
};
