<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Rapport anti-plagiat (D34) : le plus récent fait foi. */
class RapportSimilitude extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'rapports_similitude';

    protected $guarded = ['id'];

    protected $casts = ['taux' => 'decimal:2', 'date_rapport' => 'immutable_date'];
}
