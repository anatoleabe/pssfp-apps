<?php

declare(strict_types=1);

namespace App\Filament\Resources\PromotionResource\Pages;

use App\Filament\Resources\CampagneCandidatureResource\ExportsRetenusActions;
use App\Filament\Resources\CandidatureResource\DocumentsAuditeursActions;
use App\Filament\Resources\PromotionResource;
use App\Services\DocumentsAuditeurs\ParametresLettres;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

/** Réglages d'une promotion : n'enregistre que les champs de la promotion, jamais ceux de la campagne. */
class EditPromotion extends EditRecord
{
    protected static string $resource = PromotionResource::class;

    public function getTitle(): string
    {
        return 'Réglages de la '.$this->getRecord()->promotion_numero.'e promotion';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\ActionGroup::make([
                ExportsRetenusActions::attribuer(Actions\Action::make('attribuerMatricules')),
                ExportsRetenusActions::fullData(Actions\Action::make('exportFullData')),
                ExportsRetenusActions::acma(Actions\Action::make('exportAcma')),
                ExportsRetenusActions::moodle(Actions\Action::make('exportMoodle')),
            ])->label('Matricules et exports')->icon('heroicon-o-identification')->button()->color('gray'),
            Actions\ActionGroup::make([
                DocumentsAuditeursActions::campagne(Actions\Action::make('accesFoad'), ParametresLettres::DOCUMENT_ACCES),
                DocumentsAuditeursActions::campagne(Actions\Action::make('lettresAdmission'), ParametresLettres::DOCUMENT_ADMISSION),
            ])->label('Documents des auditeurs')->icon('heroicon-o-document-text')->button()->color('gray'),
        ];
    }
}
