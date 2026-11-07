<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Projection d'un scénario pour une UE d'un auditeur. */
class ResultatScenarioUe extends Model
{
    public $timestamps = false;

    protected $table = 'resultats_scenario_ue';

    protected $guarded = ['id'];

    protected $casts = [
        'note_normale' => 'decimal:3',
        'note_retenue' => 'decimal:3',
        'note_jury' => 'decimal:3',
        'eliminatoire' => 'boolean',
        'capitalisee' => 'boolean',
        'acquise_regle' => 'boolean',
        'acquise' => 'boolean',
    ];

    public function maquetteUe(): BelongsTo
    {
        return $this->belongsTo(MaquetteUe::class);
    }

    public function mesure(): BelongsTo
    {
        return $this->belongsTo(MesureJury::class, 'mesure_id');
    }
}
