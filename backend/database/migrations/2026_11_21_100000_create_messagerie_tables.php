<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Lot 14b-1 (D31) : messagerie interne, pièces jointes et file des copies externes. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('fil');
            $table->foreignId('expediteur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('categorie', 16)->default('message');
            $table->string('priorite', 16)->default('normale');
            $table->string('objet', 200);
            $table->text('corps');
            $table->foreignId('reponse_a')->nullable()->constrained('messages')->restrictOnDelete();
            $table->timestampTz('envoye_le');

            $table->index(['fil', 'envoye_le']);
            $table->index(['expediteur_id', 'envoye_le']);
            $table->index('reponse_a');
        });

        Schema::create('messages_destinataires', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('message_id')->constrained('messages')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestampTz('lu_le')->nullable();
            $table->timestampTz('archive_le')->nullable();

            $table->unique(['message_id', 'user_id']);
            $table->index(['user_id', 'archive_le', 'lu_le']);
        });

        Schema::create('messages_pieces', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('message_id')->constrained('messages')->restrictOnDelete();
            $table->string('nom_fichier', 255);
            $table->string('type_mime', 120);
            $table->unsignedInteger('taille_octets');
            $table->char('sha256', 64);
            $table->string('chemin', 500);

            $table->index('message_id');
        });

        Schema::create('envois_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('message_id')->constrained('messages')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('canal', 8);
            $table->string('adresse', 255)->nullable();
            $table->string('texte', 500);
            $table->string('statut', 16)->default('a_envoyer');
            $table->unsignedSmallInteger('tentatives')->default(0);
            $table->string('derniere_erreur', 500)->nullable();
            $table->timestampTz('envoye_le')->nullable();

            $table->unique(['message_id', 'user_id', 'canal']);
            $table->index('statut');
        });

        // SQL brut, ADR-0011 : listes fermées, bornes et ajout seul.
        DB::statement("ALTER TABLE messages ADD CONSTRAINT messages_check CHECK (
            categorie IN ('message', 'relance', 'demande', 'decision', 'notification')
            AND priorite IN ('normale', 'haute', 'urgente')
            AND length(trim(objet)) > 0 AND length(corps) BETWEEN 1 AND 10000
        )");
        DB::statement("ALTER TABLE messages_pieces ADD CONSTRAINT messages_pieces_check CHECK (
            type_mime IN ('application/pdf', 'image/jpeg', 'image/png',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document')
            AND taille_octets BETWEEN 1 AND 10485760
            AND sha256 ~ '^[0-9a-f]{64}$'
        )");
        DB::statement("ALTER TABLE envois_messages ADD CONSTRAINT envois_messages_check CHECK (
            canal IN ('email', 'sms')
            AND statut IN ('a_envoyer', 'envoye', 'echec', 'sans_adresse')
        )");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION messages_ajout_seul() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
              IF TG_OP = 'DELETE' THEN
                RAISE EXCEPTION 'Message en ajout seul : il ne se supprime pas' USING ERRCODE = 'check_violation';
              END IF;
              -- Seule exception : la suppression d'un compte vide l'expéditeur (clé étrangère ON DELETE SET NULL).
              IF NEW.expediteur_id IS NULL AND OLD.expediteur_id IS NOT NULL
                 AND NEW.uuid = OLD.uuid AND NEW.fil = OLD.fil AND NEW.categorie = OLD.categorie
                 AND NEW.priorite = OLD.priorite AND NEW.objet = OLD.objet AND NEW.corps = OLD.corps
                 AND NEW.reponse_a IS NOT DISTINCT FROM OLD.reponse_a AND NEW.envoye_le = OLD.envoye_le THEN
                RETURN NEW;
              END IF;
              RAISE EXCEPTION 'Message en ajout seul : il ne se modifie pas' USING ERRCODE = 'check_violation';
            END $$;
            CREATE TRIGGER messages_ajout_seul BEFORE UPDATE OR DELETE ON messages
              FOR EACH ROW EXECUTE FUNCTION messages_ajout_seul();

            CREATE OR REPLACE FUNCTION messages_pieces_ajout_seul() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
              RAISE EXCEPTION 'Pièce jointe en ajout seul' USING ERRCODE = 'check_violation';
            END $$;
            CREATE TRIGGER messages_pieces_ajout_seul BEFORE UPDATE OR DELETE ON messages_pieces
              FOR EACH ROW EXECUTE FUNCTION messages_pieces_ajout_seul();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('envois_messages');
        Schema::dropIfExists('messages_pieces');
        Schema::dropIfExists('messages_destinataires');
        Schema::dropIfExists('messages');
        DB::unprepared('DROP FUNCTION IF EXISTS messages_pieces_ajout_seul()');
        DB::unprepared('DROP FUNCTION IF EXISTS messages_ajout_seul()');
    }
};
