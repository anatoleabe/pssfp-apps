<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\CandidatureDocument;

/**
 * Libellés FR des types de pièces justificatives (CandidatureDocument::TYPES)
 * — partagés entre l'admin Filament et toute vue serveur affichant ces types.
 */
final class CandidatureDocumentTypeLabel
{
    private const LABELS = [
        CandidatureDocument::TYPE_DIPLOME => 'Diplôme / attestation de réussite',
        CandidatureDocument::TYPE_ACTE_NAISSANCE => 'Acte de naissance',
        CandidatureDocument::TYPE_RELEVES_NOTES => 'Relevé de notes',
        CandidatureDocument::TYPE_CV => 'CV',
        CandidatureDocument::TYPE_LETTRE_MOTIVATION => 'Lettre de motivation',
        CandidatureDocument::TYPE_ATTESTATION_EMPLOYEUR => 'Attestation employeur',
        CandidatureDocument::TYPE_AUTRE => 'Autre pièce',
    ];

    public static function for(string $type): string
    {
        return self::LABELS[$type] ?? $type;
    }
}
