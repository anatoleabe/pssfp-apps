<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Élément constitutif d'une UE : crédits et volumes horaires (CM, TD, TP, TPE). */
class MaquetteEcue extends Model
{
    use HasFactory;

    public const HEURES_PAR_CREDIT = 15;

    protected $table = 'maquette_ecues';

    protected $fillable = ['maquette_ue_id', 'code', 'intitule', 'credits', 'heures_cm', 'heures_td', 'heures_tp', 'heures_tpe', 'ordre'];

    protected $casts = [
        'credits' => 'integer',
        'heures_cm' => 'integer',
        'heures_td' => 'integer',
        'heures_tp' => 'integer',
        'heures_tpe' => 'integer',
        'ordre' => 'integer',
    ];

    public function maquetteUe(): BelongsTo
    {
        return $this->belongsTo(MaquetteUe::class);
    }

    public function heuresTotales(): int
    {
        return $this->heures_cm + $this->heures_td + $this->heures_tp + $this->heures_tpe;
    }

    public function etapes(): HasMany
    {
        return $this->hasMany(EtapeSaisie::class);
    }

    public function attributions(): HasMany
    {
        return $this->hasMany(AttributionEcue::class);
    }

    public function libelle(): string
    {
        return $this->code.' — '.$this->intitule;
    }
}
