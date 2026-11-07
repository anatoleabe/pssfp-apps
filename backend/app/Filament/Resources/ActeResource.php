<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\RestrictedToModule;
use App\Filament\Resources\ActeResource\Pages;
use App\Models\Acte;
use App\Models\CalendrierAcademique;
use App\Models\ModeleActe;
use App\Support\BelongsToModule;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Actes produits à partir du calendrier et tableau de suivi (spec module 4, lot 12b ; D18, D19). */
#[BelongsToModule('lmd')]
class ActeResource extends Resource
{
    use RestrictedToModule;

    protected static ?string $model = Acte::class;

    protected static ?string $modelLabel = 'Acte';

    protected static ?string $pluralModelLabel = 'Actes';

    protected static ?string $navigationLabel = 'Actes';

    protected static ?string $navigationIcon = 'heroicon-o-document-check';

    protected static ?string $navigationGroup = 'Parcours LMD';

    protected static ?int $navigationSort = 46;

    public static function getNavigationBadge(): ?string
    {
        $n = Acte::query()->whereIn('statut', Acte::EN_COURS)->where('echeance', '<=', now('Africa/Douala')->addDays(Acte::HORIZON_JOURS)->toDateString())->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Actes en retard ou à produire dans les '.Acte::HORIZON_JOURS.' jours';
    }

    public static function table(Table $table): Table
    {
        $aujourdhui = fn (): string => now('Africa/Douala')->toDateString();
        $horizon = fn (): string => now('Africa/Douala')->addDays(Acte::HORIZON_JOURS)->toDateString();

        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['modele:id,code,libelle', 'evenement:id,calendrier_id,libelle,niveau,debut_prevu']))
            ->defaultSort('echeance')
            ->columns([
                Tables\Columns\TextColumn::make('echeance')->label('Échéance')->date('d/m/Y')->sortable(),
                Tables\Columns\TextColumn::make('alerte')->label('Suivi')->badge()
                    ->getStateUsing(fn (Acte $a): string => $a->alerte())
                    ->formatStateUsing(fn (string $state): string => Acte::ALERTES[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        'en_retard' => 'danger', 'a_traiter' => 'warning', default => 'gray'
                    }),
                Tables\Columns\TextColumn::make('objet')->label('Objet')->wrap()->searchable()->limit(110),
                Tables\Columns\TextColumn::make('evenement.debut_prevu')->label('Événement le')->date('d/m/Y'),
                Tables\Columns\TextColumn::make('numero')->label('Numéro')->placeholder('—')->searchable()
                    ->description(fn (Acte $a): ?string => $a->date_signature?->format('d/m/Y')),
                Tables\Columns\TextColumn::make('statut')->label('État')->badge()->formatStateUsing(fn (string $state): string => Acte::STATUTS[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        'diffuse' => 'success', 'signe' => 'info', 'soumis' => 'warning', 'annule' => 'gray', default => 'gray'
                    }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('alerte')->label('Suivi')
                    ->options(['en_retard' => 'En retard', 'a_traiter' => 'À traiter (15 jours)'])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'en_retard' => $query->whereIn('statut', Acte::EN_COURS)->where('echeance', '<', $aujourdhui()),
                        'a_traiter' => $query->whereIn('statut', Acte::EN_COURS)->whereBetween('echeance', [$aujourdhui(), $horizon()]),
                        default => $query,
                    }),
                Tables\Filters\SelectFilter::make('statut')->label('État')->options(Acte::STATUTS),
                Tables\Filters\SelectFilter::make('modele_id')->label('Modèle')->options(fn (): array => ModeleActe::query()->orderBy('id')->pluck('libelle', 'id')->all()),
                Tables\Filters\SelectFilter::make('calendrier')->label('Calendrier')
                    ->options(fn (): array => CalendrierAcademique::query()->orderByDesc('id')->get()->mapWithKeys(fn (CalendrierAcademique $c): array => [$c->id => $c->libelle()])->all())
                    ->query(fn (Builder $query, array $data): Builder => empty($data['value']) ? $query
                        : $query->whereHas('evenement', fn (Builder $q) => $q->where('calendrier_id', $data['value']))),
            ])
            ->actions([Tables\Actions\ViewAction::make()]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make()->columns(4)->schema([
                Infolists\Components\TextEntry::make('modele.libelle')->label('Modèle')->columnSpan(2),
                Infolists\Components\TextEntry::make('statut')->label('État')->badge()->formatStateUsing(fn (string $state): string => Acte::STATUTS[$state] ?? $state),
                Infolists\Components\TextEntry::make('alerte')->label('Suivi')->getStateUsing(fn (Acte $a): string => Acte::ALERTES[$a->alerte()]),
                Infolists\Components\TextEntry::make('objet')->label('Objet')->columnSpanFull(),
                Infolists\Components\TextEntry::make('evenement.libelle')->label('Événement')->columnSpan(2)
                    ->helperText(fn (Acte $a): string => 'Du '.$a->evenement->debut_prevu->format('d/m/Y').' au '.$a->evenement->fin_prevue->format('d/m/Y')),
                Infolists\Components\TextEntry::make('echeance')->label('Échéance')->date('d/m/Y')
                    ->helperText(fn (Acte $a): string => $a->modele->delaiLibelle().' le début de l\'événement'),
                Infolists\Components\TextEntry::make('modele.signataire')->label('Signataire prévu'),
                Infolists\Components\TextEntry::make('numero')->label('Numéro')->placeholder('—'),
                Infolists\Components\TextEntry::make('date_signature')->label('Signé le')->date('d/m/Y')->placeholder('—'),
                Infolists\Components\TextEntry::make('signataire')->label('Signé par')->placeholder('—'),
                Infolists\Components\TextEntry::make('diffuse_at')->label('Diffusé le')->dateTime('d/m/Y H:i')->timezone('Africa/Douala')->placeholder('—'),
                Infolists\Components\TextEntry::make('motif')->label('Motif (renvoi ou annulation)')->placeholder('—')->columnSpanFull(),
            ]),
            Infolists\Components\Section::make('Texte de l\'acte')->schema([
                Infolists\Components\TextEntry::make('contenu')->hiddenLabel()->placeholder('Pas encore rédigé : le gabarit du modèle sert de point de départ.')
                    ->formatStateUsing(fn (?string $state): string => nl2br(e((string) $state)))->html(),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListActes::route('/'),
            'view' => Pages\ViewActe::route('/{record}'),
        ];
    }
}
