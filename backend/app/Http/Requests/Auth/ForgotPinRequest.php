<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

final class ForgotPinRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'phone_e164' => ['required', 'string', 'regex:/^\+[1-9]\d{6,14}$/'],
            // Nullable : le widget peut être absent (clé non configurée) — la
            // décision d'accepter revient à TurnstileVerifier, pas au FormRequest.
            'turnstile_token' => ['nullable', 'string', 'max:2048'],
        ];
    }
}
