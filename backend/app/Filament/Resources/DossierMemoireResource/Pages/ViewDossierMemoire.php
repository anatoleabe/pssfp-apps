<?php

declare(strict_types=1);

namespace App\Filament\Resources\DossierMemoireResource\Pages;

use App\Filament\Resources\AuditeurResource\MessagesValidation;
use App\Filament\Resources\DossierMemoireResource;
use App\Models\DossierMemoire;
use App\Models\EtapeMemoire;
use App\Models\User;
use App\Services\Lmd\DocumentsOfficiels;
use App\Services\Lmd\Memoires;
use App\Services\Lmd\PermissionsLmd;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ViewDossierMemoire extends ViewRecord
{
    protected static string $resource = DossierMemoireResource::class;

    protected function getHeaderActions(): array
    {
        $d = fn (): DossierMemoire => $this->getRecord()->refresh();
        $u = fn (): User => auth()->user();
        $suivre = fn (): bool => $u()->can(PermissionsLmd::MEMOIRES_SUIVRE);
        $encadre = fn (): bool => $suivre() || ($d()->encadreur_academique_id !== null && $d()->encadreur_academique_id === $u()->id);
        $chef = fn (): bool => $u()->can(PermissionsLmd::MEMOIRES_ATTESTER);
        $etapes = collect(DossierMemoire::ETAPES)->map(fn (array $e): string => $e[0])->all();
        $m = fn (): Memoires => app(Memoires::class);

        return [
            Action::make('evaluer')->label('Évaluer une étape')->icon('heroicon-o-check-badge')->color('success')->visible($suivre)
                ->form(MessagesValidation::appliquer([
                    Forms\Components\Select::make('etape')->label('Étape')->required()->native(false)->options($etapes),
                    Forms\Components\Select::make('statut')->label('Décision')->required()->native(false)
                        ->options(['valide' => 'Validée', 'ajourne' => 'Ajournée', 'dispense' => 'Dispensée']),
                    Forms\Components\TextInput::make('note')->label('Note sur 20 (pré-soutenances, soutenance)')->maxLength(6),
                    Forms\Components\Textarea::make('appreciation')->label('Appréciation (obligatoire si ajournée)')->maxLength(1000),
                ]))
                ->action(fn (array $data) => $this->executer(function () use ($m, $d, $u, $data): string {
                    $m()->evaluer($d(), (string) $data['etape'], (string) $data['statut'], $data['note'] ?? null, $data['appreciation'] ?? null, $u());

                    return 'Étape enregistrée.';
                })),
            ActionGroup::make([
                EditAction::make()->label('Modifier le dossier'),
                Action::make('planifier')->label('Dates prévues des étapes')->icon('heroicon-o-calendar')->visible($suivre)
                    ->fillForm(fn (): array => $d()->etapes()->get()->mapWithKeys(fn (EtapeMemoire $e): array => [$e->etape => $e->date_prevue?->toDateString()])->all())
                    ->form(fn (): array => collect($etapes)->map(fn (string $libelle, string $etape) => Forms\Components\DatePicker::make($etape)->label($libelle)->native(false)->displayFormat('d/m/Y'))->values()->all())
                    ->action(fn (array $data) => $this->executer(function () use ($m, $d, $u, $data): string {
                        $m()->planifier($d(), array_map(fn ($v): ?string => $v === null ? null : (string) $v, $data), $u());

                        return 'Calendrier enregistré.';
                    })),
                Action::make('suivi')->label('Fiche de suivi d\'encadrement')->icon('heroicon-o-clipboard-document-list')->visible($encadre)
                    ->form(MessagesValidation::appliquer([
                        Forms\Components\DatePicker::make('date_suivi')->label('Date')->required()->native(false)->displayFormat('d/m/Y')->default(now()),
                        Forms\Components\Select::make('mode')->label('Mode')->required()->native(false)->options(['presentiel' => 'Présentiel', 'distanciel' => 'Distanciel', 'courriel' => 'Courriel', 'telephone' => 'Téléphone']),
                        Forms\Components\TextInput::make('avancement_estime')->label('Avancement estimé (%)')->numeric()->minValue(0)->maxValue(100),
                        Forms\Components\TextInput::make('chapitres')->label('Chapitres rédigés')->maxLength(500),
                        Forms\Components\Textarea::make('observations')->label('Observations')->maxLength(2000),
                    ]))
                    ->action(fn (array $data) => $this->executer(function () use ($m, $d, $u, $data): string {
                        $m()->ajouterSuivi($d(), [...$data, 'date_suivi' => (string) $data['date_suivi'], 'avancement_estime' => $data['avancement_estime'] === null || $data['avancement_estime'] === '' ? null : (int) $data['avancement_estime']], $u());

                        return 'Fiche de suivi enregistrée.';
                    })),
                Action::make('avis')->label('Avis de dépôt d\'un encadreur')->icon('heroicon-o-hand-thumb-up')->visible($encadre)
                    ->form(MessagesValidation::appliquer([
                        Forms\Components\Select::make('encadreur')->label('Encadreur')->required()->native(false)->options(['academique' => 'Académique', 'professionnel' => 'Professionnel'])->default('academique'),
                        Forms\Components\Radio::make('favorable')->label('Avis')->required()->boolean('Favorable', 'Défavorable'),
                        Forms\Components\Textarea::make('commentaire')->label('Commentaire')->maxLength(1000),
                    ]))
                    ->action(fn (array $data) => $this->executer(function () use ($m, $d, $u, $data): string {
                        $m()->ajouterAvis($d(), (string) $data['encadreur'], (bool) $data['favorable'], $data['commentaire'] ?? null, $u());

                        return 'Avis enregistré.';
                    })),
                Action::make('similitude')->label('Rapport de similitude')->icon('heroicon-o-document-magnifying-glass')->visible($suivre)
                    ->form(MessagesValidation::appliquer([
                        Forms\Components\TextInput::make('outil')->label('Outil')->required()->maxLength(120)->default('Compilatio'),
                        Forms\Components\TextInput::make('taux')->label('Taux de similitude (%)')->required()->maxLength(6),
                        Forms\Components\DatePicker::make('date_rapport')->label('Date du rapport')->required()->native(false)->displayFormat('d/m/Y')->default(now()),
                    ]))
                    ->action(fn (array $data) => $this->executer(function () use ($m, $d, $u, $data): string {
                        $m()->ajouterRapport($d(), (string) $data['outil'], (string) $data['taux'], (string) $data['date_rapport'], $u());

                        return 'Rapport enregistré.';
                    })),
                Action::make('telecharger')->label('Télécharger un livrable')->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn (): bool => $d()->etapes()->whereNotNull('document_path')->exists())
                    ->form(fn (): array => [Forms\Components\Select::make('etape')->label('Livrable')->required()->native(false)
                        ->options($d()->etapes()->whereNotNull('document_path')->get()->mapWithKeys(fn (EtapeMemoire $e): array => [$e->etape => $e->libelle()])->all())])
                    ->action(function (array $data) use ($m, $d): ?StreamedResponse {
                        $e = $d()->etapes()->where('etape', $data['etape'])->firstOrFail();
                        try {
                            $pdf = $m()->contenuEtape($e);
                        } catch (InvalidArgumentException $ex) {
                            Notification::make()->danger()->title('Téléchargement impossible')->body($ex->getMessage())->send();

                            return null;
                        }

                        return response()->streamDownload(fn () => print ($pdf), 'memoire-'.$e->etape.'.pdf', ['Content-Type' => 'application/pdf']);
                    }),
                Action::make('statut')->label('Suspendre, abandonner ou reprendre')->icon('heroicon-o-pause-circle')->color('gray')->visible($suivre)
                    ->form(MessagesValidation::appliquer([
                        Forms\Components\Select::make('statut')->label('État')->required()->native(false)->options(['suspendu' => 'Suspendu', 'abandon' => 'Abandon', 'en_redaction' => 'Reprise de la rédaction']),
                        Forms\Components\Textarea::make('motif')->label('Motif')->maxLength(500),
                    ]))
                    ->action(fn (array $data) => $this->executer(function () use ($m, $d, $u, $data): string {
                        $m()->changerStatut($d(), (string) $data['statut'], $data['motif'] ?? null, $u());

                        return 'État du mémoire mis à jour.';
                    })),
            ])->label('Suivi')->button()->icon('heroicon-o-ellipsis-vertical'),
            ActionGroup::make([
                Action::make('retard')->label('Autoriser un retard d\'étape')->icon('heroicon-o-clock')
                    ->form(MessagesValidation::appliquer([
                        Forms\Components\Select::make('etape')->label('Étape')->required()->native(false)->options($etapes),
                        Forms\Components\Textarea::make('motif')->label('Motif')->required()->minLength(10)->maxLength(500),
                    ]))
                    ->action(fn (array $data) => $this->executer(function () use ($m, $d, $u, $data): string {
                        $m()->autoriserRetard($d(), (string) $data['etape'], (string) $data['motif'], $u());

                        return 'Retard autorisé.';
                    })),
                Action::make('attester')->label('Délivrer l\'attestation d\'autorisation de dépôt')->icon('heroicon-o-document-check')->color('success')
                    ->visible(fn (): bool => $m()->attestationValide($d()) === null)
                    ->requiresConfirmation()->modalDescription('Les conditions sont vérifiées au moment de la délivrance ; le document numéroté est signé « Le Chef de la scolarité ».')
                    ->action(fn () => $this->executer(fn (): string => 'Attestation '.$m()->delivrerAttestation($d(), $u())->numero.' délivrée.')),
                Action::make('telechargerAttestation')->label('Télécharger l\'attestation')->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn (): bool => $m()->attestationValide($d()) !== null)
                    ->action(function () use ($m, $d): ?StreamedResponse {
                        $doc = $m()->attestationValide($d());
                        try {
                            $pdf = app(DocumentsOfficiels::class)->contenu($doc);
                        } catch (InvalidArgumentException $ex) {
                            Notification::make()->danger()->title('Téléchargement impossible')->body($ex->getMessage())->send();

                            return null;
                        }

                        return response()->streamDownload(fn () => print ($pdf), mb_strtolower($doc->numero).'.pdf', ['Content-Type' => 'application/pdf']);
                    }),
            ])->label('Chef de la scolarité')->button()->color('success')->icon('heroicon-o-shield-check')->visible($chef),
        ];
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
        $this->getRecord()->refresh();
    }
}
