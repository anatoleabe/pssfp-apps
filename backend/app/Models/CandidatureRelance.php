<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\RelanceCandidatureService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une relance envoyée à un candidat dont le dossier n'avance plus.
 *
 * @see RelanceCandidatureService
 */
class CandidatureRelance extends Model
{
    public const STATUT_ENVOYE = 'envoye';

    public const STATUT_ECHEC = 'echec';

    protected $fillable = [
        'candidature_id',
        'cause',
        'canal',
        'statut',
        'erreur',
        'sent_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function candidature(): BelongsTo
    {
        return $this->belongsTo(Candidature::class);
    }

    /**
     * Relances effectivement parties. Un échec ne doit pas empêcher une
     * nouvelle tentative : seuls les envois réussis bloquent le renvoi.
     */
    public function scopeAbouties(Builder $query): Builder
    {
        return $query->where('statut', self::STATUT_ENVOYE);
    }
}
