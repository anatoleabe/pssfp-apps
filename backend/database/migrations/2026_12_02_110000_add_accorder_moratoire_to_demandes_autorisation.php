<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lot A3 (spec module 4, scolarité, §10) : l'agent demande un moratoire au responsable par le circuit du lot 14c.
 * La liste fermée des types s'élargit (précédent : `deverrouiller_compte`), et la demande porte ce que le type
 * exige en plus de son objet (échéance proposée, pièce) dans `parametres`, figé comme le reste de la demande.
 *
 * `down()` échoue tant qu'il reste une demande `accorder_moratoire` : une demande ne se supprime pas.
 */
return new class extends Migration
{
    private const TYPES_14D = "'rouvrir_etape_saisie', 'valider_quota', 'valider_maquette', 'valider_autre', 'annuler_pointage', 'revoquer_document', 'rejeter_etat_paie', 'deverrouiller_compte'";

    public function up(): void
    {
        Schema::table('demandes_autorisation', function (Blueprint $table): void {
            $table->jsonb('parametres')->nullable();
        });

        // SQL brut, ADR-0011 : liste fermée des types, paramètres d'une demande de moratoire, demande figée.
        DB::statement('ALTER TABLE demandes_autorisation DROP CONSTRAINT demandes_autorisation_check');
        DB::statement($this->contrainte(self::TYPES_14D.", 'accorder_moratoire'"));
        DB::statement("ALTER TABLE demandes_autorisation ADD CONSTRAINT demandes_autorisation_parametres_check CHECK (
            (parametres IS NULL OR jsonb_typeof(parametres) = 'object')
            AND (type <> 'accorder_moratoire' OR coalesce(parametres->>'echeance', '') ~ '^[0-9]{4}-[0-9]{2}-[0-9]{2}$')
        )");
        DB::unprepared($this->fonctionFigees("\n                 OR NEW.parametres IS DISTINCT FROM OLD.parametres"));
    }

    public function down(): void
    {
        DB::unprepared($this->fonctionFigees(''));
        DB::statement('ALTER TABLE demandes_autorisation DROP CONSTRAINT demandes_autorisation_parametres_check');
        DB::statement('ALTER TABLE demandes_autorisation DROP CONSTRAINT demandes_autorisation_check');
        DB::statement($this->contrainte(self::TYPES_14D));
        Schema::table('demandes_autorisation', function (Blueprint $table): void {
            $table->dropColumn('parametres');
        });
    }

    private function contrainte(string $types): string
    {
        return "ALTER TABLE demandes_autorisation ADD CONSTRAINT demandes_autorisation_check CHECK (
            numero ~ '^DM-[0-9]{4}-[0-9]{5}$'
            AND type IN ({$types})
            AND statut IN ('soumise', 'visa_attendu', 'rejetee', 'executee', 'echec_execution', 'retiree')
            AND length(trim(motif)) >= 10
            AND (empreinte IS NULL OR empreinte ~ '^[0-9a-f]{64}$')
        )";
    }

    /** Déclencheur du lot 14c (« ce qui a été demandé ne change plus »), avec ou sans les paramètres. */
    private function fonctionFigees(string $parametres): string
    {
        return <<<SQL
            CREATE OR REPLACE FUNCTION demandes_autorisation_figees() RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN
              IF TG_OP = 'DELETE' THEN
                RAISE EXCEPTION 'Demande en ajout seul : elle ne se supprime pas' USING ERRCODE = 'check_violation';
              END IF;
              IF NEW.uuid <> OLD.uuid OR NEW.numero <> OLD.numero OR NEW.type <> OLD.type OR NEW.demandeur_id <> OLD.demandeur_id
                 OR NEW.entite_id IS DISTINCT FROM OLD.entite_id OR NEW.objet <> OLD.objet OR NEW.motif <> OLD.motif
                 OR NEW.empreinte IS DISTINCT FROM OLD.empreinte OR NEW.soumise_le <> OLD.soumise_le OR NEW.echeance <> OLD.echeance{$parametres} THEN
                RAISE EXCEPTION 'Demande figée : seuls son statut et son résultat évoluent' USING ERRCODE = 'check_violation';
              END IF;
              IF NEW.statut <> OLD.statut AND NOT (
                   (OLD.statut = 'soumise' AND NEW.statut IN ('visa_attendu', 'rejetee', 'executee', 'echec_execution', 'retiree'))
                OR (OLD.statut = 'visa_attendu' AND NEW.statut IN ('rejetee', 'executee', 'echec_execution'))) THEN
                RAISE EXCEPTION 'Transition de statut interdite : % vers %', OLD.statut, NEW.statut USING ERRCODE = 'check_violation';
              END IF;
              RETURN NEW;
            END \$\$;
            SQL;
    }
};
