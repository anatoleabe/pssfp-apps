<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Version d'une note dans ACMA (`notes_hist`, Q5), consultable depuis une ligne de résultat. */
class AcmaVersionNote extends Model
{
    protected $table = 'acma_versions_notes';

    protected $guarded = ['*'];

    protected $casts = [
        'horodatage' => 'immutable_datetime',
    ];
}
