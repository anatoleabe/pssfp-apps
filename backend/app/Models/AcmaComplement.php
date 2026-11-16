<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Complément de l'archive ACMA (lot 15, §7.5), en ajout seul (déclencheur) : date de naissance d'après le dossier
 * papier, décision et moyenne transcrites d'un PV signé, note retenue en cas de conflit. Écrit par ArchivesAcma seul.
 */
class AcmaComplement extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'acma_complements';

    protected $fillable = ['dossier_id', 'inscription_id', 'resultat_id', 'champ', 'valeur', 'motif', 'piece_reference', 'auteur_id'];

    public const CHAMPS = [
        'date_naissance' => 'Date de naissance',
        'decision' => 'Décision',
        'moyenne' => 'Moyenne',
        'note_retenue' => 'Note retenue',
    ];

    protected $casts = [
        'created_at' => 'immutable_datetime',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'auteur_id');
    }

    public function inscription(): BelongsTo
    {
        return $this->belongsTo(AcmaInscription::class, 'inscription_id');
    }
}
