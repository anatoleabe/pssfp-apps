<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Inscription d'un auditeur à une UE de sa maquette de référence, pour une année. */
class InscriptionPedagogique extends Model
{
    use HasFactory;

    public const INSCRIT = 'inscrit';

    public const DISPENSE = 'dispense';

    public const CAPITALISEE = 'capitalisee';

    public const STATUTS = [
        self::INSCRIT => 'Inscrit',
        self::DISPENSE => 'Dispensé',
        self::CAPITALISEE => 'Acquise (capitalisée)',
    ];

    protected $table = 'inscriptions_pedagogiques';

    protected $fillable = ['inscription_annuelle_id', 'maquette_ue_id', 'statut', 'motif'];

    protected $attributes = ['statut' => self::INSCRIT];

    public function inscriptionAnnuelle(): BelongsTo
    {
        return $this->belongsTo(InscriptionAnnuelle::class);
    }

    public function maquetteUe(): BelongsTo
    {
        return $this->belongsTo(MaquetteUe::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(NoteEcue::class);
    }

    public function derogationRattrapage(): HasOne
    {
        return $this->hasOne(DerogationRattrapage::class);
    }
}
