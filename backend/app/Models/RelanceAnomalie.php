<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Relance d'une anomalie du contrôle interne (lot 14b-2, D30). Ajout seul ; seul App\Services\Supervision\Relances écrit ici. */
class RelanceAnomalie extends Model
{
    public $timestamps = false;

    protected $table = 'relances_anomalies';

    protected $fillable = ['anomalie_id', 'niveau', 'destinataire_role', 'canaux', 'message_id', 'destinataires', 'cree_le'];

    protected $casts = ['canaux' => 'array', 'cree_le' => 'immutable_datetime', 'niveau' => 'integer', 'destinataires' => 'integer'];

    public function anomalie(): BelongsTo
    {
        return $this->belongsTo(AnomalieSysteme::class, 'anomalie_id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
