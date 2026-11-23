<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Document officiel délivré (RG-059) : numéroté, scellé, vérifiable, révocable,
 * jamais modifié. Seul App\Services\Lmd\DocumentsOfficiels l'écrit.
 */
class DocumentDelivre extends Model
{
    use HasUuids;

    public const RELEVE = 'releve_notes';

    public const ATTESTATION = 'attestation_reussite';

    public const CERTIFICAT = 'certificat_scolarite';

    public const AUTORISATION_DEPOT = 'autorisation_depot';

    /** Documents administratifs délivrés par la scolarité (spec lot 26). */
    public const ATTESTATION_RECHERCHE = 'attestation_recherche';

    public const LETTRE_STAGE = 'lettre_stage';

    /** PV de délibération (lot 16a) : sans auditeur, numéroté par le numéro du PV. */
    public const PV = 'pv_deliberation';

    public const TYPES = [
        self::RELEVE => 'Relevé de notes',
        self::ATTESTATION => 'Attestation de réussite',
        self::CERTIFICAT => 'Certificat de scolarité',
        self::AUTORISATION_DEPOT => 'Attestation d\'autorisation de dépôt du mémoire',
        self::PV => 'Procès-verbal de délibération',
        self::ATTESTATION_RECHERCHE => 'Attestation de recherche',
        self::LETTRE_STAGE => 'Lettre de stage',
    ];

    public const PREFIXES = [self::RELEVE => 'RN', self::ATTESTATION => 'AR', self::CERTIFICAT => 'CS', self::AUTORISATION_DEPOT => 'AD',
        self::ATTESTATION_RECHERCHE => 'AT', self::LETTRE_STAGE => 'LS'];

    protected $table = 'documents_delivres';

    protected $guarded = ['id'];

    protected $casts = ['revoque_at' => 'datetime'];

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

    public function deliberation(): BelongsTo
    {
        return $this->belongsTo(Deliberation::class);
    }

    /** Agent qui a délivré le document ; vide quand l'auditeur l'a obtenu lui-même. */
    public function delivrePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delivre_par');
    }

    public function estRevoque(): bool
    {
        return $this->revoque_at !== null;
    }
}
