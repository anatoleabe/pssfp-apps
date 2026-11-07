<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Requêtes (spec module 4, lot 8 ; D26 à D28 ; RG-055 à RG-058).
 *
 * - Une requête de note ne change la note qu'à la validation définitive du PV de séance.
 * - L'historique des requêtes est en ajout seul (déclencheur).
 *
 * Strictement additif.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seances_requetes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('jury_id')->constrained('jurys')->restrictOnDelete();
            $table->date('tenue_le');
            $table->string('numero_pv', 30)->nullable()->unique();
            $table->timestampTz('close_at')->nullable();
            $table->foreignId('close_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('validee_at')->nullable();
            $table->foreignId('validee_par')->nullable()->constrained('users')->nullOnDelete();
            $table->char('pv_sha256', 64)->nullable();
            $table->timestampsTz();

            $table->index('jury_id');
            $table->index('close_par');
            $table->index('validee_par');
        });

        Schema::create('requetes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('numero', 30)->unique();
            $table->string('type', 12);
            $table->foreignId('auditeur_id')->constrained('auditeurs')->restrictOnDelete();
            $table->foreignId('inscription_annuelle_id')->constrained('inscriptions_annuelles')->restrictOnDelete();
            $table->foreignId('maquette_ecue_id')->nullable()->constrained('maquette_ecues')->restrictOnDelete();
            $table->string('session', 12)->nullable();
            $table->string('note_visee', 12)->nullable();
            $table->string('motif', 20)->nullable();
            $table->string('type_document', 30)->nullable();
            $table->text('description');
            $table->string('statut', 20)->default('deposee');
            $table->foreignId('agent_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('seance_requetes_id')->nullable()->constrained('seances_requetes')->restrictOnDelete();
            $table->unsignedSmallInteger('ordre_seance')->nullable();
            $table->string('mention', 24)->nullable();
            $table->decimal('note_retenue', 5, 2)->nullable();
            $table->string('decision_motif', 1000)->nullable();
            $table->string('document_path')->nullable();
            $table->char('sha256', 64);
            $table->timestampsTz();

            $table->index(['auditeur_id', 'statut']);
            $table->index('inscription_annuelle_id');
            $table->index('maquette_ecue_id');
            $table->index(['agent_id', 'statut']);
            $table->index('seance_requetes_id');
        });

        Schema::create('requete_pieces', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('requete_id')->constrained('requetes')->restrictOnDelete();
            $table->string('nature', 24);
            $table->string('chemin');
            $table->string('nom', 255);
            $table->char('sha256', 64);
            $table->string('constat', 1000)->nullable();
            $table->foreignId('ajoute_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->index('requete_id');
            $table->index('ajoute_par');
        });

        Schema::create('requete_evenements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('requete_id')->constrained('requetes')->restrictOnDelete();
            $table->string('statut', 20);
            $table->string('libelle', 255);
            $table->string('detail', 1000)->nullable();
            $table->boolean('visible_auditeur')->default(true);
            $table->foreignId('auteur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->index('requete_id');
            $table->index('auteur_id');
        });

        Schema::table('deliberations', function (Blueprint $table): void {
            $table->timestampTz('a_rectifier_at')->nullable()->after('acte_rectificatif');
            $table->string('a_rectifier_motif', 500)->nullable()->after('a_rectifier_at');
        });

        // SQL brut, ADR-0011 : états fermés, cohérence par type, historique en ajout seul.
        DB::statement("ALTER TABLE requetes ADD CONSTRAINT requetes_type_check CHECK (type IN ('note', 'document'))");
        DB::statement("ALTER TABLE requetes ADD CONSTRAINT requetes_statut_check CHECK (statut IN ('deposee', 'cotee', 'en_instruction', 'instruite', 'au_jury', 'statuee', 'validee', 'appliquee', 'cloturee', 'irrecevable', 'annulee', 'en_traitement', 'document_disponible', 'rejetee'))");
        DB::statement("ALTER TABLE requetes ADD CONSTRAINT requetes_note_check CHECK (type <> 'note' OR (maquette_ecue_id IS NOT NULL AND session IN ('normale', 'rattrapage') AND note_visee IN ('cc', 'assiduite', 'examen') AND motif IN ('non_publication', 'erreur_report', 'absence_note', 'autre')))");
        DB::statement("ALTER TABLE requetes ADD CONSTRAINT requetes_document_check CHECK (type <> 'document' OR type_document IN ('lettre_stage', 'attestation_stage', 'pv_soutenance', 'autre'))");
        DB::statement("ALTER TABLE requetes ADD CONSTRAINT requetes_mention_check CHECK (mention IS NULL OR mention IN ('fondee', 'partiellement_fondee', 'non_fondee'))");
        DB::statement('ALTER TABLE requetes ADD CONSTRAINT requetes_note_retenue_check CHECK (note_retenue IS NULL OR note_retenue BETWEEN 0 AND 20)');
        DB::statement("ALTER TABLE requete_pieces ADD CONSTRAINT requete_pieces_nature_check CHECK (nature IN ('piece_auditeur', 'photocopie_pv_notes', 'fiche_notes', 'avis_enseignant', 'copie', 'emargement', 'autre'))");
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION requete_evenements_ajout_seul() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
              RAISE EXCEPTION 'L''historique des requêtes est en ajout seul : ni modification ni suppression' USING ERRCODE = 'check_violation';
            END $$
            SQL);
        DB::statement('CREATE TRIGGER requete_evenements_ajout_seul BEFORE UPDATE OR DELETE ON requete_evenements FOR EACH ROW EXECUTE FUNCTION requete_evenements_ajout_seul()');
    }

    public function down(): void
    {
        Schema::table('deliberations', function (Blueprint $table): void {
            $table->dropColumn(['a_rectifier_at', 'a_rectifier_motif']);
        });
        DB::statement('DROP TRIGGER IF EXISTS requete_evenements_ajout_seul ON requete_evenements');
        Schema::dropIfExists('requete_evenements');
        DB::statement('DROP FUNCTION IF EXISTS requete_evenements_ajout_seul()');
        Schema::dropIfExists('requete_pieces');
        Schema::dropIfExists('requetes');
        Schema::dropIfExists('seances_requetes');
    }
};
