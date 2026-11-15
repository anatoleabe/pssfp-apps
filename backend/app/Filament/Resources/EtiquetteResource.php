<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\RestrictedToModule;
use App\Filament\Navigation\GroupeNavigation;
use App\Filament\Resources\EtiquetteResource\Pages;
use App\Models\Etiquette;
use App\Services\Scolarite\Etiquettes;
use App\Support\BelongsToModule;
use Closure;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use InvalidArgumentException;

/**
 * Vie académique > Étiquettes : renommer, recolorer ou supprimer les
 * étiquettes libres des auditeurs. Elles se créent et se posent depuis la fiche
 * d'un auditeur ou sur une sélection de la liste. Tout passe par le service
 * Etiquettes, qui revérifie la permission et journalise.
 */
#[BelongsToModule('scolarite')]
class EtiquetteResource extends Resource
{
    use RestrictedToModule;

    protected static ?string $model = Etiquette::class;

    protected static ?string $modelLabel = 'Étiquette';

    protected static ?string $pluralModelLabel = 'Étiquettes';

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static ?string $navigationGroup = GroupeNavigation::SCOLARITE;

    protected static ?int $navigationSort = 50;

    /**
     * Nom et couleur, à la création (depuis la fiche ou la liste des auditeurs)
     * comme à la modification.
     *
     * @return list<Forms\Components\Component>
     */
    public static function champs(): array
    {
        return [
            Forms\Components\TextInput::make('nom')->label('Nom')->required()->maxLength(Etiquette::NOM_MAX)
                ->placeholder('Campus B')
                // L'étiquette modifiée ne se heurte pas à elle-même (changement de casse).
                ->rule(fn ($record): Closure => function (string $attribut, mixed $valeur, Closure $echec) use ($record): void {
                    $existante = Etiquettes::existante((string) $valeur, $record instanceof Etiquette ? $record->id : null);
                    if ($existante !== null) {
                        $echec('L\'étiquette « '.$existante->nom.' » existe déjà.');
                    }
                }),
            Forms\Components\ToggleButtons::make('couleur')->label('Couleur')->required()->inline()
                ->options(Etiquette::COULEURS)
                ->colors(array_combine(array_keys(Etiquette::COULEURS), array_keys(Etiquette::COULEURS)))
                ->default('gray'),
        ];
    }

    public static function form(Form $form): Form
    {
        return $form->schema(self::champs())->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('nom')
            ->columns([
                Tables\Columns\TextColumn::make('nom')->label('Nom')->badge()
                    ->color(fn (Etiquette $record): string => $record->couleur)
                    ->searchable()->sortable(),
                Tables\Columns\TextColumn::make('couleur')->label('Couleur')
                    ->formatStateUsing(fn (string $state): string => Etiquette::COULEURS[$state] ?? $state),
                Tables\Columns\TextColumn::make('auditeurs_count')->label('Auditeurs')->counts('auditeurs')->sortable()->alignEnd(),
                Tables\Columns\TextColumn::make('auteur.name')->label('Créée par')->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')->label('Créée le')->date('d/m/Y')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->modalHeading('Modifier l\'étiquette')
                    ->using(function (Etiquette $record, array $data, Tables\Actions\EditAction $action): Etiquette {
                        try {
                            return app(Etiquettes::class)->modifier($record, (string) $data['nom'], (string) $data['couleur'], auth()->user());
                        } catch (InvalidArgumentException $e) {
                            Notification::make()->danger()->title('Étiquette non modifiée')->body($e->getMessage())->send();
                            $action->halt();

                            return $record;
                        }
                    }),
                Tables\Actions\DeleteAction::make()
                    ->modalHeading(fn (Etiquette $record): string => 'Supprimer l\'étiquette « '.$record->nom.' »')
                    ->modalDescription(fn (Etiquette $record): string => self::avertissementSuppression($record))
                    ->using(function (Etiquette $record, Tables\Actions\DeleteAction $action): bool {
                        try {
                            app(Etiquettes::class)->supprimer($record, auth()->user());
                        } catch (InvalidArgumentException $e) {
                            Notification::make()->danger()->title('Étiquette non supprimée')->body($e->getMessage())->send();
                            $action->halt();
                        }

                        return true;
                    }),
            ])
            ->bulkActions([]);
    }

    public static function avertissementSuppression(Etiquette $etiquette): string
    {
        $porteurs = $etiquette->auditeurs_count ?? $etiquette->auditeurs()->count();

        return $porteurs === 0
            ? 'Elle n\'est posée sur aucun auditeur.'
            : 'Elle est posée sur '.self::nombreAuditeurs((int) $porteurs).' : elle leur sera retirée. Leur fiche en garde la trace dans l\'historique.';
    }

    /** « 1 auditeur », « 2 auditeurs ». */
    public static function nombreAuditeurs(int $nombre): string
    {
        return $nombre.' '.($nombre > 1 ? 'auditeurs' : 'auditeur');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageEtiquettes::route('/'),
        ];
    }
}
