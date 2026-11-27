<?php

declare(strict_types=1);

namespace App\Filament\Resources\AuditeurResource\Pages;

use App\Filament\Resources\AuditeurResource;
use App\Filament\Resources\AuditeurResource\AccesAnticipe;
use App\Filament\Resources\AuditeurResource\AuditeurActions;
use App\Filament\Resources\AuditeurResource\CompteEcampusActions;
use App\Filament\Resources\AuditeurResource\DemandeMoratoireActions;
use App\Filament\Resources\AuditeurResource\DocumentsAdministratifsActions;
use App\Filament\Resources\AuditeurResource\EtiquettesActions;
use App\Filament\Resources\AuditeurResource\ParcoursActions;
use App\Filament\Resources\AuditeurResource\SuspensionActions;
use App\Filament\Resources\CandidatureResource;
use App\Filament\Resources\CandidatureResource\AjoutRetenuActions;
use App\Filament\Resources\CandidatureResource\ComptesEcampusActions;
use App\Filament\Resources\CandidatureResource\CorrectionDossierActions;
use App\Filament\Resources\CandidatureResource\DocumentsAuditeursActions;
use App\Filament\Resources\CandidatureResource\EspaceCandidatActions;
use App\Filament\Resources\CandidatureResource\IdentifiantsMoodleActions;
use App\Filament\Resources\CandidatureResource\LettresSigneesActions;
use App\Models\Auditeur;
use App\Models\BaremeScolarite;
use App\Services\DocumentsAuditeurs\DocumentsAuditeurs;
use App\Services\DocumentsAuditeurs\ParametresLettres;
use App\Services\Scolarite\CompteAuditeur;
use App\Services\Scolarite\LibelleSituation;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\IconPosition;
use Throwable;

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
        'corrigerTelephone', 'corrigerDossier',
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
            // Exception : la scolarité télécharge la lettre d'accès d'un auditeur soldé depuis
            // sa fiche (13/11/2026) ; l'action garde ses propres contrôles (droits payés, un seul dossier).
            $parLaScolarite = $action->getName() === 'lettreAccesFoad'
                && (bool) auth()->user()?->can(DocumentsAuditeurs::PERMISSION_LETTRE_ACCES_DOSSIER);
            $action->record($dossier)
                ->hidden(fn (): bool => $dossier === null
                    || (! $parLaScolarite && (! CandidatureResource::canAccess() || ! CandidatureResource::canView($dossier))));
        }
    }

    /**
     * Bandeau résumé sous le nom (lot 32) : année et niveau, parcours, statut, reste à payer, accès eCampus.
     */
    public function getSubheading(): ?string
    {
        /** @var Auditeur $auditeur */
        $auditeur = $this->getRecord();
        $inscription = $auditeur->inscriptionCourante;
        $parts = [];
        if ($inscription !== null) {
            $parts[] = $inscription->annee_academique.' · '.(BaremeScolarite::NIVEAUX[$inscription->niveau] ?? $inscription->niveau)
                .($inscription->parcours !== null ? ' · '.$inscription->parcours->libelle : '');
        }
        $parts[] = Auditeur::STATUTS[$auditeur->statut] ?? $auditeur->statut;
        try {
            $parts[] = 'Reste à payer : '.LibelleSituation::fcfa(CompteAuditeur::pour($auditeur)->resteParcours());
        } catch (Throwable $e) {
            report($e);
        }
        $parts[] = 'eCampus : '.AuditeurResource::libelleAccesCourt($auditeur);

        return implode(' · ', $parts);
    }

    /** La liste des versements est dans l'onglet « Finances » de la fiche, plus sous la fiche (lot 32). */
    public function getRelationManagers(): array
    {
        return [];
    }

    /**
     * Lot 32 (choix du Chef de projet du 25/11/2026) : le geste principal du rôle, puis un seul menu « Actions »,
     * rangé par rubrique (finances, documents, scolarité, accès et messages, dossier). Les noms des gestes ne changent
     * pas : leurs contrôles et leurs journaux restent ceux d'avant.
     */
    protected function getHeaderActions(): array
    {
        return [
            AuditeurActions::enregistrerVersement(),
            ActionGroup::make([
                ActionGroup::make([
                    AuditeurActions::accorderRemise(),
                    AuditeurActions::extraitCompte(),
                    DemandeMoratoireActions::demander(),
                    DemandeMoratoireActions::enAttenteAction(),
                    AccesAnticipe::debloquer(),
                    AccesAnticipe::prolonger(),
                    AccesAnticipe::revoquer(),
                    AccesAnticipe::voirPiece(),
                ])->dropdown(false),
                ActionGroup::make([
                    AuditeurActions::certificatScolarite(),
                    DocumentsAdministratifsActions::attestationRecherche(),
                    DocumentsAdministratifsActions::lettreStage(),
                    DocumentsAuditeursActions::dossier(Action::make('lettreAdmission'), ParametresLettres::DOCUMENT_ADMISSION),
                    DocumentsAuditeursActions::dossier(Action::make('lettreAccesFoad'), ParametresLettres::DOCUMENT_ACCES),
                    LettresSigneesActions::deposer(Action::make('deposerLettreSignee')),
                    LettresSigneesActions::voir(Action::make('voirLettreSignee')),
                    LettresSigneesActions::retirer(Action::make('retirerLettreSignee')),
                ])->dropdown(false),
                ActionGroup::make([
                    AuditeurActions::creerInscription(),
                    AuditeurActions::reinscrire(),
                    ParcoursActions::inscrire(),
                    ParcoursActions::derogationRattrapage(),
                    ParcoursActions::leverRestriction(),
                    ParcoursActions::revoquerDocument(),
                ])->dropdown(false),
                ActionGroup::make([
                    ComptesEcampusActions::creer(Action::make('creerCompteEcampus')),
                    IdentifiantsMoodleActions::consulter(Action::make('identifiantsMoodle')),
                    IdentifiantsMoodleActions::envoyer(Action::make('envoyerIdentifiantsMoodle')),
                    CompteEcampusActions::verifier(),
                    CompteEcampusActions::reinitialiser(),
                    CompteEcampusActions::ouvrir(),
                    EspaceCandidatActions::voirEspace(Action::make('voirEspace')),
                    EspaceCandidatActions::nouveauCode(Action::make('nouveauCode')),
                    AuditeurActions::renvoyerSms(),
                    AuditeurActions::envoyerLienLettre(),
                ])->dropdown(false),
                ActionGroup::make([
                    EtiquettesActions::gerer(),
                    AuditeurActions::changerStatut(),
                    SuspensionActions::suspendre(),
                    SuspensionActions::lever(),
                    SuspensionActions::reessayerEcampus(),
                    SuspensionActions::voirDemande(),
                    CorrectionDossierActions::corriger(Action::make('corrigerDossier')),
                    AjoutRetenuActions::corrigerTelephone(Action::make('corrigerTelephone')),
                    Action::make('dossier')
                        ->label('Dossier de candidature')
                        ->icon('heroicon-o-document-magnifying-glass')
                        ->visible(fn (Auditeur $record): bool => $record->candidature !== null && CandidatureResource::canView($record->candidature))
                        ->url(fn (Auditeur $record): string => CandidatureResource::getUrl('view', ['record' => $record->candidature])),
                ])->dropdown(false),
            ])->label('Actions')->icon('heroicon-m-chevron-down')->iconPosition(IconPosition::After)->button()->color('gray'),
        ];
    }
}
