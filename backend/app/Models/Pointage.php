<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Pointage d'un auditeur à une séance : présent, absent ou absence justifiée (motivée). */
class Pointage extends Model
{
    public const STATUTS = ['present' => 'Présent', 'absent' => 'Absent', 'justifie' => 'Absence justifiée'];

    protected $guarded = ['id'];

    public function seance(): BelongsTo
    {
        return $this->belongsTo(Seance::class);
    }

    public function inscriptionPedagogique(): BelongsTo
    {
        return $this->belongsTo(InscriptionPedagogique::class);
    }
}
