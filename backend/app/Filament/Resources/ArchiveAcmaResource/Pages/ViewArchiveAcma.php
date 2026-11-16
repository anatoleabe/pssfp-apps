<?php

declare(strict_types=1);

namespace App\Filament\Resources\ArchiveAcmaResource\Pages;

use App\Filament\Resources\ArchiveAcmaResource;
use App\Filament\Resources\ArchiveAcmaResource\ArchiveAcmaActions;
use App\Filament\Resources\ArchiveAcmaResource\ReinscriptionAncienActions;
use App\Models\AcmaDossier;
use App\Support\Journaux;
use Filament\Actions\ActionGroup;
use Filament\Resources\Pages\ViewRecord;

/** Fiche d'un dossier d'archive ACMA : identité, années, notes, paiements, soutenances, documents, rapprochement. */
class ViewArchiveAcma extends ViewRecord
{
    protected static string $resource = ArchiveAcmaResource::class;

    /** Chaque ouverture d'une fiche d'archive est journalisée (une entrée par fiche ouverte, uuid seul). */
    public function mount(int|string $record): void
    {
        parent::mount($record);

        /** @var AcmaDossier $dossier */
        $dossier = $this->getRecord();
        activity(Journaux::ARCHIVES)->causedBy(auth()->user())->event('fiche_consultee')
            ->withProperties(['dossier' => $dossier->uuid])->log('Fiche d\'archive ACMA consultée');
    }

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
            ReinscriptionAncienActions::reinscrire(),
            ArchiveAcmaActions::arbitrer(),
            ActionGroup::make([
                ArchiveAcmaActions::transcrireDecision(),
                ArchiveAcmaActions::completerNaissance(),
            ])->label('Compléter')->icon('heroicon-o-pencil-square')->button()->color('gray'),
        ];
    }
}
