<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Services\PinService;
use Carbon\Carbon;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /v1/auth/candidat/register.
 *
 * Scope minimal (cf. arbitrage B plan PR B) : crée le User candidat,
 * la Candidature elle-même est créée plus tard via /v1/applications/me (PR C).
 *
 * date_naissance est requis ici car PinService rejette le PIN = DDMMYY/YYMMDD.
 */
final class RegisterCandidatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'phone_e164' => ['required', 'string', 'regex:/^\+[1-9]\d{6,14}$/', 'unique:users,phone_e164'],
            'phone_country' => ['required', 'string', 'size:2'],
            'pin' => ['required', 'string', 'regex:/^\d{6}$/'],
            'pin_confirmation' => ['required', 'string', 'same:pin'],
            'nom' => ['required', 'string', 'max:100'],
            'prenom' => ['required', 'string', 'max:100'],
            'date_naissance' => ['required', 'date', 'before:'.now()->subYears(18)->toDateString()],
            'cgu' => ['required', 'accepted'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($v->errors()->has('pin')) {
                return; // format invalide, inutile d'enchaîner
            }

            $pin = $this->input('pin', '');
            $phone = $this->input('phone_e164', '');
            $dob = $this->input('date_naissance');
            $dobCarbon = $dob ? Carbon::parse($dob) : null;

            $result = app(PinService::class)->validate($pin, $phone, $dobCarbon);

            foreach ($result['reasons'] as $reason) {
                $v->errors()->add('pin', __('validation.'.$reason));
            }
        });
    }

    public function messages(): array
    {
        return [
            'phone_e164.regex' => 'Le numéro de téléphone doit être au format E.164 (ex: +237691234567).',
            'phone_e164.unique' => 'Ce numéro de téléphone est déjà enregistré. Connectez-vous.',
            'pin.regex' => 'Le PIN doit contenir exactement 6 chiffres.',
            'pin_confirmation.same' => 'La confirmation du PIN ne correspond pas.',
            'date_naissance.before' => 'Vous devez avoir au moins 18 ans pour candidater.',
            'cgu.accepted' => 'Vous devez accepter les CGU pour vous inscrire.',
        ];
    }
}
