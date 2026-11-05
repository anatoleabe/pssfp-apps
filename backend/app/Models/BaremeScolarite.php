<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Barème d'une année académique et d'un niveau : inscription et deux tranches. */
class BaremeScolarite extends Model
{
    use HasFactory;

    public const NIVEAU_M1 = 'M1';

    public const NIVEAU_M2 = 'M2';

    public const NIVEAUX = [
        self::NIVEAU_M1 => 'Master 1',
        self::NIVEAU_M2 => 'Master 2',
    ];

    protected $table = 'baremes_scolarite';

    protected $fillable = [
        'annee_academique',
        'niveau',
        'montant_inscription',
        'montant_tranche1',
        'montant_tranche2',
        'inscription_echeance',
        'tranche1_echeance',
        'tranche2_echeance',
    ];

    protected $casts = [
        'montant_inscription' => 'integer',
        'montant_tranche1' => 'integer',
        'montant_tranche2' => 'integer',
        'tranche1_echeance' => 'immutable_date',
        'tranche2_echeance' => 'immutable_date',
    ];

    public function inscriptions(): HasMany
    {
        return $this->hasMany(InscriptionAnnuelle::class, 'bareme_id');
    }
}
