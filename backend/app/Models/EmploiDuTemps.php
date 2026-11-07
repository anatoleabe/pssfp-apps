<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** Emploi du temps d'une période de cours pour un parcours (D21) : brouillon, puis publié. */
class EmploiDuTemps extends Model
{
    use HasUuids;
    use LogsActivity;

    public const BROUILLON = 'brouillon';

    public const PUBLIE = 'publie';

    public const STATUTS = [self::BROUILLON => 'Brouillon', self::PUBLIE => 'Publié'];

    protected $table = 'emplois_du_temps';

    protected $fillable = [];

    protected $attributes = ['statut' => self::BROUILLON];

    protected $casts = ['publie_at' => 'datetime'];

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
        return LogOptions::defaults()->useLogName('lmd')->logOnly(['statut', 'periode_id', 'parcours_id'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function periode(): BelongsTo
    {
        return $this->belongsTo(EvenementCalendrier::class, 'periode_id');
    }

    public function parcours(): BelongsTo
    {
        return $this->belongsTo(Parcours::class);
    }

    public function seances(): HasMany
    {
        return $this->hasMany(Seance::class, 'emploi_du_temps_id');
    }

    public function libelle(): string
    {
        return ($this->parcours?->code ?? '').' — '.($this->periode?->libelle ?? '');
    }
}
