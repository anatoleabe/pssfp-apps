<?php

declare(strict_types=1);

namespace App\Filament\Resources\EtatPaieResource\Pages;

use App\Filament\Resources\AuditeurResource\MessagesValidation;
use App\Filament\Resources\EtatPaieResource;
use App\Filament\Resources\QuotaHoraireResource\ChampsProgrammation;
use App\Models\EtatPaie;
use App\Services\Lmd\EtatsPaie;
use App\Services\Lmd\PermissionsLmd;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms;
use Filament\Resources\Pages\ViewRecord;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Fiche d'un état de paie : actions du circuit selon l'état et la permission, régénération, rectificatif, PDF. */
class ViewEtatPaie extends ViewRecord
{
    protected static string $resource = EtatPaieResource::class;

    public function getTitle(): string
    {
        return 'État de paie '.$this->etat()->numero;
    }

    private function etat(): EtatPaie
    {
        /** @var EtatPaie $e */
        $e = $this->getRecord();

        return $e;
    }

    private function peut(string $permission): bool
    {
        return (bool) auth()->user()?->can($permission);
    }

    /** Exécute une opération puis recharge la fiche. */
    private function executer(callable $operation): void
    {
        ChampsProgrammation::executer($operation);
        $this->redirect(EtatPaieResource::getUrl('view', ['record' => $this->etat()->refresh()]));
    }

