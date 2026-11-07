<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Levée de la restriction « en règle » sur les documents d'une inscription (D9), décidée par le responsable. */
class LeveeRestriction extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'levees_restriction';

    protected $fillable = ['inscription_annuelle_id', 'motif', 'accordee_par'];

    public function accordeePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accordee_par');
    }
}
