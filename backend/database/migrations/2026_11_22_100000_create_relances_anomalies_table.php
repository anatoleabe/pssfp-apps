<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Lot 14b-2 (D30, D31) : relances graduées des anomalies du contrôle interne, envoyées par la messagerie. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('relances_anomalies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('anomalie_id')->constrained('anomalies_systeme')->restrictOnDelete();
            $table->unsignedSmallInteger('niveau');
            $table->string('destinataire_role', 64);
            $table->jsonb('canaux');
            $table->foreignId('message_id')->nullable()->constrained('messages')->restrictOnDelete();
            $table->unsignedSmallInteger('destinataires')->default(0);
            $table->timestampTz('cree_le');

            $table->unique(['anomalie_id', 'niveau']);
            $table->index('message_id');
            $table->index('cree_le');
        });

        // SQL brut, ADR-0011 : bornes et ajout seul.
        DB::statement('ALTER TABLE relances_anomalies ADD CONSTRAINT relances_anomalies_check CHECK (
            niveau BETWEEN 1 AND 3 AND ((message_id IS NULL) = (destinataires = 0))
        )');
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION relances_anomalies_ajout_seul() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
              RAISE EXCEPTION 'Relance en ajout seul' USING ERRCODE = 'check_violation';
            END $$;
            CREATE TRIGGER relances_anomalies_ajout_seul BEFORE UPDATE OR DELETE ON relances_anomalies
              FOR EACH ROW EXECUTE FUNCTION relances_anomalies_ajout_seul();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('relances_anomalies');
        DB::unprepared('DROP FUNCTION IF EXISTS relances_anomalies_ajout_seul()');
    }
};
