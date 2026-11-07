<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Notes d'un auditeur pour un ECUE et une session. Jamais écrites en masse :
 * seul SaisieNotes les pose, étape ouverte, avec historique.
 */
class NoteEcue extends Model
{
    public const PRESENT = 'present';

    public const ABSENT_JUSTIFIE = 'absent_justifie';

    public const ABSENT_NON_JUSTIFIE = 'absent_non_justifie';

    public const PRESENCES = [
        self::PRESENT => 'Présent',
        self::ABSENT_JUSTIFIE => 'Absent justifié',
        self::ABSENT_NON_JUSTIFIE => 'Absent non justifié',
    ];

    protected $table = 'notes_ecue';

    protected $fillable = ['inscription_pedagogique_id', 'maquette_ecue_id', 'session'];

    protected $casts = [
        'cc' => 'decimal:2',
        'assiduite' => 'decimal:2',
        'examen' => 'decimal:2',
    ];

    public function inscriptionPedagogique(): BelongsTo
    {
        return $this->belongsTo(InscriptionPedagogique::class);
    }

    public function ecue(): BelongsTo
    {
        return $this->belongsTo(MaquetteEcue::class, 'maquette_ecue_id');
    }

    public function historique(): HasMany
    {
        return $this->hasMany(HistoriqueNote::class)->orderBy('id');
    }

    /** Valeur d'un champ en flottant (null si non saisi). */
    public function valeur(string $champ): ?float
    {
        $v = $this->getAttribute($champ);

        return $v === null ? null : (float) $v;
    }
}
