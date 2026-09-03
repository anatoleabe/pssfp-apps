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

    /** Notification rédigée à la main depuis l'admin, par opposition aux relances automatiques. */
    public const CAUSE_MANUELLE = 'manuelle';

    public const CANAL_SMS = 'sms';

    public const CANAL_EMAIL = 'email';

    protected $fillable = [
        'candidature_id',
        'cause',
        'canal',
        'statut',
        'erreur',
        'message',
        'sujet',
        'envoye_par',
        'sent_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function candidature(): BelongsTo
    {
        return $this->belongsTo(Candidature::class);
    }

    /** Agent ayant déclenché une notification manuelle. Null pour les relances automatiques. */
    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'envoye_par');
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
