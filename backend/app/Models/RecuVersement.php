<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * Reçu officiel d'un versement de scolarité (Scolarité lot 4 ; D7). Contenu figé à l'émission ;
 * un reçu ne se modifie jamais : son annulation est une écriture distincte (AnnulationRecu).
 */
class RecuVersement extends Model
{
    use HasUuids;

    protected $table = 'recus_versements';

    protected $fillable = [];

    protected $casts = ['date_versement' => 'immutable_date', 'emis_at' => 'datetime', 'montant' => 'integer', 'total_verse' => 'integer', 'reste_a_payer' => 'integer'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Un reçu émis ne se modifie pas : passez une écriture d\'annulation.'));
        static::deleting(fn () => throw new LogicException('Un reçu émis ne se supprime pas.'));
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function versement(): BelongsTo
    {
        return $this->belongsTo(Versement::class);
    }

    public function inscription(): BelongsTo
    {
        return $this->belongsTo(InscriptionAnnuelle::class, 'inscription_annuelle_id');
    }

    public function emetteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'emis_par');
    }

    public function annulation(): HasOne
    {
        return $this->hasOne(AnnulationRecu::class, 'recu_id');
    }

    public function estAnnule(): bool
    {
        return $this->annulation !== null;
    }

    public function nomFichier(): string
    {
        return mb_strtolower($this->numero).'.pdf';
    }
}
