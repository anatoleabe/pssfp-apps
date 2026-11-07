<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Avis de dépôt d'un encadreur (D33) : le plus récent fait foi. */
class AvisMemoire extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'avis_memoire';

    protected $guarded = ['id'];

    protected $casts = ['favorable' => 'boolean'];
}
