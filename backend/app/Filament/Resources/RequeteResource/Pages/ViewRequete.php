<?php

declare(strict_types=1);

namespace App\Filament\Resources\RequeteResource\Pages;

use App\Filament\Resources\AuditeurResource\MessagesValidation;
use App\Filament\Resources\RequeteResource;
use App\Models\Requete;
use App\Models\RequetePiece;
use App\Models\SeanceRequetes;
use App\Models\User;
use App\Services\Lmd\PermissionsLmd;
use App\Services\Lmd\Requetes;
use App\Support\CandidatureObjectStorage;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/** Fiche d'une requête : chaque geste passe par App\Services\Lmd\Requetes, qui trace l'étape. */
class ViewRequete extends ViewRecord
{
    protected static string $resource = RequeteResource::class;

    public function getTitle(): string
    {
        /** @var Requete $r */
        $r = $this->getRecord();

        return 'Requête '.$r->numero;
    }

    protected function getHeaderActions(): array
    {
        $r = fn (): Requete => $this->getRecord()->refresh();
        $u = fn (): User => auth()->user();
        $etape = fn (string ...$statuts): bool => in_array($r()->statut, $statuts, true);
        $moi = fn (): bool => $r()->agent_id === auth()->id() && $u()->can(PermissionsLmd::REQUETES_TRAITER);
        $fichier = fn (string $nom = 'fichier', bool $requis = false): Forms\Components\FileUpload => Forms\Components\FileUpload::make($nom)
            ->label('Pièce (PDF, JPG ou PNG, 5 Mo)')->required($requis)->storeFiles(false)->maxSize(5120)
            ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png']);

        return [
            Action::make('coter')->label('Coter')->icon('heroicon-o-user-plus')
                ->visible(fn (): bool => $etape('deposee', 'cotee', 'en_instruction', 'en_traitement') && $u()->can(PermissionsLmd::REQUETES_COTER))
                ->form([Forms\Components\Select::make('agent')->label('Agent')->required()->native(false)
                    ->options(fn (): array => User::permission(PermissionsLmd::REQUETES_TRAITER)->orderBy('name')->pluck('name', 'id')->all())])
                ->action(fn (array $data) => $this->executer(fn () => app(Requetes::class)->coter($r(), User::query()->findOrFail((int) $data['agent']), $u()), 'Requête cotée')),
            Action::make('element')->label('Ajouter un élément')->icon('heroicon-o-paper-clip')
                ->visible(fn (): bool => $etape('cotee', 'en_instruction') && $moi())
                ->form(MessagesValidation::appliquer([
                    Forms\Components\Select::make('nature')->label('Élément')->required()->native(false)
                        ->options(collect(RequetePiece::NATURES)->except('piece_auditeur')->all())->default('photocopie_pv_notes'),
                    $fichier(),
                    Forms\Components\Textarea::make('constat')->label('Constat')->maxLength(1000),
                ], ['mimetypes' => 'Format accepté : PDF, JPG ou PNG.']))
                ->action(fn (array $data) => $this->executer(fn () => app(Requetes::class)->ajouterElement($r(), (string) $data['nature'], $data['fichier'] ?? null, (string) ($data['constat'] ?? ''), $u()), 'Élément ajouté')),
            Action::make('instruite')->label('Vérification terminée')->icon('heroicon-o-check')->color('success')
                ->visible(fn (): bool => $etape('cotee', 'en_instruction') && $moi())->requiresConfirmation()
                ->action(fn () => $this->executer(fn () => app(Requetes::class)->marquerInstruite($r(), $u()), 'Requête instruite')),
            Action::make('inscrire')->label('Inscrire au jury')->icon('heroicon-o-scale')
                ->visible(fn (): bool => $etape('instruite') && $u()->can(PermissionsLmd::REQUETES_JURY))
                ->form([Forms\Components\Select::make('seance')->label('Séance')->required()->native(false)
                    ->options(fn (): array => SeanceRequetes::query()->whereNull('close_at')->orderBy('tenue_le')->get()
                        ->mapWithKeys(fn (SeanceRequetes $s): array => [$s->id => $s->libelle()])->all())])
                ->action(fn (array $data) => $this->executer(fn () => app(Requetes::class)->inscrire($r(), SeanceRequetes::query()->findOrFail((int) $data['seance']), $u()), 'Requête inscrite au jury')),
            Action::make('statuer')->label('Mention du jury')->icon('heroicon-o-clipboard-document-check')
                ->visible(fn (): bool => $etape('au_jury') && $u()->can(PermissionsLmd::REQUETES_JURY))
                ->form(MessagesValidation::appliquer([
                    Forms\Components\Select::make('mention')->label('Mention')->required()->native(false)->live()
                        ->options(Requete::MENTIONS + ['ajournee' => 'Ajournée (vérification complémentaire)']),
                    Forms\Components\TextInput::make('note')->label('Note retenue / 20')->maxLength(5)
                        ->visible(fn (Forms\Get $get): bool => in_array($get('mention'), ['fondee', 'partiellement_fondee'], true)),
                    Forms\Components\Textarea::make('motif')->label('Motif de la décision')->required()->minLength(10)->maxLength(1000),
                ]))
                ->action(fn (array $data) => $this->executer(fn () => app(Requetes::class)->statuer($r(), (string) $data['mention'], $data['note'] ?? null, (string) $data['motif'], $u()), 'Mention enregistrée')),
            Action::make('document')->label('Déposer le document')->icon('heroicon-o-arrow-up-tray')->color('success')
                ->visible(fn (): bool => $etape('en_traitement') && $moi())
                ->form(MessagesValidation::appliquer([Forms\Components\FileUpload::make('fichier')->label('Document (PDF, 5 Mo)')->required()->storeFiles(false)
                    ->maxSize(5120)->acceptedFileTypes(['application/pdf'])], ['mimetypes' => 'Format accepté : PDF.']))
                ->action(fn (array $data) => $this->executer(fn () => app(Requetes::class)->deposerDocument($r(), $data['fichier'], $u()), 'Document mis à disposition de l\'auditeur')),
            ActionGroup::make([
                Action::make('voirPiece')->label('Voir une pièce')->icon('heroicon-o-eye')
                    ->visible(fn (): bool => $r()->pieces()->where('chemin', '!=', '')->exists())
                    ->form([Forms\Components\Select::make('piece')->label('Pièce')->required()->native(false)
                        ->options(fn (): array => $r()->pieces()->where('chemin', '!=', '')->get()
                            ->mapWithKeys(fn (RequetePiece $p): array => [$p->id => (RequetePiece::NATURES[$p->nature] ?? $p->nature).' — '.$p->nom])->all())])
                    ->action(function (array $data) use ($r) {
                        $piece = $r()->pieces()->findOrFail((int) $data['piece']);
                        abort_unless(RequetePiece::cheminValide($piece->chemin), 403);
                        activity('lmd')->causedBy(auth()->user())->performedOn($r())->event('piece_consultee')->log('Pièce de requête consultée');

                        return redirect()->away(Storage::disk(CandidatureObjectStorage::DISK)->temporaryUrl($piece->chemin, now()->addMinutes(30)));
                    }),
                Action::make('irrecevable')->label('Irrecevable')->icon('heroicon-o-x-circle')->color('danger')
                    ->visible(fn (): bool => $etape('deposee', 'cotee', 'en_instruction') && $u()->can(PermissionsLmd::REQUETES_COTER))
                    ->form(MessagesValidation::appliquer([Forms\Components\Textarea::make('motif')->label('Motif')->required()->minLength(10)->maxLength(1000)]))
                    ->action(fn (array $data) => $this->executer(fn () => app(Requetes::class)->irrecevable($r(), (string) $data['motif'], $u()), 'Requête déclarée irrecevable')),
                Action::make('rejeter')->label('Rejeter la demande')->icon('heroicon-o-x-circle')->color('danger')
                    ->visible(fn (): bool => $etape('en_traitement') && $moi())
                    ->form(MessagesValidation::appliquer([Forms\Components\Textarea::make('motif')->label('Motif')->required()->minLength(10)->maxLength(1000)]))
                    ->action(fn (array $data) => $this->executer(fn () => app(Requetes::class)->rejeterDocument($r(), (string) $data['motif'], $u()), 'Demande rejetée')),
            ])->label('Plus')->icon('heroicon-o-ellipsis-vertical')->button()->color('gray'),
        ];
    }

    private function executer(callable $operation, string $succes): void
    {
        try {
            $operation();
        } catch (InvalidArgumentException $e) {
            Notification::make()->danger()->title('Opération refusée')->body($e->getMessage())->persistent()->send();

            return;
        }
        Notification::make()->success()->title($succes)->send();
    }
}
