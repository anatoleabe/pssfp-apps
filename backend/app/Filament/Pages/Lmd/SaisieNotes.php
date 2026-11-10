<?php

declare(strict_types=1);

namespace App\Filament\Pages\Lmd;

use App\Filament\Concerns\ChecksModuleAccess;
use App\Filament\Navigation\GroupeNavigation;
use App\Filament\Resources\AuditeurResource\MessagesValidation;
use App\Models\AttributionEcue;
use App\Models\EtapeSaisie;
use App\Models\Maquette;
use App\Models\MaquetteEcue;
use App\Models\NoteEcue;
use App\Models\User;
use App\Services\Lmd\Assiduite;
use App\Services\Lmd\NotesExcel;
use App\Services\Lmd\PermissionsLmd;
use App\Services\Lmd\SaisieNotes as ServiceSaisie;
use App\Support\BelongsToModule;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Saisie des notes d'un ECUE, par session et par étape (spec module 4, lot 5).
 * Tout passe par App\Services\Lmd\SaisieNotes : étape ouverte, motif pour une
 * correction, historique. L'ECUE choisi est revérifié à chaque action.
 */
#[BelongsToModule('lmd')]
class SaisieNotes extends Page implements HasForms
{
    use ChecksModuleAccess;
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-pencil-square';

    protected static ?string $navigationGroup = GroupeNavigation::EVALUATIONS;

    protected static ?int $navigationSort = 10;

    protected static ?string $title = 'Saisie des notes';

    protected static ?string $slug = 'lmd/saisie-notes';

    protected static string $view = 'filament.lmd.saisie-notes';

    /** @var array<string, mixed> */
    public array $filtres = [];

    /** @var array<int|string, array{note?: string|null, presence?: string|null, motif?: string|null}> */
    public array $saisies = [];

