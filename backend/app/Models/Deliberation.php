<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Délibération d'une année, d'un niveau et d'une session (D35). Les états ne
 * changent que par App\Services\Lmd\Deliberations : simulation → arrêtée
 * (→ rectifiée quand sa rectification est arrêtée).
 */
class Deliberation extends Model
{
    use HasUuids;

    public const SIMULATION = 'simulation';

    public const ARRETEE = 'arretee';

    public const RECTIFIEE = 'rectifiee';

    public const STATUTS = [self::SIMULATION => 'En préparation', self::ARRETEE => 'Arrêtée', self::RECTIFIEE => 'Rectifiée'];

    protected $fillable = ['annee_academique', 'niveau', 'parcours_id', 'session', 'jury_id', 'date_deliberation'];

    protected $attributes = ['statut' => self::SIMULATION];

    protected $casts = [
        'date_deliberation' => 'immutable_date',
        'seance_ouverte_at' => 'datetime',
        'quorum_atteint' => 'boolean',
        'arretee_at' => 'datetime',
        'publiee_at' => 'datetime',
        'requetes_jusqu_au' => 'immutable_date',
        'a_rectifier_at' => 'datetime',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function jury(): BelongsTo
    {
        return $this->belongsTo(Jury::class);
    }

    public function parcours(): BelongsTo
    {
        return $this->belongsTo(Parcours::class);
    }

    public function scenarios(): HasMany
    {
        return $this->hasMany(Scenario::class)->orderBy('id');
    }

    public function scenarioRetenu(): HasOne
    {
        return $this->hasOne(Scenario::class)->where('retenu', true);
    }

    public function mesures(): HasManyThrough
    {
        return $this->hasManyThrough(MesureJury::class, Scenario::class);
    }

    public function participations(): HasMany
    {
        return $this->hasMany(ParticipationDeliberation::class);
    }

    public function resultats(): HasMany
    {
        return $this->hasMany(ResultatAnnuel::class);
    }

    public function rectifiee(): BelongsTo
    {
        return $this->belongsTo(self::class, 'rectifie_id');
    }

    public function rectification(): HasOne
    {
        return $this->hasOne(self::class, 'rectifie_id');
    }

    public function arreteePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'arretee_par');
    }

    public function estModifiable(): bool
    {
        return $this->statut === self::SIMULATION;
    }

    public function libelle(): string
    {
        return sprintf('%s · %s%s · %s', $this->annee_academique, $this->niveau,
            $this->parcours ? ' '.$this->parcours->code : '', EtapeSaisie::SESSIONS[$this->session] ?? $this->session);
    }
}
