<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Requête d'un auditeur (D26 à D28) : de note (une par ECUE) ou de document.
 * Le statut ne change que par App\Services\Lmd\Requetes, avec un événement à chaque étape.
 */
class Requete extends Model
{
    use HasUuids;

    public const NOTE = 'note';

    public const DOCUMENT = 'document';

    public const MOTIFS = [
        'non_publication' => 'Non-publication de la note',
        'erreur_report' => 'Erreur de report',
        'absence_note' => 'Absence de note',
        'autre' => 'Autre',
    ];

    public const NOTES_VISEES = ['cc' => 'Contrôle continu', 'assiduite' => 'Assiduité', 'examen' => 'Examen'];

    public const DOCUMENTS = [
        'lettre_stage' => 'Lettre de stage',
        'attestation_stage' => 'Attestation de stage',
        'pv_soutenance' => 'PV de soutenance',
        'autre' => 'Autre document',
    ];

    public const MENTIONS = ['fondee' => 'Fondée', 'partiellement_fondee' => 'Partiellement fondée', 'non_fondee' => 'Non fondée'];

    /** Libellés publics des étapes, tels que l'auditeur les voit (D27). */
    public const STATUTS = [
        'deposee' => 'Déposée',
        'cotee' => 'Confiée à un agent',
        'en_instruction' => 'En vérification',
        'instruite' => 'Vérification terminée',
        'au_jury' => 'Inscrite au jury des requêtes',
        'statuee' => 'Examinée par le jury',
        'validee' => 'Décision validée',
        'appliquee' => 'Appliquée',
        'cloturee' => 'Clôturée',
        'irrecevable' => 'Irrecevable',
        'annulee' => 'Annulée',
        'en_traitement' => 'En traitement',
        'document_disponible' => 'Document disponible',
        'rejetee' => 'Rejetée',
    ];

    public const OUVERTS = ['deposee', 'cotee', 'en_instruction', 'instruite', 'au_jury', 'statuee', 'validee', 'en_traitement'];

    protected $guarded = ['id'];

    protected $attributes = ['statut' => 'deposee'];

    protected $casts = ['note_retenue' => 'decimal:2'];

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

    public function inscriptionAnnuelle(): BelongsTo
    {
        return $this->belongsTo(InscriptionAnnuelle::class);
    }

    public function ecue(): BelongsTo
    {
        return $this->belongsTo(MaquetteEcue::class, 'maquette_ecue_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function seance(): BelongsTo
    {
        return $this->belongsTo(SeanceRequetes::class, 'seance_requetes_id');
    }

    public function pieces(): HasMany
    {
        return $this->hasMany(RequetePiece::class)->orderBy('id');
    }

    public function evenements(): HasMany
    {
        return $this->hasMany(RequeteEvenement::class)->orderBy('id');
    }

    public function estOuverte(): bool
    {
        return in_array($this->statut, self::OUVERTS, true);
    }

    public function objet(): string
    {
        return $this->type === self::NOTE
            ? sprintf('%s — %s (%s)', $this->ecue?->code, self::NOTES_VISEES[$this->note_visee] ?? $this->note_visee, $this->session === 'rattrapage' ? 'rattrapage' : 'session normale')
            : (self::DOCUMENTS[$this->type_document] ?? 'Document');
    }
}
