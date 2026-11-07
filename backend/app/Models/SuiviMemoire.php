<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Fiche de suivi d'encadrement (D32). */
class SuiviMemoire extends Model
{
    protected $table = 'suivis_memoire';

    protected $guarded = ['id'];

    protected $casts = ['date_suivi' => 'immutable_date'];
}
