<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\RestrictedToModule;
use App\Filament\Navigation\GroupeNavigation;
use App\Filament\Resources\AuditeurResource\MessagesValidation;
use App\Filament\Resources\RubriqueFraisResource\Pages;
use App\Models\BaremeScolarite;
use App\Models\RubriqueFrais;
use App\Services\Scolarite\EspaceFinances;
use App\Services\Scolarite\LibelleSituation;
use App\Services\Scolarite\RubriquesFrais;
use App\Support\BelongsToModule;
use Filament\Actions\Action as PageAction;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Finances > Rubriques (reçu A4, R2) : les frais que couvre le barème de chaque année et niveau, leur code d'imputation et
 * leur ordre de répartition. Lecture par l'espace Finances ; modification par l'administrateur seulement
 * (`finances.rubriques_gerer`), journalisée. Les rubriques d'un couple se modifient toutes ensemble, dans un seul formulaire
 * dont l'enregistrement est refusé si leur somme n'égale pas le barème (relecture M2). Une rubrique ne se supprime pas :
 * elle se désactive.
 */
#[BelongsToModule('scolarite')]
class RubriqueFraisResource extends Resource
{
    use RestrictedToModule;

    protected static ?string $model = RubriqueFrais::class;

    protected static ?string $modelLabel = 'Rubrique de frais';

    protected static ?string $pluralModelLabel = 'Rubriques de frais';

    protected static ?string $navigationLabel = 'Rubriques';

    protected static ?string $navigationIcon = 'heroicon-o-list-bullet';

    protected static ?string $navigationGroup = GroupeNavigation::FINANCES;

    protected static ?int $navigationSort = 40;

    protected static ?string $slug = 'finances/rubriques';

    public static function canViewAny(): bool
    {
        return EspaceFinances::peutConsulter(auth()->user());
    }

    /** Pas de création ni de modification ligne à ligne : tout passe par le formulaire du couple. */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    /** Pastille : nombre d'anomalies (couples ventilés par palier, promotions sans compte). */
    public static function getNavigationBadge(): ?string
    {
        $n = RubriquesFrais::nombreAnomalies();

        return $n > 0 ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Anomalies des rubriques de frais';
    }

    /**
     * Champs du formulaire d'un couple : les rubriques dans leur ordre de répartition (glisser pour réordonner).
     *
     * @return array<int, Forms\Components\Component>
     */
    public static function champsLignes(): array
    {
        return [
            Forms\Components\Repeater::make('lignes')->label('Rubriques, dans l\'ordre de répartition')->reorderableWithDragAndDrop()->columns(6)
                ->addActionLabel('Ajouter une rubrique')->minItems(1)
                ->helperText('Un versement remplit les rubriques dans cet ordre : frais fixes d\'abord, pension en dernier. Une ligne retirée est désactivée, jamais supprimée.')
                ->schema(MessagesValidation::appliquer([
                    Forms\Components\Hidden::make('id'),
                    Forms\Components\TextInput::make('libelle')->label('Libellé')->required()->maxLength(120)->columnSpan(2),
                    Forms\Components\TextInput::make('montant')->label('Montant')->required()->integer()->minValue(0)->maxValue(100000000)->suffix('FCFA'),
                    Forms\Components\Toggle::make('compte_selon_promotion')->label('Compte de la promotion')->live()->inline(false),
                    // Masqué pour la pension : non transmis, le service le force à null (plus d'erreur 500 au basculement).
                    Forms\Components\TextInput::make('code_imputation')->label('Code d\'imputation')->regex('/^\d{6}$/')
                        ->notIn([RubriquesFrais::COMPTE_INTERDIT])->placeholder('À préciser')
                        ->hidden(fn (Forms\Get $get): bool => (bool) $get('compte_selon_promotion')),
                    Forms\Components\Toggle::make('actif')->label('Active')->default(true)->inline(false),
                ])),
        ];
    }

    /** Enregistre le couple ; un refus (somme, code, libellé) s'affiche sans fermer le formulaire. */
    public static function enregistrer(string $annee, string $niveau, array $lignes, Tables\Actions\Action|PageAction $action): void
    {
        try {
            RubriquesFrais::enregistrerCouple($annee, $niveau, array_values($lignes), auth()->user());
        } catch (InvalidArgumentException $e) {
            Notification::make()->danger()->title('Rubriques non enregistrées')->body($e->getMessage())->persistent()->send();
            $action->halt();

            return;
        }
        Notification::make()->success()->title("Rubriques {$annee} {$niveau} enregistrées")
            ->body('Elles valent pour les reçus émis ensuite : un reçu déjà émis garde sa ventilation.')->send();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('ordre')
            ->defaultGroup(Group::make('couple')->label('Année et niveau')
                ->getKeyFromRecordUsing(fn (RubriqueFrais $r): string => $r->annee_academique.' '.$r->niveau)
                ->getTitleFromRecordUsing(fn (RubriqueFrais $r): string => $r->annee_academique.' — '.(BaremeScolarite::NIVEAUX[$r->niveau] ?? $r->niveau))
                ->orderQueryUsing(fn ($query, string $direction) => $query->orderBy('annee_academique', 'desc')->orderBy('niveau')))
            ->columns([
                Tables\Columns\TextColumn::make('ordre')->label('Ordre'),
                Tables\Columns\TextColumn::make('libelle')->label('Rubrique'),
                Tables\Columns\TextColumn::make('code_imputation')->label('Imputation')
                    ->getStateUsing(fn (RubriqueFrais $r): string => $r->compte_selon_promotion ? 'Compte de la promotion' : ($r->code_imputation ?? 'À préciser')),
                Tables\Columns\TextColumn::make('montant')->label('Montant')->alignEnd()->formatStateUsing(fn (int $state): string => LibelleSituation::fcfa($state)),
                Tables\Columns\IconColumn::make('actif')->label('Active')->boolean(),
            ])
            ->actions([
                Tables\Actions\Action::make('modifierCouple')->label('Modifier le couple')->icon('heroicon-o-pencil-square')
                    ->visible(fn (): bool => RubriquesFrais::peutGerer(auth()->user()))
                    ->modalHeading(fn (RubriqueFrais $record): string => 'Rubriques '.$record->annee_academique.' '.$record->niveau)
                    ->modalDescription(fn (RubriqueFrais $record): string => 'Total du barème : '
                        .(($t = RubriquesFrais::etat($record->annee_academique, $record->niveau)['total_bareme']) === null ? 'aucun barème' : LibelleSituation::fcfa($t))
                        .'. Les rubriques actives doivent l\'égaler.')
                    ->modalWidth('6xl')
                    ->fillForm(fn (RubriqueFrais $record): array => ['lignes' => RubriquesFrais::lignesDuCouple($record->annee_academique, $record->niveau)])
                    ->form(self::champsLignes())
                    ->action(fn (RubriqueFrais $record, array $data, Tables\Actions\Action $action) => self::enregistrer(
                        $record->annee_academique, $record->niveau, $data['lignes'] ?? [], $action)),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRubriquesFrais::route('/'),
        ];
    }
}
