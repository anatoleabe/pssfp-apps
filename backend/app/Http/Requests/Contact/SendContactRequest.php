<?php

declare(strict_types=1);

namespace App\Http\Requests\Contact;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /v1/contact (cf. spec module 1 PR O).
 *
 * Validation : nom, email, message obligatoires. phone, organisation,
 * subject optionnels. cf_turnstile_response = jeton Cloudflare Turnstile
 * vérifié côté serveur si TURNSTILE_SECRET configuré (optionnel V1).
 */
final class SendContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Endpoint public.
    }

    public function rules(): array
    {
        return [
            'nom' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:150'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'organisation' => ['sometimes', 'nullable', 'string', 'max:150'],
            'subject' => ['sometimes', 'nullable', 'string', 'max:200'],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
            'cf_turnstile_response' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'cgu' => ['required', 'accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'nom.required' => 'Le nom est obligatoire.',
            'email.required' => 'L\'email est obligatoire.',
            'email.email' => 'L\'email n\'est pas valide.',
            'message.required' => 'Le message est obligatoire.',
            'message.min' => 'Le message doit contenir au moins 10 caractères.',
            'cgu.required' => 'Vous devez accepter la politique de confidentialité.',
            'cgu.accepted' => 'Vous devez accepter la politique de confidentialité.',
        ];
    }
}
