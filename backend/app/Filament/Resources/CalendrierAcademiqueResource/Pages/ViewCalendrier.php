<?php

declare(strict_types=1);

namespace App\Filament\Resources\CalendrierAcademiqueResource\Pages;

use App\Filament\Resources\AuditeurResource\MessagesValidation;
use App\Filament\Resources\CalendrierAcademiqueResource;
use App\Models\CalendrierAcademique;
use App\Models\User;
use App\Services\Lmd\Calendriers;
use App\Services\Lmd\ControlesCalendrier;
use App\Services\Lmd\PermissionsLmd;
use App\Support\CandidatureObjectStorage;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ViewCalendrier extends ViewRecord
{
    protected static string $resource = CalendrierAcademiqueResource::class;

    public function getTitle(): string
    {
        return $this->getRecord()->libelle();
    }

    protected function getHeaderActions(): array
    {
        $c = fn (): CalendrierAcademique => $this->getRecord()->refresh();
        $u = fn (): User => auth()->user();
        $peut = fn (string $p): bool => $u()->can($p);
        $s = fn (): Calendriers => app(Calendriers::class);

        return [
            Action::make('soumettre')->label('Soumettre à la signature')->icon('heroicon-o-paper-airplane')->color('primary')
                ->visible(fn (): bool => $c()->statut === CalendrierAcademique::BROUILLON && $peut(PermissionsLmd::CALENDRIER_PREPARER))
                ->requiresConfirmation()->modalDescription('La soumission est refusée tant qu\'il reste une anomalie bloquante.')
                ->action(fn () => $this->executer(function () use ($s, $c, $u): string {
                    $s()->soumettre($c(), $u());

                    return 'Calendrier soumis à la signature.';
                })),
            Action::make('signer')->label('Signer')->icon('heroicon-o-pencil-square')->color('success')
                ->visible(fn (): bool => $c()->statut === CalendrierAcademique::SOUMIS && $peut(PermissionsLmd::CALENDRIER_SIGNER))
                ->modalDescription('La signature fige la référence du Gantt et remplace la version précédente.')
                ->form(MessagesValidation::appliquer([
                    Forms\Components\TextInput::make('signataire')->label('Signataire')->required()->maxLength(255)->default('Le Chef de la structure'),
                    Forms\Components\DatePicker::make('date')->label('Date de signature')->required()->native(false)->displayFormat('d/m/Y')->default(now()),
                    Forms\Components\FileUpload::make('piece')->label('Calendrier signé numérisé (PDF)')->required()->storeFiles(false)
                        ->acceptedFileTypes(['application/pdf'])->maxSize(10240),
                ], ['mimetypes' => 'Format accepté : PDF.']))
                ->action(fn (array $data) => $this->executer(function () use ($s, $c, $u, $data): string {
                    $s()->signer($c(), $data['piece'], (string) $data['signataire'], (string) $data['date'], $u());

                    return 'Calendrier signé : référence figée.';
                })),
            Action::make('renvoyer')->label('Renvoyer en préparation')->icon('heroicon-o-arrow-uturn-left')->color('gray')
                ->visible(fn (): bool => $c()->statut === CalendrierAcademique::SOUMIS && $peut(PermissionsLmd::CALENDRIER_SIGNER))
                ->form(MessagesValidation::appliquer([Forms\Components\Textarea::make('motif')->label('Motif')->required()->minLength(5)->maxLength(500)]))
                ->action(fn (array $data) => $this->executer(function () use ($s, $c, $u, $data): string {
                    $s()->renvoyer($c(), (string) $data['motif'], $u());

                    return 'Calendrier renvoyé en préparation.';
                })),
            Action::make('publier')->label('Publier')->icon('heroicon-o-megaphone')->color('success')
                ->visible(fn (): bool => $c()->statut === CalendrierAcademique::SIGNE && ($peut(PermissionsLmd::CALENDRIER_REAJUSTER) || $peut(PermissionsLmd::CALENDRIER_SIGNER)))
                ->requiresConfirmation()->modalDescription('Le calendrier publié est visible des auditeurs dans leur espace.')
                ->action(fn () => $this->executer(function () use ($s, $c, $u): string {
                    $s()->publier($c(), $u());

                    return 'Calendrier publié.';
                })),
            ActionGroup::make([
                Action::make('dependances')->label('Générer les dépendances des règles')->icon('heroicon-o-link')
                    ->visible(fn (): bool => $c()->estModifiable() && $peut($c()->estSigne() ? PermissionsLmd::CALENDRIER_REAJUSTER : PermissionsLmd::CALENDRIER_PREPARER))
                    ->action(fn () => $this->executer(fn (): string => app(ControlesCalendrier::class)->genererDependances($c()).' lien(s) créé(s).')),
                Action::make('version')->label('Nouvelle version (révision majeure)')->icon('heroicon-o-document-duplicate')
                    ->visible(fn (): bool => $c()->estSigne() && $peut(PermissionsLmd::CALENDRIER_PREPARER))
                    ->requiresConfirmation()->modalDescription('Les événements et les liens sont copiés dans un brouillon ; la version actuelle reste en vigueur jusqu\'à la signature de la nouvelle.')
                    ->action(function () use ($s, $c, $u): void {
                        try {
                            $n = $s()->nouvelleVersion($c(), $u());
                        } catch (InvalidArgumentException $e) {
                            Notification::make()->danger()->title('Opération refusée')->body($e->getMessage())->send();

                            return;
                        }
                        $this->redirect(CalendrierAcademiqueResource::getUrl('view', ['record' => $n]));
                    }),
                Action::make('piece')->label('Télécharger le calendrier signé')->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn (): bool => $c()->piece_signee_path !== null)
                    ->action(function () use ($c): ?StreamedResponse {
                        $contenu = CandidatureObjectStorage::read((string) $c()->piece_signee_path);
                        if ($contenu === null || hash('sha256', $contenu) !== $c()->piece_signee_sha256) {
                            Notification::make()->danger()->title('Pièce momentanément indisponible')->send();

                            return null;
                        }

                        return response()->streamDownload(fn () => print ($contenu), 'calendrier-'.$c()->annee_academique.'-v'.$c()->version.'.pdf', ['Content-Type' => 'application/pdf']);
                    }),
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
