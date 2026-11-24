<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** Planning d'une session d'examens du calendrier (spec lot 27) : brouillon, puis publié. */
class PlanningExamens extends Model
{
    use HasUuids;
    use LogsActivity;

    public const BROUILLON = 'brouillon';

    public const PUBLIE = 'publie';

    public const STATUTS = [self::BROUILLON => 'Brouillon', self::PUBLIE => 'Publié'];

    /** Types d'événement du calendrier qui portent un planning. */
    public const TYPES_SESSION = ['examens', 'rattrapage'];

    protected $table = 'plannings_examens';

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
        return LogOptions::defaults()->useLogName('lmd')->logOnly(['statut', 'session_id'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(EvenementCalendrier::class, 'session_id');
    }

    public function epreuves(): HasMany
    {
        return $this->hasMany(EpreuveExamen::class)->orderBy('date_epreuve')->orderBy('debut');
    }

    public function estPublie(): bool
    {
        return $this->statut === self::PUBLIE;
    }

    /** Session de rattrapage : seuls les éligibles composent. */
    public function estRattrapage(): bool
    {
        return $this->session?->type === 'rattrapage' || $this->session?->session === EtapeSaisie::RATTRAPAGE;
    }

    public function libelle(): string
    {
        return (string) $this->session?->libelle;
    }
}
