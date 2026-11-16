<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Trace, en ajout seul, d'un ancien auditeur réinscrit depuis son dossier d'archive ACMA (spec de la réinscription
 * des anciens, §6.2). Écrite par `ReinscriptionAncien` seul ; la base refuse toute modification.
 */
class ReinscriptionAcma extends Model
{
    use HasUuids;

    public const CREATION = 'creation';

    public const CONVERSION = 'conversion';

    public const ATTRIBUTION = 'attribution';

    public const UPDATED_AT = null;

    protected $table = 'reinscriptions_acma';

    protected $guarded = ['*'];

    protected $casts = [
        'derogation_m2' => 'boolean',
        'coordonnees_modifiees' => 'array',
        'created_at' => 'immutable_datetime',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function dossierAcma(): BelongsTo
    {
        return $this->belongsTo(AcmaDossier::class, 'dossier_acma_id');
    }

    public function candidature(): BelongsTo
    {
        return $this->belongsTo(Candidature::class);
    }

    public function auditeur(): BelongsTo
    {
        return $this->belongsTo(Auditeur::class);
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'auteur_id');
    }
}
