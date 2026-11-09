<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\RestrictedToModule;
use App\Filament\Navigation\GroupeNavigation;
use App\Filament\Resources\AuditeurResource\MessagesValidation;
use App\Filament\Resources\ProgrammationEnseignantResource\Pages;
use App\Filament\Resources\QuotaHoraireResource\ChampsProgrammation;
use App\Models\ProgrammationEnseignant;
use App\Services\Lmd\PermissionsLmd;
use App\Services\Lmd\Programmations;
use App\Support\BelongsToModule;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Programmations des enseignants (lot 12d ; D22). La scolarité les voit toutes, les valide et les notifie ;
 * l'enseignant n'y voit que les siennes (« Mes programmations ») et y répond.
 */
#[BelongsToModule('lmd')]
class ProgrammationEnseignantResource extends Resource
{
    use RestrictedToModule;

    protected static ?string $model = ProgrammationEnseignant::class;

    protected static ?string $modelLabel = 'Programmation';

    protected static ?string $pluralModelLabel = 'Programmations';

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationGroup = GroupeNavigation::PLANIFICATION;

    protected static ?string $breadcrumb = 'Programmations';

    protected static ?int $navigationSort = 60;

    protected static ?string $slug = 'programmations-enseignant';

    /** L'utilisateur ne voit que ses propres programmations (enseignant sans droit de gestion). */
    public static function enseignantSeul(): bool
    {
        $u = auth()->user();

        return $u !== null && ! $u->can(PermissionsLmd::CONSULTER) && ! $u->can(PermissionsLmd::PROGRAMMATION) && ! $u->can(PermissionsLmd::PROGRAMMATION_VALIDER);
    }

    public static function getNavigationLabel(): string
    {
        return static::enseignantSeul() ? 'Mes programmations' : 'Programmations';
    }

    public static function getNavigationBadge(): ?string
    {
        $id = auth()->id();
        if (! static::enseignantSeul() || $id === null) {
            return null;
        }
        $n = ProgrammationEnseignant::query()->where('enseignant_id', $id)->where('statut', ProgrammationEnseignant::NOTIFIE)->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->when(static::enseignantSeul(), fn (Builder $q) => $q->where('enseignant_id', auth()->id()));
    }

