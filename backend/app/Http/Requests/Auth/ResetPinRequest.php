<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Services\PinService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /v1/auth/candidat/reset-pin.
 *
 * Authentifié via le token court issu de verify-otp (ability `pin:reset`).
 * Le User est récupéré côté controller via `$request->user()`.
 *
 * On revalide le PIN avec PinService — la date de naissance n'est pas
 * disponible ici (pas de Candidature obligatoire à ce stade), donc seules
 * les règles format / blacklist / phone_suffix s'appliquent.
 */
final class ResetPinRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->tokenCan('pin:reset');
    }

    public function rules(): array
    {
        return [
            'pin' => ['required', 'string', 'regex:/^\d{6}$/'],
            'pin_confirmation' => ['required', 'string', 'same:pin'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($v->errors()->has('pin')) {
                return;
            }

            $pin = $this->input('pin', '');
            $phone = $this->user()?->phone_e164 ?? '';

            $result = app(PinService::class)->validate($pin, $phone, null);

            foreach ($result['reasons'] as $reason) {
                $v->errors()->add('pin', __('validation.'.$reason));
            }
        });
    }
}
