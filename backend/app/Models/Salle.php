<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** Salle de cours (D21) : présentielle avec capacité, ou virtuelle avec lien de réunion permanent. */
class Salle extends Model
{
    use LogsActivity;

    public const NATURES = ['presentiel' => 'Présentiel', 'virtuelle' => 'Virtuelle'];

    protected $fillable = ['code', 'libelle', 'capacite', 'nature', 'lien_reunion', 'active'];

    protected $attributes = ['nature' => 'presentiel', 'active' => true];

    protected $casts = ['capacite' => 'integer', 'active' => 'boolean'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('lmd')->logOnly($this->fillable)->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function libelleComplet(): string
    {
        return $this->code.' — '.$this->libelle.($this->capacite ? ' ('.$this->capacite.' places)' : '');
    }
}
