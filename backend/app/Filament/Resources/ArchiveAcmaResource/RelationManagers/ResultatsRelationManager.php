<?php

declare(strict_types=1);

namespace App\Filament\Resources\ArchiveAcmaResource\RelationManagers;

use App\Filament\Concerns\ChecksModuleAccess;
use App\Filament\Resources\ArchiveAcmaResource\ArchiveAcmaActions;
use App\Models\AcmaComplement;
use App\Models\AcmaDossier;
use App\Models\AcmaNoteNonRattachee;
use App\Models\AcmaResultatUe;
use App\Models\AcmaVersionNote;
use App\Services\Acma\ArchivesAcma;
use App\Support\BelongsToModule;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

/**
 * Notes par UE d'ACMA (lot 15, §7.5) : valeurs d'ACMA, session retenue, moyenne recalculée selon ACMA. Une note en
 * conflit (fiches fusionnées) n'entre dans aucun relevé tant qu'une note n'est pas retenue par complément. Les lignes
 * disparues d'un export plus récent ne sont jamais montrées comme vivantes.
 */
#[BelongsToModule('archives')]
class ResultatsRelationManager extends RelationManager
{
    use ChecksModuleAccess;

    protected static string $relationship = 'resultats';

    protected static ?string $title = 'Notes par UE';

    /** @var list<int>|null résultats retenus du dossier, lus une fois */
    private ?array $retenues = null;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = auth()->user();