    public string $motifCommun = '';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return static::moduleAllows() && $user !== null
            && ($user->can(PermissionsLmd::NOTES_SAISIR) || $user->can(PermissionsLmd::NOTES_VERROUILLER) || $user->can(PermissionsLmd::NOTES_ENSEIGNANT));
    }

    public function mount(): void
    {
        $this->form->fill(['session' => EtapeSaisie::NORMALE, 'etape' => EtapeSaisie::CC]);
    }

    public function form(Form $form): Form
    {
        return $form->statePath('filtres')->columns(4)->schema([
            Forms\Components\Select::make('maquette_id')->label('Maquette')->native(false)->live()
                ->options(fn (): array => $this->maquettesAccessibles())
                ->afterStateUpdated(function (Forms\Set $set): void {
                    $set('ecue_id', null);
                    $this->charger();
                }),
            Forms\Components\Select::make('ecue_id')->label('ECUE')->native(false)->live()->searchable()
                ->options(fn (Forms\Get $get): array => $this->ecuesAccessibles((int) $get('maquette_id')))
                ->afterStateUpdated(fn () => $this->charger()),
            Forms\Components\Select::make('session')->label('Session')->native(false)->live()->required()
                ->options(fn (): array => $this->enseignantSeul() ? [EtapeSaisie::NORMALE => EtapeSaisie::SESSIONS[EtapeSaisie::NORMALE]] : EtapeSaisie::SESSIONS)
                ->afterStateUpdated(function (Forms\Set $set, ?string $state): void {
                    if ($state === EtapeSaisie::RATTRAPAGE) {
                        $set('etape', EtapeSaisie::EXAMEN);
                    }
                    $this->charger();
                }),
            Forms\Components\Select::make('etape')->label('Étape')->native(false)->live()->required()
                ->options(fn (Forms\Get $get): array => collect(EtapeSaisie::etapesDe((string) $get('session')))
                    ->reject(fn (string $e): bool => $this->enseignantSeul() && $e === EtapeSaisie::EXAMEN)
                    ->mapWithKeys(fn (string $e): array => [$e => EtapeSaisie::ETAPES[$e]])->all())
                ->afterStateUpdated(fn () => $this->charger()),
        ]);
    }

    /** Étape choisie, ou null si la sélection est incomplète ou hors des droits de l'utilisateur. */
    public function etapeCourante(): ?EtapeSaisie
    {
        $ecue = $this->ecueCourant();
        $session = (string) ($this->filtres['session'] ?? '');
        $etape = (string) ($this->filtres['etape'] ?? '');
        if ($ecue === null || ! in_array($etape, EtapeSaisie::etapesDe($session), true)) {
            return null;
        }

        return app(ServiceSaisie::class)->etape($ecue, $session, $etape);
    }

    public function ecueCourant(): ?MaquetteEcue
    {
        $id = (int) ($this->filtres['ecue_id'] ?? 0);
        $maquetteId = (int) ($this->filtres['maquette_id'] ?? 0);
        if ($id === 0 || ! array_key_exists($id, $this->ecuesAccessibles($maquetteId))) {
            return null;
        }

        return MaquetteEcue::query()->with('maquetteUe.maquette', 'maquetteUe.ue')->find($id);
    }

    /** @return Collection<int, array{id: int, matricule: string, nom: string, actuelle: ?string, presence: ?string}> */
    public function lignes(): Collection
    {
        $etape = $this->etapeCourante();
        if ($etape === null) {
            return collect();
        }
        $notes = NoteEcue::query()->where('maquette_ecue_id', $etape->maquette_ecue_id)->where('session', $etape->session)->get()->keyBy('inscription_pedagogique_id');

        return app(ServiceSaisie::class)->inscrits($etape->ecue)->map(function ($i) use ($notes, $etape): array {
            $dossier = $i->inscriptionAnnuelle?->auditeur?->candidature;
            $note = $notes->get($i->id);
            $valeur = $note?->getAttribute($etape->etape);

            return [
                'id' => $i->id,
                'matricule' => (string) $dossier?->matricule,
                'nom' => trim(mb_strtoupper((string) $dossier?->nom).' '.$dossier?->prenom),
                'actuelle' => $valeur === null ? null : rtrim(rtrim(number_format((float) $valeur, 2, ',', ''), '0'), ','),
                'presence' => $note?->presence_examen,
            ];
        });
    }

    public function charger(): void
    {
        $this->saisies = $this->lignes()->mapWithKeys(fn (array $l): array => [$l['id'] => [
            'note' => $l['actuelle'],
            'presence' => in_array($l['presence'], [NoteEcue::ABSENT_JUSTIFIE, NoteEcue::ABSENT_NON_JUSTIFIE], true) ? $l['presence'] : null,
            'motif' => null,
        ]])->all();
        $this->motifCommun = '';
    }

    public function enregistrer(): void
    {
        $etape = $this->etapeCourante();
        if ($etape === null) {
            return;
        }
        $lignes = collect($this->saisies)->map(fn (array $s): array => [
            'note' => $s['note'] ?? null,
            'presence' => $s['presence'] ?? null,
            'motif' => filled($s['motif'] ?? null) ? $s['motif'] : $this->motifCommun,
        ])->all();
        try {
            $n = app(ServiceSaisie::class)->saisir($etape, $lignes, $this->utilisateur());
        } catch (InvalidArgumentException $e) {
            Notification::make()->danger()->title('Notes non enregistrées')->body($e->getMessage())->persistent()->send();

            return;
        }
        Notification::make()->success()->title($n === 0 ? 'Aucune modification' : "{$n} note(s) enregistrée(s)")->send();
        $this->charger();
    }

    protected function getHeaderActions(): array
    {
        $statut = fn (): ?string => $this->etapeCourante()?->statut;

        return [
            Action::make('ouvrir')->label('Ouvrir l\'étape')->icon('heroicon-o-lock-open')->color('success')
                ->visible(fn (): bool => $statut() === EtapeSaisie::FERMEE && $this->utilisateur()->can(PermissionsLmd::NOTES_VERROUILLER))
                ->requiresConfirmation()
                ->action(fn () => $this->executer(fn (EtapeSaisie $e) => app(ServiceSaisie::class)->ouvrir($e, $this->utilisateur()), 'Étape ouverte')),
            Action::make('verrouiller')->label('Verrouiller')->icon('heroicon-o-lock-closed')->color('warning')
                ->visible(fn (): bool => $statut() === EtapeSaisie::OUVERTE && $this->utilisateur()->can(PermissionsLmd::NOTES_VERROUILLER))
                ->requiresConfirmation()
                ->modalDescription('Verrouillée, l\'étape refuse toute modification. Elle ne se rouvre qu\'avec un motif, par le responsable de la scolarité.')
                ->action(fn () => $this->executer(fn (EtapeSaisie $e) => app(ServiceSaisie::class)->verrouiller($e, $this->utilisateur()), 'Étape verrouillée')),
            Action::make('rouvrir')->label('Rouvrir')->icon('heroicon-o-arrow-path')->color('gray')
                ->visible(fn (): bool => $statut() === EtapeSaisie::VERROUILLEE && $this->utilisateur()->can(PermissionsLmd::NOTES_ROUVRIR))
                ->form(MessagesValidation::appliquer([Forms\Components\Textarea::make('motif')->label('Motif')->required()->minLength(10)->maxLength(500)]))
                ->action(fn (array $data) => $this->executer(fn (EtapeSaisie $e) => app(ServiceSaisie::class)->rouvrir($e, $this->utilisateur(), (string) $data['motif']), 'Étape rouverte')),
            Action::make('reporterAssiduite')->label('Reporter l\'assiduité calculée')->icon('heroicon-o-calendar-days')->color('gray')
                ->visible(fn (): bool => $statut() === EtapeSaisie::OUVERTE && $this->etapeCourante()?->etape === EtapeSaisie::ASSIDUITE
                    && $this->utilisateur()->can(PermissionsLmd::ASSIDUITE))
                ->requiresConfirmation()
                ->modalDescription('La note d\'assiduité de chaque auditeur est calculée sur les séances tenues de l\'ECUE (D12) et écrite ici, avec historique.')
                ->action(function (): void {
                    $etape = $this->etapeCourante();
                    if ($etape === null) {
                        return;
                    }
                    $this->executer(fn () => app(Assiduite::class)->reporter($etape->ecue, $this->utilisateur()), 'Assiduité reportée');
                    $this->charger();
                }),
            Action::make('modele')->label('Modèle Excel')->icon('heroicon-o-arrow-down-tray')->color('gray')
                ->visible(fn (): bool => $this->etapeCourante() !== null)
                ->action(function (): ?StreamedResponse {
                    $etape = $this->etapeCourante();
                    if ($etape === null) {
                        return null;
                    }
                    $contenu = app(NotesExcel::class)->modele($etape);

                    return response()->streamDownload(fn () => print ($contenu),
                        sprintf('notes-%s-%s-%s.xlsx', mb_strtolower($etape->ecue->code), $etape->session, $etape->etape));
                }),
            Action::make('importer')->label('Importer')->icon('heroicon-o-arrow-up-tray')->color('gray')
                ->visible(fn (): bool => $statut() === EtapeSaisie::OUVERTE)
                ->modalDescription('Déposez le modèle rempli. Rien n\'est enregistré si une ligne est invalide.')
                ->form(MessagesValidation::appliquer([
                    Forms\Components\FileUpload::make('fichier')->label('Modèle rempli (.xlsx)')->required()->storeFiles(false)
                        ->acceptedFileTypes(['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->maxSize(2048),
                    Forms\Components\Toggle::make('simulation')->label('Contrôler seulement, sans enregistrer')->default(true),
                ], ['mimetypes' => 'Format accepté : le modèle Excel (.xlsx).']))
                ->action(function (array $data): void {
                    $etape = $this->etapeCourante();
                    if ($etape === null) {
                        return;
                    }
                    $fichier = $data['fichier'];
                    $chemin = is_object($fichier) && method_exists($fichier, 'getRealPath') ? (string) $fichier->getRealPath() : '';
                    try {
                        $n = app(NotesExcel::class)->importer($etape, $chemin, $this->utilisateur(), ! (bool) $data['simulation']);
                    } catch (InvalidArgumentException $e) {
                        Notification::make()->danger()->title('Import refusé')->body($e->getMessage())->persistent()->send();

                        return;
                    }
                    Notification::make()->success()
                        ->title($data['simulation'] ? "Fichier valide : {$n} note(s) à enregistrer" : "{$n} note(s) importée(s)")->send();
                    $this->charger();
                }),
        ];
    }

    private function executer(callable $operation, string $succes): void
    {
        $etape = $this->etapeCourante();
        if ($etape === null) {
            return;
        }
        try {
            $operation($etape);
        } catch (InvalidArgumentException $e) {
            Notification::make()->danger()->title('Opération refusée')->body($e->getMessage())->persistent()->send();

            return;
        }
        Notification::make()->success()->title($succes)->send();
    }

    private function utilisateur(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    /** L'utilisateur ne saisit que comme enseignant (ses ECUE, sans l'examen). */
    private function enseignantSeul(): bool
    {
        $u = $this->utilisateur();

        return ! $u->can(PermissionsLmd::NOTES_SAISIR) && ! $u->can(PermissionsLmd::NOTES_VERROUILLER);
    }

    /** @return array<int, string> */
    private function maquettesAccessibles(): array
    {
        $query = Maquette::query()->validees()->with('parcours')->orderByDesc('annee_academique');
        if ($this->enseignantSeul()) {
            $query->whereHas('ues.ecues.attributions', fn ($q) => $q->where('user_id', $this->utilisateur()->id));
        }

        return $query->get()->mapWithKeys(fn (Maquette $m): array => [$m->id => $m->libelle()])->all();
    }

    /** @return array<int, string> */
    private function ecuesAccessibles(int $maquetteId): array
    {
        if ($maquetteId === 0 || ! array_key_exists($maquetteId, $this->maquettesAccessibles())) {
            return [];
        }
        $query = MaquetteEcue::query()->whereHas('maquetteUe', fn ($q) => $q->where('maquette_id', $maquetteId))
            ->with('maquetteUe')->orderBy('code');
        if ($this->enseignantSeul()) {
            $query->whereIn('id', AttributionEcue::query()->where('user_id', $this->utilisateur()->id)->select('maquette_ecue_id'));
        }

        return $query->get()->mapWithKeys(fn (MaquetteEcue $e): array => [$e->id => $e->libelle()])->all();
    }
}
