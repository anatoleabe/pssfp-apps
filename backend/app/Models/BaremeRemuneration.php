<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** Barème de rémunération des enseignants, par année académique et par nature (D24, D25). Seul un barème validé paie. */
class BaremeRemuneration extends Model
{
    use LogsActivity;

    public const NATURES = [
        'CM' => 'Cours magistral (heure)', 'TD' => 'Travaux dirigés (heure)', 'TP' => 'Travaux pratiques (heure)',
        'surveillance' => 'Surveillance d\'évaluation (séance)', 'prime_animation' => 'Prime d\'animation en ligne (cours)',
        'encadrement_memoire' => 'Encadrement de mémoire', 'jury_soutenance' => 'Jury de soutenance', 'jury_deliberation' => 'Jury de délibération', 'autre' => 'Autre',
    ];

    public const UNITES = ['heure' => 'Heure', 'seance' => 'Séance', 'forfait' => 'Forfait', 'memoire' => 'Mémoire'];

    public const STATUTS = ['propose' => 'Proposé', 'valide' => 'Validé'];

    protected $table = 'baremes_remuneration';

    protected $fillable = [];

    protected $casts = ['montant_unitaire' => 'integer', 'taux_retenue' => 'float', 'valide_at' => 'datetime'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('lmd')
            ->logOnly(['montant_unitaire', 'taux_retenue', 'statut', 'reference_texte'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function modifiePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'modifie_par');
    }

    public function validePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'valide_par');
    }

    public function estValide(): bool
    {
        return $this->statut === 'valide';
    }
}
