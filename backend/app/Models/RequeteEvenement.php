<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Étape d'une requête : en ajout seul (déclencheur). `detail` reste interne au service. */
class RequeteEvenement extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'requete_evenements';

    protected $guarded = ['id'];

    protected $casts = ['visible_auditeur' => 'boolean'];

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'auteur_id');
    }
}
