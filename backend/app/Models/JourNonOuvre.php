<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** Jour non ouvré (fête légale, fermeture) : ni séance ni examen (D21). */
class JourNonOuvre extends Model
{
    use LogsActivity;

    protected $table = 'jours_non_ouvres';

    protected $fillable = ['jour', 'libelle'];

    protected $casts = ['jour' => 'immutable_date'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('lmd')->logOnly($this->fillable)->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
