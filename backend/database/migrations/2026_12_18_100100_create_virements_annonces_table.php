<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Virements en deux temps (spec virements-bordereaux §2) : l'annonce, hors compte, puis sa validation à la date de paiement. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('virements_annonces', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('auditeur_id')->constrained('auditeurs')->restrictOnDelete();
            $table->foreignId('inscription_annuelle_id')->constrained('inscriptions_annuelles')->restrictOnDelete();
            $table->unsignedInteger('montant');
            $table->string('reference', 100);
            $table->string('payeur', 20);
            $table->date('date_ordre');
            $table->string('justificatif_path', 255);
            $table->foreignId('saisi_par')->nullable()->constrained('users')->nullOnDelete();
            $table->string('statut', 12)->default('en_attente');
            $table->date('date_paiement')->nullable();
            $table->foreignId('versement_id')->nullable()->unique()->constrained('versements')->restrictOnDelete();
            $table->foreignId('traite_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('traite_le')->nullable();
            $table->string('motif_abandon', 500)->nullable();
            $table->timestampsTz();

            $table->index(['statut', 'created_at']);
            $table->index('auditeur_id');
            $table->index('inscription_annuelle_id');
        });

        // SQL brut, ADR-0011 : cohérence de chaque statut, une référence par virement en attente, transitions contrôlées.
        DB::statement("ALTER TABLE virements_annonces ADD CONSTRAINT virements_annonces_check CHECK (
            montant > 0 AND length(trim(reference)) > 0 AND payeur IN ('auditeur', 'employeur', 'autre')
            AND statut IN ('en_attente', 'valide', 'abandonne')
            AND (statut <> 'en_attente' OR (date_paiement IS NULL AND versement_id IS NULL AND traite_le IS NULL AND motif_abandon IS NULL))
            AND (statut <> 'valide' OR (date_paiement IS NOT NULL AND date_paiement >= date_ordre AND versement_id IS NOT NULL AND traite_le IS NOT NULL AND motif_abandon IS NULL))
            AND (statut <> 'abandonne' OR (versement_id IS NULL AND date_paiement IS NULL AND traite_le IS NOT NULL AND length(trim(coalesce(motif_abandon, ''))) >= 10))
        )");
        DB::statement("CREATE UNIQUE INDEX virements_annonces_reference_en_attente ON virements_annonces (reference) WHERE statut = 'en_attente'");
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION virements_annonces_transitions() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE libres text[] := ARRAY['statut', 'date_paiement', 'versement_id', 'traite_par', 'traite_le', 'motif_abandon', 'saisi_par', 'updated_at'];
            BEGIN
              IF TG_OP = 'DELETE' THEN
                RAISE EXCEPTION 'Virement annoncé : il s''abandonne, il ne se supprime pas' USING ERRCODE = 'check_violation';
              END IF;
              IF (to_jsonb(NEW) - libres) IS DISTINCT FROM (to_jsonb(OLD) - libres) THEN
                RAISE EXCEPTION 'Virement annoncé : ce qui a été annoncé ne change plus' USING ERRCODE = 'check_violation';
              END IF;
              -- Mise à nul d'un auteur par la seule suppression de son compte (clés SET NULL : déclencheur imbriqué).
              IF pg_trigger_depth() > 1 AND NEW.statut = OLD.statut THEN
                RETURN NEW;
              END IF;
              IF OLD.statut <> 'en_attente' OR NEW.statut = 'en_attente' THEN
                RAISE EXCEPTION 'Virement annoncé : une seule validation ou un seul abandon, depuis « en attente »' USING ERRCODE = 'check_violation';
              END IF;
              RETURN NEW;
            END $$;
            CREATE TRIGGER virements_annonces_transitions BEFORE UPDATE OR DELETE ON virements_annonces
              FOR EACH ROW EXECUTE FUNCTION virements_annonces_transitions();
        SQL);
    }

    public function down(): void
    {
        // Pas de retour arrière sur des écritures d'argent ou leur historique : on ne perd rien en silence.
        if (Schema::hasTable('virements_annonces') && DB::table('virements_annonces')->exists()) {
            throw new RuntimeException('virements_annonces contient des lignes : retour arrière refusé.');
        }
        Schema::dropIfExists('virements_annonces');
        DB::unprepared('DROP FUNCTION IF EXISTS virements_annonces_transitions()');
    }
};
