<?php

declare(strict_types=1);

namespace App\Http\Requests\Applications;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /v1/applications/me/photo (multipart/form-data).
 *
 * Validation (cf. spec module 5 PR G) :
 * - mimes: jpg/jpeg/png
 * - max  : 2048 KB (= 2 Mo)
 * - dimensions min_width=200,min_height=200
 *
 * La validation du contenu (antivirus) est asynchrone via ScanUploadedPhotoJob.
 */
final class UploadPhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null
            && $this->user()->tokenCan('application:create');
    }

    public function rules(): array
    {
        return [
            'photo' => [
                'required',
                'file',
                'image',
                // mimes vérifie l'extension ; mimetypes durcit en validant
                // le Content-Type/MIME réel issu de getimagesize (cf. revue
                // sécu PR G — défense en profondeur contre polyglot).
                'mimes:jpg,jpeg,png',
                'mimetypes:image/jpeg,image/png',
                'max:2048',
                'dimensions:min_width=200,min_height=200',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'photo.required' => 'La photo est obligatoire.',
            'photo.image' => 'Le fichier envoyé n\'est pas une image valide.',
            'photo.mimes' => 'Seuls les formats JPG et PNG sont acceptés.',
            'photo.max' => 'La photo ne doit pas dépasser 2 Mo.',
            'photo.dimensions' => 'La photo doit faire au moins 200×200 pixels.',
        ];
    }
}
