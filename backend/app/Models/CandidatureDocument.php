<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pièce justificative optionnelle d'un dossier candidature (diplôme, acte de
 * naissance, relevés de notes, CV, lettre de motivation, attestation
 * employeur). Cf. migration pour le détail des types et la justification du
 * caractère non-bloquant.
 */
class CandidatureDocument extends Model
{
    use HasFactory;
    use HasUuids;

    public const TYPE_DIPLOME = 'diplome';

    public const TYPE_ACTE_NAISSANCE = 'acte_naissance';

    public const TYPE_RELEVES_NOTES = 'releves_notes';

    public const TYPE_CV = 'cv';

    public const TYPE_LETTRE_MOTIVATION = 'lettre_motivation';

    public const TYPE_ATTESTATION_EMPLOYEUR = 'attestation_employeur';

    public const TYPE_AUTRE = 'autre';

    public const TYPES = [
        self::TYPE_DIPLOME,
        self::TYPE_ACTE_NAISSANCE,
        self::TYPE_RELEVES_NOTES,
        self::TYPE_CV,
        self::TYPE_LETTRE_MOTIVATION,
        self::TYPE_ATTESTATION_EMPLOYEUR,
        self::TYPE_AUTRE,
    ];

    protected $fillable = [
        'candidature_id',
        'type',
        'original_filename',
        'mime',
        'size',
        'path',
    ];

    protected $casts = [
        'size' => 'integer',
    ];

    /** Le `id` reste BIGSERIAL ; HasUuids génère uniquement le champ `uuid`. */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function candidature(): BelongsTo
    {
        return $this->belongsTo(Candidature::class);
    }
}
