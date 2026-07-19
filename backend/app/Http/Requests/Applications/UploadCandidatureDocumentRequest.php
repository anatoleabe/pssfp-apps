<?php

declare(strict_types=1);

namespace App\Http\Requests\Applications;

use App\Models\CandidatureDocument;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /v1/applications/me/documents (multipart/form-data).
 *
 * Pièce justificative optionnelle (cf. migration candidature_documents) :
 * - type  : un des CandidatureDocument::TYPES.
 * - fichier : PDF ou image scannée, max 5 Mo.
 *
 * La validation du contenu (antivirus) est asynchrone via ScanUploadedDocumentJob.
 */
final class UploadCandidatureDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null
            && $this->user()->tokenCan('application:create');
    }

    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(CandidatureDocument::TYPES)],
            'fichier' => [
                'required',
                'file',
                'mimes:pdf,jpg,jpeg,png',
                'mimetypes:application/pdf,image/jpeg,image/png',
                'max:5120',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'type.required' => 'Le type de pièce est obligatoire.',
            'type.in' => 'Type de pièce inconnu.',
            'fichier.required' => 'Le fichier est obligatoire.',
            'fichier.mimes' => 'Seuls les formats PDF, JPG et PNG sont acceptés.',
            'fichier.max' => 'Le fichier ne doit pas dépasser 5 Mo.',
        ];
    }
}
