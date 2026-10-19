<?php

declare(strict_types=1);

namespace App\Filament\Resources\DocumentResource\Pages;

use App\Filament\Forms\Components\PrivateFileUpload;
use App\Filament\Resources\DocumentResource;
use App\Jobs\ProcessDocumentFile;
use App\Models\Document;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditDocument extends EditRecord
{
    protected static string $resource = DocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->regenerateCoverAction(),
            $this->customCoverAction(),
            Actions\DeleteAction::make(),
        ];
    }

    /**
     * Relance le traitement du PDF en mode forcé : la couverture est
     * recalculée même si elle existe, y compris personnalisée.
     */
    private function regenerateCoverAction(): Actions\Action
    {
        return Actions\Action::make('regenererCouverture')->label('Régénérer la couverture')->icon('heroicon-o-arrow-path')->color('gray')
            ->authorize(fn (Document $record): bool => self::managesCover($record))
            ->visible(fn (Document $record): bool => $record->file_path !== null)
            ->requiresConfirmation()
            ->modalDescription('La couverture est recalculée à partir de la première page du PDF ; une couverture personnalisée est remplacée.')
            ->action(function (Document $record): void {
                abort_unless(self::managesCover($record) && $record->file_path !== null, 403);

                ProcessDocumentFile::dispatch($record->id, forceCover: true);

                activity('documents')->causedBy(auth()->user())->performedOn($record)
                    ->event('cover_regeneration_requested')
                    ->log('Régénération de la couverture demandée');

                Notification::make()->title('Régénération lancée')
                    ->body('La nouvelle couverture apparaîtra d\'ici quelques instants.')
                    ->success()->send();
            });
    }

    /**
     * Couverture déposée à la main, sur le disque public des couvertures.
     * Le champ n'admet qu'un nouveau dépôt (PrivateFileUpload refuse toute
     * chaîne qui n'est pas déjà enregistrée) : pas de chemin forgé.
     */
    private function customCoverAction(): Actions\Action
    {
        return Actions\Action::make('couverturePersonnalisee')->label('Couverture personnalisée')->icon('heroicon-o-photo')->color('gray')
            ->authorize(fn (Document $record): bool => self::managesCover($record))
            ->modalDescription('Remplace la couverture tirée du PDF. Elle n\'est plus recalculée ensuite, sauf « Régénérer la couverture ».')
            ->form([
                PrivateFileUpload::make('cover')->label('Image')
                    ->disk((string) config('library.covers_disk'))->directory(Document::customCoversDirectory())
                    ->visibility('public')
                    ->image()->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])->maxSize(5120)
                    // Pas de HeadObject en prod (MinIO).
                    ->fetchFileInformation(false)
                    ->required(),
            ])
            ->action(function (Document $record, array $data): void {
                abort_unless(self::managesCover($record), 403);

                $record->update(['cover_path' => (string) $data['cover']]);

                Notification::make()->title('Couverture personnalisée enregistrée')->success()->send();
            });
    }

    /** Catalogue seulement (pas les contributeurs), et la Policy pour un document en ligne. */
    private static function managesCover(Document $record): bool
    {
        return DocumentResource::managesCatalogue() && (bool) auth()->user()?->can('update', $record);
    }

    /**
     * Un contributeur ne change ni l'accès ni le prix, même par une requête forgée.
     * (Filament revérifie `update` à chaque requête : un brouillon soumis en
     * revue entre-temps lui est fermé.)
     *
     * @param  array<string, mixed>  $data
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return DocumentResource::enforceContributorUpdateData($data);
    }

    /** Les auteurs et mots-clés sont enregistrés : l'index reflète l'état final. */
    protected function afterSave(): void
    {
        /** @var Document $document */
        $document = $this->getRecord();
        $document->syncSearchIndex();
    }
}