        return static::moduleAllows() && $ownerRecord instanceof AcmaDossier
            && (bool) $user?->can('view', $ownerRecord) && (bool) $user?->can('viewAny', AcmaResultatUe::class);
    }

    public function mount(): void
    {
        abort_unless(static::canViewForRecord($this->getOwnerRecord(), $this->getPageClass()), 403);

        parent::mount();
    }

    /** La note retenue d'un conflit se choisit depuis la fiche. */
    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        $note = fn (string $colonne) => Tables\Columns\TextColumn::make($colonne)->alignEnd()->placeholder('—')
            ->formatStateUsing(fn ($state): string => ArchivesAcma::note((float) $state));

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->whereNull('acma_resultats_ue.retire_de_la_source_at')->with('unite')->orderBy('acma_resultats_ue.id'))
            ->description(fn (): ?string => ($n = AcmaNoteNonRattachee::query()->where('dossier_id', $this->getOwnerRecord()->getKey())->whereNull('retire_de_la_source_at')->count()) > 0
                ? $n.' note(s) non rattachée(s) à une inscription (programme supprimé dans ACMA, ou année sans inscription) : gardées, hors crédits et hors relevés.' : null)
            ->columns([
                Tables\Columns\TextColumn::make('unite.annee')->label('Année')->sortable(),
                Tables\Columns\TextColumn::make('unite.niveau_acma')->label('Niveau'),
                Tables\Columns\TextColumn::make('unite')->label('UE ACMA')->wrap()
                    ->getStateUsing(fn (AcmaResultatUe $record): string => $record->unite?->libelle() ?? '—'),
                Tables\Columns\TextColumn::make('credits')->label('Crédits')->alignEnd(),
                $note('cc')->label('CC'),
                $note('tp')->label('TP'),
                $note('examen')->label('Examen'),
                $note('rattrapage')->label('Rattrapage'),
                Tables\Columns\TextColumn::make('session')->label('Session')
                    ->formatStateUsing(fn (string $state): string => $state === 'rattrapage' ? 'Rattrapage' : 'Normale'),
                $note('moyenne')->label('Moyenne (recalcul)')->weight('bold'),
                Tables\Columns\IconColumn::make('acquise')->label('Acquise')->boolean(),
                Tables\Columns\TextColumn::make('etat')->label('État')->badge()
                    ->getStateUsing(fn (AcmaResultatUe $record): string => self::etat($record, $this->retenues()))
                    ->color(fn (AcmaResultatUe $record): string => match (self::etat($record, $this->retenues())) {
                        'En conflit' => 'danger', 'Retenue' => 'success', 'Reconstituée', 'Hors bornes' => 'warning', default => 'gray',
                    }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('annee')->label('Année')
                    ->options(fn (): array => $this->getOwnerRecord()->inscriptions()->whereNull('retire_de_la_source_at')->distinct()->orderBy('annee')->pluck('annee', 'annee')->all())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereHas('unite', fn (Builder $u) => $u->where('annee', $data['value'])) : $query),
                Tables\Filters\Filter::make('conflit')->label('En conflit')->toggle()->query(fn (Builder $query): Builder => $query->where('en_conflit', true)),
            ])
            ->actions([
                Action::make('versions')->label('Historique ACMA')->icon('heroicon-o-clock')->color('gray')
                    ->visible(fn (AcmaResultatUe $record): bool => $record->id_note_acma !== null)
                    ->modalHeading('Historique ACMA de cette note')
                    ->modalSubmitAction(false)->modalCancelActionLabel('Fermer')
                    ->modalContent(fn (AcmaResultatUe $record): HtmlString => self::versions($record)),
                Action::make('retenir')->label('Retenir cette note')->icon('heroicon-o-check-circle')
                    ->visible(fn (AcmaResultatUe $record): bool => $record->en_conflit && self::etat($record, $this->retenues()) === 'En conflit'
                        && (bool) auth()->user()?->can(ArchivesAcma::COMPLETER))
                    ->modalDescription('Deux fiches fusionnées notent la même UE. La note retenue est la seule qui comptera dans un relevé ; une seule note se retient par UE.')
                    ->form([
                        Forms\Components\TextInput::make('reference')->label('Pièce (PV, relevé papier)')->required()->maxLength(100),
                        Forms\Components\Textarea::make('motif')->label('Motif')->required()->minLength(ArchivesAcma::MOTIF_MINIMUM)->maxLength(500)->rows(2),
                    ])
                    ->action(function (AcmaResultatUe $record, array $data, Action $action): void {
                        ArchiveAcmaActions::executer($action, fn () => app(ArchivesAcma::class)->retenirNote($record, (string) $data['reference'], (string) $data['motif'], auth()->user()),
                            'Note retenue.');
                        $this->retenues = null;
                    }),
            ])
            ->bulkActions([])
            ->paginated([25, 50, 100]);
    }

    /** @return list<int> */
    private function retenues(): array
    {
        return $this->retenues ??= AcmaComplement::query()->where('dossier_id', $this->getOwnerRecord()->getKey())->where('champ', 'note_retenue')
            ->pluck('resultat_id')->map(fn ($id): int => (int) $id)->all();
    }

    /**
     * @param  list<int>  $retenues  résultats retenus du dossier
     */
    public static function etat(AcmaResultatUe $r, array $retenues): string
    {
        if ($r->en_conflit) {
            if (in_array((int) $r->id, $retenues, true)) {
                return 'Retenue';
            }

            // Une autre note du même conflit est retenue : celle-ci est écartée.
            return AcmaResultatUe::query()->whereIn('id', $retenues)->where('inscription_id', $r->inscription_id)->where('unite_id', $r->unite_id)->exists()
                ? 'Écartée' : 'En conflit';
        }

        return match (true) {
            $r->hors_bornes => 'Hors bornes',
            $r->origine === 'historique' => 'Reconstituée',
            default => 'ACMA',
        };
    }

    private static function versions(AcmaResultatUe $r): HtmlString
    {
        $lignes = AcmaVersionNote::query()->where('id_note_acma', $r->id_note_acma)->whereNull('retire_de_la_source_at')->orderBy('horodatage_source')->get()
            ->map(fn (AcmaVersionNote $v): string => '<tr><td>'.e($v->horodatage?->format('d/m/Y H:i') ?? $v->horodatage_source).'</td><td>'.e($v->code_ue ?? '')
                .'</td><td>'.e(self::brut($v->cc)).'</td><td>'.e(self::brut($v->tp)).'</td><td>'.e(self::brut($v->examen)).'</td><td>'.e(self::brut($v->rattrapage))
                .'</td></tr>')->implode('');

        return new HtmlString($lignes === '' ? '<p>Aucune version dans l\'historique d\'ACMA.</p>'
            : '<table class="w-full text-sm"><thead><tr><th class="text-left">Date</th><th class="text-left">UE</th><th class="text-left">CC</th><th class="text-left">TP</th>'
            .'<th class="text-left">Examen</th><th class="text-left">Rattrapage</th></tr></thead><tbody>'.$lignes.'</tbody></table>');
    }

    private static function brut(mixed $v): string
    {
        return $v === null ? '—' : ArchivesAcma::note((float) $v);
    }
}
