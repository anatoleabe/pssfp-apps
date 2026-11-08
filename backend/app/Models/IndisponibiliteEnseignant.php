<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** Indisponibilité déclarée d'un enseignant (D22) : aucune séance pendant. */
class IndisponibiliteEnseignant extends Model
{
    use LogsActivity;

    protected $table = 'indisponibilites_enseignant';

    protected $fillable = ['enseignant_id', 'debut', 'fin', 'motif'];

    protected $casts = ['debut' => 'immutable_datetime', 'fin' => 'immutable_datetime'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('lmd')->logOnly($this->fillable)->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function enseignant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enseignant_id');
    }

    /** La séance (heures locales de Douala) chevauche-t-elle l'indisponibilité ? */
    public function couvre(Seance $s): bool
    {
        $jour = $s->date_seance->toDateString();
        $debut = CarbonImmutable::parse($jour.' '.substr((string) $s->debut, 0, 5), 'Africa/Douala');
        $fin = CarbonImmutable::parse($jour.' '.substr((string) $s->fin, 0, 5), 'Africa/Douala');

        return $debut->lt($this->fin) && $fin->gt($this->debut);
    }

    /** Dates seules : le motif, parfois personnel, ne sort pas de la liste des indisponibilités. */
    public function libelle(): string
    {
        $f = 'd/m/Y H:i';

        return 'du '.$this->debut->timezone('Africa/Douala')->format($f).' au '.$this->fin->timezone('Africa/Douala')->format($f);
    }
}
