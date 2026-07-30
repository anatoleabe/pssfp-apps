<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\CampagneCandidatureResource\Pages;
use App\Models\CampagneCandidature;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

/**
 * Resource Filament minimaliste pour gérer les campagnes (PR D arbitrage A1).
 *
 * Permet à super_admin / admin d'ouvrir P15/2027 le moment venu sans passer
 * par tinker. Pas de delete (FK RESTRICT).
 */
class CampagneCandidatureResource extends Resource
{
    protected static ?string $model = CampagneCandidature::class;

    protected static ?string $modelLabel = 'Campagne';

    protected static ?string $pluralModelLabel = 'Campagnes';

    protected static ?string $navigationIcon = 'heroicon-o-megaphone';

    protected static ?string $navigationGroup = 'Module 5 — Admissions';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'nom';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Campagne')->schema([
                Forms\Components\TextInput::make('slug')->required()->unique(ignoreRecord: true)->maxLength(50),
                Forms\Components\TextInput::make('nom')->required()->maxLength(100),
                Forms\Components\TextInput::make('promotion_numero')->required()->numeric()->minValue(1),
                Forms\Components\TextInput::make('prefix_numero')->required()->maxLength(20)
                    ->helperText('Ex : P14026- pour Promotion 14, année 2026.'),
                Forms\Components\Select::make('status')->required()->options([
                    'draft' => 'Brouillon',
                    'open' => 'Ouverte',
                    'closed' => 'Clôturée',
                    'archived' => 'Archivée',
                ]),
                Forms\Components\TextInput::make('max_voeux')->numeric()->default(1)->minValue(1)->maxValue(5),
                Forms\Components\DateTimePicker::make('opens_at')->required()->native(false),
                Forms\Components\DateTimePicker::make('closes_at')->required()->native(false)->after('opens_at'),
                Forms\Components\DateTimePicker::make('results_at')->native(false)->after('closes_at'),
            ])->columns(2),

            Forms\Components\Section::make('Communiqué conjoint signé')
                ->description('Publié en téléchargement sur la page d\'accueil de apply.pssfp.org dès qu\'un PDF est déposé ici.')
                ->schema([
                    Forms\Components\FileUpload::make('communique_pdf_path')
                        ->label('PDF du communiqué signé')
                        ->disk(CampagneCandidature::COMMUNIQUE_DISK)
                        ->directory('communiques')
                        ->visibility('public')
                        ->acceptedFileTypes(['application/pdf'])
                        ->maxSize(10240)
                        ->downloadable()
                        ->openable()
                        ->previewable(false)
                        ->helperText('PDF uniquement, 10 Mo maximum. Le scan signé MINFI + Recteur UY2-Soa.')
                        ->getUploadedFileNameForStorageUsing(
                            fn (Forms\Get $get): string => 'appel-candidature-'
                                .Str::slug((string) ($get('slug') ?: 'campagne')).'.pdf',
                        )
                        ->columnSpanFull(),
                    Forms\Components\TextInput::make('communique_reference')
                        ->label('Référence du communiqué')
                        ->maxLength(100)
                        ->placeholder('N° 90001121/C/MINFI/SG/PSSFP/PCP/PCS/UPAAS/AT'),
                    Forms\Components\DatePicker::make('communique_signed_at')
                        ->label('Date de signature')
                        ->native(false)
                        ->displayFormat('d/m/Y'),
                ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('opens_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('slug')->searchable(),
                Tables\Columns\TextColumn::make('nom')->searchable()->limit(45),
                Tables\Columns\TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    'draft' => 'gray', 'open' => 'success', 'closed' => 'warning', 'archived' => 'danger',
                    default => 'gray',
                }),
                Tables\Columns\TextColumn::make('opens_at')->dateTime('d/m/Y')->sortable(),
                Tables\Columns\TextColumn::make('closes_at')->dateTime('d/m/Y')->sortable(),
                Tables\Columns\TextColumn::make('candidatures_count')
                    ->label('Dossiers')
                    ->counts('candidatures')
                    ->sortable(),
                Tables\Columns\IconColumn::make('communique_pdf_path')
                    ->label('Communiqué')
                    ->boolean()
                    ->tooltip(fn (CampagneCandidature $record): string => $record->communique_pdf_path === null
                        ? 'Aucun communiqué publié — la page d\'accueil n\'affiche pas de bouton de téléchargement.'
                        : 'Communiqué publié sur apply.pssfp.org'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options([
                    'draft' => 'Brouillon', 'open' => 'Ouverte',
                    'closed' => 'Clôturée', 'archived' => 'Archivée',
                ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCampagneCandidatures::route('/'),
            'create' => Pages\CreateCampagneCandidature::route('/create'),
            'edit' => Pages\EditCampagneCandidature::route('/{record}/edit'),
        ];
    }
}
