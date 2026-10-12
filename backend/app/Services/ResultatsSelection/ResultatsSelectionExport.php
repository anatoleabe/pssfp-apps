<?php

declare(strict_types=1);

namespace App\Services\ResultatsSelection;

use App\Models\CampagneCandidature;
use App\Models\User;
use InvalidArgumentException;

/**
 * Point d'entrée des téléchargements depuis l'admin : construit les données,
 * rend le document au format demandé et trace le téléchargement.
 *
 * Rien n'est stocké : le document contient des données nominatives et se
 * régénère à la demande à partir de l'état courant des dossiers.
 */
final class ResultatsSelectionExport
{
    public const COMMUNIQUE = 'communique';

    public const PV = 'pv';

    public const DOCX = 'docx';

    public const PDF = 'pdf';

    private const MIME = [
        self::DOCX => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        self::PDF => 'application/pdf',
    ];

    public function __construct(
        private readonly ResultatsSelectionService $donnees,
        private readonly ResultatsSelectionPdf $pdf,
        private readonly ResultatsSelectionWord $word,
    ) {}

    /** @return array{nom: string, mime: string, contenu: string} */
    public function generer(CampagneCandidature $campagne, string $document, string $format, SeanceJury $seance, User $agent): array
    {
        if (! in_array($document, [self::COMMUNIQUE, self::PV], true) || ! array_key_exists($format, self::MIME)) {
            throw new InvalidArgumentException("Document ou format inconnu : {$document}.{$format}");
        }

        $resultats = $this->donnees->construire($campagne, $seance);

        $contenu = match ([$document, $format]) {
            [self::COMMUNIQUE, self::DOCX] => $this->word->communique($resultats),
            [self::COMMUNIQUE, self::PDF] => $this->pdf->communique($resultats),
            [self::PV, self::DOCX] => $this->word->pv($resultats),
            default => $this->pdf->pv($resultats),
        };

        activity('candidatures')
            ->causedBy($agent)
            ->performedOn($campagne)
            ->event('resultats_selection_downloaded')
            ->withProperties([
                'document' => $document,
                'format' => $format,
                'retenus' => $resultats->totalRetenus(),
                'en_ligne' => $resultats->totalEnLigne,
            ])
            ->log($document === self::PV ? 'PV du jury de sélection téléchargé' : 'Communiqué des résultats téléchargé');

        return [
            'nom' => $this->nomFichier($resultats, $document, $format),
            'mime' => self::MIME[$format],
            'contenu' => $contenu,
        ];
    }

    private function nomFichier(ResultatsSelection $r, string $document, string $format): string
    {
        $prefixe = $document === self::PV ? 'PV-jury-selection' : 'Communique-resultats';

        return "{$prefixe}-P{$r->promotionNumero}-{$r->anneeAcademique}.{$format}";
    }
}
