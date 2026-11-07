<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Projection d'un scénario pour un auditeur : crédits, décision, moyenne, MGP, rang, mention. */
class ResultatScenario extends Model
{
    public $timestamps = false;

    protected $table = 'resultats_scenario';

    protected $guarded = ['id'];

    protected $casts = [
        'moyenne' => 'decimal:3',
        'mgp' => 'decimal:2',
        'felicitations_proposees' => 'boolean',
    ];

    public function inscriptionAnnuelle(): BelongsTo
    {
        return $this->belongsTo(InscriptionAnnuelle::class);
    }
}
