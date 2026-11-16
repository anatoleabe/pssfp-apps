<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Pièce de correction d'un versement (ADR-0015, spec module-4-scolarite-espace-financier §7 et §8.1) : elle relie le
 * versement annulé, son remplaçant s'il y en a un, l'écriture d'annulation du reçu et le nouveau reçu, avec la catégorie,
 * le motif, l'avant/après et les deux auteurs. Écrite par CorrectionsVersements seul ; jamais modifiée ni supprimée
 * (déclencheur en base).
 */
class CorrectionVersement extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'corrections_versements';

    public const REMPLACEMENT = 'remplacement';

    public const ANNULATION = 'annulation';

    public const NATURES = [self::REMPLACEMENT => 'Remplacement', self::ANNULATION => 'Annulation sans remplacement'];

    /** Catégories de motif (R-C3). */
    public const CATEGORIES = [
        'montant' => 'Erreur de montant',
        'auditeur' => 'Erreur d\'auditeur',
        'reference' => 'Erreur de référence',
        'date' => 'Erreur de date',
        'mode' => 'Erreur de mode',
        'payeur' => 'Erreur de payeur',
        'doublon' => 'Doublon',
        'rejet_banque' => 'Paiement rejeté par la banque',
        'autre' => 'Autre',
    ];

    public const REPORT_OFFICE = 'office';

    public const REPORT_ATTESTE = 'attestee';

    protected $casts = [
        'champs' => 'array',
        'rectification_immediate' => 'boolean',
        'versement_annule_id' => 'integer',
        'versement_remplacant_id' => 'integer',
        'demandee_par' => 'integer',
        'executee_par' => 'integer',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Une pièce de correction ne se modifie pas.'));
        static::deleting(fn () => throw new LogicException('Une pièce de correction ne se supprime pas.'));
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function versementAnnule(): BelongsTo
    {
        return $this->belongsTo(Versement::class, 'versement_annule_id');
    }

    public function versementRemplacant(): BelongsTo
    {
        return $this->belongsTo(Versement::class, 'versement_remplacant_id');
    }

    public function annulationRecu(): BelongsTo
    {
        return $this->belongsTo(AnnulationRecu::class, 'annulation_recu_id');
    }

    public function recuRemplacant(): BelongsTo
    {
        return $this->belongsTo(RecuVersement::class, 'recu_remplacant_id');
    }

    public function demande(): BelongsTo
    {
        return $this->belongsTo(DemandeAutorisation::class, 'demande_id');
    }

    public function demandeePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'demandee_par');
    }

    public function executeePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executee_par');
    }
}
