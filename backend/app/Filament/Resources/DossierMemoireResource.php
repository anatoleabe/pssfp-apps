<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\RestrictedToModule;
use App\Filament\Navigation\GroupeNavigation;
use App\Filament\Resources\AuditeurResource\MessagesValidation;
use App\Filament\Resources\DossierMemoireResource\Pages;
use App\Models\DossierMemoire;
use App\Models\EtapeMemoire;
use App\Models\Parcours;
use App\Support\BelongsToModule;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Mémoires des autorisés à soutenir et attestation d'autorisation de dépôt (spec module 4, lot 11 ; D32 à D34). */
#[BelongsToModule('lmd')]
class DossierMemoireResource extends Resource
{
    use RestrictedToModule;

    protected static ?string $model = DossierMemoire::class;

    protected static ?string $modelLabel = 'Mémoire';

    protected static ?string $pluralModelLabel = 'Mémoires';

    protected static ?string $navigationLabel = 'Mémoires';

    protected static ?string $navigationIcon = 'heroicon-o-book-open';

    protected static ?string $navigationGroup = GroupeNavigation::EVALUATIONS;

    protected static ?int $navigationSort = 80;

    public static function form(Form $form): Form
    {
        return $form->schema(MessagesValidation::appliquer([
            Forms\Components\Section::make('Mémoire')->columns(2)->schema([
                Forms\Components\Textarea::make('theme')->label('Thème')->rows(2)->maxLength(500)->columnSpanFull(),
                Forms\Components\TextInput::make('structure_accueil')->label('Structure d\'accueil')->maxLength(255),
                Forms\Components\Grid::make(2)->columnSpan(1)->schema([
                    Forms\Components\DatePicker::make('stage_debut')->label('Début du stage')->native(false)->displayFormat('d/m/Y'),
                    Forms\Components\DatePicker::make('stage_fin')->label('Fin du stage')->native(false)->displayFormat('d/m/Y')->afterOrEqual('stage_debut'),
                ]),
                Forms\Components\TextInput::make('encadreur_academique')->label('Encadreur académique')->maxLength(255),
                Forms\Components\Select::make('encadreur_academique_id')->label('Compte de l\'encadreur académique')->searchable()
                    ->helperText('Facultatif : le compte permet à l\'encadreur d\'ajouter ses fiches de suivi et son avis.')
                    ->relationship('encadreurCompte', 'name'),
                Forms\Components\TextInput::make('encadreur_professionnel')->label('Encadreur professionnel')->maxLength(255),
                Forms\Components\Grid::make(2)->columnSpan(1)->schema([
                    Forms\Components\TextInput::make('vague')->label('Vague de soutenance')->numeric()->minValue(1)->maxValue(20),
                    Forms\Components\DatePicker::make('date_soutenance')->label('Date de soutenance')->native(false)->displayFormat('d/m/Y'),
                ]),
            ]),
        ], ['after_or_equal' => 'La fin du stage doit suivre son début.']));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['auditeur.candidature', 'inscriptionAnnuelle.parcours', 'etapes']))
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('auditeur.candidature.matricule')->label('Matricule')->fontFamily('mono')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas('auditeur.candidature', fn ($q) => $q->where('matricule', 'ilike', '%'.$search.'%')
                        ->orWhere('nom', 'ilike', '%'.$search.'%')->orWhere('prenom', 'ilike', '%'.$search.'%'))),
                Tables\Columns\TextColumn::make('nom')->label('Auditeur')->getStateUsing(fn (DossierMemoire $d): string => trim(mb_strtoupper((string) $d->auditeur?->candidature?->nom).' '.$d->auditeur?->candidature?->prenom)),
                Tables\Columns\TextColumn::make('inscriptionAnnuelle.parcours.code')->label('Option')->placeholder('—'),
                Tables\Columns\TextColumn::make('theme')->label('Thème')->limit(50)->placeholder('À enregistrer')->wrap(),
                Tables\Columns\TextColumn::make('encadreur_academique')->label('Encadreur')->placeholder('—'),
                Tables\Columns\TextColumn::make('avancement')->label('Avancement')
                    ->getStateUsing(fn (DossierMemoire $d): string => $d->etapes->whereIn('statut', ['valide', 'dispense'])->sum('poids').' %'),
                Tables\Columns\TextColumn::make('retards')->label('Retards')->badge()->color('danger')
                    ->getStateUsing(fn (DossierMemoire $d): ?string => ($n = $d->etapes->filter(fn (EtapeMemoire $e): bool => $e->enRetard() || $e->enAttenteDepassee())->count()) > 0 ? (string) $n : null)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('statut')->label('État')->badge()->formatStateUsing(fn (string $state): string => DossierMemoire::STATUTS[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        'pret' => 'info', 'soutenu' => 'success', 'suspendu', 'abandon' => 'gray', default => 'warning'
                    }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('statut')->label('État')->options(DossierMemoire::STATUTS),
                Tables\Filters\SelectFilter::make('parcours')->label('Option')
                    ->options(fn (): array => Parcours::query()->orderBy('code')->pluck('libelle', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => blank($data['value'] ?? null) ? $query
                        : $query->whereHas('inscriptionAnnuelle', fn ($q) => $q->where('parcours_id', $data['value']))),
            ])
            ->actions([Tables\Actions\ViewAction::make()]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make()->columns(4)->schema([
                Infolists\Components\TextEntry::make('auditeur')->label('Auditeur')
                    ->getStateUsing(fn (DossierMemoire $d): string => $d->auditeur?->candidature?->matricule.' — '.trim(mb_strtoupper((string) $d->auditeur?->candidature?->nom).' '.$d->auditeur?->candidature?->prenom)),
                Infolists\Components\TextEntry::make('inscriptionAnnuelle.parcours.libelle')->label('Option')->placeholder('—'),
                Infolists\Components\TextEntry::make('statut')->label('État')->badge()->formatStateUsing(fn (string $state): string => DossierMemoire::STATUTS[$state] ?? $state),
                Infolists\Components\TextEntry::make('statut_motif')->label('Motif')->placeholder('—'),
                Infolists\Components\TextEntry::make('theme')->label('Thème')->placeholder('À enregistrer')->columnSpan(2),
                Infolists\Components\TextEntry::make('encadreur_academique')->label('Encadreur académique')->placeholder('—'),
                Infolists\Components\TextEntry::make('encadreur_professionnel')->label('Encadreur professionnel')->placeholder('—'),
                Infolists\Components\TextEntry::make('structure_accueil')->label('Structure d\'accueil')->placeholder('—'),
                Infolists\Components\TextEntry::make('stage')->label('Stage')->placeholder('—')
                    ->getStateUsing(fn (DossierMemoire $d): ?string => $d->stage_debut ? 'du '.$d->stage_debut->format('d/m/Y').($d->stage_fin ? ' au '.$d->stage_fin->format('d/m/Y') : '') : null),
                Infolists\Components\TextEntry::make('vague')->label('Vague')->placeholder('—'),
                Infolists\Components\TextEntry::make('date_soutenance')->label('Soutenance')->date('d/m/Y')->placeholder('—'),
            ]),
            Infolists\Components\Section::make('Suivi du mémoire')->schema([
                Infolists\Components\ViewEntry::make('etapes')->hiddenLabel()->view('filament.lmd.memoire'),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDossiersMemoire::route('/'),
            'view' => Pages\ViewDossierMemoire::route('/{record}'),
            'edit' => Pages\EditDossierMemoire::route('/{record}/edit'),
        ];
    }
}
