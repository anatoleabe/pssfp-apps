<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

final class VerifyOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'phone_e164' => ['required', 'string', 'regex:/^\+[1-9]\d{6,14}$/'],
            'code' => ['required', 'string', 'regex:/^\d{6}$/'],
        ];
    }
}
