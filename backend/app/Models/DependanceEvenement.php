<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Lien du Gantt entre deux événements (D21) : écart minimal et maximal en jours, bloquant ou simple alerte. */
class DependanceEvenement extends Model
{
    public const TYPES = ['fin_debut' => 'Fin → début', 'debut_debut' => 'Début → début', 'fin_fin' => 'Fin → fin', 'debut_fin' => 'Début → fin'];

    protected $table = 'dependances_evenement';

    protected $fillable = ['predecesseur_id', 'successeur_id', 'type_lien', 'decalage_min_jours', 'decalage_max_jours', 'regle', 'bloquant'];

    protected $casts = ['bloquant' => 'boolean', 'decalage_min_jours' => 'integer', 'decalage_max_jours' => 'integer'];

    public function predecesseur(): BelongsTo
    {
        return $this->belongsTo(EvenementCalendrier::class, 'predecesseur_id');
    }

    public function successeur(): BelongsTo
    {
        return $this->belongsTo(EvenementCalendrier::class, 'successeur_id');
    }
}
