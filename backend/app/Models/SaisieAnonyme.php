<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Saisie d'une note sur un numéro : correction 1 à 3, saisie 1 à 3 (la 3e tranche un écart). Ajout seul. */
class SaisieAnonyme extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'saisies_anonymes';

    protected $guarded = ['id'];

    protected $casts = ['note' => 'decimal:2'];
}
