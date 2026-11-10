<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Virement reçu par un enseignant (relevé bancaire, avis du Trésor ou saisie), rapproché d'un état de paie
 * (lot 13c-1, D38). Ajout seul : seule la pose unique de l'annulation est admise ; seul
 * App\Services\Lmd\RapprochementVirements écrit ici, et journalise.
 */
class AvisVirement extends Model
{
    use HasUuids;

    public const SOURCES = ['releve_bancaire' => 'Relevé bancaire', 'avis_tresor' => 'Avis du Trésor', 'saisie' => 'Saisie'];

    protected $table = 'avis_virement';

    protected $fillable = ['etat_id', 'enseignant_id', 'montant', 'date_valeur', 'reference', 'source', 'piece_path', 'piece_sha256', 'saisi_par'];

    protected $casts = ['montant' => 'integer', 'date_valeur' => 'immutable_date', 'annule_at' => 'datetime'];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @param  Builder<AvisVirement>  $query */
    public function scopeEnVigueur(Builder $query): void
    {
        $query->whereNull('annule_at');
    }

    public function etat(): BelongsTo
    {
        return $this->belongsTo(EtatPaie::class, 'etat_id');
    }

    public function enseignant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enseignant_id');
    }

    public function saisiPar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'saisi_par');
    }

    public function estAnnule(): bool
    {
        return $this->annule_at !== null;
    }
}
