<?php

declare(strict_types=1);

namespace App\Http\Requests\Scolarite;

use Illuminate\Foundation\Http\FormRequest;

/** Date de naissance saisie pour ouvrir le lien de la lettre d'accès (format du champ date du navigateur). */
final class LienLettreAccesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date_naissance' => ['required', 'string', 'date_format:Y-m-d'],
        ];
    }
}
