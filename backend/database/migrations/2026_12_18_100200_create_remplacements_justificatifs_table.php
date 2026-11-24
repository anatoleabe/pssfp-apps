<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Remplacement d'un bordereau par un administrateur (spec virements-bordereaux §3) : l'ancien fichier est conservé,
 * chaque remplacement est enregistré en ajout seul, et le déclencheur des versements n'admet le changement de bordereau
 * que si la même transaction a enregistré ce remplacement.
 */
return new class extends Migration
{
    private const CONDITIONS_COMMUNES = <<<'SQL'
                 OR (NEW.saisi_par IS DISTINCT FROM OLD.saisi_par AND (NEW.saisi_par IS NOT NULL OR pg_trigger_depth() < 2))
    SQL;

    private const FIN = <<<'SQL'
                 OR (OLD.annule_at IS NOT NULL AND (NEW.annule_at IS DISTINCT FROM OLD.annule_at OR NEW.annule_motif IS DISTINCT FROM OLD.annule_motif
                     OR (NEW.annule_par IS DISTINCT FROM OLD.annule_par AND (NEW.annule_par IS NOT NULL OR pg_trigger_depth() < 2))))
                 OR (OLD.annule_at IS NULL AND NEW.annule_at IS NULL
                     AND ROW(NEW.annule_par, NEW.annule_motif) IS DISTINCT FROM ROW(OLD.annule_par, OLD.annule_motif)) THEN
    SQL;

    public function up(): void
    {
        Schema::create('remplacements_justificatifs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('versement_id')->constrained('versements')->restrictOnDelete();
            $table->string('ancien_path', 255);
            $table->string('nouveau_path', 255);
            $table->string('motif', 500);
            $table->foreignId('remplace_par')->nullable()->constrained('users')->nullOnDelete();
            // Précision à la microseconde : le déclencheur des versements la compare à now() de la transaction.
            $table->timestampTz('remplace_le', 6)->useCurrent();

            $table->index(['versement_id', 'remplace_le']);
        });

        DB::statement('ALTER TABLE remplacements_justificatifs ADD CONSTRAINT remplacements_justificatifs_check CHECK (
            length(trim(motif)) >= 10 AND ancien_path <> nouveau_path
        )');
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION remplacements_justificatifs_ajout_seul() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
              -- Seule exception : la suppression d'un compte vide l'auteur (clé SET NULL, déclencheur imbriqué).
              IF TG_OP = 'UPDATE' AND pg_trigger_depth() > 1 AND NEW.remplace_par IS NULL
                 AND (to_jsonb(NEW) - 'remplace_par') = (to_jsonb(OLD) - 'remplace_par') THEN
                RETURN NEW;
              END IF;
              RAISE EXCEPTION 'Remplacement de bordereau en ajout seul' USING ERRCODE = 'check_violation';
            END $$;
            CREATE TRIGGER remplacements_justificatifs_ajout_seul BEFORE UPDATE OR DELETE ON remplacements_justificatifs
              FOR EACH ROW EXECUTE FUNCTION remplacements_justificatifs_ajout_seul();
        SQL);

        DB::unprepared($this->fonction(<<<'SQL'
                 OR (NEW.justificatif_path IS DISTINCT FROM OLD.justificatif_path AND OLD.justificatif_path IS NOT NULL
                     AND NOT (OLD.annule_at IS NULL AND NEW.justificatif_path IS NOT NULL AND EXISTS (
                       SELECT 1 FROM remplacements_justificatifs r WHERE r.versement_id = NEW.id
                         AND r.ancien_path = OLD.justificatif_path AND r.nouveau_path = NEW.justificatif_path
                         AND r.remplace_le = now())))
        SQL, 'seuls son annulation, son bordereau (joint une fois, puis remplacé par un administrateur) sont permis'));
    }

    public function down(): void
    {
        // Pas de retour arrière sur des écritures d'argent ou leur historique : on ne perd rien en silence.
        if (Schema::hasTable('remplacements_justificatifs') && DB::table('remplacements_justificatifs')->exists()) {
            throw new RuntimeException('remplacements_justificatifs contient des lignes : retour arrière refusé.');
        }
        DB::unprepared($this->fonction(<<<'SQL'
                 OR (NEW.justificatif_path IS DISTINCT FROM OLD.justificatif_path AND OLD.justificatif_path IS NOT NULL)
        SQL, 'seuls son annulation et son bordereau, une fois chacun, sont permis'));
        Schema::dropIfExists('remplacements_justificatifs');
        DB::unprepared('DROP FUNCTION IF EXISTS remplacements_justificatifs_ajout_seul()');
    }

    /** Fonction versements_immuables (migration du 01/12), avec la règle du bordereau donnée. */
    private function fonction(string $regleBordereau, string $message): string
    {
        return "CREATE OR REPLACE FUNCTION versements_immuables() RETURNS trigger LANGUAGE plpgsql AS \$\$
            DECLARE libres text[] := ARRAY['annule_at', 'annule_par', 'annule_motif', 'justificatif_path', 'saisi_par', 'updated_at'];
            BEGIN
              IF TG_OP = 'DELETE' THEN
                RAISE EXCEPTION 'Versement immuable : annulez-le, ne le supprimez pas' USING ERRCODE = 'check_violation';
              END IF;
              IF (to_jsonb(NEW) - libres) IS DISTINCT FROM (to_jsonb(OLD) - libres)
".self::CONDITIONS_COMMUNES."\n".$regleBordereau."\n".self::FIN."
                RAISE EXCEPTION 'Versement immuable : {$message}' USING ERRCODE = 'check_violation';
              END IF;
              RETURN NEW;
            END \$\$;";
    }
};
