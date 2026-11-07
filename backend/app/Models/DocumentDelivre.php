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

    public const TYPES = [
        self::RELEVE => 'Relevé de notes',
        self::ATTESTATION => 'Attestation de réussite',
        self::CERTIFICAT => 'Certificat de scolarité',
        self::AUTORISATION_DEPOT => 'Attestation d\'autorisation de dépôt du mémoire',
    ];

    public const PREFIXES = [self::RELEVE => 'RN', self::ATTESTATION => 'AR', self::CERTIFICAT => 'CS', self::AUTORISATION_DEPOT => 'AD'];

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

    public function estRevoque(): bool
    {
        return $this->revoque_at !== null;
    }
}
