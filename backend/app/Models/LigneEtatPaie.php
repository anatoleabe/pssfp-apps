<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Ligne d'un état de paie : un enseignant, un ECUE, une nature ; montants calculés à la génération. */
class LigneEtatPaie extends Model
{
    protected $table = 'lignes_etat_paie';

    protected $fillable = [];

    protected $casts = [
        'quantite' => 'float', 'heures_service' => 'float', 'montant_unitaire' => 'integer', 'taux_retenue' => 'float',
        'montant_brut' => 'integer', 'montant_retenue' => 'integer', 'montant_net' => 'integer', 'heures_hors_quota' => 'float',
    ];

    public function etat(): BelongsTo
    {
        return $this->belongsTo(EtatPaie::class, 'etat_id');
    }

    public function enseignant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enseignant_id');
    }

    public function ecue(): BelongsTo
    {
        return $this->belongsTo(MaquetteEcue::class, 'maquette_ecue_id');
    }

    public function pointages(): HasMany
    {
        return $this->hasMany(PointageEnseignant::class, 'ligne_paie_id');
    }
}
