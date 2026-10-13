<?php

declare(strict_types=1);

namespace App\Services\ResultatsSelection;

use App\Models\CampagneCandidature;
use App\Models\User;
use InvalidArgumentException;

/**
 * Point d'entrée des téléchargements depuis l'admin : construit les données,
 * rend le document Word et trace le téléchargement.
 *
 * Word uniquement (arbitrage du 13 oct. 2026) : le document est relu et
 * ajusté avant impression et signature.
 *
 * Rien n'est stocké : le document contient des données nominatives et se
 * régénère à la demande à partir de l'état courant des dossiers.
 */
final class ResultatsSelectionExport
{
    public const COMMUNIQUE = 'communique';

    public const PV = 'pv';

    public const MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    public function __construct(
        private readonly ResultatsSelectionService $donnees,
        private readonly ResultatsSelectionWord $word,
    ) {}

    /** @return array{nom: string, mime: string, contenu: string} */
    public function generer(
        CampagneCandidature $campagne,
        string $document,
        SeanceJury $seance,
        User $agent,
        bool $conserverDoublons = false,
    ): array {
        if (! in_array($document, [self::COMMUNIQUE, self::PV], true)) {
            throw new InvalidArgumentException("Document inconnu : {$document}");
        }

        $resultats = $this->donnees->construire($campagne, $seance, $conserverDoublons);

        $contenu = $document === self::PV
            ? $this->word->pv($resultats)
            : $this->word->communique($resultats);

        activity('candidatures')
            ->causedBy($agent)
            ->performedOn($campagne)
            ->event('resultats_selection_downloaded')
            ->withProperties([
                'document' => $document,
                'format' => 'docx',
                'retenus' => $resultats->totalRetenus(),
                'en_ligne' => $resultats->totalEnLigne,
                'doublons_conserves' => $conserverDoublons,
                'doublons_retires' => $resultats->doublonsRetires,
            ])
            ->log($document === self::PV ? 'PV du jury de sélection téléchargé' : 'Communiqué des résultats téléchargé');

        $prefixe = $document === self::PV ? 'PV-jury-selection' : 'Communique-resultats';

        return [
            'nom' => "{$prefixe}-P{$resultats->promotionNumero}-{$resultats->anneeAcademique}.docx",
            'mime' => self::MIME,
            'contenu' => $contenu,
        ];
    }
}
