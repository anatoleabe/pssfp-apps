<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** ECUE confié à un enseignant (compte du back-office) : il en saisit le CC et l'assiduité. */
class AttributionEcue extends Model
{
    use LogsActivity;

    public const ROLES = ['responsable' => 'Responsable', 'intervenant' => 'Intervenant'];

    protected $table = 'attributions_ecue';

    protected $fillable = ['maquette_ecue_id', 'user_id', 'role'];

    protected $attributes = ['role' => 'responsable'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('lmd')->logOnly($this->fillable)->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function ecue(): BelongsTo
    {
        return $this->belongsTo(MaquetteEcue::class, 'maquette_ecue_id');
    }

    public function enseignant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
