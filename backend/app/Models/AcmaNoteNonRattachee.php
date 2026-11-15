<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Note d'ACMA sans inscription d'archive (programme disparu, Q6, ou sans inscription à cette année et ce niveau). */
class AcmaNoteNonRattachee extends Model
{
    protected $table = 'acma_notes_non_rattachees';

    protected $guarded = ['*'];

    public const MOTIFS = [
        'programme_disparu' => 'Programme supprimé dans ACMA (année inconnue)',
        'sans_inscription' => 'Aucune inscription à l\'année et au niveau du programme',
    ];
}
