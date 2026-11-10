<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Anomalie détectée par un contrôle du contrôle interne (lot 14a, D30). Seul App\Services\Supervision\Supervision écrit ici. */
class AnomalieSysteme extends Model
{
    use HasUuids;

    public const OUVERTE = 'ouverte';

    public const EN_TRAITEMENT = 'en_traitement';

    public const CORRIGEE = 'corrigee';

    public const JUSTIFIEE = 'justifiee';

    public const STATUTS = [self::OUVERTE => 'Ouverte', self::EN_TRAITEMENT => 'En traitement', self::CORRIGEE => 'Corrigée', self::JUSTIFIEE => 'Justifiée'];

    /** Anomalie encore « vivante » : une seule par cas (index unique partiel). */
    public const ACTIVES = [self::OUVERTE, self::EN_TRAITEMENT, self::JUSTIFIEE];

    /** À traiter : ni corrigée ni justifiée. */
    public const A_TRAITER = [self::OUVERTE, self::EN_TRAITEMENT];

    protected $table = 'anomalies_systeme';

    protected $fillable = ['controle_code', 'cle', 'detail', 'premiere_detection', 'derniere_detection', 'echeance', 'statut'];

    protected $casts = [
        'detail' => 'array', 'premiere_detection' => 'datetime', 'derniere_detection' => 'datetime', 'echeance' => 'immutable_date',
        'traite_le' => 'datetime', 'fermee_auto' => 'boolean',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @param  Builder<AnomalieSysteme>  $query */
    public function scopeATraiter(Builder $query): void
    {
        $query->whereIn('statut', self::A_TRAITER);
    }

    public function assigneA(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigne_a');
    }

    public function traitePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'traite_par');
    }
}
