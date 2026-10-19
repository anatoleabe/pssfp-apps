<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Filament\Resources\DocumentResource;
use App\Models\Document;
use Filament\Tables;

/** Colonnes et lien communs aux classements de documents du tableau de bord. */
trait ListsTopDocuments
{
    /** @return array<int, Tables\Columns\Column> */
    protected function topDocumentColumns(string $countAttribute, string $countLabel): array
    {
        return [
            Tables\Columns\TextColumn::make('title')->label('Titre')
                ->state(fn (Document $r): string => (string) ($r->getTranslation('title', 'fr', false) ?: $r->slug))
                ->url(fn (Document $r): ?string => DocumentResource::recordUrl($r))
                ->limit(70),
            Tables\Columns\TextColumn::make('type')->label('Type')
                ->formatStateUsing(fn (?string $state): string => Document::TYPES[$state] ?? (string) $state),
            Tables\Columns\TextColumn::make($countAttribute)->label($countLabel)->numeric(),
        ];
    }
}
