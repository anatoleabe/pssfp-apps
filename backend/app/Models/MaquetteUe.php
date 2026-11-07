<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * UE d'une maquette. Pondérations : CC, TP (au PSSFP la note de TP est la note
 * d'assiduité, D12) et examen ; leur somme vaut 1 (contrainte en base).
 * Partie « soutenance » : le mémoire, ouvert après la décision AS (D2).
 */
class MaquetteUe extends Model
{
    use HasFactory;

    public const ECRITE = 'ecrite';

    public const SOUTENANCE = 'soutenance';

    public const PARTIES = [
        self::ECRITE => 'Écrite',
        self::SOUTENANCE => 'Soutenance (mémoire)',
    ];

    protected $table = 'maquette_ues';

    protected $attributes = ['partie' => self::ECRITE];

    protected $fillable = ['maquette_id', 'unite_enseignement_id', 'semestre', 'credits', 'pcc', 'ptp', 'pexam', 'partie', 'ordre'];

    protected $casts = [
        'semestre' => 'integer',
        'credits' => 'integer',
        'pcc' => 'decimal:4',
        'ptp' => 'decimal:4',
        'pexam' => 'decimal:4',
        'ordre' => 'integer',
    ];

    public function maquette(): BelongsTo
    {
        return $this->belongsTo(Maquette::class);
    }

    public function ue(): BelongsTo
    {
        return $this->belongsTo(UniteEnseignement::class, 'unite_enseignement_id');
    }

    public function ecues(): HasMany
    {
        return $this->hasMany(MaquetteEcue::class)->orderBy('ordre')->orderBy('id');
    }

    public function heures(): int
    {
        return (int) $this->ecues->sum(fn (MaquetteEcue $e): int => $e->heuresTotales());
    }
}
