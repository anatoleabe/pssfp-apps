<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** Classe confiée à un point focal (spec lot 16b) : un parcours pour une année académique. */
class AffectationPointFocal extends Model
{
    use LogsActivity;

    protected $table = 'affectations_points_focaux';

    protected $fillable = ['user_id', 'parcours_id', 'annee_academique'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('lmd')->logOnly($this->fillable)->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function pointFocal(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function parcours(): BelongsTo
    {
        return $this->belongsTo(Parcours::class);
    }
}
