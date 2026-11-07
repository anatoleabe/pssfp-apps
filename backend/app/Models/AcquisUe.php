<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * UE capitalisée d'un auditeur (D35) : acquise définitivement. Écrite à l'arrêt
 * d'une délibération ; retirée seulement par l'arrêt de sa rectification.
 */
class AcquisUe extends Model
{
    public const ORIGINES = [
        'evaluation' => 'Acquise',
        'deliberation' => 'Acquise par décision du jury',
        'reprise' => 'Reprise d\'ACMA',
        'correspondance' => 'Équivalence',
    ];

    protected $table = 'acquis_ue';

    protected $guarded = ['id'];

    protected $casts = ['note_retenue' => 'decimal:3', 'credits' => 'integer'];

    public function ue(): BelongsTo
    {
        return $this->belongsTo(UniteEnseignement::class, 'unite_enseignement_id');
    }

    public function maquetteUe(): BelongsTo
    {
        return $this->belongsTo(MaquetteUe::class);
    }

    public function deliberation(): BelongsTo
    {
        return $this->belongsTo(Deliberation::class);
    }
}