    protected function getHeaderActions(): array
    {
        $svc = fn (): EtatsPaie => app(EtatsPaie::class);
        $statut = fn (): string => $this->etat()->statut;

        return [
            Action::make('verifier')->label('Vérifier')->icon('heroicon-o-check-badge')->color('success')
                ->visible(fn (): bool => $statut() === EtatPaie::BROUILLON && $this->peut(PermissionsLmd::PAIE_VERIFIER))
                ->requiresConfirmation()->modalDescription('Vous attestez avoir contrôlé les lignes de l\'état. Il ne se régénère plus.')
                ->action(fn () => $this->executer(function () use ($svc): string {
                    $svc()->avancer($this->etat(), EtatPaie::VERIFIE, auth()->user());

                    return 'État vérifié.';
                })),
            Action::make('viser')->label('Viser')->icon('heroicon-o-shield-check')->color('success')
                ->visible(fn (): bool => $statut() === EtatPaie::VERIFIE && $svc()->visaRequis() && $this->peut(PermissionsLmd::PAIE_VISER))
                ->requiresConfirmation()
                ->action(fn () => $this->executer(function () use ($svc): string {
                    $svc()->avancer($this->etat(), EtatPaie::VISE, auth()->user());

                    return 'État visé.';
                })),
            Action::make('transmettre')->label('Transmettre au service financier')->icon('heroicon-o-paper-airplane')
                ->visible(fn (): bool => in_array($statut(), [EtatPaie::VERIFIE, EtatPaie::VISE], true) && $this->peut(PermissionsLmd::PAIE_VERIFIER))
                ->form(MessagesValidation::appliquer([Forms\Components\TextInput::make('reference')->label('Référence du bordereau de transmission')->required()->maxLength(100)]))
                ->action(fn (array $data) => $this->executer(function () use ($svc, $data): string {
                    $svc()->avancer($this->etat(), EtatPaie::TRANSMIS, auth()->user(), (string) $data['reference']);

                    return 'État transmis au service financier.';
                })),
            Action::make('prendreEnCharge')->label('Prendre en charge')->icon('heroicon-o-inbox-arrow-down')->color('success')
                ->visible(fn (): bool => $statut() === EtatPaie::TRANSMIS && $this->peut(PermissionsLmd::PAIE_PAYER))
                ->requiresConfirmation()->modalDescription('Après la prise en charge, l\'état ne se rejette plus : une erreur se corrige par un état rectificatif.')
                ->action(fn () => $this->executer(function () use ($svc): string {
                    $svc()->avancer($this->etat(), EtatPaie::PRIS_EN_CHARGE, auth()->user());

                    return 'État pris en charge.';
                })),
            Action::make('payer')->label('Enregistrer le virement')->icon('heroicon-o-banknotes')->color('success')
                ->visible(fn (): bool => $statut() === EtatPaie::PRIS_EN_CHARGE && $this->peut(PermissionsLmd::PAIE_PAYER))
                ->form(MessagesValidation::appliquer([
                    Forms\Components\DatePicker::make('date')->label('Date du virement')->required()->native(false)->displayFormat('d/m/Y')->maxDate(now('Africa/Douala')),
                    Forms\Components\TextInput::make('reference')->label('Référence du virement')->required()->maxLength(100),
                ]))
                ->action(fn (array $data) => $this->executer(function () use ($svc, $data): string {
                    $svc()->avancer($this->etat(), EtatPaie::PAYE, auth()->user(), (string) $data['reference'], (string) $data['date']);

                    return 'Virement enregistré : état payé.';
                })),
            ActionGroup::make([
                Action::make('pdfEtat')->label('État (PDF)')->icon('heroicon-o-document-text')
                    ->action(fn (): StreamedResponse => $this->pdf('pdf.lmd.etat-paie', 'landscape', mb_strtolower($this->etat()->numero).'.pdf')),
                Action::make('pdfBordereau')->label('Bordereau de transmission (PDF)')->icon('heroicon-o-document-duplicate')
                    ->action(fn (): StreamedResponse => $this->pdf('pdf.lmd.bordereau-paie', 'portrait', 'bordereau-'.mb_strtolower($this->etat()->numero).'.pdf')),
                Action::make('regenerer')->label('Régénérer')->icon('heroicon-o-arrow-path')
                    ->visible(fn (): bool => $statut() === EtatPaie::BROUILLON && $this->peut(PermissionsLmd::PAIE_PREPARER))
                    ->requiresConfirmation()->modalDescription('Les lignes sont recalculées à partir des pointages validés du mois (après une validation, un dossier complété ou un barème validé).')
                    ->action(fn () => $this->executer(function () use ($svc): string {
                        $svc()->regenerer($this->etat(), auth()->user());

                        return 'État régénéré.';
                    })),
                Action::make('rectificatif')->label('Préparer un état rectificatif')->icon('heroicon-o-document-plus')
                    ->visible(fn (): bool => in_array($statut(), [EtatPaie::PRIS_EN_CHARGE, EtatPaie::PAYE], true) && $this->etat()->rectificatif_de === null
                        && $this->peut(PermissionsLmd::PAIE_PREPARER))
                    ->requiresConfirmation()->modalDescription('Il reprend les pointages validés du mois qui ne sont pas encore payés.')
                    ->action(function () use ($svc): void {
                        $nouveau = null;
                        ChampsProgrammation::executer(function () use ($svc, &$nouveau): string {
                            $nouveau = $svc()->creerRectificatif($this->etat(), auth()->user());

                            return 'État rectificatif '.$nouveau->numero.' préparé.';
                        });
                        $this->redirect(EtatPaieResource::getUrl('view', ['record' => $nouveau]));
                    }),
                Action::make('rejeter')->label('Rejeter')->icon('heroicon-o-x-circle')->color('danger')
                    ->visible(fn (): bool => in_array($statut(), EtatPaie::REJETABLES, true)
                        && ($this->peut(PermissionsLmd::PAIE_VERIFIER) || ($statut() === EtatPaie::TRANSMIS && $this->peut(PermissionsLmd::PAIE_PAYER))))
                    ->modalDescription('Les pointages de l\'état sont libérés : un nouvel état du mois pourra les reprendre.')
                    ->form(MessagesValidation::appliquer([Forms\Components\Textarea::make('motif')->label('Motif du rejet')->required()->minLength(5)->maxLength(500)],
                        ['min' => 'Le motif compte au moins 5 caractères.']))
                    ->action(fn (array $data) => $this->executer(function () use ($svc, $data): string {
                        $svc()->rejeter($this->etat(), (string) $data['motif'], auth()->user());

                        return 'État rejeté.';
                    })),
            ])->label('Plus')->icon('heroicon-o-ellipsis-vertical')->button()->color('gray'),
        ];
    }

    private function pdf(string $vue, string $orientation, string $nom): StreamedResponse
    {
        $e = $this->etat();
        $contenu = Pdf::loadView($vue, ['etat' => $e, 'synthese' => app(EtatsPaie::class)->synthese($e), 'edite_le' => now('Africa/Douala')])
            ->setPaper('a4', $orientation)->output();
        activity('lmd')->causedBy(auth()->user())->performedOn($e)->event('etat_paie_telecharge')->withProperties(['vue' => $vue])->log('PDF d\'état de paie téléchargé');

        return response()->streamDownload(function () use ($contenu): void {
            echo $contenu;
        }, $nom, ['Content-Type' => 'application/pdf']);
    }
}
