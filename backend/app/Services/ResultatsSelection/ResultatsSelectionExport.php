<?php

declare(strict_types=1);

namespace App\Services\ResultatsSelection;

use App\Models\CampagneCandidature;
use App\Models\User;
use InvalidArgumentException;

/**
 * Point d'entrée des téléchargements depuis l'admin : construit les données,
 * rend le document et trace le téléchargement.
 *
 * Communiqué et PV en Word uniquement (arbitrage du 13 oct. 2026) : relus et
 * ajustés avant impression et signature. Liste des retenus en Excel.
 *
 * Rien n'est stocké : le document contient des données nominatives et se
 * régénère à la demande à partir de l'état courant des dossiers.
 */
final class ResultatsSelectionExport
{
    public const COMMUNIQUE = 'communique';

    public const PV = 'pv';

    public const LISTE = 'liste';

    private const MIME_DOCX = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    private const MIME_XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    public function __construct(
        private readonly ResultatsSelectionService $donnees,
        private readonly ResultatsSelectionWord $word,
        private readonly ListeRetenusExcel $excel,
    ) {}

    /** @return array{nom: string, mime: string, contenu: string} */
    public function generer(
        CampagneCandidature $campagne,
        string $document,
        SeanceJury $seance,
        User $agent,
        bool $conserverDoublons = false,
    ): array {
        if (! in_array($document, [self::COMMUNIQUE, self::PV, self::LISTE], true)) {
            throw new InvalidArgumentException("Document inconnu : {$document}");
        }

        [$resultats, $dossiers] = $this->donnees->construireAvecDossiers($campagne, $seance, $conserverDoublons);

        $contenu = match ($document) {
            self::PV => $this->word->pv($resultats),
            self::COMMUNIQUE => $this->word->communique($resultats),
            self::LISTE => $this->excel->generer($dossiers, $resultats),
        };
        $format = $document === self::LISTE ? 'xlsx' : 'docx';

        activity('candidatures')
            ->causedBy($agent)
            ->performedOn($campagne)
            ->event('resultats_selection_downloaded')
            ->withProperties([
                'document' => $document,
                'format' => $format,
                'retenus' => $resultats->totalRetenus(),
                'en_ligne' => $resultats->totalEnLigne,
                'doublons_conserves' => $conserverDoublons,
                'doublons_retires' => $resultats->doublonsRetires,
            ])
            ->log(match ($document) {
                self::PV => 'PV du jury de sélection téléchargé',
                self::COMMUNIQUE => 'Communiqué des résultats téléchargé',
                self::LISTE => 'Liste Excel des candidats retenus téléchargée',
            });

        $prefixe = match ($document) {
            self::PV => 'PV-jury-selection',
            self::COMMUNIQUE => 'Communique-resultats',
            self::LISTE => 'Liste-candidats-retenus',
        };

        return [
            'nom' => "{$prefixe}-P{$resultats->promotionNumero}-{$resultats->anneeAcademique}.{$format}",
            'mime' => $format === 'xlsx' ? self::MIME_XLSX : self::MIME_DOCX,
            'contenu' => $contenu,
        ];
    }
}
