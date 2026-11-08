<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** Quota horaire d'un ECUE pour une période de cours (D22) : proposé, validé, modifié (avec motif). */
class QuotaHoraire extends Model
{
    use HasUuids;
    use LogsActivity;

    public const PROPOSE = 'propose';

    public const VALIDE = 'valide';

    public const MODIFIE = 'modifie';

    public const STATUTS = [self::PROPOSE => 'Proposé', self::VALIDE => 'Validé', self::MODIFIE => 'Modifié'];

    protected $table = 'quotas_horaires';

    protected $fillable = [];

    protected $attributes = ['statut' => self::PROPOSE, 'heures_cm' => 0, 'heures_td' => 0, 'heures_tp' => 0];

    protected $casts = ['heures_cm' => 'float', 'heures_td' => 'float', 'heures_tp' => 'float', 'valide_at' => 'datetime'];

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
        return LogOptions::defaults()->useLogName('lmd')->logOnly(['heures_cm', 'heures_td', 'heures_tp', 'statut', 'motif_modification'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function periode(): BelongsTo
    {
        return $this->belongsTo(EvenementCalendrier::class, 'periode_id');
    }

    public function ecue(): BelongsTo
    {
        return $this->belongsTo(MaquetteEcue::class, 'maquette_ecue_id');
    }

    public function programmations(): HasMany
    {
        return $this->hasMany(ProgrammationEnseignant::class, 'quota_id');
    }

    public function total(): float
    {
        return round($this->heures_cm + $this->heures_td + $this->heures_tp, 1);
    }

    public function estValide(): bool
    {
        return $this->statut !== self::PROPOSE;
    }
}
