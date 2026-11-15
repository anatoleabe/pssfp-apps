<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Part d'un versement qui règle une inscription (compte de l'auditeur, ADR-0013 ; spec D1 §5.3).
 *
 * Un fait, jamais un total en cache : seule ImputationsCompte écrit ici. Jamais modifiée ni
 * supprimée, sauf son annulation, une fois ; le déclencheur `imputations_immuables` le garantit
 * aussi hors de l'application.
 */
class ImputationVersement extends Model
{
    use HasUuids;

    /** Seules ces colonnes changent, à l'annulation d'une ligne encore active. */
    private const ANNULATION = ['annule_at', 'annule_par', 'annule_motif', 'updated_at'];

    protected $table = 'imputations_versements';

    protected $fillable = [];

    protected $casts = [
        'montant' => 'integer',
        'auditeur_id' => 'integer',
        'versement_id' => 'integer',
        'inscription_annuelle_id' => 'integer',
        'remplace_id' => 'integer',
        'cree_par' => 'integer',
        'annule_at' => 'datetime',
        'annule_par' => 'integer',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $imputation): void {
            $autres = array_diff(array_keys($imputation->getDirty()), self::ANNULATION);
            if ($imputation->getOriginal('annule_at') !== null || $imputation->annule_at === null || $autres !== []) {
                throw new LogicException('Une imputation ne se modifie pas : seule son annulation, une fois, est permise.');
            }
        });
        static::deleting(fn () => throw new LogicException('Une imputation ne se supprime pas : annulez-la.'));
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function auditeur(): BelongsTo
    {
        return $this->belongsTo(Auditeur::class);
    }

    public function versement(): BelongsTo
    {
        return $this->belongsTo(Versement::class);
    }

    public function inscription(): BelongsTo
    {
        return $this->belongsTo(InscriptionAnnuelle::class, 'inscription_annuelle_id');
    }

    public function remplace(): BelongsTo
    {
        return $this->belongsTo(self::class, 'remplace_id');
    }

    public function estActive(): bool
    {
        return $this->annule_at === null;
    }
}
