<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * Dossier d'archive d'un ancien auditeur d'ACMA (lot 15, ADR-0014). Lecture seule : l'archive n'est écrite que par
 * `acma:reprendre` (déclencheurs). Seul le lien vers un auditeur change, par l'arbitrage (ArchivesAcma).
 *
 * @property int $id
 * @property string $uuid
 * @property string $matricule
 * @property string|null $nom_complet
 * @property int|null $auditeur_id
 * @property string $statut_rapprochement
 */
class AcmaDossier extends Model
{
    protected $table = 'acma_dossiers';

    protected $guarded = ['*'];

    public const STATUTS = [
        'lie_auto' => 'Lié automatiquement',
        'lie_arbitre' => 'Lié par arbitrage',
        'a_arbitrer' => 'À arbitrer',
        'sans_auditeur' => 'Sans auditeur',
        'ecarte' => 'Écarté',
    ];

    public const SIGNALEMENTS = [
        'caractere_parasite' => 'Matricule avec un caractère parasite',
        'identites_divergentes' => 'Fiches fusionnées aux identités divergentes',
        'sans_inscription_datee' => 'Aucune inscription datée (dossier gardé pour un document)',
    ];

    protected $casts = [
        'date_naissance' => 'immutable_date',
        'date_naissance_a_verifier' => 'boolean',
        'fiches_fusionnees' => 'array',
        'signalements' => 'array',
        'retire_de_la_source_at' => 'immutable_datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function estLie(): bool
    {
        return in_array($this->statut_rapprochement, ['lie_auto', 'lie_arbitre'], true);
    }

    public function auditeur(): BelongsTo
    {
        return $this->belongsTo(Auditeur::class);
    }

    public function inscriptions(): HasMany
    {
        return $this->hasMany(AcmaInscription::class, 'dossier_id');
    }

    public function resultats(): HasManyThrough
    {
        return $this->hasManyThrough(AcmaResultatUe::class, AcmaInscription::class, 'dossier_id', 'inscription_id');
    }

    public function notesNonRattachees(): HasMany
    {
        return $this->hasMany(AcmaNoteNonRattachee::class, 'dossier_id');
    }

    public function paiements(): HasMany
    {
        return $this->hasMany(AcmaPaiement::class, 'dossier_id');
    }

    public function soutenances(): HasMany
    {
        return $this->hasMany(AcmaSoutenance::class, 'dossier_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(AcmaDocument::class, 'dossier_id');
    }

    public function complements(): HasMany
    {
        return $this->hasMany(AcmaComplement::class, 'dossier_id');
    }

    public function rapprochements(): HasMany
    {
        return $this->hasMany(AcmaRapprochement::class, 'dossier_id');
    }
}
