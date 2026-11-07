<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** Règle d'assiduité d'une année (D12, D13) : temps requis à distance, retard toléré, absences justifiées. */
class RegleAssiduite extends Model
{
    use LogsActivity;

    protected $table = 'regles_assiduite';

    protected $fillable = ['annee_academique', 'temps_requis_pct', 'retard_tolere_min', 'justifiee_comptee', 'note_max'];

    protected $casts = ['justifiee_comptee' => 'boolean', 'note_max' => 'decimal:2', 'temps_requis_pct' => 'integer', 'retard_tolere_min' => 'integer'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('lmd')->logOnly($this->fillable)->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public static function pourAnnee(string $annee): self
    {
        return self::query()->firstOrCreate(['annee_academique' => $annee],
            ['temps_requis_pct' => 75, 'retard_tolere_min' => 15, 'justifiee_comptee' => true, 'note_max' => '20.00']);
    }
}
