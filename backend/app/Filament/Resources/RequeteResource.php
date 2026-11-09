<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\RestrictedToModule;
use App\Filament\Navigation\GroupeNavigation;
use App\Filament\Resources\RequeteResource\Pages;
use App\Models\Requete;
use App\Models\RequetePiece;
use App\Support\BelongsToModule;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Requêtes des auditeurs (spec module 4, lot 8) : cotation, instruction, jury, validation, application. */
#[BelongsToModule('lmd')]
class RequeteResource extends Resource
{
    use RestrictedToModule;

    protected static ?string $model = Requete::class;

    protected static ?string $modelLabel = 'Requête';

    protected static ?string $pluralModelLabel = 'Requêtes';

    protected static ?string $navigationIcon = 'heroicon-o-inbox-stack';

    protected static ?string $navigationGroup = GroupeNavigation::EVALUATIONS;

    protected static ?int $navigationSort = 60;

    public static function getNavigationBadge(): ?string
    {
        $n = Requete::query()->whereIn('statut', ['deposee', 'cotee', 'en_instruction', 'en_traitement'])
            ->when(! auth()->user()?->can('lmd.requetes_coter'), fn (Builder $q) => $q->where('agent_id', auth()->id()))->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['auditeur.candidature', 'ecue', 'agent']))
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('numero')->label('Numéro')->fontFamily('mono')->searchable(),
                Tables\Columns\TextColumn::make('auditeur.candidature.matricule')->label('Matricule')->searchable(),
                Tables\Columns\TextColumn::make('nom')->label('Auditeur')
                    ->getStateUsing(fn (Requete $r): string => trim(mb_strtoupper((string) $r->auditeur?->candidature?->nom).' '.$r->auditeur?->candidature?->prenom)),
                Tables\Columns\TextColumn::make('objet')->label('Objet')->wrap()->getStateUsing(fn (Requete $r): string => $r->objet()),
                Tables\Columns\TextColumn::make('statut')->label('Étape')->badge()
                    ->formatStateUsing(fn (string $state): string => Requete::STATUTS[$state] ?? $state)
                    ->color(fn (string $state): string => match (true) {
                        in_array($state, ['appliquee', 'document_disponible', 'cloturee'], true) => 'success',
                        in_array($state, ['irrecevable', 'annulee', 'rejetee'], true) => 'gray',
                        default => 'warning',
                    }),
                Tables\Columns\TextColumn::make('agent.name')->label('Agent')->placeholder('Non cotée'),
                Tables\Columns\TextColumn::make('created_at')->label('Déposée le')->dateTime('d/m/Y H:i', 'Africa/Douala')->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('statut')->label('Étape')->options(Requete::STATUTS)->multiple(),
                Tables\Filters\SelectFilter::make('type')->label('Type')->options([Requete::NOTE => 'Requête de note', Requete::DOCUMENT => 'Demande de document']),
                Tables\Filters\Filter::make('miennes')->label('Mes requêtes')->query(fn (Builder $q): Builder => $q->where('agent_id', auth()->id())),
            ])
            ->actions([Tables\Actions\ViewAction::make()]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make()->columns(3)->schema([
                Infolists\Components\TextEntry::make('numero')->label('Numéro'),
                Infolists\Components\TextEntry::make('auditeur')->label('Auditeur')
                    ->getStateUsing(fn (Requete $r): string => $r->auditeur?->candidature?->matricule.' — '.trim(mb_strtoupper((string) $r->auditeur?->candidature?->nom).' '.$r->auditeur?->candidature?->prenom)),
                Infolists\Components\TextEntry::make('statut')->label('Étape')->badge()->formatStateUsing(fn (string $state): string => Requete::STATUTS[$state] ?? $state),
                Infolists\Components\TextEntry::make('objet')->label('Objet')->getStateUsing(fn (Requete $r): string => $r->objet()),
                Infolists\Components\TextEntry::make('motif')->label('Motif')->placeholder('—')->formatStateUsing(fn (?string $state): string => Requete::MOTIFS[$state] ?? '—'),
                Infolists\Components\TextEntry::make('agent.name')->label('Agent')->placeholder('Non cotée'),
                Infolists\Components\TextEntry::make('description')->label('Description de l\'auditeur')->columnSpanFull(),
                Infolists\Components\TextEntry::make('decision')->label('Décision')->columnSpanFull()
                    ->visible(fn (Requete $r): bool => $r->mention !== null || $r->decision_motif !== null)
                    ->getStateUsing(fn (Requete $r): string => trim(($r->mention ? Requete::MENTIONS[$r->mention] : '').($r->note_retenue !== null ? ' — note retenue '.number_format((float) $r->note_retenue, 2, ',', ' ') : '').($r->decision_motif ? ' — '.$r->decision_motif : ''), ' —')),
                Infolists\Components\TextEntry::make('sha256')->label('Empreinte du dépôt')->fontFamily('mono')->columnSpanFull(),
            ]),
            Infolists\Components\Section::make('Pièces et éléments de vérification')->schema([
                Infolists\Components\RepeatableEntry::make('pieces')->hiddenLabel()->columns(3)->schema([
                    Infolists\Components\TextEntry::make('nature')->label('Nature')->formatStateUsing(fn (string $state): string => RequetePiece::NATURES[$state] ?? $state),
                    Infolists\Components\TextEntry::make('nom')->label('Fichier')->placeholder('Constat sans pièce'),
                    Infolists\Components\TextEntry::make('constat')->label('Constat')->placeholder('—'),
                ]),
            ]),
            Infolists\Components\Section::make('Historique')->collapsible()->schema([
                Infolists\Components\RepeatableEntry::make('evenements')->hiddenLabel()->columns(3)->schema([
                    Infolists\Components\TextEntry::make('created_at')->label('Le')->dateTime('d/m/Y H:i', 'Africa/Douala'),
                    Infolists\Components\TextEntry::make('libelle')->label('Étape'),
                    Infolists\Components\TextEntry::make('detail')->label('Détail interne')->placeholder('—'),
                ]),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRequetes::route('/'),
            'view' => Pages\ViewRequete::route('/{record}'),
        ];
    }
}
