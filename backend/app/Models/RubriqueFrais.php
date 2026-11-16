<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Rubrique de frais d'une année académique et d'un niveau (reçu A4, R2) : libellé, code d'imputation budgétaire, montant
 * et ordre de répartition. La pension n'a pas de code fixe : son compte est celui de la promotion de l'auditeur
 * (`compte_selon_promotion`). Modifiée par l'administrateur seulement (`finances.rubriques_gerer`), journalisée.
 */
class RubriqueFrais extends Model
{
    use LogsActivity;

    protected $table = 'rubriques_frais';

    protected $fillable = ['annee_academique', 'niveau', 'libelle', 'code_imputation', 'compte_selon_promotion', 'montant', 'ordre', 'actif'];

    protected $casts = ['montant' => 'integer', 'ordre' => 'integer', 'actif' => 'boolean', 'compte_selon_promotion' => 'boolean'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('finances')->logOnly($this->fillable)->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    /** Rubriques actives d'un couple (année, niveau), dans l'ordre de répartition. */
    public function scopeDuCouple(Builder $query, string $annee, string $niveau): Builder
    {
        return $query->where('annee_academique', $annee)->where('niveau', $niveau)->where('actif', true)->orderBy('ordre');
    }
}
