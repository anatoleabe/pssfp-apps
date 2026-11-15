<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Document délivré par ACMA, listé sans fichier (lot 15, §7.9). */
class AcmaDocument extends Model
{
    protected $table = 'acma_documents';

    protected $guarded = ['*'];

    public const TYPES = [
        'certificat' => 'Certificat',
        'attestation_reussite' => 'Attestation de réussite',
        'lettre_stage' => 'Lettre de stage',
        'non_perception_bourse' => 'Attestation de non-perception de bourse',
        'mise_a_disposition' => 'Mise à disposition',
    ];

    protected $casts = [
        'date' => 'immutable_date',
        'details' => 'array',
    ];
}
