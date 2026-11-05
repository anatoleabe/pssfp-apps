<?php

declare(strict_types=1);

namespace App\Filament\Resources\AuditeurResource\Pages;

use App\Filament\Resources\AuditeurResource;
use App\Filament\Resources\AuditeurResource\AuditeurActions;
use App\Filament\Resources\CandidatureResource;
use App\Models\Auditeur;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewAuditeur extends ViewRecord
{
    protected static string $resource = AuditeurResource::class;

    public function getTitle(): string
    {
        /** @var Auditeur $auditeur */
        $auditeur = $this->getRecord();

        return trim(mb_strtoupper((string) $auditeur->candidature?->nom).' '.$auditeur->candidature?->prenom).' — '.$auditeur->candidature?->matricule;
    }

    protected function getHeaderActions(): array
    {
        return [
            AuditeurActions::enregistrerVersement(),
            AuditeurActions::accorderRemise(),
            AuditeurActions::renvoyerSms(),
            AuditeurActions::creerInscription(),
            Action::make('dossier')
                ->label('Dossier de candidature')
                ->icon('heroicon-o-document-text')
                ->color('gray')
                ->visible(fn (Auditeur $record): bool => $record->candidature !== null && CandidatureResource::canView($record->candidature))
                ->url(fn (Auditeur $record): string => CandidatureResource::getUrl('view', ['record' => $record->candidature])),
        ];
    }
}
