<?php

declare(strict_types=1);

namespace App\Http\Requests\Applications;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /v1/applications/me/submit.
 *
 * Body optionnel : `confirmation_engagement: true` (cf. P-min-3 PR C) pour
 * forcer l'utilisateur à confirmer une dernière fois avant la transition
 * irréversible.
 *
 * La validation stricte des champs profil + les règles métier sont gérées
 * dans CandidatureService::checkSubmittable() (cf. ajout 3 PR C). Le
 * controller renvoie 422 avec le détail si non submittable.
 */
final class SubmitCandidatureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null
            && $this->user()->tokenCan('application:submit');
    }

    public function rules(): array
    {
        return [
            'confirmation_engagement' => ['required', 'accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'confirmation_engagement.required' => 'La confirmation d\'engagement est requise pour soumettre la candidature.',
            'confirmation_engagement.accepted' => 'Vous devez confirmer votre engagement (true/1).',
        ];
    }
}
