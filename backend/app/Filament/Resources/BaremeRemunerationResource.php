<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\RestrictedToModule;
use App\Filament\Resources\AuditeurResource\MessagesValidation;
use App\Filament\Resources\BaremeRemunerationResource\Pages;
use App\Filament\Resources\QuotaHoraireResource\ChampsProgrammation;
use App\Models\BaremeRemuneration;
use App\Services\Lmd\EtatsPaie;
use App\Services\Lmd\PermissionsLmd;
use App\Support\BelongsToModule;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/** Barème de rémunération des enseignants (lot 13b ; D24, D25) : modifié par la Scolarité, validé par son responsable. */
#[BelongsToModule('lmd')]
class BaremeRemunerationResource extends Resource
{
    use RestrictedToModule;

    protected static ?string $model = BaremeRemuneration::class;

    protected static ?string $modelLabel = 'Ligne du barème';

    protected static ?string $pluralModelLabel = 'Barème des enseignants';

    protected static ?string $navigationLabel = 'Barème des enseignants';

    protected static ?string $breadcrumb = 'Barème des enseignants';

    protected static ?string $navigationIcon = 'heroicon-o-calculator';

    protected static ?string $navigationGroup = 'Paie des enseignants';

    protected static ?int $navigationSort = 49;

    protected static ?string $slug = 'bareme-enseignants';

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('annee_academique', 'desc')
            ->defaultGroup(Tables\Grouping\Group::make('annee_academique')->label('Année académique'))
            ->paginated(false)
            ->columns([
                Tables\Columns\TextColumn::make('nature')->label('Nature')->formatStateUsing(fn (string $state): string => BaremeRemuneration::NATURES[$state] ?? $state),
                Tables\Columns\TextColumn::make('montant_unitaire')->label('Montant')->alignEnd()
                    ->formatStateUsing(fn (BaremeRemuneration $b): string => EtatsPaie::fcfa($b->montant_unitaire).' / '.mb_strtolower(BaremeRemuneration::UNITES[$b->unite] ?? $b->unite)),
                Tables\Columns\TextColumn::make('taux_retenue')->label('Retenue')->formatStateUsing(fn (float $state): string => str_replace('.', ',', (string) $state).' %'),
                Tables\Columns\TextColumn::make('reference_texte')->label('Texte')->wrap()->limit(90)->placeholder('à préciser')
                    ->tooltip(fn (BaremeRemuneration $b): ?string => $b->reference_texte),
                Tables\Columns\TextColumn::make('statut')->label('État')->badge()
                    ->formatStateUsing(fn (string $state): string => BaremeRemuneration::STATUTS[$state] ?? $state)
                    ->color(fn (string $state): string => $state === 'valide' ? 'success' : 'warning'),
            ])
            ->actions([
                Tables\Actions\Action::make('modifier')->label('Modifier')->icon('heroicon-o-pencil')
                    ->visible(fn (): bool => (bool) auth()->user()?->can(PermissionsLmd::PAIE_PREPARER))
                    ->modalDescription('Si vous changez une valeur, la ligne repasse à « proposé » : elle ne paie plus jusqu\'à sa validation par un autre responsable.')
                    ->modalSubmitActionLabel('Enregistrer')
                    ->fillForm(fn (BaremeRemuneration $b): array => $b->only(['montant_unitaire', 'taux_retenue', 'reference_texte']))
                    ->form(MessagesValidation::appliquer([
                        Forms\Components\TextInput::make('montant_unitaire')->label('Montant unitaire')->numeric()->integer()->minValue(0)->maxValue(10_000_000)->suffix('FCFA')->required(),
                        Forms\Components\TextInput::make('taux_retenue')->label('Retenue à la source')->numeric()->minValue(0)->maxValue(100)->step(0.01)->suffix('%')->required(),
                        Forms\Components\TextInput::make('reference_texte')->label('Texte qui fixe le montant')->maxLength(255),
                    ]))
                    ->action(fn (BaremeRemuneration $b, array $data) => ChampsProgrammation::executer(function () use ($b, $data): string {
                        $change = app(EtatsPaie::class)->modifierBareme($b, (int) $data['montant_unitaire'], (float) $data['taux_retenue'], $data['reference_texte'] ?? null, auth()->user());

                        return $change ? 'Ligne du barème modifiée : elle est à valider par un autre responsable.' : 'Aucun changement : la ligne garde son état.';
                    })),
                Tables\Actions\Action::make('valider')->label('Valider')->icon('heroicon-o-check-badge')->color('success')
                    ->visible(fn (BaremeRemuneration $b): bool => ! $b->estValide() && (bool) auth()->user()?->can(PermissionsLmd::PAIE_VERIFIER))
                    ->disabled(fn (BaremeRemuneration $b): bool => blank($b->reference_texte))
                    ->tooltip(fn (BaremeRemuneration $b): ?string => blank($b->reference_texte) ? 'Référence du texte requise' : null)
                    ->requiresConfirmation()->modalSubmitActionLabel('Valider')
                    ->modalDescription(fn (BaremeRemuneration $b): string => 'Les états de paie appliqueront '.EtatsPaie::fcfa($b->montant_unitaire).' par '
                        .mb_strtolower(BaremeRemuneration::UNITES[$b->unite] ?? $b->unite).' et une retenue de '.str_replace('.', ',', (string) $b->taux_retenue).' %.')
                    ->action(fn (BaremeRemuneration $b) => ChampsProgrammation::executer(function () use ($b): string {
                        app(EtatsPaie::class)->validerBareme($b, auth()->user(), $b->montant_unitaire);

                        return 'Ligne du barème validée.';
                    })),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListBaremesRemuneration::route('/')];
    }
}
