<?php

declare(strict_types=1);

namespace App\Filament\Resources\DocumentResource;

use App\Filament\Resources\DocumentResource;
use App\Models\Document;
use App\Models\MotCle;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Illuminate\Support\Number;

/**
 * Fiche en lecture seule (page « Voir ») : notice, auteurs et thèmes,
 * fichier et couverture, statut, accès et compteurs. Aucune lecture du
 * stockage (taille et format viennent de la base, l'URL de couverture est
 * calculée) : pas de HeadObject en prod.
 */
final class DocumentInfolist
{
    public static function make(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            self::notice(),
            Section::make('Auteurs et thèmes')->columns(2)->schema([
                TextEntry::make('authors.author_name')->label('Auteurs')->listWithLineBreaks()->placeholder('—'),
                TextEntry::make('themes')->label('Thèmes')->badge()->placeholder('—')
                    ->getStateUsing(fn (Document $record): array => $record->motsCles
                        ->map(fn (MotCle $m): string => (string) ($m->getTranslation('label', 'fr', false) ?: $m->slug))
                        ->all()),
            ]),
            self::fileAndCover(),
            self::statusAndAccess(),
        ]);
    }

    private static function notice(): Section
    {
        return Section::make('Notice')->columns(3)->schema([
            TextEntry::make('title')->label('Titre')->columnSpanFull()->weight('bold')
                ->getStateUsing(fn (Document $record): string => (string) $record->getTranslation('title', 'fr', false)),
            TextEntry::make('subtitle')->label('Sous-titre')->columnSpanFull()->placeholder('—')
                ->getStateUsing(fn (Document $record): ?string => $record->getTranslation('subtitle', 'fr', false) ?: null),
            TextEntry::make('abstract')->label('Résumé')->columnSpanFull()->placeholder('—')
                ->getStateUsing(fn (Document $record): ?string => $record->getTranslation('abstract', 'fr', false) ?: null),
            TextEntry::make('type')->label('Type')->badge()->formatStateUsing(fn (string $state): string => Document::TYPES[$state] ?? $state),
            TextEntry::make('language')->label('Langue')->formatStateUsing(fn (string $state): string => Document::LANGUAGES[$state] ?? $state),
            TextEntry::make('year')->label('Année')->placeholder('—'),
            TextEntry::make('specialite')->label('Spécialité')->placeholder('—')
                ->formatStateUsing(fn (string $state): string => (string) (config('specialites')[$state] ?? $state)),
            TextEntry::make('promotion')->label('Promotion')->placeholder('—'),
            TextEntry::make('pages_count')->label('Pages')->placeholder('—'),
            TextEntry::make('journal')->label('Revue')->placeholder('—'),
            TextEntry::make('doi')->label('DOI')->placeholder('—'),
            TextEntry::make('isbn')->label('ISBN')->placeholder('—'),
            TextEntry::make('slug')->label('Adresse')->formatStateUsing(fn (string $state): string => '/documents/'.$state),
        ]);
    }

    private static function fileAndCover(): Section
    {
        return Section::make('Fichier et couverture')->columns(3)->schema([
            TextEntry::make('file_name')->label('Fichier')->placeholder('Aucun fichier')
                ->getStateUsing(fn (Document $record): ?string => $record->file_path === null ? null : basename($record->file_path)),
            TextEntry::make('file_format')->label('Format')->placeholder('—')
                ->getStateUsing(fn (Document $record): ?string => $record->fileExtension() === null ? null : strtoupper((string) $record->fileExtension())),
            TextEntry::make('file_size_bytes')->label('Taille')->placeholder('—')
                ->formatStateUsing(fn (int $state): string => Number::fileSize($state, precision: 1)),
            TextEntry::make('external_url')->label('Lien externe')->placeholder('—')->columnSpanFull()
                ->url(fn (Document $record): ?string => $record->external_url, shouldOpenInNewTab: true),
            ImageEntry::make('cover')->label('Couverture')->height(240)->placeholder('Aucune couverture')
                ->getStateUsing(fn (Document $record): ?string => $record->coverUrl())
                ->checkFileExistence(false)
                ->extraImgAttributes(['alt' => 'Couverture du document']),
        ]);
    }

    private static function statusAndAccess(): Section
    {
        return Section::make('Statut, accès et consultation')->columns(3)->schema([
            TextEntry::make('status')->label('Statut')->badge()
                ->formatStateUsing(fn (string $state): string => DocumentResource::STATUS_LABELS[$state] ?? $state),
            TextEntry::make('access_level')->label('Niveau d\'accès')
                ->formatStateUsing(fn (string $state): string => Document::ACCESS_LEVELS[$state] ?? $state),
            TextEntry::make('price_xaf')->label('Prix (FCFA)')->placeholder('Gratuit'),
            TextEntry::make('published_at')->label('Publié le')->dateTime('d/m/Y H:i')->placeholder('—'),
            TextEntry::make('approver.name')->label('Validé par')->placeholder('—'),
            TextEntry::make('approved_at')->label('Validé le')->dateTime('d/m/Y H:i')->placeholder('—'),
            TextEntry::make('created_at')->label('Créé le')->dateTime('d/m/Y H:i'),
            TextEntry::make('updated_at')->label('Modifié le')->dateTime('d/m/Y H:i'),
            TextEntry::make('views_count')->label('Vues')->numeric(),
            TextEntry::make('downloads_count')->label('Téléchargements')->numeric(),
        ]);
    }
}
