<?php

declare(strict_types=1);

namespace App\Filament\Resources\PlanningExamensResource\RelationManagers;

use App\Filament\Concerns\ChecksModuleAccess;
use App\Filament\Resources\AuditeurResource\MessagesValidation;
use App\Filament\Resources\PlanningExamensResource;
use App\Models\EpreuveExamen;
use App\Models\PlageHoraire;
use App\Models\PlanningExamens;
use App\Models\Salle;
use App\Services\Lmd\DocumentsExamen;
use App\Services\Lmd\PermissionsLmd;
use App\Services\Lmd\PlanningsExamens;
use App\Support\BelongsToModule;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Épreuves d'un planning d'examens (lot 27) : ajout, modification ou report, suppression, émargement. */
#[BelongsToModule('lmd')]
class EpreuvesRelationManager extends RelationManager
{
    use ChecksModuleAccess;

    protected static string $relationship = 'epreuves';

    protected static ?string $title = 'Épreuves';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return static::moduleAllows() && (bool) auth()->user()?->can('view', $ownerRecord);
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    private function planning(): PlanningExamens
    {
        return $this->getOwnerRecord()->refresh();
    }

    private function peut(): bool
    {
        return (bool) auth()->user()?->can(PermissionsLmd::EMPLOIS_DU_TEMPS);
    }

    /** @return list<Forms\Components\Component> */
    private function champs(): array
    {
        $p = $this->planning();
        $service = app(PlanningsExamens::class);

        return MessagesValidation::appliquer([
            Forms\Components\Grid::make(3)->schema([
                Forms\Components\Select::make('code_ecue')->label('ECUE')->required()->native(false)->searchable()->columnSpan(3)
                    ->helperText('Un ECUE partagé par plusieurs options donne une seule épreuve commune.')
                    ->options(fn (): array => $service->codes($p)),
                Forms\Components\DatePicker::make('date_epreuve')->label('Date')->required()->native(false)->displayFormat('l d/m/Y')
                    ->minDate($p->session->debut_prevu)->maxDate($p->session->fin_prevue),
                Forms\Components\Select::make('plage_horaire_id')->label('Plage horaire')->native(false)->placeholder('Heures libres')->live()->columnSpan(2)
                    ->options(fn (): array => PlageHoraire::query()->orderBy('ordre')->get()->mapWithKeys(fn (PlageHoraire $x): array => [$x->id => $x->libelle()])->all()),
                Forms\Components\TimePicker::make('debut')->label('Début')->seconds(false)->required(fn (Forms\Get $get): bool => blank($get('plage_horaire_id')))
                    ->visible(fn (Forms\Get $get): bool => blank($get('plage_horaire_id'))),
                Forms\Components\TimePicker::make('fin')->label('Fin')->seconds(false)->after('debut')->required(fn (Forms\Get $get): bool => blank($get('plage_horaire_id')))
                    ->visible(fn (Forms\Get $get): bool => blank($get('plage_horaire_id'))),
            ]),
            Forms\Components\Repeater::make('salles')->label('Salles, dans l\'ordre de remplissage')->addActionLabel('Ajouter une salle')->columns(2)->defaultItems(1)->reorderable()
                ->helperText('Les candidats sont répartis par ordre alphabétique : la première salle est remplie jusqu\'à sa capacité, puis la suivante.')
                ->schema([
                    Forms\Components\Select::make('salle_id')->label('Salle')->required()->native(false)->searchable()
                        ->options(fn (): array => Salle::query()->where('active', true)->orderBy('code')->get()->mapWithKeys(fn (Salle $s): array => [$s->id => $s->libelleComplet()])->all()),
                    Forms\Components\Select::make('surveillants')->label('Surveillants')->multiple()->native(false)->searchable()
                        ->options(fn (): array => $service->surveillantsPossibles()->pluck('name', 'id')->all()),
                ]),
        ], ['after' => 'La fin doit suivre le début.']);
    }

