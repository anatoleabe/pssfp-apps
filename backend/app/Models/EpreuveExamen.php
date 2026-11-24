<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Épreuve d'un planning d'examens (spec lot 27) : un code d'ECUE — commun à plusieurs options le cas
 * échéant —, une date, des heures, des salles ordonnées et leurs surveillants.
 * Ne s'écrit que par App\Services\Lmd\PlanningsExamens.
 */
class EpreuveExamen extends Model
{
    use HasUuids;
    use LogsActivity;

    protected $table = 'epreuves_examen';

    protected $fillable = [];

    protected $casts = ['date_epreuve' => 'immutable_date'];

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
        return LogOptions::defaults()->useLogName('lmd')->logOnly(['code_ecue', 'date_epreuve', 'debut', 'fin'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function planning(): BelongsTo
    {
        return $this->belongsTo(PlanningExamens::class, 'planning_examens_id');
    }

    public function salles(): BelongsToMany
    {
        return $this->belongsToMany(Salle::class, 'epreuve_examen_salle')->withPivot('ordre')->orderByPivot('ordre');
    }

    public function surveillants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'epreuve_examen_surveillant')->withPivot('salle_id')->orderBy('name');
    }

    public function heures(): string
    {
        return substr((string) $this->debut, 0, 5).' – '.substr((string) $this->fin, 0, 5);
    }

    public function chevauche(self $autre): bool
    {
        return $this->date_epreuve->equalTo($autre->date_epreuve)
            && strcmp(substr((string) $this->debut, 0, 5), substr((string) $autre->fin, 0, 5)) < 0
            && strcmp(substr((string) $autre->debut, 0, 5), substr((string) $this->fin, 0, 5)) < 0;
    }

    public function libelle(): string
    {
        return $this->code_ecue.' — '.$this->date_epreuve?->format('d/m/Y').' '.substr((string) $this->debut, 0, 5).'-'.substr((string) $this->fin, 0, 5);
    }
}
