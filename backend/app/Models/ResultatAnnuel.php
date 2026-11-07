<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Résultat arrêté d'un auditeur : figé, la base refuse modification et suppression. */
class ResultatAnnuel extends Model
{
    public const UPDATED_AT = null;

    public const DECISIONS = ['AD' => 'Admis(e)', 'AS' => 'Autorisé(e) à soutenir', 'AJ' => 'Ajourné(e)'];

    protected $table = 'resultats_annuels';

    protected $guarded = ['id'];

    protected $casts = [
        'moyenne' => 'decimal:3',
        'mgp' => 'decimal:2',
        'felicitations_proposees' => 'boolean',
    ];

    public function deliberation(): BelongsTo
    {
        return $this->belongsTo(Deliberation::class);
    }

    public function inscriptionAnnuelle(): BelongsTo
    {
        return $this->belongsTo(InscriptionAnnuelle::class);
    }
}
