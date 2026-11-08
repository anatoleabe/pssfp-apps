<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\RestrictedToModule;
use App\Filament\Resources\AuditeurResource\MessagesValidation;
use App\Filament\Resources\PointageEnseignantResource\Pages;
use App\Filament\Resources\QuotaHoraireResource\ChampsProgrammation;
use App\Models\PointageEnseignant;
use App\Models\Seance;
use App\Services\Lmd\PermissionsLmd;
use App\Services\Lmd\PointagesEnseignant;
use App\Support\BelongsToModule;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Pointages des cours enseignés (lot 13a ; D23). La scolarité les voit tous, les saisit, les valide ou les rejette ;
 * l'enseignant n'y voit que les siens (« Mes émargements ») et pointe ses séances.
 */
#[BelongsToModule('lmd')]
class PointageEnseignantResource extends Resource
{
    use RestrictedToModule;

    protected static ?string $model = PointageEnseignant::class;

    protected static ?string $modelLabel = 'Pointage';

    protected static ?string $pluralModelLabel = 'Pointages des enseignants';

    protected static ?string $navigationIcon = 'heroicon-o-finger-print';

    protected static ?string $navigationGroup = 'Paie des enseignants';

    protected static ?int $navigationSort = 46;

    protected static ?string $slug = 'pointages-enseignant';

    /** L'utilisateur ne voit que ses propres pointages (enseignant sans droit de gestion). */
    public static function enseignantSeul(): bool
    {
        $u = auth()->user();

        return $u !== null && ! $u->can(PermissionsLmd::CONSULTER) && ! $u->can(PermissionsLmd::POINTAGE);
    }

    public static function getNavigationLabel(): string
    {
        return static::enseignantSeul() ? 'Mes émargements' : 'Pointages des enseignants';
    }

    public static function getPluralModelLabel(): string
    {
        return static::enseignantSeul() ? 'Mes émargements' : 'Pointages des enseignants';
    }

    public static function getBreadcrumb(): string
    {
        return static::getPluralModelLabel();
    }

    /** Ce que la validation figera, et ce qui la bloquera. */
    public static function descriptionValidation(PointageEnseignant $p): string
    {
        if ($p->arrivee === null || $p->depart === null || blank($p->contenu_enseigne)) {
            return 'Contenu enseigné ou horaires manquants : la validation sera refusée. Modifiez d\'abord le pointage.';
        }
        $h = str_replace('.', ',', (string) round(app(PointagesEnseignant::class)->minutesPayables($p) / 60, 2));

        return $h.' h de '.$p->nature.' seront validées (durée plafonnée à la séance, arrondie au pas inférieur) et la séance passera à « tenue ».';
    }

    public static function getNavigationBadge(): ?string
    {
        $u = auth()->user();
        if ($u === null || ! $u->can(PermissionsLmd::POINTAGE)) {
            return null;
        }
        $n = PointageEnseignant::query()->where('statut', PointageEnseignant::A_VALIDER)->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Pointages à valider';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->when(static::enseignantSeul(), fn (Builder $q) => $q->where('enseignant_id', auth()->id()));
    }

    /**
     * Horaires, nature et cahier de textes d'un pointage.
     *
     * @return list<Forms\Components\Component>
     */
    public static function champsHoraires(bool $gestion): array
    {
        return [
            Forms\Components\Grid::make(3)->schema([
                Forms\Components\TimePicker::make('arrivee')->label('Arrivée')->seconds(false)->required()
                    ->helperText('Heure réelle : corrigez l\'horaire prévu en cas de retard.'),
                Forms\Components\TimePicker::make('depart')->label('Départ')->seconds(false)->required()->after('arrivee'),
                Forms\Components\Select::make('nature')->label('Nature')->native(false)->options(PointageEnseignant::NATURES)->default('CM')->required()
                    ->disabled(! $gestion)->helperText($gestion ? null : 'Celle de la séance.'),
            ]),
            Forms\Components\Textarea::make('contenu_enseigne')->label('Contenu enseigné (cahier de textes)')->rows(4)->maxLength(5000)
                ->helperText('Chapitre ou thème traité, travaux donnés. Obligatoire pour que la Scolarité valide le pointage.'),
            Forms\Components\Grid::make(2)->visible($gestion)->schema([
                Forms\Components\Select::make('mode')->label('Mode')->native(false)->options(PointageEnseignant::MODES_SAISIE)->default('saisie')->required()
                    ->helperText('La signature est réservée à l\'enseignant qui émarge lui-même.'),
                Forms\Components\Select::make('confirme_delegue')->label('Confirmation du délégué')->native(false)->placeholder('Non renseignée')
                    ->options(['1' => 'Confirmé par le délégué', '0' => 'Non confirmé']),
            ]),
        ];
    }

