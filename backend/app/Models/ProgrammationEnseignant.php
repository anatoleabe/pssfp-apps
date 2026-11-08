<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Part d'un quota confiée à un enseignant pour la période (D22) :
 * proposée → validée → notifiée → acceptée ou déclinée (motif) ; annulée (motif) à toute étape.
 */
class ProgrammationEnseignant extends Model
{
    use HasUuids;
    use LogsActivity;

    public const PROPOSE = 'propose';

    public const VALIDE = 'valide';

    public const NOTIFIE = 'notifie';

    public const ACCEPTE = 'accepte';

    public const DECLINE = 'decline';

    public const ANNULE = 'annule';

    public const STATUTS = [
        self::PROPOSE => 'Proposée', self::VALIDE => 'Validée', self::NOTIFIE => 'Notifiée',
        self::ACCEPTE => 'Acceptée', self::DECLINE => 'Déclinée', self::ANNULE => 'Annulée',
    ];

    public const ROLES = ['responsable' => 'Responsable', 'intervenant' => 'Intervenant'];

    /** Statuts qui comptent dans la répartition du quota et la charge de l'enseignant. */
    public const ACTIVES = [self::PROPOSE, self::VALIDE, self::NOTIFIE, self::ACCEPTE];

    /** Statuts qui se modifient (la programmation repasse à « proposée »). */
    public const MODIFIABLES = [self::PROPOSE, self::VALIDE, self::DECLINE];

    protected $table = 'programmations_enseignant';

    protected $fillable = [];

    protected $attributes = ['statut' => self::PROPOSE, 'role' => 'intervenant', 'modalite' => 'hybride', 'heures_cm' => 0, 'heures_td' => 0, 'heures_tp' => 0];

    protected $casts = [
        'heures_cm' => 'float', 'heures_td' => 'float', 'heures_tp' => 'float',
        'debut_prevu' => 'immutable_date', 'fin_prevue' => 'immutable_date',
        'valide_at' => 'datetime', 'notifie_at' => 'datetime', 'repondu_at' => 'datetime',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('lmd')
            ->logOnly(['enseignant_id', 'role', 'heures_cm', 'heures_td', 'heures_tp', 'debut_prevu', 'fin_prevue', 'modalite', 'statut', 'motif'])
            ->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function quota(): BelongsTo
    {
        return $this->belongsTo(QuotaHoraire::class, 'quota_id');
    }

    public function enseignant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enseignant_id');
    }

    public function seances(): HasMany
    {
        return $this->hasMany(Seance::class, 'programmation_id');
    }

    public function total(): float
    {
        return round($this->heures_cm + $this->heures_td + $this->heures_tp, 1);
    }

    public function estActive(): bool
    {
        return in_array($this->statut, self::ACTIVES, true);
    }

    public function fenetre(): ?string
    {
        if ($this->debut_prevu === null && $this->fin_prevue === null) {
            return null;
        }

        return 'du '.($this->debut_prevu?->format('d/m/Y') ?? '…').' au '.($this->fin_prevue?->format('d/m/Y') ?? '…');
    }
}
