<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Proposition ou arbitrage du rapprochement d'un dossier d'archive (lot 15, §6.3), en ajout seul. */
class AcmaRapprochement extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'acma_rapprochements';

    protected $guarded = ['*'];

    public const DECISIONS = [
        'lie' => 'Lier',
        'non_lie' => 'Ne pas lier (personnes différentes)',
        'ecarte' => 'Écarter le dossier (fiche de test)',
    ];

    protected $casts = [
        'created_at' => 'immutable_datetime',
    ];

    public function auditeur(): BelongsTo
    {
        return $this->belongsTo(Auditeur::class);
    }

    public function decidePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decide_par');
    }
}
