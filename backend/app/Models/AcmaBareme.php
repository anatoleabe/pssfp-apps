<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Barème d'archive d'ACMA, par année et niveau (lot 15, §5.4) : affichage et arriérés, jamais le compte. */
class AcmaBareme extends Model
{
    protected $table = 'acma_baremes';

    protected $guarded = ['*'];

    protected $casts = [
        'categories' => 'array',
        'heterogene' => 'boolean',
    ];
}
