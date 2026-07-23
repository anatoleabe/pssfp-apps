<?php

declare(strict_types=1);

namespace App\Http\Requests\Applications;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PUT /v1/applications/me — partial update du dossier en draft.
 *
 * Validation laxiste (cf. arbitrage B PR C) : seulement le format des champs
 * fournis. Aucun champ obligatoire ici — la validation stricte se fait à submit.
 *
 * Les champs systèmes (uuid, numero_dossier, statut, dates, frais_paye, ...)
 * sont filtrés dans CandidatureService::updateDraft pour empêcher toute
 * tentative d'écraser via le body (cf. P-min-4 PR C).
 */
final class UpdateCandidatureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->tokenCan('application:create');
    }

    public function rules(): array
    {
        return [
            // Identité
            'civilite' => ['sometimes', 'string', 'in:M.,Mme,Mlle'],
            'nom' => ['sometimes', 'string', 'max:100'],
            'prenom' => ['sometimes', 'string', 'max:100'],
            'epouse' => ['sometimes', 'nullable', 'string', 'max:100'],
            'date_naissance' => ['sometimes', 'date', 'before:'.now()->subYears(18)->toDateString()],
            'lieu_naissance' => ['sometimes', 'string', 'max:100'],
            'genre' => ['sometimes', 'string', 'in:M,F,autre'],
            'statut_matrimonial' => ['sometimes', 'string', 'max:20'],

            // Géographie (codes ISO-2 / référentiels)
            'nationalite' => ['sometimes', 'string', 'size:2', 'exists:pays,code_iso'],
            'pays_origine' => ['sometimes', 'string', 'size:2', 'exists:pays,code_iso'],
            'pays_residence' => ['sometimes', 'string', 'size:2', 'exists:pays,code_iso'],
            'region' => ['sometimes', 'nullable', 'string', 'exists:regions_cameroun,code'],
            'departement' => ['sometimes', 'nullable', 'string', 'exists:departements_cameroun,code'],
            'adresse' => ['sometimes', 'string', 'max:200'],
            'ville_residence' => ['sometimes', 'string', 'max:100'],

            // Téléphones
            'indicatif1' => ['sometimes', 'string', 'max:10'],
            'telephone1' => ['sometimes', 'string', 'max:20'],
            'indicatif2' => ['sometimes', 'nullable', 'string', 'max:10'],
            'telephone2' => ['sometimes', 'nullable', 'string', 'max:20'],

            // Email autorisé à rester vide dans un brouillon, mais obligatoire
            // au moment de la soumission (CandidatureService::checkSubmittable).
            'email' => ['sometimes', 'nullable', 'email', 'max:150'],

            // Pédagogique
            // Rule::in() (tableau) plutôt que la syntaxe 'in:a,b,c' : les
            // intitulés officiels contiennent des virgules (ex. « Fiscalité,
            // Finance et Comptabilité Publique »), qui casseraient le
            // parsing par virgule de la syntaxe string.
            'specialite' => ['sometimes', 'string', Rule::in(array_values((array) config('specialites', [])))],
            'second_choix' => ['sometimes', 'nullable', 'string', 'max:100'],
            'type_etude' => ['sometimes', 'string', 'in:presentiel,distanciel'],
            'premiere_langue' => ['sometimes', 'string', 'in:fr,en'],
            'diplome_obtenu' => ['sometimes', 'string', 'max:100'],
            'institut' => ['sometimes', 'string', 'max:150'],
            'specialite_diplome' => ['sometimes', 'string', 'max:100'],
            'annee_diplome' => ['sometimes', 'integer', 'min:1950', 'max:'.now()->year],
            'statut_actuel' => [
                'sometimes', 'string',
                'in:Etudiant,Sans-emploi,Fonctionnaire,Contractuel-Etat,Etablissement-public,Entreprise-publique,Prive,Independant,ONG-International,Autre,Fonctionnaire-Contractuel',
            ],
            'fonction_actuelle' => ['sometimes', 'nullable', 'string', 'max:150'],
            'employeur' => ['sometimes', 'nullable', 'string', 'max:150'],
            'adresse_employeur' => ['sometimes', 'nullable', 'string', 'max:200'],
            'tel_employeur' => ['sometimes', 'nullable', 'string', 'max:30'],

            // Engagement
            'engagement_nom' => ['sometimes', 'string', 'max:200'],
            'moyen_connaissance' => ['sometimes', 'nullable', 'string', 'max:100'],
            'moyen_connaissance_detail' => ['sometimes', 'nullable', 'string', 'max:150'],
        ];
    }
}
