<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\RestrictedToModule;
use App\Filament\Navigation\GroupeNavigation;
use App\Filament\Resources\ArchiveAcmaResource\Pages;
use App\Filament\Resources\ArchiveAcmaResource\RelationManagers;
use App\Models\AcmaComplement;
use App\Models\AcmaDocument;
use App\Models\AcmaDossier;
use App\Models\AcmaInscription;
use App\Models\AcmaRapprochement;
use App\Models\AcmaSoutenance;
use App\Services\Acma\ArchivesAcma;
use App\Services\Scolarite\LibelleSituation;
use App\Support\BelongsToModule;
use Carbon\CarbonImmutable;
use Filament\Infolists;
use Filament\Infolists\Components\Component;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Scolarité > Archives ACMA (lot 15d, spec §7.4 à §7.9) : dossiers des anciens auditeurs repris d'ACMA, en lecture
 * seule, pour l'administration seule (décision 4 : rien dans l'espace de l'auditeur, aucun envoi). Les gestes —
 * compléments et arbitrage du rapprochement — sont sur la fiche (ArchiveAcmaActions) et revérifiés par ArchivesAcma.
 */
#[BelongsToModule('archives')]
class ArchiveAcmaResource extends Resource
{
    use RestrictedToModule;

    protected static ?string $model = AcmaDossier::class;

    protected static ?string $modelLabel = 'dossier d\'archive ACMA';

    protected static ?string $pluralModelLabel = 'Archives ACMA';

    protected static ?string $navigationLabel = 'Archives ACMA';

    protected static ?string $navigationIcon = 'heroicon-o-archive-box';

    protected static ?string $navigationGroup = GroupeNavigation::SCOLARITE;

    protected static ?int $navigationSort = 40;

    protected static ?string $slug = 'scolarite/archives-acma';

    protected static ?string $recordTitleAttribute = 'matricule';

