<?php

declare(strict_types=1);

namespace App\Filament\Resources\PromotionResource\Pages;

use App\Filament\Resources\CampagneCandidatureResource\ExportsRetenusActions;
use App\Filament\Resources\CandidatureResource\DocumentsAuditeursActions;
use App\Filament\Resources\PromotionResource;
use App\Models\CampagneCandidature;
use App\Services\DocumentsAuditeurs\ParametresLettres;
use App\Services\Lmd\NumerotationSemestres;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

/** Réglages d'une promotion : n'enregistre que les champs de la promotion, jamais ceux de la campagne. */
class EditPromotion extends EditRecord
{
    protected static string $resource = PromotionResource::class;

    /** Numérotation des semestres avant l'enregistrement, pour tracer son changement. */
    private ?string $numerotationAvant = null;

    public function getTitle(): string
    {
        $numero = $this->getRecord()->promotion_numero;

        return $numero ? 'Réglages de la '.$numero.'e promotion' : 'Réglages de la promotion';
    }

    /**
     * - Les paramètres absents du formulaire (montants repris du barème) sont gardés :
     *   ils servent encore de repli tant qu'aucun barème n'existe.
     * - La signature n'accepte que l'existant ou un scan déposé par ce champ.
     * - Les copies restent une liste de textes.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $existants = (array) $this->getRecord()->parametres_lettres;
        $saisis = (array) ($data['parametres_lettres'] ?? []);
        if (array_key_exists('copies', $saisis)) {
            $saisis['copies'] = array_values(array_filter((array) $saisis['copies'], fn ($c): bool => is_string($c) && $c !== ''));
        }
        $data['parametres_lettres'] = array_merge($existants, $saisis);

        $signature = $data['signature_lettres_path'] ?? null;
        if ($signature !== null && $signature !== $this->getRecord()->signature_lettres_path
            && preg_match(PromotionResource::MOTIF_SIGNATURE, (string) $signature) !== 1) {
            $data['signature_lettres_path'] = $this->getRecord()->signature_lettres_path;
        }

        return $data;
    }

    protected function beforeSave(): void
    {
        $this->numerotationAvant = NumerotationSemestres::mode($this->getRecord());
    }

    /** Le changement de numérotation est tracé avec ses valeurs (le journal général ne note que le champ). */
    protected function afterSave(): void
    {
        /** @var CampagneCandidature $promotion */
        $promotion = $this->getRecord();
        $apres = NumerotationSemestres::mode($promotion);
        if ($this->numerotationAvant === null || $apres === $this->numerotationAvant) {
            return;
        }
        activity('scolarite')->causedBy(auth()->user())->performedOn($promotion)
            ->event('numerotation_semestres_modifiee')
            ->withProperties(['promotion' => $promotion->promotion_numero, 'avant' => $this->numerotationAvant, 'apres' => $apres])
            ->log('Numérotation des semestres modifiée');
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
