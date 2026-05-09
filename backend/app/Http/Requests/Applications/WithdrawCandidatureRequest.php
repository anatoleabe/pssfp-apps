<?php

declare(strict_types=1);

namespace App\Http\Requests\Applications;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /v1/applications/me/withdraw.
 *
 * Le candidat doit confirmer explicitement le retrait pour éviter les
 * actions accidentelles. Aucun motif requis (le candidat n'a pas à se
 * justifier — un retrait administratif super_admin est traité ailleurs
 * via Filament avec motif obligatoire, cf. PR D).
 */
final class WithdrawCandidatureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->tokenCan('application:create');
    }

    public function rules(): array
    {
        return [
            'confirmation' => ['required', 'accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'confirmation.required' => 'La confirmation du retrait est requise.',
            'confirmation.accepted' => 'Vous devez confirmer le retrait pour le valider.',
        ];
    }
}
