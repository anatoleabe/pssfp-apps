<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Émargement d'une séance par l'enseignant qui a fait cours (D23) : arrivée, départ, nature, cahier de textes.
 * À valider → validé (heures figées, séance « tenue ») ou rejeté (motif). Payé (lot 13b) : figé.
 */
class PointageEnseignant extends Model
{
    use HasUuids;
    use LogsActivity;

    public const A_VALIDER = 'a_valider';

    public const VALIDE = 'valide';

    public const REJETE = 'rejete';

    public const STATUTS = [self::A_VALIDER => 'À valider', self::VALIDE => 'Validé', self::REJETE => 'Rejeté'];

    public const MODES = ['signature' => 'Signature (back-office)', 'badge' => 'Badge', 'qr_code' => 'QR code', 'zoom' => 'Zoom', 'saisie' => 'Saisie par la Scolarité'];

    /** Modes d'une saisie par la Scolarité (la signature est celle de l'enseignant lui-même). */
    public const MODES_SAISIE = ['saisie' => 'Saisie par la Scolarité', 'badge' => 'Badge', 'qr_code' => 'QR code', 'zoom' => 'Zoom'];

    public const NATURES = ['CM' => 'Cours magistral', 'TD' => 'Travaux dirigés', 'TP' => 'Travaux pratiques'];

    protected $table = 'pointages_enseignant';

    protected $fillable = [];

    protected $attributes = ['statut' => self::A_VALIDER, 'nature' => 'CM'];

    protected $casts = [
        'arrivee' => 'datetime', 'depart' => 'datetime', 'valide_at' => 'datetime',
        'confirme_delegue' => 'boolean', 'minutes_payables' => 'integer',
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
            ->logOnly(['enseignant_id', 'remplace_enseignant_id', 'mode', 'arrivee', 'depart', 'nature', 'confirme_delegue', 'statut', 'minutes_payables', 'motif_rejet', 'ligne_paie_id'])
            ->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function seance(): BelongsTo
    {
        return $this->belongsTo(Seance::class);
    }

    public function enseignant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enseignant_id');
    }

    public function remplace(): BelongsTo
    {
        return $this->belongsTo(User::class, 'remplace_enseignant_id');
    }

    public function saisiPar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'saisi_par');
    }

    public function estPaye(): bool
    {
        return $this->ligne_paie_id !== null;
    }

    /** Durée effective en minutes (départ moins arrivée) ; null si incomplet. */
    public function minutesEffectives(): ?int
    {
        if ($this->arrivee === null || $this->depart === null) {
            return null;
        }

        return (int) $this->arrivee->diffInMinutes($this->depart);
    }

    /** Heures payables figées à la validation. */
    public function heures(): ?float
    {
        return $this->minutes_payables === null ? null : round($this->minutes_payables / 60, 2);
    }

    public function horaires(): ?string
    {
        if ($this->arrivee === null || $this->depart === null) {
            return null;
        }
        $tz = 'Africa/Douala';

        return $this->arrivee->setTimezone($tz)->format('H:i').' – '.$this->depart->setTimezone($tz)->format('H:i');
    }
}
