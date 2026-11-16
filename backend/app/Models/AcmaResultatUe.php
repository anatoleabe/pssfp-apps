<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Résultat d'une UE d'ACMA dans l'archive (lot 15) : notes brutes et bornées, moyenne recalculée selon ACMA. */
class AcmaResultatUe extends Model
{
    protected $table = 'acma_resultats_ue';

    protected $guarded = ['*'];

    protected $casts = [
        'rattrapage_applique' => 'boolean',
        'acquise' => 'boolean',
        'en_conflit' => 'boolean',
        'hors_bornes' => 'boolean',
        'retire_de_la_source_at' => 'immutable_datetime',
    ];

    public function inscription(): BelongsTo
    {
        return $this->belongsTo(AcmaInscription::class, 'inscription_id');
    }

    public function unite(): BelongsTo
    {
        return $this->belongsTo(AcmaUnite::class, 'unite_id');
    }

    /** Versions de cette note dans ACMA (Q5). */
    public function versions(): HasMany
    {
        return $this->hasMany(AcmaVersionNote::class, 'id_note_acma', 'id_note_acma');
    }
}
