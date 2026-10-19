<?php

declare(strict_types=1);

namespace App\Filament\Resources\DocumentResource\Pages;

use App\Filament\Resources\DocumentResource;
use App\Models\Document;
use App\Services\Library\LibraryStatsCsvExport;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

/**
 * Liste des documents, organisée en file de validation : « À valider » en
 * tête, ouverte d'emblée pour un validateur quand elle n'est pas vide.
 * Les compteurs partent de la requête de la ressource, donc du périmètre
 * du compte (un contributeur ne compte que ses dépôts).
 */
class ListDocuments extends ListRecords
{
    protected static string $resource = DocumentResource::class;

    private ?int $inReviewCount = null;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('exportStats')
                ->label('Exporter les statistiques (CSV)')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->visible(fn (): bool => $this->canExportStats())
                ->action(function () {
                    abort_unless($this->canExportStats(), 403);

                    return app(LibraryStatsCsvExport::class)->download();
                }),
            Actions\CreateAction::make(),
        ];
    }

    private function canExportStats(): bool
    {
        return (bool) auth()->user()?->can('library.stats_export');
    }

    public function getTabs(): array
    {
        $inReview = $this->inReviewCount();

        return [
            'a_valider' => Tab::make('À valider')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', Document::STATUS_IN_REVIEW))
                ->badge($inReview > 0 ? $inReview : null)
                ->badgeColor('warning'),
            'brouillons' => Tab::make('Brouillons')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', Document::STATUS_DRAFT)),
            'publies' => Tab::make('Publiés')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', Document::STATUS_PUBLISHED)),
            'archives' => Tab::make('Archivés')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', Document::STATUS_ARCHIVED)),
            'tous' => Tab::make('Tous'),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        $canPublish = (bool) auth()->user()?->can('document.publish');

        return $canPublish && $this->inReviewCount() > 0 ? 'a_valider' : 'tous';
    }

    private function inReviewCount(): int
    {
        return $this->inReviewCount ??= DocumentResource::getEloquentQuery()
            ->where('status', Document::STATUS_IN_REVIEW)
            ->count();
    }
}
