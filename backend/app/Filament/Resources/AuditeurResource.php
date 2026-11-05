<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\RestrictedToModule;
use App\Filament\Resources\AuditeurResource\Pages;
use App\Filament\Resources\AuditeurResource\RelationManagers\VersementsRelationManager;
use App\Filament\Resources\CandidatureResource\ComptesEcampusActions;
use App\Filament\Resources\CandidatureResource\DocumentsAuditeursActions;
use App\Filament\Resources\CandidatureResource\IdentifiantsMoodleActions;
use App\Models\Auditeur;
use App\Models\BaremeScolarite;
use App\Models\CampagneCandidature;
use App\Services\DocumentsAuditeurs\ParametresLettres;
use App\Services\Scolarite\AccesEcampus;
use App\Services\Scolarite\LibelleSituation;
use App\Support\BelongsToModule;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Vie académique > Auditeurs (spec module 4, lot 1).
 *
 * Lecture seule : l'identité vient de la candidature et ne se modifie pas ici,
 * l'argent passe par les actions de la fiche. Ni mot de passe FOAD, ni lien
 * vers le dossier pour qui n'a pas déjà le droit de le voir.
 */
#[BelongsToModule('scolarite')]
class AuditeurResource extends Resource
{
    use RestrictedToModule;

    protected static ?string $model = Auditeur::class;

    protected static ?string $modelLabel = 'Auditeur';

    protected static ?string $pluralModelLabel = 'Auditeurs';

    protected static ?string $navigationIcon = 'heroicon-o-academic-cap';

    protected static ?string $navigationGroup = 'Vie académique';