    public static function table(Table $table): Table
    {
        $moi = fn (ProgrammationEnseignant $p): bool => $p->enseignant_id === auth()->id() && (bool) auth()->user()?->can(PermissionsLmd::PROGRAMMATION_REPONDRE);
        $valider = fn (): bool => (bool) auth()->user()?->can(PermissionsLmd::PROGRAMMATION_VALIDER);

        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['enseignant', 'quota.periode', 'quota.ecue.maquetteUe.maquette.parcours']))
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('enseignant.name')->label('Enseignant')->searchable()->hidden(fn (): bool => static::enseignantSeul())
                    ->description(fn (ProgrammationEnseignant $p): string => ProgrammationEnseignant::ROLES[$p->role]),
                Tables\Columns\TextColumn::make('quota.ecue.code')->label('Cours')->searchable()->wrap()
                    ->description(fn (ProgrammationEnseignant $p): string => $p->quota->ecue->intitule.' · '.$p->quota->ecue->maquetteUe->maquette->parcours->code),
                Tables\Columns\TextColumn::make('quota.periode.libelle')->label('Période')->wrap()
                    ->description(fn (ProgrammationEnseignant $p): string => 'Intervention '.($p->fenetre() ?? 'sur toute la période')),
                Tables\Columns\TextColumn::make('heures')->label('Heures')
                    ->getStateUsing(fn (ProgrammationEnseignant $p): string => ChampsProgrammation::heuresLibelle($p->heures_cm, $p->heures_td, $p->heures_tp))
                    ->description(fn (ProgrammationEnseignant $p): string => ChampsProgrammation::modalites()[$p->modalite]),
                Tables\Columns\TextColumn::make('statut')->label('État')->badge()->formatStateUsing(fn (string $state): string => ProgrammationEnseignant::STATUTS[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        'accepte' => 'success', 'decline', 'annule' => 'danger', 'notifie' => 'info', default => 'warning'
                    })
                    ->description(fn (ProgrammationEnseignant $p): ?string => $p->motif),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('periode')->label('Période')->options(fn (): array => ChampsProgrammation::periodes())
                    ->query(fn (Builder $query, array $data): Builder => empty($data['value']) ? $query : $query->whereHas('quota', fn ($q) => $q->where('periode_id', $data['value']))),
                Tables\Filters\SelectFilter::make('enseignant_id')->label('Enseignant')->options(fn (): array => ChampsProgrammation::enseignants())
                    ->searchable()->visible(fn (): bool => ! static::enseignantSeul()),
                Tables\Filters\SelectFilter::make('statut')->label('État')->options(ProgrammationEnseignant::STATUTS),
            ])
            ->actions([
                Tables\Actions\Action::make('accepter')->label('Accepter')->icon('heroicon-o-check')->color('success')
                    ->visible(fn (ProgrammationEnseignant $p): bool => $p->statut === ProgrammationEnseignant::NOTIFIE && $moi($p))
                    ->requiresConfirmation()->modalDescription('Vous acceptez d\'assurer ces heures dans la période.')
                    ->action(fn (ProgrammationEnseignant $p) => ChampsProgrammation::executer(function () use ($p): string {
                        app(Programmations::class)->repondre($p, true, null, auth()->user());

                        return 'Programmation acceptée.';
                    })),
                Tables\Actions\Action::make('decliner')->label('Décliner')->icon('heroicon-o-x-mark')->color('danger')
                    ->visible(fn (ProgrammationEnseignant $p): bool => $p->statut === ProgrammationEnseignant::NOTIFIE && $moi($p))
                    ->form(MessagesValidation::appliquer([Forms\Components\Textarea::make('motif')->label('Motif du refus')->required()->minLength(5)->maxLength(500)]))
                    ->action(fn (ProgrammationEnseignant $p, array $data) => ChampsProgrammation::executer(function () use ($p, $data): string {
                        app(Programmations::class)->repondre($p, false, (string) $data['motif'], auth()->user());

                        return 'Programmation déclinée : la Scolarité est informée par le suivi.';
                    })),
                Tables\Actions\Action::make('quota')->label('Quota')->icon('heroicon-o-clock')->color('gray')
                    ->visible(fn (): bool => ! static::enseignantSeul())
                    ->url(fn (ProgrammationEnseignant $p): string => QuotaHoraireResource::getUrl('view', ['record' => $p->quota])),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('valider')->label('Valider')->icon('heroicon-o-check-badge')->visible($valider)
                    ->requiresConfirmation()->deselectRecordsAfterCompletion()
                    ->action(fn (Collection $records) => ChampsProgrammation::executer(function () use ($records): string {
                        $n = 0;
                        foreach ($records->where('statut', ProgrammationEnseignant::PROPOSE) as $p) {
                            app(Programmations::class)->valider($p, auth()->user());
                            $n++;
                        }

                        return $n.' programmation(s) validée(s).';
                    })),
                Tables\Actions\BulkAction::make('notifier')->label('Notifier les enseignants')->icon('heroicon-o-envelope')->visible($valider)
                    ->requiresConfirmation()->deselectRecordsAfterCompletion()
                    ->modalDescription('Chaque enseignant reçoit un courriel qui récapitule ses programmations validées de la période. Les autres programmations sélectionnées sont ignorées.')
                    ->action(fn (Collection $records) => ChampsProgrammation::executer(function () use ($records): string {
                        $b = app(Programmations::class)->notifier($records, auth()->user());

                        return $b['notifiees'].' programmation(s) notifiée(s)'.($b['ignorees'] > 0 ? ', '.$b['ignorees'].' ignorée(s) (non validées)' : '').'.';
                    })),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListProgrammationsEnseignant::route('/')];
    }
}
