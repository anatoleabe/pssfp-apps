<?php

declare(strict_types=1);

namespace App\Filament\Resources\SyntheseAnimationResource\Pages;

use App\Filament\Resources\AuditeurResource\MessagesValidation;
use App\Filament\Resources\QuotaHoraireResource\ChampsProgrammation;
use App\Filament\Resources\SyntheseAnimationResource;
use App\Models\SyntheseAnimation;
use App\Models\User;
use App\Services\Lmd\PermissionsLmd;
use App\Services\Lmd\SynthesesAnimation;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Resources\Pages\ViewRecord;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/** Fiche d'une synthèse : modification, import de l'export WhatsApp, validation, rejet, réouverture. */
class ViewSyntheseAnimation extends ViewRecord
{
    private const RELATIONS = ['enseignant', 'ecue', 'activites', 'ligne.etat', 'validePar', 'importePar'];

    protected static string $resource = SyntheseAnimationResource::class;

    /** Empreinte de la version affichée : la validation porte sur ce que le valideur a vu. */
    public ?string $empreinte = null;

    public function mount(int|string $record): void
    {
        parent::mount($record);
        $this->empreinte = app(SynthesesAnimation::class)->empreinte($this->synthese());
    }

    protected function resolveRecord(int|string $key): SyntheseAnimation
    {
        return SyntheseAnimation::query()->where('uuid', $key)->with(self::RELATIONS)->firstOrFail();
    }

    public function getTitle(): string
    {
        $s = $this->synthese();

        return 'Prime d\'animation — '.$s->enseignant->name.', '.$s->ecue->code;
    }

    protected function getHeaderActions(): array
    {
        $service = fn (): SynthesesAnimation => app(SynthesesAnimation::class);
        $u = fn (): User => auth()->user();
        $modifiable = fn (): bool => (bool) $u()->can('update', $this->synthese());

        return [
            Action::make('modifier')->label('Modifier')->icon('heroicon-o-pencil')->visible($modifiable)
                ->fillForm(fn (): array => [
                    'periode_du' => $this->synthese()->periode_du->format('Y-m-d'), 'periode_au' => $this->synthese()->periode_au->format('Y-m-d'),
                    'groupe_whatsapp' => $this->synthese()->groupe_whatsapp,
                    'activites' => $this->synthese()->activites->map(fn ($a): array => $a->only(['type_activite', 'famille', 'attendu', 'realise']))->all(),
                ])
                ->form(SyntheseAnimationResource::champs())->modalWidth('5xl')->modalSubmitActionLabel('Enregistrer')
                ->action(fn (array $data) => $this->executer(function () use ($service, $u, $data): string {
                    $service()->modifier($this->synthese(), $data, $u());

                    return 'Synthèse enregistrée.';
                })),
            Action::make('importer')->label('Importer l\'export WhatsApp')->icon('heroicon-o-arrow-up-tray')->visible($modifiable)
                ->modalDescription('Dans WhatsApp, ouvrez le groupe du cours, puis « Exporter la discussion » sans médias. Seuls les messages de l\'enseignant pendant la période du cours sont comptés ; le fichier n\'est pas conservé, seule son empreinte l\'est.')
                ->form(MessagesValidation::appliquer([
                    Forms\Components\FileUpload::make('export')->label('Export de la discussion (.txt, ou .zip de l\'iPhone)')->required()->storeFiles(false)
                        ->acceptedFileTypes(['text/plain', 'application/zip', 'application/x-zip-compressed'])->maxSize(10240),
                    Forms\Components\TextInput::make('auteur')->label('Nom ou numéro de l\'enseignant dans le groupe')->required()->maxLength(120)
                        ->helperText('Tel qu\'il apparaît devant ses messages dans l\'export (par exemple « +237 6 99 00 00 00 »). Il n\'est pas enregistré.'),
                ], ['mimetypes' => 'Formats acceptés : texte (.txt) ou archive (.zip).', 'uploaded' => 'Le fichier n\'a pas pu être envoyé : 10 Mo au plus.',
                    'max' => 'Le fichier dépasse 10 Mo : exportez la discussion sans médias.']))
                ->modalSubmitActionLabel('Compter')
                ->action(function (array $data) use ($service, $u): void {
                    /** @var TemporaryUploadedFile $fichier */
                    $fichier = $data['export'];
                    try {
                        $this->executer(function () use ($service, $u, $data, $fichier): string {
                            $r = $service()->importer($this->synthese(), (string) $fichier->getRealPath(), $fichier->getClientOriginalName(), (string) $data['auteur'], $u());
                            $detail = collect($r['comptes'])->map(fn (int $n, string $type): string => $type.' : '.$n)->implode(' ; ');

                            return $r['lignes'].' messages lus. '.$detail.($r['absentes'] === [] ? '.' : '. Non reportés (absents de la synthèse) : '.implode(', ', $r['absentes']).'.');
                        });
                    } finally {
                        $fichier->delete();
                    }
                }),
            Action::make('valider')->label('Valider')->icon('heroicon-o-check-badge')->color('success')
                ->visible(fn (): bool => $service()->peutValider($this->synthese(), $u()))
                ->requiresConfirmation()
                ->modalDescription(fn (): string => 'Prime : '.SyntheseAnimationResource::primeEstimee($this->synthese()).'. Elle sera portée sur l\'état de paie du mois du '.$this->synthese()->periode_au->format('d/m/Y').'.')
                ->action(fn () => $this->executer(function () use ($service, $u): string {
                    $service()->valider($this->synthese(), $u(), $this->empreinte);

                    return 'Synthèse validée.';
                })),
            Action::make('rejeter')->label('Rejeter')->icon('heroicon-o-x-circle')->color('danger')
                ->visible(fn (): bool => $modifiable())
                ->form(MessagesValidation::appliquer([Forms\Components\Textarea::make('motif')->label('Motif du rejet')->required()->minLength(5)->maxLength(500)],
                    ['min' => 'Le motif compte au moins 5 caractères.']))
                ->action(fn (array $data) => $this->executer(function () use ($service, $u, $data): string {
                    $service()->rejeter($this->synthese(), (string) $data['motif'], $u());

                    return 'Synthèse rejetée.';
                })),
            Action::make('rouvrir')->label('Rouvrir')->icon('heroicon-o-arrow-uturn-left')->color('gray')
                ->visible(fn (): bool => $this->synthese()->statut !== SyntheseAnimation::BROUILLON && ! $this->synthese()->estPayee()
                    && $this->synthese()->enseignant_id !== $u()->id && $u()->can(PermissionsLmd::POINTAGE))
                ->modalDescription('La synthèse repasse en brouillon pour être corrigée, puis validée de nouveau.')
                ->form(MessagesValidation::appliquer([Forms\Components\Textarea::make('motif')->label('Motif')->required()->minLength(5)->maxLength(500)],
                    ['min' => 'Le motif compte au moins 5 caractères.']))
                ->action(fn (array $data) => $this->executer(function () use ($service, $u, $data): string {
                    $service()->rouvrir($this->synthese(), (string) $data['motif'], $u());

                    return 'Synthèse rouverte.';
                })),
        ];
    }

    private function synthese(): SyntheseAnimation
    {
        /** @var SyntheseAnimation $s */
        $s = $this->getRecord();

        return $s;
    }

    private function executer(callable $operation): void
    {
        ChampsProgrammation::executer($operation);
        $this->record = $this->synthese()->fresh(self::RELATIONS);
        $this->empreinte = app(SynthesesAnimation::class)->empreinte($this->synthese());
    }
}
