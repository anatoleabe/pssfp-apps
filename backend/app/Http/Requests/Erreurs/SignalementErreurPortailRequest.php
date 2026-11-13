<?php

declare(strict_types=1);

namespace App\Http\Requests\Erreurs;

use App\Http\Middleware\IpVisiteurDuPortail;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Écran d'erreur signalé par le portail apply. Accepté du seul serveur du
 * portail (secret partagé), avec des champs bornés : l'adresse n'est pas une
 * boîte de dépôt ouverte.
 */
final class SignalementErreurPortailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->attributes->get(IpVisiteurDuPortail::ATTRIBUT_PORTAIL) === true;
    }

    public function rules(): array
    {
        return [
            'page' => ['required', 'string', 'max:500'],
            'message' => ['required', 'string', 'max:1000'],
            'digest' => ['nullable', 'string', 'max:64'],
            'navigateur' => ['nullable', 'string', 'max:300'],
        ];
    }
}
