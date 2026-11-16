<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Ligne de programme d'ACMA (UE d'une année et d'un niveau), photographie annuelle de l'archive (lot 15, §5.3). */
class AcmaUnite extends Model
{
    protected $table = 'acma_unites';

    protected $guarded = ['*'];

    protected $casts = [
        'heures' => 'array',
        'ponderations_hors_norme' => 'boolean',
        'groupe_autre_niveau' => 'boolean',
    ];

    public function libelle(): string
    {
        return trim($this->code_ue.' — '.($this->intitule_fr ?? ''), ' —');
    }
}
