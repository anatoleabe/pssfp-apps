<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** PV de soutenance repris d'ACMA (lot 15, §7.9). */
class AcmaSoutenance extends Model
{
    protected $table = 'acma_soutenances';

    protected $guarded = ['*'];

    protected $casts = [
        'date' => 'immutable_date',
        'membres' => 'array',
    ];
}
