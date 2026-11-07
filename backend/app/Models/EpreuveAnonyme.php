<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Épreuve anonyme : un ECUE pour une session (D36). États changés par App\Services\Lmd\Anonymat seulement. */
class EpreuveAnonyme extends Model
{
    use HasUuids;

    public const STATUTS = ['preparation' => 'En préparation', 'anonymisee' => 'Anonymisée', 'verrouillee' => 'Verrouillée', 'levee' => 'Anonymat levé'];

    protected $table = 'epreuves_anonymes';

    protected $fillable = ['maquette_ecue_id', 'session'];

    protected $attributes = ['statut' => 'preparation'];

    protected $hidden = ['sel', 'verificateur'];

    protected $casts = [
        'anonymisee_at' => 'datetime',
        'verrouillee_at' => 'datetime',
        'autorisee_at' => 'datetime',
        'levee_at' => 'datetime',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function ecue(): BelongsTo
    {
        return $this->belongsTo(MaquetteEcue::class, 'maquette_ecue_id');
    }

    public function copies(): HasMany
    {
        return $this->hasMany(CopieAnonyme::class)->orderBy('ordre');
    }

    public function libelle(): string
    {
        return $this->ecue?->code.' — '.($this->session === 'rattrapage' ? 'rattrapage' : 'session normale');
    }
}