    /** Pré-remplit les horaires et la nature d'après la séance choisie. */
    public static function remplirDepuisSeance(?string $seanceId, Forms\Set $set): void
    {
        $s = $seanceId === null ? null : Seance::query()->find((int) $seanceId);
        if ($s === null) {
            return;
        }
        $set('arrivee', substr((string) $s->debut, 0, 5));
        $set('depart', substr((string) $s->fin, 0, 5));
        $set('nature', match (true) {
            $s->nature === 'evaluation' => 'surveillance',
            in_array($s->nature, ['CM', 'TD', 'TP'], true) => $s->nature,
            default => 'CM',
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function donnees(array $data): array
    {
        if (array_key_exists('confirme_delegue', $data)) {
            $data['confirme_delegue'] = $data['confirme_delegue'] === null || $data['confirme_delegue'] === '' ? null : $data['confirme_delegue'] === '1';
        }

        return $data;
    }

    public static function table(Table $table): Table
    {
        $gestion = fn (): bool => (bool) auth()->user()?->can(PermissionsLmd::POINTAGE);

        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['enseignant', 'remplace', 'saisiPar', 'seance.ecue']))
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('seance.ecue.code')->label('Séance')->searchable()
                    ->tooltip(fn (PointageEnseignant $p): string => $p->seance->ecue->intitule)
                    ->description(fn (PointageEnseignant $p): string => $p->seance->date_seance->format('d/m/Y').' · '.substr((string) $p->seance->debut, 0, 5).'-'.substr((string) $p->seance->fin, 0, 5)
                        .' · '.Str::limit($p->seance->ecue->intitule, 40)),
                Tables\Columns\TextColumn::make('enseignant.name')->label('Enseignant')->searchable()->hidden(fn (): bool => static::enseignantSeul())
                    ->description(fn (PointageEnseignant $p): ?string => $p->remplace !== null ? 'remplace '.$p->remplace->name : null),
                Tables\Columns\TextColumn::make('horaires')->label('Pointé')->getStateUsing(fn (PointageEnseignant $p): string => $p->horaires() ?? 'incomplet')
                    ->description(fn (PointageEnseignant $p): string => $p->nature.' · '.(PointageEnseignant::MODES[$p->mode] ?? $p->mode)
                        .($p->saisi_par !== null && $p->saisi_par !== $p->enseignant_id ? ' · saisi par '.$p->saisiPar?->name : '')),
                Tables\Columns\TextColumn::make('heures')->label('Heures validées')
                    ->getStateUsing(fn (PointageEnseignant $p): string => $p->heures() === null ? '—' : str_replace('.', ',', (string) $p->heures()).' h'),
                Tables\Columns\TextColumn::make('contenu_enseigne')->label('Contenu enseigné')->limit(60)->wrap()->placeholder('à renseigner'),
                Tables\Columns\TextColumn::make('statut')->label('État')->badge()
                    ->formatStateUsing(fn (string $state, PointageEnseignant $p): string => $p->estPaye() ? 'Payé' : (PointageEnseignant::STATUTS[$state] ?? $state))
                    ->color(fn (string $state): string => match ($state) {
                        'valide' => 'success', 'rejete' => 'danger', default => 'warning'
                    })
                    ->description(fn (PointageEnseignant $p): ?string => $p->motif_rejet === null ? null : Str::limit($p->motif_rejet, 80)),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('periode')->label('Période')->options(fn (): array => ChampsProgrammation::periodes())
                    ->query(fn (Builder $query, array $data): Builder => empty($data['value']) ? $query
                        : $query->whereHas('seance.emploiDuTemps', fn ($q) => $q->where('periode_id', $data['value']))),
                Tables\Filters\SelectFilter::make('enseignant_id')->label('Enseignant')->options(fn (): array => ChampsProgrammation::enseignants())
                    ->searchable()->visible(fn (): bool => ! static::enseignantSeul()),
                Tables\Filters\SelectFilter::make('statut')->label('État')->options(PointageEnseignant::STATUTS),
            ])
            ->actions([Tables\Actions\ActionGroup::make([
                Tables\Actions\Action::make('modifier')->label('Modifier')->icon('heroicon-o-pencil')
                    ->visible(fn (PointageEnseignant $p): bool => (bool) auth()->user()?->can('update', $p))
                    ->fillForm(fn (PointageEnseignant $p): array => [
                        'arrivee' => $p->arrivee?->setTimezone(PointagesEnseignant::FUSEAU)->format('H:i'),
                        'depart' => $p->depart?->setTimezone(PointagesEnseignant::FUSEAU)->format('H:i'),
                        'nature' => $p->nature, 'contenu_enseigne' => $p->contenu_enseigne, 'mode' => $p->mode,
                        'confirme_delegue' => $p->confirme_delegue === null ? null : ($p->confirme_delegue ? '1' : '0'),
                    ])
                    ->form(fn (): array => MessagesValidation::appliquer(static::champsHoraires($gestion()), ['after' => 'Le départ suit l\'arrivée.']))
                    ->modalDescription(fn (PointageEnseignant $p): ?string => $p->statut === PointageEnseignant::A_VALIDER ? null : 'Le pointage modifié repasse à valider.')
                    ->action(fn (PointageEnseignant $p, array $data) => ChampsProgrammation::executer(function () use ($p, $data): string {
                        app(PointagesEnseignant::class)->modifier($p, static::donnees($data), auth()->user());

                        return 'Pointage modifié.';
                    })),
                Tables\Actions\Action::make('valider')->label('Valider')->icon('heroicon-o-check-badge')->color('success')
                    ->visible(fn (PointageEnseignant $p): bool => $p->statut === PointageEnseignant::A_VALIDER && $gestion()
                        && app(PointagesEnseignant::class)->peutValider($p, auth()->user()))
                    ->requiresConfirmation()->modalDescription(fn (PointageEnseignant $p): string => static::descriptionValidation($p))
                    ->action(fn (PointageEnseignant $p) => ChampsProgrammation::executer(function () use ($p): string {
                        app(PointagesEnseignant::class)->valider($p, auth()->user());

                        return 'Pointage validé.';
                    })),
                Tables\Actions\Action::make('rejeter')->label('Rejeter')->icon('heroicon-o-x-circle')->color('danger')
                    ->visible(fn (PointageEnseignant $p): bool => $p->statut === PointageEnseignant::A_VALIDER && $gestion())
                    ->form(MessagesValidation::appliquer([Forms\Components\Textarea::make('motif')->label('Motif du rejet')->required()->minLength(5)->maxLength(500)],
                        ['min' => 'Le motif compte au moins 5 caractères.']))
                    ->action(fn (PointageEnseignant $p, array $data) => ChampsProgrammation::executer(function () use ($p, $data): string {
                        app(PointagesEnseignant::class)->rejeter($p, (string) $data['motif'], auth()->user());

                        return 'Pointage rejeté : l\'enseignant voit le motif dans ses émargements.';
                    })),
            ])->label('Actions')->icon('heroicon-o-ellipsis-vertical')])
            ->bulkActions([
                Tables\Actions\BulkAction::make('valider')->label('Valider')->icon('heroicon-o-check-badge')->visible($gestion)
                    ->requiresConfirmation()->deselectRecordsAfterCompletion()
                    ->modalDescription('Seuls les pointages à valider et complets (arrivée, départ, contenu enseigné) sont validés. Sont ignorés : vos propres heures d\'enseignement, et une séance qui a déjà un pointage validé.')
                    ->action(fn (Collection $records) => ChampsProgrammation::executer(function () use ($records): string {
                        [$n, $ignores] = [0, 0];
                        foreach ($records as $p) {
                            try {
                                app(PointagesEnseignant::class)->valider($p, auth()->user());
                                $n++;
                            } catch (InvalidArgumentException) {
                                $ignores++;
                            }
                        }

                        return $n.' pointage(s) validé(s)'.($ignores > 0 ? ', '.$ignores.' ignoré(s)' : '').'.';
                    })),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListPointagesEnseignant::route('/')];
    }
}
