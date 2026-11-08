<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\RestrictedToModule;
use App\Filament\Resources\AuditeurResource\MessagesValidation;
use App\Filament\Resources\QuotaHoraireResource\ChampsProgrammation;
use App\Filament\Resources\QuotaHoraireResource\Pages;
use App\Filament\Resources\QuotaHoraireResource\RelationManagers\ProgrammationsRelationManager;
use App\Models\Parcours;
use App\Models\ProgrammationEnseignant;
use App\Models\QuotaHoraire;
use App\Services\Lmd\PermissionsLmd;
use App\Services\Lmd\Programmations;
use App\Support\BelongsToModule;
use Filament\Forms;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/** Quotas horaires des cours par période (spec module 4, lot 12d ; D22). */
#[BelongsToModule('lmd')]
class QuotaHoraireResource extends Resource
{
    use RestrictedToModule;

    protected static ?string $model = QuotaHoraire::class;

    protected static ?string $modelLabel = 'Quota horaire';

    protected static ?string $pluralModelLabel = 'Quotas horaires';

    protected static ?string $navigationLabel = 'Quotas horaires';

    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static ?string $navigationGroup = 'Parcours LMD';

    protected static ?string $breadcrumb = 'Quotas horaires';

    protected static ?int $navigationSort = 42;

