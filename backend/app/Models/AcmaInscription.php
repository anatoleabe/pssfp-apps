<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Inscription d'archive (lot 15) : valeurs enregistrées par ACMA, recalculs indicatifs, décision d'archive. Lecture seule.
 *
 * @property int $id
 * @property string $annee
 * @property string $niveau_acma
 * @property string $niveau
 * @property string|null $decision
 */
class AcmaInscription extends Model
{
    protected $table = 'acma_inscriptions';

    protected $guarded = ['*'];

    public const DECISIONS = [
        'admis' => 'Admis',
        'non_admis' => 'Non admis',
        'non_enregistree' => 'Non enregistrée',
    ];

    public const MOTIFS_DECISION = [
        'cohorte_sans_decision' => 'cohorte sans décision enregistrée dans ACMA',
        'sans_note' => 'aucune note',
        'admis_absent' => 'décision absente d\'ACMA',
    ];

    public const SIGNALEMENTS = [
        'admis_sans_toutes_les_ue' => 'Admis dans ACMA sans toutes ses UE au recalcul',
        'credits_acma_differents' => 'Total de crédits d\'ACMA différent du recalcul',
        'conflit_de_notes' => 'Conflit de notes (fiches fusionnées)',
    ];

    protected $casts = [
        'ids_acma' => 'array',
        'admis_acma' => 'boolean',
        'redoublant_acma' => 'boolean',
        'deux_niveaux_meme_annee' => 'boolean',
        'signalements' => 'array',
        'retire_de_la_source_at' => 'immutable_datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function dossier(): BelongsTo
    {
        return $this->belongsTo(AcmaDossier::class, 'dossier_id');
    }

    public function resultats(): HasMany
    {
        return $this->hasMany(AcmaResultatUe::class, 'inscription_id');
    }

    public function complements(): HasMany
    {
        return $this->hasMany(AcmaComplement::class, 'inscription_id');
    }

    /** Moyenne enregistrée par ACMA si elle est renseignée et non nulle (§7.5). */
    public function moyenneEnregistree(): ?float
    {
        return $this->moyenne_acma === null || (float) $this->moyenne_acma === 0.0 ? null : (float) $this->moyenne_acma;
    }
}
