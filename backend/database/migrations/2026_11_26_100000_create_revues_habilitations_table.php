<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Lot 14d-3 (D30) : revue annuelle des habilitations (un rôle d'un compte, maintenu ou retiré). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('revues_habilitations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 125);
            $table->string('decision', 10);
            $table->string('commentaire', 1000)->nullable();
            $table->foreignId('revu_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('revu_le');

            $table->index(['user_id', 'role', 'revu_le']);
            $table->index('revu_par');
        });

        // SQL brut, ADR-0011 : décisions fermées, motif d'un retrait, ajout seul (sauf effacement d'un compte supprimé).
        DB::statement("ALTER TABLE revues_habilitations ADD CONSTRAINT revues_habilitations_check CHECK (
            decision IN ('maintenu', 'retire')
            AND (decision = 'maintenu' OR length(trim(coalesce(commentaire, ''))) >= 10)
        )");
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION revues_habilitations_ajout_seul() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
              -- Seule exception : la suppression d'un compte efface ses revues, ou vide le réviseur (clés étrangères).
              IF TG_OP = 'UPDATE' AND NEW.revu_par IS NULL AND OLD.revu_par IS NOT NULL
                 AND NEW.user_id = OLD.user_id AND NEW.role = OLD.role AND NEW.decision = OLD.decision
                 AND NEW.commentaire IS NOT DISTINCT FROM OLD.commentaire AND NEW.revu_le = OLD.revu_le THEN
                RETURN NEW;
              END IF;
              IF TG_OP = 'DELETE' AND NOT EXISTS (SELECT 1 FROM users WHERE id = OLD.user_id) THEN
                RETURN OLD;
              END IF;
              RAISE EXCEPTION 'Revue des habilitations en ajout seul' USING ERRCODE = 'check_violation';
            END $$;
            CREATE TRIGGER revues_habilitations_ajout_seul BEFORE UPDATE OR DELETE ON revues_habilitations
              FOR EACH ROW EXECUTE FUNCTION revues_habilitations_ajout_seul();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('revues_habilitations');
        DB::unprepared('DROP FUNCTION IF EXISTS revues_habilitations_ajout_seul()');
    }
};
