<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lot 14d-1 (D30) : chaîne d'empreintes SHA-256 du journal d'activité. Scellement asynchrone (rang attribué au scellement),
 * vérification par la base. SQL brut, ADR-0011 : fonctions de scellement et de vérification.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_log', function (Blueprint $table): void {
            $table->unsignedBigInteger('chaine_rang')->nullable()->unique();
            $table->char('empreinte', 64)->nullable();
            $table->char('empreinte_precedente', 64)->nullable();
        });
        // Les entrées à sceller : index partiel, petit par construction.
        DB::statement('CREATE INDEX activity_log_a_sceller ON activity_log (id) WHERE empreinte IS NULL');

        DB::unprepared(<<<'SQL'
            -- Contenu scellé d'une entrée : tout ce qui fait foi, sous une forme textuelle stable.
            CREATE OR REPLACE FUNCTION activity_log_contenu(a activity_log) RETURNS text LANGUAGE sql STABLE AS $$
              SELECT concat_ws('|', a.id, coalesce(a.log_name, ''), a.description, coalesce(a.subject_type, ''),
                coalesce(a.subject_id::text, ''), coalesce(a.causer_type, ''), coalesce(a.causer_id::text, ''),
                coalesce(a.properties::text, ''), coalesce(a.event, ''), coalesce(a.batch_uuid::text, ''),
                coalesce(to_char(a.created_at, 'YYYY-MM-DD"T"HH24:MI:SS.US'), ''))
            $$;

            CREATE OR REPLACE FUNCTION activity_log_empreinte(precedente text, rang bigint, a activity_log) RETURNS text LANGUAGE sql STABLE AS $$
              SELECT encode(sha256(convert_to(coalesce(precedente, '') || '|' || rang || '|' || activity_log_contenu(a), 'UTF8')), 'hex')
            $$;

            -- Scelle, dans l'ordre des identifiants, les entrées pas encore scellées. Un seul scellement à la fois.
            CREATE OR REPLACE FUNCTION activity_log_sceller() RETURNS integer LANGUAGE plpgsql AS $$
            DECLARE r activity_log%ROWTYPE; precedente text; rang bigint; n integer := 0;
            BEGIN
              PERFORM pg_advisory_xact_lock(hashtext('activity_log_chaine'));
              SELECT l.empreinte, l.chaine_rang INTO precedente, rang FROM activity_log l
                WHERE l.chaine_rang IS NOT NULL ORDER BY l.chaine_rang DESC LIMIT 1;
              rang := coalesce(rang, 0);
              FOR r IN SELECT * FROM activity_log WHERE empreinte IS NULL ORDER BY id LOOP
                rang := rang + 1;
                UPDATE activity_log SET chaine_rang = rang, empreinte_precedente = precedente,
                  empreinte = activity_log_empreinte(precedente, rang, r)
                WHERE id = r.id RETURNING empreinte INTO precedente;
                n := n + 1;
              END LOOP;
              RETURN n;
            END $$;

            -- Ruptures de la chaîne : contenu modifié, maillon manquant ou déplacé.
            CREATE OR REPLACE FUNCTION activity_log_ruptures() RETURNS TABLE (id bigint, chaine_rang bigint, nature text) LANGUAGE sql STABLE AS $$
              SELECT x.id, x.chaine_rang,
                CASE WHEN x.recalcul <> x.empreinte THEN 'modifiee' ELSE 'maillon_manquant' END
              FROM (
                SELECT a.id, a.chaine_rang, a.empreinte, a.empreinte_precedente,
                  lag(a.empreinte) OVER (ORDER BY a.chaine_rang) AS precedente_reelle,
                  lag(a.chaine_rang) OVER (ORDER BY a.chaine_rang) AS rang_precedent,
                  activity_log_empreinte(a.empreinte_precedente, a.chaine_rang, a) AS recalcul
                FROM activity_log a WHERE a.chaine_rang IS NOT NULL
              ) x
              WHERE x.recalcul <> x.empreinte
                 OR x.precedente_reelle IS DISTINCT FROM x.empreinte_precedente
                 OR x.chaine_rang <> coalesce(x.rang_precedent, 0) + 1
              ORDER BY x.chaine_rang
            $$;
        SQL);

        // Reprise : l'historique est scellé une fois, dans l'ordre.
        DB::select('SELECT activity_log_sceller()');
    }

    public function down(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS activity_log_ruptures()');
        DB::unprepared('DROP FUNCTION IF EXISTS activity_log_sceller()');
        DB::unprepared('DROP FUNCTION IF EXISTS activity_log_empreinte(text, bigint, activity_log)');
        DB::unprepared('DROP FUNCTION IF EXISTS activity_log_contenu(activity_log)');
        DB::statement('DROP INDEX IF EXISTS activity_log_a_sceller');
        Schema::table('activity_log', function (Blueprint $table): void {
            $table->dropUnique(['chaine_rang']);
            $table->dropColumn(['chaine_rang', 'empreinte', 'empreinte_precedente']);
        });
    }
};
