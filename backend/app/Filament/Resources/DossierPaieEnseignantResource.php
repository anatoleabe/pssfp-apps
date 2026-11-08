<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\RestrictedToModule;
use App\Filament\Resources\AuditeurResource\MessagesValidation;
use App\Filament\Resources\DossierPaieEnseignantResource\Pages;
use App\Filament\Resources\QuotaHoraireResource\ChampsProgrammation;
use App\Models\DossierPaieEnseignant;
use App\Services\Lmd\PermissionsLmd;
use App\Services\Lmd\PointagesEnseignant;
use App\Support\BelongsToModule;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Dossiers de paie des enseignants (lot 13a ; D23, D24). La Scolarité tient le statut ; le service financier,
 * seul, voit et saisit le NIU, la banque et le compte de virement (chiffrés en base).
 */
#[BelongsToModule('lmd')]
class DossierPaieEnseignantResource extends Resource
{
    use RestrictedToModule;

    protected static ?string $model = DossierPaieEnseignant::class;

    protected static ?string $modelLabel = 'Dossier de paie';

    protected static ?string $pluralModelLabel = 'Dossiers de paie';

    protected static ?string $navigationIcon = 'heroicon-o-identification';

    protected static ?string $navigationGroup = 'Parcours LMD';

    protected static ?string $navigationLabel = 'Dossiers de paie';

    protected static ?string $breadcrumb = 'Dossiers de paie';

    protected static ?int $navigationSort = 47;

    protected static ?string $slug = 'dossiers-paie';

    public static function financier(): bool
    {
        return (bool) auth()->user()?->can(PermissionsLmd::DOSSIER_PAIE);
    }

    /** @return list<Forms\Components\Component> */
    public static function champs(): array
    {
        return MessagesValidation::appliquer([
            Forms\Components\Grid::make(2)->schema([
                Forms\Components\Select::make('statut_paie')->label('Statut')->native(false)->options(DossierPaieEnseignant::STATUTS)->required()->live(),
                Forms\Components\Select::make('categorie')->label('Catégorie')->native(false)->options(DossierPaieEnseignant::CATEGORIES)->placeholder('Non précisée'),
                Forms\Components\TextInput::make('service_du_heures')->label('Service dû annuel')->numeric()->minValue(0)->maxValue(2000)->suffix('h')
                    ->visible(fn (Forms\Get $get): bool => $get('statut_paie') === 'permanent')
                    ->helperText('Au-delà, les heures sont payées comme heures complémentaires.'),
                Forms\Components\Toggle::make('actif')->label('Enseignant actif')->default(true)->inline(false),
            ]),
            Forms\Components\Section::make('Coordonnées de paiement')->visible(fn (): bool => static::financier())
                ->description('Paiement par virement uniquement : le NIU et le compte sont obligatoires pour figurer sur l\'état de paie. Données chiffrées, réservées au service financier.')
                ->schema([
                    Forms\Components\TextInput::make('niu')->label('NIU')->maxLength(20)->autocomplete(false)->regex('/^[A-Za-z0-9]{8,20}$/')
                        ->helperText('8 à 20 lettres ou chiffres.'),
                    Forms\Components\TextInput::make('banque')->label('Banque')->maxLength(120),
                    Forms\Components\TextInput::make('compte_paiement')->label('Numéro de compte (RIB)')->maxLength(40)->autocomplete(false)->regex('/^[A-Za-z0-9 ]{10,40}$/')
                        ->helperText('10 à 40 lettres, chiffres ou espaces.'),
                ]),
        ], ['regex' => 'Le format n\'est pas valide.']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('enseignant'))
            ->defaultSort('id')
            ->columns([
                Tables\Columns\TextColumn::make('enseignant.name')->label('Enseignant')->searchable()->sortable()
                    ->description(fn (DossierPaieEnseignant $d): ?string => $d->actif ? null : 'inactif'),
                Tables\Columns\TextColumn::make('statut_paie')->label('Statut')->formatStateUsing(fn (string $state): string => DossierPaieEnseignant::STATUTS[$state] ?? $state)
                    ->description(fn (DossierPaieEnseignant $d): ?string => $d->categorie !== null ? DossierPaieEnseignant::CATEGORIES[$d->categorie] : null),
                Tables\Columns\TextColumn::make('service_du_heures')->label('Service dû')->placeholder('—')
                    ->formatStateUsing(fn (?float $state): string => $state === null ? '—' : str_replace('.', ',', (string) $state).' h'),
                Tables\Columns\TextColumn::make('banque')->label('Banque')->placeholder('—')->visible(fn (): bool => static::financier()),
                Tables\Columns\TextColumn::make('complet')->label('Dossier')->badge()
                    ->getStateUsing(fn (DossierPaieEnseignant $d): string => $d->estComplet() ? 'Complet' : 'Incomplet')
                    ->color(fn (string $state): string => $state === 'Complet' ? 'success' : 'danger')
                    ->description(fn (DossierPaieEnseignant $d): ?string => $d->estComplet() ? null : implode(' et ', $d->manques()).' manquant(s)'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('statut_paie')->label('Statut')->options(DossierPaieEnseignant::STATUTS),
                Tables\Filters\TernaryFilter::make('actif')->label('Actif'),
                Tables\Filters\TernaryFilter::make('complet')->label('Dossier')->trueLabel('Complet')->falseLabel('Incomplet')
                    ->queries(
                        true: fn (Builder $q): Builder => $q->whereNotNull('niu')->whereNotNull('compte_paiement'),
                        false: fn (Builder $q): Builder => $q->where(fn (Builder $x) => $x->whereNull('niu')->orWhereNull('compte_paiement')),
                    ),
            ])
            ->actions([
                Tables\Actions\Action::make('modifier')->label('Modifier')->icon('heroicon-o-pencil')
                    ->fillForm(fn (DossierPaieEnseignant $d): array => [
                        ...$d->only(['statut_paie', 'categorie', 'service_du_heures', 'actif']),
                        // Les coordonnées ne quittent le serveur que pour le service financier.
                        ...(static::financier() ? ['niu' => $d->niu, 'banque' => $d->banque, 'compte_paiement' => $d->compte_paiement] : []),
                    ])
                    ->form(fn (): array => static::champs())
                    ->action(fn (DossierPaieEnseignant $d, array $data) => ChampsProgrammation::executer(function () use ($d, $data): string {
                        app(PointagesEnseignant::class)->majDossier($d, $data, auth()->user());

                        return 'Dossier de paie enregistré.';
                    })),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListDossiersPaieEnseignant::route('/')];
    }
}
