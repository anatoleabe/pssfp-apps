<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Scénario d'une délibération : base stricte ou variante avec mesures du jury (D35). */
class Scenario extends Model
{
    protected $fillable = ['deliberation_id', 'libelle'];

    protected $casts = [
        'retenu' => 'boolean',
        'calcule_at' => 'datetime',
        'taux_reussite' => 'decimal:2',
    ];

    public function deliberation(): BelongsTo
    {
        return $this->belongsTo(Deliberation::class);
    }

    public function mesures(): HasMany
    {
        return $this->hasMany(MesureJury::class)->orderBy('ordre')->orderBy('id');
    }

    public function resultats(): HasMany
    {
        return $this->hasMany(ResultatScenario::class);
    }

    public function resultatsUe(): HasMany
    {
        return $this->hasMany(ResultatScenarioUe::class);
    }
}
