<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** Modèle d'acte (D18, D19) : événement déclencheur, signataire, délai par rapport au début de l'événement, gabarit. */
class ModeleActe extends Model
{
    use LogsActivity;

    /** Champs remplacés dans le gabarit à la rédaction. */
    public const CHAMPS = ['objet', 'evenement', 'promotion', 'niveau', 'debut', 'fin', 'date_seance', 'annee', 'signataire'];

    protected $table = 'modeles_acte';

    protected $fillable = ['code', 'libelle', 'type_evenement', 'signataire', 'delai_jours', 'gabarit', 'diffusion_auditeurs', 'actif'];

    protected $casts = [
        'delai_jours' => 'integer',
        'diffusion_auditeurs' => 'boolean',
        'actif' => 'boolean',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('lmd')->logOnly($this->fillable)->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function actes(): HasMany
    {
        return $this->hasMany(Acte::class, 'modele_id');
    }

    public function delaiLibelle(): string
    {
        return match (true) {
            $this->delai_jours < 0 => abs($this->delai_jours).' j avant',
            $this->delai_jours > 0 => $this->delai_jours.' j après',
            default => 'le jour même',
        };
    }
}
