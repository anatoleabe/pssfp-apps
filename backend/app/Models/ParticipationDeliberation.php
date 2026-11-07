<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Présence d'un membre à la séance (motif de l'absence) et signature du PV. */
class ParticipationDeliberation extends Model
{
    protected $table = 'participations_deliberation';

    protected $fillable = ['deliberation_id', 'membre_jury_id', 'present', 'motif_absence'];

    protected $casts = ['present' => 'boolean', 'signe_at' => 'datetime'];

    public function membre(): BelongsTo
    {
        return $this->belongsTo(MembreJury::class, 'membre_jury_id');
    }

    public function deliberation(): BelongsTo
    {
        return $this->belongsTo(Deliberation::class);
    }
}
