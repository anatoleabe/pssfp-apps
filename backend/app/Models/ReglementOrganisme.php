<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Règlement d'un organisme sur un engagement (spec module 4, lot A2 §9.7), réparti en
 * un versement par auditeur. Jamais supprimé ni modifié : seule son annulation, une
 * fois, est permise. Seul ReglementsOrganismes l'écrit ; l'argent lui-même est porté
 * par les versements, immuables en base (ADR-0013).
 */
class ReglementOrganisme extends Model
{
    use HasUuids;

    /** Colonnes que l'annulation, et elle seule, peut écrire. */
    private const ANNULATION = ['annule_at', 'annule_par', 'annule_motif', 'updated_at'];

    protected $table = 'reglements_organismes';

    protected $casts = [
        'engagement_id' => 'integer',
        'montant' => 'integer',
        'date_reglement' => 'immutable_date',
        'saisi_par' => 'integer',
        'annule_at' => 'datetime',
        'annule_par' => 'integer',
    ];

    protected $hidden = ['piece_path'];

    protected static function booted(): void
    {
        static::deleting(function (): never {
            throw new LogicException('Un règlement ne se supprime pas : annulez-le.');
        });
        static::updating(function (self $r): void {
            $modifiees = array_keys($r->getDirty());
            if ($r->getOriginal('annule_at') !== null || array_diff($modifiees, self::ANNULATION) !== [] || $r->annule_at === null) {
                throw new LogicException('Un règlement est immuable : seule son annulation, une fois, est permise.');
            }
        });
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function engagement(): BelongsTo
    {
        return $this->belongsTo(EngagementPriseEnCharge::class, 'engagement_id');
    }

    /** Un versement par auditeur, annulés compris. */
    public function versements(): HasMany
    {
        return $this->hasMany(Versement::class, 'reglement_organisme_id');
    }

    public function saisiPar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'saisi_par');
    }

    public function annulePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'annule_par');
    }

    public function estAnnule(): bool
    {
        return $this->annule_at !== null;
    }
}
