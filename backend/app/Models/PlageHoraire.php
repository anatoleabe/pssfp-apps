<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** Plage de la grille horaire (D21). */
class PlageHoraire extends Model
{
    use LogsActivity;

    protected $table = 'plages_horaires';

    protected $fillable = ['code', 'heure_debut', 'heure_fin', 'ordre'];

    protected $casts = ['ordre' => 'integer'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('lmd')->logOnly($this->fillable)->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function libelle(): string
    {
        return substr((string) $this->heure_debut, 0, 5).' – '.substr((string) $this->heure_fin, 0, 5);
    }
}
