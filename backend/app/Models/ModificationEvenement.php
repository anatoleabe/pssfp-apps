<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Journal des réajustements d'un calendrier signé (D20), en ajout seul. */
class ModificationEvenement extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'modifications_evenement';

    protected $guarded = ['id'];

    protected $casts = [
        'ancien_debut' => 'immutable_date',
        'ancienne_fin' => 'immutable_date',
        'nouveau_debut' => 'immutable_date',
        'nouvelle_fin' => 'immutable_date',
    ];

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'auteur_id');
    }
}
