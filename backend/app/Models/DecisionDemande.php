<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Décision sur une demande d'autorisation (lot 14c). Ajout seul, une par qualité. */
class DecisionDemande extends Model
{
    public const RESPONSABLE = 'responsable';

    public const CONTROLE_INTERNE = 'controle_interne';

    public const QUALITES = [self::RESPONSABLE => 'Responsable', self::CONTROLE_INTERNE => 'Contrôle interne'];

    public const APPROUVE = 'approuve';

    public const REJETE = 'rejete';

    public $timestamps = false;

    protected $table = 'decisions_demandes';

    protected $fillable = ['demande_id', 'decideur_id', 'qualite', 'decision', 'commentaire', 'decide_le'];

    protected $casts = ['decide_le' => 'immutable_datetime'];

    public function demande(): BelongsTo
    {
        return $this->belongsTo(DemandeAutorisation::class, 'demande_id');
    }

    public function decideur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decideur_id');
    }
}
