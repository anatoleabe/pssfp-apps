<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Parcours LMD : tronc commun du M1 (TC) ou option du M2 (FFCP, GTFP, ACFP, EPGP, MPP).
 * `specialite` porte l'intitulé de la spécialité du dossier de candidature (config/specialites.php).
 */
class Parcours extends Model
{
    use HasFactory;
    use LogsActivity;

    public const TRONC_COMMUN = 'TC';

    protected $table = 'parcours';

    protected $fillable = ['code', 'libelle', 'niveau', 'specialite', 'actif', 'ordre'];

    protected $casts = [
        'actif' => 'boolean',
        'ordre' => 'integer',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('lmd')->logOnly($this->fillable)->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function maquettes(): HasMany
    {
        return $this->hasMany(Maquette::class);
    }

    public function libelleComplet(): string
    {
        return $this->niveau.' — '.$this->libelle.($this->code !== self::TRONC_COMMUN ? ' ('.$this->code.')' : '');
    }
}
