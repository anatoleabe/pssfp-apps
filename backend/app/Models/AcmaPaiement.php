<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Paiement d'ACMA archivé (lot 15, §7.7) : un historique, jamais un versement. */
class AcmaPaiement extends Model
{
    protected $table = 'acma_paiements';

    protected $guarded = ['*'];

    public const DRAPEAUX = [
        'negatif' => 'négatif',
        'total_nul' => 'total nul',
        'date_aberrante' => 'date aberrante',
        'hors_annee' => 'hors de l\'année',
        'doublon_exact' => 'doublon exact',
        'niveau_sans_inscription' => 'niveau sans inscription',
    ];

    protected $casts = [
        'date_recu' => 'immutable_date',
        'negatif' => 'boolean',
        'total_nul' => 'boolean',
        'date_aberrante' => 'boolean',
        'hors_annee' => 'boolean',
        'doublon_exact' => 'boolean',
        'niveau_sans_inscription' => 'boolean',
    ];

    public function dossier(): BelongsTo
    {
        return $this->belongsTo(AcmaDossier::class, 'dossier_id');
    }

    /** @return list<string> */
    public function drapeaux(): array
    {
        $d = [];
        foreach (self::DRAPEAUX as $colonne => $libelle) {
            if ($this->{$colonne}) {
                $d[] = $colonne === 'negatif' && $this->contrepartie_id_acma !== null ? 'négatif (annulation appariée)' : $libelle;
            }
        }

        return $d;
    }
}
