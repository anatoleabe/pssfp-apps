<?php

declare(strict_types=1);

namespace App\Filament\Resources\ActeResource\Pages;

use App\Filament\Resources\ActeResource;
use App\Filament\Resources\AuditeurResource\MessagesValidation;
use App\Models\Acte;
use App\Models\User;
use App\Services\Lmd\Actes;
use App\Services\Lmd\PermissionsLmd;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ViewActe extends ViewRecord
{
    protected static string $resource = ActeResource::class;

    public function getTitle(): string
    {
        return $this->getRecord()->modele->libelle;
    }

    protected function getHeaderActions(): array
    {
        $a = fn (): Acte => $this->getRecord()->refresh();
        $u = fn (): User => auth()->user();
        $peut = fn (string $p): bool => $u()->can($p);
        $s = fn (): Actes => app(Actes::class);

        return [
            Action::make('rediger')->label('Rédiger')->icon('heroicon-o-pencil')->color('primary')
                ->visible(fn (): bool => in_array($a()->statut, [Acte::A_PRODUIRE, Acte::BROUILLON], true) && $peut(PermissionsLmd::ACTES_PRODUIRE))
                ->fillForm(fn (): array => ['contenu' => $a()->contenu ?? $s()->texteInitial($a())])
                ->form(MessagesValidation::appliquer([
                    Forms\Components\Textarea::make('contenu')->label('Texte de l\'acte')->required()->minLength(10)->maxLength(20000)->rows(14),
                ]))
                ->action(fn (array $data) => $this->executer(function () use ($s, $a, $u, $data): string {
                    $s()->rediger($a(), (string) $data['contenu'], $u());

                    return 'Acte enregistré en brouillon.';
                })),
            Action::make('soumettre')->label('Soumettre à la signature')->icon('heroicon-o-paper-airplane')->color('primary')
                ->visible(fn (): bool => $a()->statut === Acte::BROUILLON && $peut(PermissionsLmd::ACTES_PRODUIRE))
                ->requiresConfirmation()->modalDescription('Le texte ne se modifie plus, sauf renvoi en brouillon.')
                ->action(fn () => $this->executer(function () use ($s, $a, $u): string {
                    $s()->soumettre($a(), $u());

                    return 'Acte soumis à la signature.';
                })),
            Action::make('signer')->label('Enregistrer la signature')->icon('heroicon-o-check-badge')->color('success')
                ->visible(fn (): bool => $a()->statut === Acte::SOUMIS && $peut(PermissionsLmd::ACTES_SIGNER))
                ->modalDescription('Numéro et date attribués par l\'autorité signataire, acte signé numérisé.')
                ->form(MessagesValidation::appliquer([
                    Forms\Components\TextInput::make('numero')->label('Numéro de l\'acte')->required()->maxLength(120)->placeholder('26/123/PSSFP/CS'),
                    Forms\Components\DatePicker::make('date')->label('Date de signature')->required()->native(false)->displayFormat('d/m/Y')->maxDate(now())->default(now()),
                    Forms\Components\TextInput::make('signataire')->label('Signataire')->required()->maxLength(255)->default(fn (): string => $a()->modele->signataire),
                    Forms\Components\FileUpload::make('piece')->label('Acte signé numérisé (PDF)')->required()->storeFiles(false)
                        ->acceptedFileTypes(['application/pdf'])->maxSize(10240),
                ], ['mimetypes' => 'Format accepté : PDF.']))
                ->action(fn (array $data) => $this->executer(function () use ($s, $a, $u, $data): string {
                    $s()->signer($a(), (string) $data['numero'], (string) $data['date'], (string) $data['signataire'], $data['piece'], $u());

                    return 'Signature enregistrée.';
                })),
            Action::make('diffuser')->label('Diffuser')->icon('heroicon-o-megaphone')->color('success')
                ->visible(fn (): bool => $a()->statut === Acte::SIGNE && $peut(PermissionsLmd::ACTES_SIGNER))
                ->requiresConfirmation()
                ->modalDescription(fn (): string => $a()->modele->diffusion_auditeurs
                    ? 'L\'acte signé devient visible dans l\'espace des auditeurs concernés.' : 'L\'acte est marqué diffusé ; il n\'est pas publié dans l\'espace des auditeurs.')
                ->action(fn () => $this->executer(function () use ($s, $a, $u): string {
                    $s()->diffuser($a(), $u());

                    return 'Acte diffusé.';
                })),
            ActionGroup::make([
                Action::make('projet')->label('Télécharger le projet (PDF)')->icon('heroicon-o-document-arrow-down')
                    ->visible(fn (): bool => $a()->estEnCours())
                    ->action(fn (): StreamedResponse => response()->streamDownload(fn () => print ($s()->projetPdf($a())),
                        'projet-'.Str::slug($a()->modele->code).'-'.$a()->echeance->format('Y-m-d').'.pdf', ['Content-Type' => 'application/pdf'])),
                Action::make('piece')->label('Télécharger l\'acte signé')->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn (): bool => $a()->piece_signee_path !== null)
                    ->action(function () use ($s, $a): ?StreamedResponse {
                        $contenu = $s()->piece($a());
                        if ($contenu === null) {
                            Notification::make()->danger()->title('Pièce momentanément indisponible')->send();

                            return null;
                        }

                        return response()->streamDownload(fn () => print ($contenu), 'acte-'.Str::slug((string) $a()->numero).'.pdf', ['Content-Type' => 'application/pdf']);
                    }),
                Action::make('renvoyer')->label('Renvoyer en brouillon')->icon('heroicon-o-arrow-uturn-left')
                    ->visible(fn (): bool => $a()->statut === Acte::SOUMIS && $peut(PermissionsLmd::ACTES_SIGNER))
                    ->form(MessagesValidation::appliquer([Forms\Components\Textarea::make('motif')->label('Motif')->required()->minLength(5)->maxLength(500)]))
                    ->action(fn (array $data) => $this->executer(function () use ($s, $a, $u, $data): string {
                        $s()->renvoyer($a(), (string) $data['motif'], $u());

                        return 'Acte renvoyé en brouillon.';
                    })),
                Action::make('annuler')->label('Annuler l\'acte')->icon('heroicon-o-x-circle')->color('danger')
                    ->visible(fn (): bool => $a()->estEnCours() && $peut(PermissionsLmd::ACTES_PRODUIRE))
                    ->form(MessagesValidation::appliquer([Forms\Components\Textarea::make('motif')->label('Motif')->required()->minLength(5)->maxLength(500)]))
                    ->action(fn (array $data) => $this->executer(function () use ($s, $a, $u, $data): string {
                        $s()->annuler($a(), (string) $data['motif'], $u());

                        return 'Acte annulé.';
                    })),
            ])->label('Autres actions')->button()->color('gray')->icon('heroicon-o-ellipsis-vertical'),
        ];
    }

    private function executer(callable $operation): void
    {
        try {
            $message = $operation();
        } catch (InvalidArgumentException $e) {
            Notification::make()->danger()->title('Opération refusée')->body($e->getMessage())->persistent()->send();

            return;
        }
        Notification::make()->success()->title($message)->send();
        $this->getRecord()->refresh();
    }
}