    protected static ?int $navigationSort = 10;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'candidature.campagne',
            'inscriptionCourante' => fn ($q) => $q->with('bareme')->withSum('versementsValides', 'montant'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('candidature.matricule')->label('Matricule')->fontFamily('mono')->sortable()
                    ->searchable(query: fn (Builder $query, string $search): Builder => self::rechercher($query, $search)),
                Tables\Columns\TextColumn::make('nom')->label('Nom')
                    ->getStateUsing(fn (Auditeur $record): string => trim(mb_strtoupper((string) $record->candidature?->nom).' '.$record->candidature?->prenom)),
                Tables\Columns\TextColumn::make('candidature.campagne.promotion_numero')->label('Promotion')
                    ->formatStateUsing(fn ($state): string => $state === null ? '—' : $state.'e'),
                Tables\Columns\TextColumn::make('inscriptionCourante.niveau')->label('Niveau')->placeholder('—'),
                Tables\Columns\TextColumn::make('verse')->label('Versé')->alignEnd()
                    ->getStateUsing(fn (Auditeur $record): ?string => ($s = $record->inscriptionCourante?->situation()) === null ? null : LibelleSituation::fcfa($s->verse))
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('reste')->label('Reste à payer')->alignEnd()
                    ->getStateUsing(fn (Auditeur $record): ?string => ($s = $record->inscriptionCourante?->situation()) === null ? null : LibelleSituation::fcfa($s->reste))
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('situation')->label('Situation')->badge()
                    ->getStateUsing(fn (Auditeur $record): string => LibelleSituation::libelle($record->inscriptionCourante))
                    ->color(fn (Auditeur $record): string => LibelleSituation::couleur(LibelleSituation::cle($record->inscriptionCourante))),
                Tables\Columns\TextColumn::make('statut')->label('Statut')->badge()
                    ->formatStateUsing(fn (string $state): string => Auditeur::STATUTS[$state] ?? $state)
                    ->color(fn (string $state): string => self::couleurStatut($state))
                    ->toggleable(),
                Tables\Columns\TextColumn::make('retrait')->label('')
                    ->getStateUsing(fn (Auditeur $record): ?string => $record->candidature?->withdrawn_at === null ? null : 'Retiré')
                    ->badge()->color('gray'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('promotion')->label('Promotion')
                    ->options(fn (): array => CampagneCandidature::query()->whereHas('candidatures.auditeur')->orderByDesc('promotion_numero')
                        ->pluck('promotion_numero', 'id')->map(fn ($n): string => $n.'e promotion')->all())
                    ->query(fn (Builder $query, array $data): Builder => blank($data['value'] ?? null) ? $query
                        : $query->whereHas('candidature', fn (Builder $c) => $c->where('campagne_id', $data['value']))),
                Tables\Filters\SelectFilter::make('statut')->label('Statut')->options(Auditeur::STATUTS),
                Tables\Filters\SelectFilter::make('niveau')->label('Niveau')->options(BaremeScolarite::NIVEAUX)
                    ->query(fn (Builder $query, array $data): Builder => blank($data['value'] ?? null) ? $query
                        : $query->whereHas('inscriptionCourante', fn (Builder $i) => $i->where('niveau', $data['value']))),
                Tables\Filters\SelectFilter::make('situation')->label('Situation')
                    ->options([
                        LibelleSituation::NON_SOLDEE => 'Inscription non soldée',
                        LibelleSituation::EN_RETARD => 'En retard (inscription soldée, tranche échue)',
                        LibelleSituation::A_JOUR => 'À jour',
                        LibelleSituation::SOLDEE => 'Soldé',
                        LibelleSituation::SANS_INSCRIPTION => 'Barème manquant',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        LibelleSituation::NON_SOLDEE => $query->whereHas('inscriptionCourante', fn (Builder $i) => $i->inscriptionNonSoldee()),
                        // Même priorité que le badge : une inscription non soldée n'est pas « en retard ».
                        LibelleSituation::EN_RETARD => $query->whereHas('inscriptionCourante', fn (Builder $i) => $i->inscriptionSoldee()->enRetard()),
                        LibelleSituation::A_JOUR => $query->whereHas('inscriptionCourante', fn (Builder $i) => $i->inscriptionSoldee()
                            ->whereNot(fn (Builder $q) => $q->enRetard())
                            ->whereNot(fn (Builder $q) => $q->entierementSoldee())),
                        LibelleSituation::SOLDEE => $query->whereHas('inscriptionCourante', fn (Builder $i) => $i->entierementSoldee()),
                        LibelleSituation::SANS_INSCRIPTION => $query->doesntHave('inscriptions'),
                        default => $query,
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label('Fiche'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    AuditeurResource\AuditeurActions::message(),
                    ComptesEcampusActions::creerGroupe(Tables\Actions\BulkAction::make('creerComptesEcampus')),
                    IdentifiantsMoodleActions::envoyerGroupe(Tables\Actions\BulkAction::make('envoyerIdentifiantsMoodleBulk')),
                    DocumentsAuditeursActions::selection(Tables\Actions\BulkAction::make('lettresAdmissionBulk'), ParametresLettres::DOCUMENT_ADMISSION),
                    DocumentsAuditeursActions::selection(Tables\Actions\BulkAction::make('accesFoadBulk'), ParametresLettres::DOCUMENT_ACCES),
                ])->label('Actions sur la sélection'),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Identité')->columns(3)->schema([
                Infolists\Components\TextEntry::make('candidature.matricule')->label('Matricule')->fontFamily('mono')->copyable(),
                Infolists\Components\TextEntry::make('nom')->label('Nom et prénom')
                    ->getStateUsing(fn (Auditeur $record): string => trim(mb_strtoupper((string) $record->candidature?->nom).' '.$record->candidature?->prenom)),
                Infolists\Components\TextEntry::make('candidature.phone_e164')->label('Téléphone'),
                Infolists\Components\TextEntry::make('candidature.campagne.promotion_numero')->label('Promotion')
                    ->formatStateUsing(fn ($state): string => $state.'e promotion'),
                Infolists\Components\TextEntry::make('candidature.specialite')->label('Spécialité')->placeholder('—'),
                Infolists\Components\TextEntry::make('candidature.withdrawn_at')->label('Dossier')
                    ->getStateUsing(fn (Auditeur $record): string => $record->candidature?->withdrawn_at === null ? 'Actif' : 'Retiré le '.$record->candidature->withdrawn_at->timezone('Africa/Douala')->format('d/m/Y'))
                    ->badge()->color(fn (Auditeur $record): string => $record->candidature?->withdrawn_at === null ? 'success' : 'gray'),
            ]),
            Infolists\Components\Section::make('Statut')->columns(3)
                ->visible(fn (Auditeur $record): bool => $record->statut !== Auditeur::STATUT_ACTIF || $record->statut_depuis !== null)
                ->schema([
                    Infolists\Components\TextEntry::make('statut')->label('Statut')->badge()
                        ->formatStateUsing(fn (string $state): string => Auditeur::STATUTS[$state] ?? $state)
                        ->color(fn (string $state): string => self::couleurStatut($state)),
                    Infolists\Components\TextEntry::make('statut_depuis')->label('Depuis le')->date('d/m/Y')->placeholder('—'),
                    Infolists\Components\TextEntry::make('statut_motif')->label('Motif')->placeholder('—'),
                ]),
            Infolists\Components\Section::make('Situation financière')
                ->description(fn (Auditeur $record): ?string => $record->inscriptionCourante === null ? null
                    : $record->inscriptionCourante->annee_academique.' · '.(BaremeScolarite::NIVEAUX[$record->inscriptionCourante->niveau] ?? $record->inscriptionCourante->niveau))
                ->schema([
                    Infolists\Components\ViewEntry::make('situation')->hiddenLabel()->view('filament.scolarite.situation'),
                ]),
            Infolists\Components\Section::make('Historique')->collapsible()->collapsed()
                ->description('Les 100 dernières entrées du journal : fiche, inscriptions, versements et dossier.')
                ->schema([
                    Infolists\Components\ViewEntry::make('historique')->hiddenLabel()->view('filament.scolarite.historique'),
                ]),
            Infolists\Components\Section::make('Accès à eCampus')->columns(2)->schema([
                Infolists\Components\TextEntry::make('acces')->label('État')->badge()
                    ->getStateUsing(fn (Auditeur $record): string => self::libelleAcces($record))
                    ->color(fn (Auditeur $record): string => match ($record->candidature === null ? null : AccesEcampus::etat($record->candidature)) {
                        AccesEcampus::DISPONIBLE => 'success',
                        AccesEcampus::EN_PREPARATION => 'warning',
                        AccesEcampus::BLOQUE => 'danger',
                        default => 'gray',
                    }),
                Infolists\Components\TextEntry::make('inscriptionCourante.acces_notifie_at')->label('SMS « accès disponibles »')
                    ->getStateUsing(fn (Auditeur $record): string => $record->inscriptionCourante?->acces_notifie_at === null ? 'Pas encore envoyé'
                        : 'Envoyé le '.$record->inscriptionCourante->acces_notifie_at->timezone('Africa/Douala')->format('d/m/Y à H:i')),
            ]),
        ]);
    }

    public static function couleurStatut(string $statut): string
    {
        return match ($statut) {
            Auditeur::STATUT_ACTIF => 'success',
            Auditeur::STATUT_DIPLOME => 'info',
            Auditeur::STATUT_SUSPENDU => 'warning',
            default => 'danger',
        };
    }

    public static function libelleAcces(Auditeur $record): string
    {
        return match ($record->candidature === null ? null : AccesEcampus::etat($record->candidature)) {
            AccesEcampus::DISPONIBLE => 'Disponible dans l\'espace de l\'auditeur',
            AccesEcampus::EN_PREPARATION => 'Inscription soldée, compte eCampus à créer : prévenez l\'USI',
            AccesEcampus::BLOQUE => 'Bloqué : inscription non soldée',
            default => 'Dossier retiré',
        };
    }

    public static function getRelations(): array
    {
        return [VersementsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAuditeurs::route('/'),
            'view' => Pages\ViewAuditeur::route('/{record}'),
        ];
    }

    /** Matricule, nom, prénom ou téléphone (chiffres seuls). */
    private static function rechercher(Builder $query, string $search): Builder
    {
        $terme = '%'.addcslashes(trim($search), '%_\\').'%';
        $chiffres = (string) preg_replace('/\D+/', '', $search);

        return $query->whereHas('candidature', fn (Builder $c) => $c->where(function (Builder $q) use ($terme, $chiffres): void {
            $q->where('matricule', 'ilike', $terme)
                ->orWhere('nom', 'ilike', $terme)
                ->orWhere('prenom', 'ilike', $terme);
            if (strlen($chiffres) >= 4) {
                $q->orWhere('phone_e164', 'like', '%'.$chiffres.'%');
            }
        }));
    }
}
