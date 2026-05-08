<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Permet la persistance progressive d'une `Candidature` au statut `postulant`.
 *
 * Décision PR C arbitrage B (validation laxiste à PUT /me, stricte à submit) :
 * la plupart des colonnes profil deviennent NULLABLE pour qu'un candidat puisse
 * sauver progressivement via le wizard 4 étapes. La validation stricte se fera
 * dans SubmitCandidatureRequest au moment de POST /me/submit.
 *
 * Restent NOT NULL (cf. spec PR C précision B) :
 * - id, uuid, numero_dossier (générés par observer)
 * - campagne_id, user_id (lien technique avec auth)
 *   Note : user_id est en réalité NULLABLE depuis PR A pour permettre les imports
 *   admin futurs ; on ne le rend pas NOT NULL ici.
 * - phone_e164, phone_country (login + identifiant)
 * - nom, prenom, date_naissance (déjà fournis à register PR B)
 * - statut, frais_paye, dates de transition (booleans/timestamps avec defaults)
 *
 * Toutes les autres colonnes profil deviennent NULLABLE.
 */
return new class extends Migration
{
    /**
     * @var list<string> Colonnes profil rendues NULLABLE pour le draft.
     */
    private array $columnsToNull = [
        'civilite',
        'epouse',
        'lieu_naissance',
        'genre',
        'statut_matrimonial',
        'nationalite',
        'pays_origine',
        'pays_residence',
        'adresse',
        'ville_residence',
        'indicatif1',
        'telephone1',
        'specialite',
        'type_etude',
        'premiere_langue',
        'diplome_obtenu',
        'institut',
        'specialite_diplome',
        'annee_diplome',
        'statut_actuel',
        'engagement_nom',
    ];

    public function up(): void
    {
        foreach ($this->columnsToNull as $col) {
            DB::statement("ALTER TABLE candidatures ALTER COLUMN {$col} DROP NOT NULL");
        }
    }

    public function down(): void
    {
        foreach ($this->columnsToNull as $col) {
            DB::statement("ALTER TABLE candidatures ALTER COLUMN {$col} SET NOT NULL");
        }
    }
};
