<?php

declare(strict_types=1);

namespace App\Filament\Resources\AuditeurResource\Pages;

use App\Filament\Resources\AuditeurResource;
use App\Filament\Resources\AuditeurResource\AuditeurActions;
use App\Filament\Resources\CandidatureResource;
use App\Filament\Resources\CandidatureResource\AjoutRetenuActions;
use App\Filament\Resources\CandidatureResource\ComptesEcampusActions;
use App\Filament\Resources\CandidatureResource\DocumentsAuditeursActions;
use App\Filament\Resources\CandidatureResource\EspaceCandidatActions;
use App\Filament\Resources\CandidatureResource\IdentifiantsMoodleActions;
use App\Filament\Resources\CandidatureResource\LettresSigneesActions;
use App\Models\Auditeur;
use App\Services\DocumentsAuditeurs\ParametresLettres;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Resources\Pages\ViewRecord;

/**
 * Fiche auditeur (spec module 4, lots 1 et 2). Les gestes sur le dossier (accès
 * eCampus, documents, téléphone) réutilisent les actions des candidatures : elles
 * reçoivent la candidature de l'auditeur, avec leurs contrôles et leurs journaux.
 */
class ViewAuditeur extends ViewRecord
{
    protected static string $resource = AuditeurResource::class;

    /** Actions dont l'enregistrement est la candidature de l'auditeur. */
    private const ACTIONS_DU_DOSSIER = [
        'creerCompteEcampus', 'identifiantsMoodle', 'envoyerIdentifiantsMoodle', 'voirEspace', 'nouveauCode',
        'lettreAccesFoad', 'lettreAdmission', 'deposerLettreSignee', 'voirLettreSignee', 'retirerLettreSignee',
        'corrigerTelephone',
    ];

    public function getTitle(): string
    {
        /** @var Auditeur $auditeur */
        $auditeur = $this->getRecord();

        return trim(mb_strtoupper((string) $auditeur->candidature?->nom).' '.$auditeur->candidature?->prenom).' — '.$auditeur->candidature?->matricule;
    }

    protected function configureAction(Action $action): void
    {
        parent::configureAction($action);

        if (in_array($action->getName(), self::ACTIONS_DU_DOSSIER, true)) {
            /** @var Auditeur $auditeur */
            $auditeur = $this->getRecord();
            $dossier = $auditeur->candidature;
            // Gestes des admissions : ils exigent aussi l'accès à ce module et le droit de voir
            // ce dossier, comme depuis les candidatures. Sans dossier (supprimé), rien.
            $action->record($dossier)
                ->hidden(fn (): bool => $dossier === null || ! CandidatureResource::canAccess() || ! CandidatureResource::canView($dossier));
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            AuditeurActions::enregistrerVersement(),
            AuditeurActions::accorderRemise(),
            AuditeurActions::renvoyerSms(),
            AuditeurActions::creerInscription(),
            ActionGroup::make([
                ComptesEcampusActions::creer(Action::make('creerCompteEcampus')),
                IdentifiantsMoodleActions::consulter(Action::make('identifiantsMoodle')),
                IdentifiantsMoodleActions::envoyer(Action::make('envoyerIdentifiantsMoodle')),
                EspaceCandidatActions::voirEspace(Action::make('voirEspace')),
                EspaceCandidatActions::nouveauCode(Action::make('nouveauCode')),
            ])->label('Accès eCampus')->icon('heroicon-o-key')->button()->color('gray'),
            ActionGroup::make([
                DocumentsAuditeursActions::dossier(Action::make('lettreAdmission'), ParametresLettres::DOCUMENT_ADMISSION),
                DocumentsAuditeursActions::dossier(Action::make('lettreAccesFoad'), ParametresLettres::DOCUMENT_ACCES),
                LettresSigneesActions::deposer(Action::make('deposerLettreSignee')),
                LettresSigneesActions::voir(Action::make('voirLettreSignee')),
                LettresSigneesActions::retirer(Action::make('retirerLettreSignee')),
            ])->label('Documents')->icon('heroicon-o-document-text')->button()->color('gray'),
            ActionGroup::make([
                AuditeurActions::changerStatut(),
                AjoutRetenuActions::corrigerTelephone(Action::make('corrigerTelephone')),
                Action::make('dossier')
                    ->label('Dossier de candidature')
                    ->icon('heroicon-o-document-magnifying-glass')
                    ->visible(fn (Auditeur $record): bool => $record->candidature !== null && CandidatureResource::canView($record->candidature))
                    ->url(fn (Auditeur $record): string => CandidatureResource::getUrl('view', ['record' => $record->candidature])),
            ])->label('Dossier')->icon('heroicon-o-ellipsis-vertical')->button()->color('gray'),
        ];
    }
}
