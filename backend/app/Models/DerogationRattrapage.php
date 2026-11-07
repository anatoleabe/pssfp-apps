<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Dérogation à l'éligibilité au rattrapage (D11) : autorité, acte, motif. */
class DerogationRattrapage extends Model
{
    protected $table = 'derogations_rattrapage';

    protected $fillable = ['inscription_pedagogique_id', 'accordee_par', 'reference_acte', 'date_acte', 'motif', 'saisie_par'];

    protected $casts = ['date_acte' => 'immutable_date'];

    public function inscriptionPedagogique(): BelongsTo
    {
        return $this->belongsTo(InscriptionPedagogique::class);
    }
}