    protected static ?string $slug = 'quotas-horaires';

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['periode', 'ecue.maquetteUe.maquette.parcours', 'programmations']))
            ->defaultSort(fn (Builder $query): Builder => $query->orderBy('periode_id')->orderBy('id'))
            ->columns([
                Tables\Columns\TextColumn::make('periode.libelle')->label('Période')->wrap()
                    ->description(fn (QuotaHoraire $q): string => $q->periode->debut_prevu->format('d/m/Y').' – '.$q->periode->fin_prevue->format('d/m/Y')),
                Tables\Columns\TextColumn::make('ecue.code')->label('ECUE')->searchable()->wrap()
                    ->description(fn (QuotaHoraire $q): string => $q->ecue->intitule.' · '.$q->ecue->maquetteUe->maquette->parcours->code),
                Tables\Columns\TextColumn::make('heures')->label('Quota')
                    ->getStateUsing(fn (QuotaHoraire $q): string => ChampsProgrammation::heuresLibelle($q->heures_cm, $q->heures_td, $q->heures_tp)),
                Tables\Columns\TextColumn::make('reparti')->label('Réparti')
                    ->getStateUsing(fn (QuotaHoraire $q): string => self::reparti($q).' h')
                    ->color(fn (QuotaHoraire $q): ?string => abs(self::reparti($q) - $q->total()) > 0.01 ? 'danger' : 'success')
                    ->description(fn (QuotaHoraire $q): string => $q->programmations->filter->estActive()->count().' enseignant(s)'),
                Tables\Columns\TextColumn::make('statut')->label('État')->badge()->formatStateUsing(fn (string $state): string => QuotaHoraire::STATUTS[$state] ?? $state)
                    ->color(fn (string $state): string => $state === QuotaHoraire::PROPOSE ? 'warning' : 'success')
                    ->description(fn (QuotaHoraire $q): ?string => $q->motif_modification),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('periode_id')->label('Période')->options(fn (): array => ChampsProgrammation::periodes()),
                Tables\Filters\SelectFilter::make('parcours')->label('Parcours')
                    ->options(fn (): array => Parcours::query()->orderBy('niveau')->orderBy('ordre')->pluck('code', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => empty($data['value']) ? $query
                        : $query->whereHas('ecue.maquetteUe.maquette', fn ($m) => $m->where('parcours_id', $data['value']))),
                Tables\Filters\SelectFilter::make('statut')->label('État')->options(QuotaHoraire::STATUTS),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('modifier')->label('Modifier')->icon('heroicon-o-pencil')
                    ->visible(fn (): bool => (bool) auth()->user()?->can(PermissionsLmd::PROGRAMMATION))
                    ->fillForm(fn (QuotaHoraire $q): array => $q->only(['heures_cm', 'heures_td', 'heures_tp']))
                    ->form(fn (QuotaHoraire $q): array => MessagesValidation::appliquer([
                        Forms\Components\Grid::make(3)->schema(ChampsProgrammation::heures()),
                        Forms\Components\Textarea::make('motif')->label('Motif de la modification')->visible($q->estValide())->required($q->estValide())->minLength(5)->maxLength(500),
                    ]))
                    ->action(fn (QuotaHoraire $q, array $data) => ChampsProgrammation::executer(function () use ($q, $data): string {
                        app(Programmations::class)->definirQuota($q->periode, $q->ecue, $data, auth()->user(), $data['motif'] ?? null);

                        return 'Quota enregistré.';
                    })),
                Tables\Actions\Action::make('valider')->label('Valider')->icon('heroicon-o-check-badge')->color('success')
                    ->visible(fn (QuotaHoraire $q): bool => $q->statut === QuotaHoraire::PROPOSE && (bool) auth()->user()?->can(PermissionsLmd::PROGRAMMATION_VALIDER))
                    ->requiresConfirmation()
                    ->action(fn (QuotaHoraire $q) => ChampsProgrammation::executer(function () use ($q): string {
                        app(Programmations::class)->validerQuota($q, auth()->user());

                        return 'Quota validé.';
                    })),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('valider')->label('Valider les quotas')->icon('heroicon-o-check-badge')
                    ->visible(fn (): bool => (bool) auth()->user()?->can(PermissionsLmd::PROGRAMMATION_VALIDER))
                    ->requiresConfirmation()->deselectRecordsAfterCompletion()
                    ->action(fn (Collection $records) => ChampsProgrammation::executer(function () use ($records): string {
                        $n = 0;
                        foreach ($records->where('statut', QuotaHoraire::PROPOSE) as $q) {
                            app(Programmations::class)->validerQuota($q, auth()->user());
                            $n++;
                        }

                        return $n.' quota(s) validé(s).';
                    })),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make()->columns(4)->schema([
                Infolists\Components\TextEntry::make('ecue.code')->label('ECUE')
                    ->helperText(fn (QuotaHoraire $q): string => $q->ecue->intitule.' · '.$q->ecue->maquetteUe->maquette->parcours->code),
                Infolists\Components\TextEntry::make('periode.libelle')->label('Période de cours')->columnSpan(2)
                    ->helperText(fn (QuotaHoraire $q): string => 'Du '.$q->periode->debut_prevu->format('d/m/Y').' au '.$q->periode->fin_prevue->format('d/m/Y')),
                Infolists\Components\TextEntry::make('statut')->label('État')->badge()->formatStateUsing(fn (string $state): string => QuotaHoraire::STATUTS[$state] ?? $state)
                    ->helperText(fn (QuotaHoraire $q): ?string => $q->motif_modification),
                Infolists\Components\TextEntry::make('heures')->label('Quota de la période')->columnSpan(2)
                    ->getStateUsing(fn (QuotaHoraire $q): string => ChampsProgrammation::heuresLibelle($q->heures_cm, $q->heures_td, $q->heures_tp)),
                Infolists\Components\TextEntry::make('maquette')->label('Volume de la maquette (année)')->columnSpan(2)
                    ->getStateUsing(fn (QuotaHoraire $q): string => ChampsProgrammation::heuresLibelle((float) $q->ecue->heures_cm, (float) $q->ecue->heures_td, (float) $q->ecue->heures_tp)),
            ]),
            Infolists\Components\Section::make('Consommation')->collapsible()->schema([
                Infolists\Components\ViewEntry::make('consommation')->hiddenLabel()->view('filament.lmd.quota-consommation'),
            ]),
        ]);
    }

    public static function reparti(QuotaHoraire $q): float
    {
        return round($q->programmations->filter(fn (ProgrammationEnseignant $p): bool => $p->estActive())->sum(fn (ProgrammationEnseignant $p): float => $p->total()), 1);
    }

    public static function getRelations(): array
    {
        return [ProgrammationsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListQuotasHoraires::route('/'),
            'view' => Pages\ViewQuotaHoraire::route('/{record}'),
        ];
    }
}