    /** @return array<string, mixed> */
    private function valeurs(EpreuveExamen $e): array
    {
        $e->load(['salles', 'surveillants']);

        return [
            'code_ecue' => $e->code_ecue, 'date_epreuve' => $e->date_epreuve->toDateString(), 'debut' => substr((string) $e->debut, 0, 5), 'fin' => substr((string) $e->fin, 0, 5),
            'salles' => $e->salles->map(fn (Salle $s): array => ['salle_id' => $s->id,
                'surveillants' => $e->surveillants->filter(fn ($u): bool => (int) $u->pivot->salle_id === $s->id)->modelKeys()])->all(),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['salles', 'surveillants']))
            ->columns([
                Tables\Columns\TextColumn::make('date_epreuve')->label('Date')
                    ->formatStateUsing(fn (EpreuveExamen $e): string => ucfirst($e->date_epreuve->locale('fr')->translatedFormat('l j F Y')))
                    ->description(fn (EpreuveExamen $e): string => $e->heures()),
                Tables\Columns\TextColumn::make('code_ecue')->label('Épreuve')->description(fn (EpreuveExamen $e): string => (string) $e->intitule)->wrap(),
                Tables\Columns\TextColumn::make('lieux')->label('Salles')->placeholder('—')
                    ->getStateUsing(fn (EpreuveExamen $e): string => $e->salles->pluck('code')->implode(', ')),
                Tables\Columns\TextColumn::make('surveillance')->label('Surveillants')->placeholder('—')->wrap()
                    ->getStateUsing(fn (EpreuveExamen $e): string => $e->surveillants->pluck('name')->implode(', ')),
            ])
            ->headerActions([
                Tables\Actions\Action::make('ajouter')->label('Ajouter une épreuve')->icon('heroicon-o-plus')
                    ->visible(fn (): bool => $this->peut())
                    ->form(fn (): array => $this->champs())
                    ->action(fn (array $data) => $this->executer(function () use ($data): string {
                        app(PlanningsExamens::class)->enregistrer($this->planning(), $data, auth()->user());

                        return 'Épreuve ajoutée.';
                    })),
            ])
            ->actions([Tables\Actions\ActionGroup::make([
                Tables\Actions\Action::make('modifier')->label('Modifier ou reporter')->icon('heroicon-o-pencil')
                    ->visible(fn (): bool => $this->peut())
                    ->fillForm(fn (EpreuveExamen $e): array => $this->valeurs($e))
                    ->form(fn (): array => $this->champs())
                    ->action(fn (EpreuveExamen $e, array $data) => $this->executer(function () use ($e, $data): string {
                        app(PlanningsExamens::class)->enregistrer($this->planning(), $data, auth()->user(), $e);

                        return 'Épreuve modifiée.';
                    })),
                Tables\Actions\Action::make('emargement')->label('Listes d\'émargement (PDF)')->icon('heroicon-o-list-bullet')
                    ->visible(fn (): bool => $this->peut())
                    ->action(function (EpreuveExamen $e): StreamedResponse {
                        $pdf = app(DocumentsExamen::class)->emargements($this->planning(), collect([$e]));

                        return response()->streamDownload(fn () => print ($pdf), 'emargement-'.mb_strtolower($e->code_ecue).'.pdf', ['Content-Type' => 'application/pdf']);
                    }),
                Tables\Actions\Action::make('supprimer')->label('Supprimer')->icon('heroicon-o-trash')->color('danger')->requiresConfirmation()
                    ->visible(fn (): bool => ! $this->planning()->estPublie() && $this->peut())
                    ->action(fn (EpreuveExamen $e) => $this->executer(function () use ($e): string {
                        app(PlanningsExamens::class)->supprimer($e, auth()->user());

                        return 'Épreuve supprimée.';
                    })),
            ])->label('Actions')->icon('heroicon-o-ellipsis-vertical')]);
    }

    private function executer(callable $operation): void
    {
        try {
            $message = $operation();
        } catch (InvalidArgumentException $e) {
            Notification::make()->danger()->title('Opération refusée')->body($e->getMessage())->persistent()->send();

            return;
        }
        Notification::make()->success()->title($message)->send();
        // La section des contrôles est sur la page : on la recharge.
        $this->redirect(PlanningExamensResource::getUrl('view', ['record' => $this->planning()]));
    }
}
