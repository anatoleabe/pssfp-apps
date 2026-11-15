<?php

declare(strict_types=1);

namespace App\Filament\Resources\AuditeurResource;

use App\Filament\Resources\EtiquetteResource;
use App\Models\Auditeur;
use App\Models\Etiquette;
use App\Services\Scolarite\Etiquettes;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Étiquettes libres sur la liste et la fiche des auditeurs : badges, filtre,
 * gestes. Les voir n'exige que de voir les auditeurs ; les gestes n'apparaissent
 * qu'avec la permission, revérifiée par le service Etiquettes.
 */
final class EtiquettesActions
{
    /** Colonne de la liste : badges colorés, masquable. */
    public static function colonne(): TextColumn
    {
        return TextColumn::make('etiquettes.nom')->label('Étiquettes')->badge()
            ->color(fn (mixed $state, Auditeur $record): string => self::couleur($record, $state))
            ->toggleable();
    }

    /** Entrée de la section Identité de la fiche. */
    public static function entree(): TextEntry
    {
        return TextEntry::make('etiquettes.nom')->label('Étiquettes')->badge()
            ->color(fn (mixed $state, Auditeur $record): string => self::couleur($record, $state))
            ->placeholder('Aucune')
            ->columnSpanFull();
    }

    /** Filtre de la liste : auditeurs qui portent au moins une des étiquettes choisies. */
    public static function filtre(): SelectFilter
    {
        return SelectFilter::make('etiquettes')->label('Étiquettes')->multiple()
            ->options(fn (): array => self::options())
            ->query(fn (Builder $query, array $data): Builder => blank($data['values'] ?? null) ? $query
                : $query->whereHas('etiquettes', fn (Builder $e) => $e->whereKey(array_map('intval', $data['values']))));
    }

    /** Action d'en-tête de la fiche : choisir les étiquettes, en créer à la volée. */
    public static function gerer(): Action
    {
        return Action::make('etiquettes')
            ->label('Étiquettes')
            ->icon('heroicon-o-tag')
            ->color('gray')
            ->visible(fn (): bool => self::autorise())
            ->modalHeading('Étiquettes de l\'auditeur')
            ->modalDescription('Libres, par exemple « Campus B » ou « Président de l\'amicale des auditeurs » : elles servent à le retrouver dans la liste (filtre « Étiquettes »).')
            ->modalSubmitActionLabel('Enregistrer')
            ->fillForm(fn (Auditeur $record): array => ['etiquettes' => $record->etiquettes()->pluck('etiquettes.id')->all()])
            ->form([self::champ('etiquettes')->label('Étiquettes')->multiple()])
            ->action(function (Auditeur $record, array $data, Action $action): void {
                try {
                    $bilan = app(Etiquettes::class)->definir($record, array_values($data['etiquettes'] ?? []), auth()->user());
                } catch (InvalidArgumentException $e) {
                    Notification::make()->danger()->title('Étiquettes non enregistrées')->body($e->getMessage())->send();
                    $action->halt();

                    return;
                }
                $record->unsetRelation('etiquettes');
                Notification::make()->success()->title('Étiquettes enregistrées')
                    ->body($bilan['posees'].' posée(s), '.$bilan['retirees'].' retirée(s).')->send();
            });
    }

    public static function ajouterGroupe(): BulkAction
    {
        return BulkAction::make('ajouterEtiquette')
            ->label('Ajouter une étiquette')
            ->icon('heroicon-o-tag')
            ->visible(fn (): bool => self::autorise())
            ->modalHeading('Ajouter une étiquette aux auditeurs sélectionnés')
            ->modalSubmitActionLabel('Ajouter')
            ->form([self::champ('etiquette')->label('Étiquette')->required()])
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records, array $data, BulkAction $action): void {
                $etiquette = self::etiquetteChoisie($data, $action);
                $posees = self::executer(fn (): int => app(Etiquettes::class)->poser($records, $etiquette, auth()->user()), $action);
                $deja = $records->count() - $posees;
                Notification::make()->success()
                    ->title('Étiquette « '.$etiquette->nom.' » posée sur '.EtiquetteResource::nombreAuditeurs($posees))
                    ->body($deja > 0 ? EtiquetteResource::nombreAuditeurs($deja).($deja > 1 ? ' la portaient déjà.' : ' la portait déjà.') : null)
                    ->send();
            });
    }

    public static function retirerGroupe(): BulkAction
    {
        return BulkAction::make('retirerEtiquette')
            ->label('Retirer une étiquette')
            ->icon('heroicon-o-x-mark')
            ->visible(fn (): bool => self::autorise())
            ->modalHeading('Retirer une étiquette des auditeurs sélectionnés')
            ->modalSubmitActionLabel('Retirer')
            ->form([
                Forms\Components\Select::make('etiquette')->label('Étiquette')->required()->native(false)->searchable()
                    ->options(fn (): array => self::options()),
            ])
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records, array $data, BulkAction $action): void {
                $etiquette = self::etiquetteChoisie($data, $action);
                $retirees = self::executer(fn (): int => app(Etiquettes::class)->retirer($records, $etiquette, auth()->user()), $action);
                $retirees === 0
                    ? Notification::make()->info()->title('Aucun des auditeurs sélectionnés ne portait l\'étiquette « '.$etiquette->nom.' »')->send()
                    : Notification::make()->success()->title('Étiquette « '.$etiquette->nom.' » retirée de '.EtiquetteResource::nombreAuditeurs($retirees))->send();
            });
    }

    /** Choix d'une ou plusieurs étiquettes, avec création à la volée (nom et couleur). */
    private static function champ(string $nom): Forms\Components\Select
    {
        return Forms\Components\Select::make($nom)
            ->native(false)
            ->searchable()
            ->options(fn (): array => self::options())
            ->createOptionForm(EtiquetteResource::champs())
            ->createOptionModalHeading('Nouvelle étiquette')
            ->createOptionUsing(function (array $data): int {
                try {
                    return app(Etiquettes::class)->creer((string) $data['nom'], (string) $data['couleur'], auth()->user())->id;
                } catch (InvalidArgumentException $e) {
                    // Création simultanée du même nom : la fenêtre reste ouverte.
                    Notification::make()->danger()->title('Étiquette non créée')->body($e->getMessage())->send();

                    throw new Halt;
                }
            });
    }

    /** @param array<string, mixed> $data */
    private static function etiquetteChoisie(array $data, BulkAction $action): Etiquette
    {
        $etiquette = Etiquette::query()->find($data['etiquette'] ?? null);
        if ($etiquette === null) {
            Notification::make()->danger()->title('Étiquette introuvable')->body('Elle vient peut-être d\'être supprimée : rechargez la page.')->send();
            $action->halt();
        }

        return $etiquette;
    }

    /** @param callable(): int $geste */
    private static function executer(callable $geste, BulkAction $action): int
    {
        try {
            return $geste();
        } catch (InvalidArgumentException $e) {
            Notification::make()->danger()->title('Rien n\'a été modifié')->body($e->getMessage())->send();
            $action->halt();

            return 0;
        }
    }

    /** @return array<int, string> */
    private static function options(): array
    {
        return Etiquette::query()->orderBy('nom')->pluck('nom', 'id')->all();
    }

    private static function couleur(Auditeur $auditeur, mixed $nom): string
    {
        return $auditeur->etiquettes->firstWhere('nom', $nom)?->couleur ?? 'gray';
    }

    private static function autorise(): bool
    {
        return (bool) auth()->user()?->can(Etiquettes::PERMISSION);
    }
}
