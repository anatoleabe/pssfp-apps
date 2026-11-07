<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Historique d'une note : en ajout seul, garanti par la base (RG-017). */
class HistoriqueNote extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'historique_notes';

    protected $fillable = ['note_ecue_id', 'champ', 'ancienne_valeur', 'nouvelle_valeur', 'motif', 'auteur_id'];

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'auteur_id');
    }
}
