<?php

declare(strict_types=1);

namespace App\Filament\Resources\ArchiveAcmaResource\Pages;

use App\Filament\Resources\ArchiveAcmaResource;
use App\Filament\Resources\ArchiveAcmaResource\ArchiveAcmaActions;
use App\Models\AcmaDossier;
use Filament\Actions\ActionGroup;
use Filament\Resources\Pages\ViewRecord;

/** Fiche d'un dossier d'archive ACMA : identité, années, notes, paiements, soutenances, documents, rapprochement. */
class ViewArchiveAcma extends ViewRecord
{
    protected static string $resource = ArchiveAcmaResource::class;

    public function getTitle(): string
    {
        /** @var AcmaDossier $d */
        $d = $this->getRecord();

        return $d->matricule.' — '.($d->nom_complet ?? 'nom inconnu');
    }

    public function getSubheading(): ?string
    {
        return 'Archive ACMA, en lecture seule. Les valeurs d\'ACMA restent celles d\'ACMA ; les recalculs sont indicatifs.';
    }

    protected function getHeaderActions(): array
    {
        return [
            ArchiveAcmaActions::ficheAuditeur(),
            ArchiveAcmaActions::arbitrer(),
            ActionGroup::make([
                ArchiveAcmaActions::transcrireDecision(),
                ArchiveAcmaActions::completerNaissance(),
            ])->label('Compléter')->icon('heroicon-o-pencil-square')->button()->color('gray'),
        ];
    }
}