    /** Pas de recherche globale : l'archive ne se consulte que depuis son écran. */
    protected static bool $isGloballySearchable = false;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['auditeur.candidature:id,matricule']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('matricule')
            ->columns([
                Tables\Columns\TextColumn::make('matricule')->label('Matricule')->fontFamily('mono')->searchable()->sortable()->copyable(),
                Tables\Columns\TextColumn::make('nom_complet')->label('Nom (ACMA)')->searchable()->sortable()->wrap(),
                Tables\Columns\TextColumn::make('naissance')->label('Naissance')
                    ->getStateUsing(fn (AcmaDossier $record): string => $record->date_naissance?->format('d/m/Y')
                        ?? ($record->date_naissance_precision === 'vers' ? 'vers '.$record->annee_naissance : 'à vérifier'))
                    ->color(fn (AcmaDossier $record): ?string => $record->date_naissance_a_verifier ? 'warning' : null),
                Tables\Columns\TextColumn::make('annees')->label('Années')
                    ->getStateUsing(fn (AcmaDossier $record): string => $record->premiere_annee === null ? '—'
                        : ($record->premiere_annee === $record->derniere_annee ? $record->premiere_annee : $record->premiere_annee.' → '.$record->derniere_annee)),
                Tables\Columns\TextColumn::make('statut_rapprochement')->label('Plateforme')->badge()
                    ->formatStateUsing(fn (string $state): string => AcmaDossier::STATUTS[$state] ?? $state)
                    ->color(fn (string $state): string => self::couleurStatut($state)),
                Tables\Columns\TextColumn::make('auditeur.candidature.matricule')->label('Auditeur lié')->fontFamily('mono')->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('statut_rapprochement')->label('Rapprochement')->options(AcmaDossier::STATUTS),
                Tables\Filters\Filter::make('lie')->label('Lié à un auditeur')->toggle()
                    ->query(fn (Builder $query): Builder => $query->whereIn('statut_rapprochement', ['lie_auto', 'lie_arbitre'])),
                Tables\Filters\Filter::make('a_arbitrer')->label('À arbitrer')->toggle()
                    ->query(fn (Builder $query): Builder => $query->where('statut_rapprochement', 'a_arbitrer')),
                Tables\Filters\Filter::make('decision_non_enregistree')->label('Décision non enregistrée')->toggle()
                    ->query(fn (Builder $query): Builder => $query->whereHas('inscriptions', fn (Builder $i) => $i->where('decision', 'non_enregistree')
                        ->whereDoesntHave('complements', fn (Builder $c) => $c->where('champ', 'decision')))),
                Tables\Filters\SelectFilter::make('annee')->label('Année')
                    ->options(fn (): array => AcmaInscription::query()->distinct()->orderByDesc('annee')->pluck('annee', 'annee')->all())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
? $query->whereHas('inscriptions', fn (Builder $i) => $i->where('annee', $data['value'])) : $query),
                Tables\Filters\SelectFilter::make('niveau')->label('Niveau')->options(['M1' => 'M1', 'M2' => 'M2'])
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
? $query->whereHas('inscriptions', fn (Builder $i) => $i->where('niveau', $data['value'])) : $query),
                // Par défaut, les dossiers disparus d'un export plus récent ne sont pas montrés comme vivants.
                Tables\Filters\TernaryFilter::make('retire')->label('Disparus de la source')->default(false)
                    ->trueLabel('Seulement les disparus')->falseLabel('Dossiers en vigueur')->placeholder('Tous')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('retire_de_la_source_at'),
                        false: fn (Builder $query): Builder => $query->whereNull('retire_de_la_source_at'),
                        blank: fn (Builder $query): Builder => $query,
                    ),
            ])
            ->actions([Tables\Actions\ViewAction::make()])
            ->bulkActions([]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Tabs::make('archive')->columnSpanFull()->tabs([
                Infolists\Components\Tabs\Tab::make('Identité')->schema(self::identite()),
                Infolists\Components\Tabs\Tab::make('Années et résultats')->schema([
                    Infolists\Components\RepeatableEntry::make('inscriptions')->hiddenLabel()->columns(4)
                        ->getStateUsing(fn (AcmaDossier $record) => self::inscriptionsVivantes($record))
                        ->schema([
                            Infolists\Components\TextEntry::make('annee')->label('Année')->weight('bold')
                                ->formatStateUsing(fn (AcmaInscription $record): string => $record->annee.' · '.$record->niveau.' ('.$record->niveau_acma.')'),
                            Infolists\Components\TextEntry::make('decision')->label('Décision')
                                ->getStateUsing(fn (AcmaInscription $record): string => ArchivesAcma::decision($record, $record->dossier->complements)['libelle'])
                                ->color(fn (AcmaInscription $record): ?string => match (ArchivesAcma::decision($record, $record->dossier->complements)['code']) {
                                    'admis' => 'success', 'non_admis' => 'danger', default => 'warning'
                                }),
                            Infolists\Components\TextEntry::make('moyenne')->label('Moyenne')
                                ->getStateUsing(fn (AcmaInscription $record): string => ArchivesAcma::moyenne($record, $record->dossier->complements)),
                            Infolists\Components\TextEntry::make('credits')->label('Crédits acquis (recalcul)')
                                ->getStateUsing(fn (AcmaInscription $record): string => $record->credits_acquis === null ? 'Aucune note'
                                    : $record->credits_acquis.' / '.($record->credits_programme ?? '?')),
                            Infolists\Components\TextEntry::make('moyenne_recalculee')->label('Moyenne pondérée')
                                ->getStateUsing(fn (AcmaInscription $record): string => $record->moyenne_recalculee === null ? '—'
                                    : ArchivesAcma::note((float) $record->moyenne_recalculee).' (indicatif : recalcul selon ACMA)'),
                            Infolists\Components\TextEntry::make('nb_rattrapages')->label('Rattrapages')->placeholder('—'),
                            Infolists\Components\TextEntry::make('signalements')->label('Signalements')->columnSpan(2)
                                ->getStateUsing(fn (AcmaInscription $record): string => implode(' ; ', array_merge(
                                    array_map(fn (string $s): string => AcmaInscription::SIGNALEMENTS[$s] ?? $s, $record->signalements ?? []),
                                    $record->deux_niveaux_meme_annee ? ['Deux niveaux la même année'] : [],
                                )) ?: '—'),
                        ]),
                ]),
                Infolists\Components\Tabs\Tab::make('Paiements')->schema([
                    Infolists\Components\TextEntry::make('avertissement_paiements')->hiddenLabel()
                        ->getStateUsing(fn (): string => 'Historique d\'ACMA, en lecture seule. L\'écart affiché est un « écart selon ACMA (non vérifié) » : ce n\'est pas un arriéré, et rien n\'entre au compte de l\'auditeur.'),
                    Infolists\Components\RepeatableEntry::make('paiements_par_annee')->hiddenLabel()->columns(6)
                        ->getStateUsing(fn (AcmaDossier $record): array => ArchivesAcma::paiementsParAnnee($record->loadMissing(['inscriptions', 'paiements'])))
                        ->schema([
                            Infolists\Components\TextEntry::make('annee')->label('Année')->weight('bold'),
                            Infolists\Components\TextEntry::make('du')->label('Dû (barème ACMA)')
                                ->formatStateUsing(fn ($state): string => LibelleSituation::fcfa((int) $state))->placeholder('—'),
                            Infolists\Components\TextEntry::make('verse')->label('Versé selon ACMA')
                                ->formatStateUsing(fn ($state): string => LibelleSituation::fcfa((int) $state)),
                            Infolists\Components\TextEntry::make('ecart')->label('Écart selon ACMA (non vérifié)')
                                ->formatStateUsing(fn ($state): string => LibelleSituation::fcfa((int) $state))->placeholder('—'),
                            Infolists\Components\TextEntry::make('lignes')->label('Lignes'),
                            Infolists\Components\TextEntry::make('signalees')->label('Lignes signalées'),
                        ]),
                ]),
                Infolists\Components\Tabs\Tab::make('Soutenances')->schema([
                    Infolists\Components\RepeatableEntry::make('soutenances')->hiddenLabel()->columns(3)
                        ->getStateUsing(fn (AcmaDossier $record) => $record->soutenances()->whereNull('retire_de_la_source_at')->orderBy('date')->get())
                        ->schema([
                            Infolists\Components\TextEntry::make('date')->label('Date')
                                ->getStateUsing(fn (AcmaSoutenance $record): string => $record->date?->format('d/m/Y') ?? ('non lue : '.($record->date_source ?? 'vide'))),
                            Infolists\Components\TextEntry::make('note')->label('Note')
                                ->formatStateUsing(fn ($state): string => ArchivesAcma::note((float) $state).' / 20')->placeholder('—'),
                            Infolists\Components\TextEntry::make('mention')->label('Mention')->placeholder('—'),
                            Infolists\Components\TextEntry::make('theme')->label('Thème')->columnSpan(3)->placeholder('—'),
                            Infolists\Components\TextEntry::make('jury')->label('Jury')->columnSpan(3)
                                ->getStateUsing(fn (AcmaSoutenance $record): string => implode(' ; ', array_filter([
                                    $record->president ? 'Président : '.$record->president : null,
                                    $record->rapporteur ? 'Rapporteur : '.$record->rapporteur : null,
                                    $record->membres ? 'Membres : '.implode(', ', $record->membres) : null,
                                ])) ?: '—'),
                        ])
                        ->placeholder('Aucun PV de soutenance dans ACMA.'),
                ]),
                Infolists\Components\Tabs\Tab::make('Documents ACMA')->schema([
                    Infolists\Components\RepeatableEntry::make('documents')->hiddenLabel()->columns(3)
                        ->getStateUsing(fn (AcmaDossier $record) => $record->documents()->whereNull('retire_de_la_source_at')->orderBy('type')->orderBy('id_acma')->get())
                        ->schema([
                            Infolists\Components\TextEntry::make('type')->label('Document')
                                ->formatStateUsing(fn (string $state): string => AcmaDocument::TYPES[$state] ?? $state),
                            Infolists\Components\TextEntry::make('date')->label('Date')
                                ->getStateUsing(fn (AcmaDocument $record): string => $record->date?->format('d/m/Y') ?? ($record->date_source ?? '—')),
                            Infolists\Components\TextEntry::make('details')->label('Détails')
                                ->getStateUsing(fn (AcmaDocument $record): string => collect($record->details ?? [])->map(fn ($v, $k): string => str_replace('_', ' ', (string) $k).' : '.$v)->implode(' ; ') ?: '—'),
                        ])
                        ->placeholder('Aucun document délivré par ACMA.'),
                ]),
                Infolists\Components\Tabs\Tab::make('Rapprochement')->schema([
                    Infolists\Components\TextEntry::make('statut_rapprochement')->label('État')->badge()
                        ->formatStateUsing(fn (string $state): string => AcmaDossier::STATUTS[$state] ?? $state)
                        ->color(fn (string $state): string => self::couleurStatut($state)),
                    Infolists\Components\TextEntry::make('historique_rapprochement')->label('Historique')->listWithLineBreaks()
                        ->getStateUsing(fn (AcmaDossier $record): array => $record->rapprochements()->with(['decidePar:id,name', 'auditeur.candidature:id,matricule'])->latest('id')->get()
                            ->map(fn (AcmaRapprochement $r): string => $r->created_at->timezone('Africa/Douala')->format('d/m/Y').' — '
                                .($r->cas !== null ? $r->cas.' — ' : '')
                                .($r->decision !== null ? (AcmaRapprochement::DECISIONS[$r->decision] ?? $r->decision) : 'proposition à arbitrer')
                                .($r->auditeur?->candidature?->matricule ? ' ('.$r->auditeur->candidature->matricule.')' : '')
                                .' — '.$r->motif.' — '.($r->decide_par === null ? 'reprise automatique' : 'par '.($r->decidePar?->name ?? 'un compte supprimé')))
                            ->all())
                        ->placeholder('Aucune proposition : aucun auditeur de la plateforme ne correspond.'),
                ]),
            ]),
        ]);
    }

    /** @return list<Component> */
    private static function identite(): array
    {
        return [
            Infolists\Components\Section::make('Identité selon ACMA')->columns(3)->schema([
                Infolists\Components\TextEntry::make('matricule')->label('Matricule')->fontFamily('mono')->copyable(),
                Infolists\Components\TextEntry::make('nom_complet')->label('Nom et prénoms')->weight('bold'),
                Infolists\Components\TextEntry::make('sexe')->label('Sexe')->placeholder('—'),
                Infolists\Components\TextEntry::make('naissance')->label('Date de naissance')
                    ->getStateUsing(fn (AcmaDossier $record): string => ArchivesAcma::dateNaissance($record)),
                Infolists\Components\TextEntry::make('lieu_naissance')->label('Lieu de naissance')->placeholder('—'),
                Infolists\Components\TextEntry::make('nationalite')->label('Nationalité')->placeholder('—'),
                Infolists\Components\TextEntry::make('categorie_acma')->label('Catégorie ACMA')->placeholder('—'),
                Infolists\Components\TextEntry::make('annees')->label('Années')
                    ->getStateUsing(fn (AcmaDossier $record): string => $record->premiere_annee === null ? 'Aucune inscription datée'
                        : $record->premiere_annee.' → '.$record->derniere_annee),
                Infolists\Components\TextEntry::make('auditeur_plateforme')->label('Auditeur de la plateforme')
                    ->getStateUsing(fn (AcmaDossier $record): string => $record->estLie() && $record->auditeur !== null
                        ? (string) $record->auditeur->candidature?->matricule : (AcmaDossier::STATUTS[$record->statut_rapprochement] ?? '—'))
                    ->url(fn (AcmaDossier $record): ?string => $record->estLie() && $record->auditeur !== null && AuditeurResource::canAccess()
                        ? AuditeurResource::getUrl('view', ['record' => $record->auditeur]) : null),
                Infolists\Components\TextEntry::make('fiches_fusionnees')->label('Fiches ACMA réunies')->columnSpan(3)
                    ->visible(fn (AcmaDossier $record): bool => $record->fiches_fusionnees !== null)
                    ->getStateUsing(fn (AcmaDossier $record): string => implode(' ; ', $record->fiches_fusionnees ?? [])),
                Infolists\Components\TextEntry::make('signalements')->label('Signalements')->columnSpan(3)
                    ->visible(fn (AcmaDossier $record): bool => ! empty($record->signalements) || $record->retire_de_la_source_at !== null)
                    ->getStateUsing(fn (AcmaDossier $record): string => implode(' ; ', array_merge(
                        array_map(fn (string $s): string => AcmaDossier::SIGNALEMENTS[$s] ?? $s, $record->signalements ?? []),
                        $record->retire_de_la_source_at !== null ? ['Disparu de l\'export ACMA du '.$record->retire_de_la_source_at->format('d/m/Y')] : [],
                    ))),
            ]),
            Infolists\Components\Section::make('Compléments')->collapsible()
                ->description('Ajouts de la scolarité d\'après une pièce : l\'archive ne se modifie jamais. Le dernier complément d\'un champ l\'emporte.')
                ->schema([
                    Infolists\Components\TextEntry::make('complements')->hiddenLabel()->listWithLineBreaks()
                        ->getStateUsing(fn (AcmaDossier $record): array => ArchivesAcma::complements($record)->loadMissing('inscription:id,annee,niveau_acma')->sortByDesc('id')
                            ->map(fn (AcmaComplement $c): string => $c->created_at->timezone('Africa/Douala')->format('d/m/Y').' — '
                                .(AcmaComplement::CHAMPS[$c->champ] ?? $c->champ)
                                .($c->inscription !== null ? ' '.$c->inscription->annee.' '.$c->inscription->niveau_acma : '')
                                .' : '.self::valeurComplement($c).' — pièce : '.$c->piece_reference.' — motif : '.$c->motif
                                .' — par '.($c->auteur?->name ?? 'un compte supprimé'))
                            ->all())
                        ->placeholder('Aucun complément.'),
                ]),
        ];
    }

    /**
     * Inscriptions en vigueur (jamais celles disparues de la source), avec les compléments du dossier lus une fois.
     *
     * @return Collection<int, AcmaInscription>
     */
    public static function inscriptionsVivantes(AcmaDossier $dossier): Collection
    {
        ArchivesAcma::complements($dossier);

        return $dossier->inscriptions()->whereNull('retire_de_la_source_at')->orderBy('annee')->orderBy('niveau_acma')->get()
            ->each(fn (AcmaInscription $i) => $i->setRelation('dossier', $dossier));
    }

    private static function valeurComplement(AcmaComplement $c): string
    {
        return match ($c->champ) {
            'date_naissance' => CarbonImmutable::parse($c->valeur)->format('d/m/Y'),
            'decision' => AcmaInscription::DECISIONS[$c->valeur] ?? $c->valeur,
            'moyenne' => $c->valeur === ArchivesAcma::SANS_MOYENNE ? 'non portée au PV' : ArchivesAcma::note((float) $c->valeur),
            default => 'note retenue',
        };
    }

    public static function couleurStatut(string $statut): string
    {
        return match ($statut) {
            'lie_auto', 'lie_arbitre' => 'success',
            'a_arbitrer' => 'warning',
            'ecarte' => 'danger',
            default => 'gray',
        };
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\ResultatsRelationManager::class,
            RelationManagers\PaiementsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListArchivesAcma::route('/'),
            'view' => Pages\ViewArchiveAcma::route('/{record}'),
        ];
    }
}
