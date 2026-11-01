<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/** Connexion d'un auditeur avec ses identifiants FOAD (matricule et mot de passe Moodle). */
final class LoginMatriculeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // « 26EFP001 », saisi en majuscules ou en minuscules (identifiant Moodle).
            'identifiant' => ['required', 'string', 'max:20', 'regex:/^\d{2}[A-Za-z]{2,6}\d{2,4}$/'],
            'mot_de_passe' => ['required', 'string', 'max:100'],
        ];
    }
}
