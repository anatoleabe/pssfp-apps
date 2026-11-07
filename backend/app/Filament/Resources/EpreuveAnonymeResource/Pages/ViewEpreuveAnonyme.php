<?php

declare(strict_types=1);

namespace App\Filament\Resources\EpreuveAnonymeResource\Pages;

use App\Filament\Resources\AuditeurResource\MessagesValidation;
use App\Filament\Resources\EpreuveAnonymeResource;
use App\Models\CopieAnonyme;
use App\Models\EpreuveAnonyme;
use App\Models\User;
use App\Services\Lmd\Anonymat;
use App\Services\Lmd\PermissionsLmd;
use App\Services\Lmd\SaisieNotes;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use InvalidArgumentException;
use SimpleSoftwareIO\QrCode\Facades\QrCode;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Fiche d'une épreuve anonyme : gestes du gestionnaire et du témoin, via App\Services\Lmd\Anonymat. */
class ViewEpreuveAnonyme extends ViewRecord
{
    protected static string $resource = EpreuveAnonymeResource::class;

    public function getTitle(): string
    {
        /** @var EpreuveAnonyme $e */
        $e = $this->getRecord();

        return 'Épreuve anonyme '.$e->libelle();
    }

    protected function getHeaderActions(): array
    {
        $e = fn (): EpreuveAnonyme => $this->getRecord()->refresh();
        $u = fn (): User => auth()->user();
        $gere = fn (): bool => $u()->can(PermissionsLmd::ANONYMAT_GERER);
        $phrase = fn (bool $confirmer = false): array => array_values(array_filter([
            Forms\Components\TextInput::make('phrase')->label('Phrase secrète du gestionnaire')->password()->revealable()->required()->minLength(Anonymat::PHRASE_MIN)
                ->helperText('Elle n\'est jamais enregistrée. Sans elle, aucune copie ne se relie à un auditeur : conservez-la sous séquestre.'),
            $confirmer ? Forms\Components\TextInput::make('confirmation')->label('Confirmez la phrase secrète')->password()->required()->same('phrase') : null,
        ]));

        return [
            Action::make('anonymiser')->label('Attribuer les numéros')->icon('heroicon-o-hashtag')->color('success')
                ->visible(fn (): bool => $e()->statut === 'preparation' && $gere())
                ->form(MessagesValidation::appliquer($phrase(true), ['same' => 'Les deux saisies ne correspondent pas.']))
                ->action(fn (array $data) => $this->executer(fn (): string => app(Anonymat::class)->anonymiser($e(), (string) $data['phrase'], $u()).' numéro(s) attribué(s).')),
            Action::make('constater')->label('Constater une copie')->icon('heroicon-o-clipboard-document-check')
                ->visible(fn (): bool => $e()->statut === 'anonymisee' && $gere())
                ->form(MessagesValidation::appliquer([
                    Forms\Components\TextInput::make('numero')->label('Numéro d\'anonymat')->required()->length(9),
                    Forms\Components\Select::make('constat')->label('Constat')->required()->native(false)->options(collect(CopieAnonyme::CONSTATS)->except('attribuee')->all()),
                    Forms\Components\Textarea::make('motif')->label('Décision motivée (égarée traitée, écartée, fraude)')->maxLength(500),
                ]))
                ->action(fn (array $data) => $this->executer(function () use ($e, $u, $data): string {
                    app(Anonymat::class)->constater($e(), (string) $data['numero'], (string) $data['constat'], $data['motif'] ?? null, $u());

                    return 'Constat enregistré.';
                })),
            Action::make('verrouiller')->label('Verrouiller')->icon('heroicon-o-lock-closed')->color('warning')
                ->visible(fn (): bool => $e()->statut === 'anonymisee' && $gere())->requiresConfirmation()
                ->action(fn () => $this->executer(function () use ($e, $u): string {
                    app(Anonymat::class)->verrouiller($e(), $u());

                    return 'Épreuve verrouillée : le témoin peut autoriser la levée.';
                })),
            Action::make('autoriser')->label('Autoriser la levée')->icon('heroicon-o-hand-raised')->color('warning')
                ->visible(fn (): bool => $e()->statut === 'verrouillee' && $e()->autorisee_at === null && $u()->can(PermissionsLmd::NOTES_VERROUILLER) && ! $gere())
                ->requiresConfirmation()->modalDescription('En tant que témoin, vous autorisez le gestionnaire à lever l\'anonymat de cette épreuve.')
                ->action(fn () => $this->executer(function () use ($e, $u): string {
                    app(Anonymat::class)->autoriserLevee($e(), $u());

                    return 'Levée autorisée.';
                })),
            Action::make('lever')->label('Lever l\'anonymat')->icon('heroicon-o-eye')->color('danger')
                ->visible(fn (): bool => $e()->statut === 'verrouillee' && $e()->autorisee_at !== null && $gere())
                ->modalDescription('Les notes sont reportées dans les notes d\'examen et l\'étape d\'examen se verrouille. Irréversible.')
                ->form(MessagesValidation::appliquer($phrase()))
                ->action(fn (array $data) => $this->executer(fn (): string => 'Anonymat levé : '.app(Anonymat::class)->lever($e(), (string) $data['phrase'], $u()).' copie(s) reportée(s).')),
            ActionGroup::make([
                Action::make('etiquettes')->label('Étiquettes (PDF)')->icon('heroicon-o-tag')
                    ->visible(fn (): bool => $e()->statut === 'anonymisee' && $gere())
                    ->form(MessagesValidation::appliquer($phrase()))
                    ->action(function (array $data) use ($e, $u): ?StreamedResponse {
                        try {
                            $correspondance = app(Anonymat::class)->correspondance($e(), (string) $data['phrase'], $u(), 'etiquettes');
                        } catch (InvalidArgumentException $ex) {
                            Notification::make()->danger()->title('Clé refusée')->body($ex->getMessage())->send();

                            return null;
                        }
                        $etiquettes = collect($correspondance)->map(fn ($ip, string $numero): array => [
                            'numero' => $numero,
                            'qr' => 'data:image/svg+xml;base64,'.base64_encode((string) QrCode::format('svg')->size(80)->margin(0)->generate($numero)),
                            'matricule' => (string) $ip->inscriptionAnnuelle?->auditeur?->candidature?->matricule,
                            'nom' => trim(mb_strtoupper((string) $ip->inscriptionAnnuelle?->auditeur?->candidature?->nom).' '.$ip->inscriptionAnnuelle?->auditeur?->candidature?->prenom),
                        ])->sortBy('nom')->values();
                        $pdf = Pdf::loadView('pdf.lmd.etiquettes-anonymat', ['e' => $e(), 'etiquettes' => $etiquettes])->setPaper('a4')->output();

                        return response()->streamDownload(fn () => print ($pdf), 'etiquettes-'.mb_strtolower((string) $e()->ecue?->code).'.pdf', ['Content-Type' => 'application/pdf']);
                    }),
                Action::make('emargement')->label('Liste d\'émargement (PDF)')->icon('heroicon-o-list-bullet')
                    ->visible(fn (): bool => $e()->statut !== 'levee' && $gere())
                    ->action(function () use ($e): StreamedResponse {
                        $inscrits = app(SaisieNotes::class)->inscrits($e()->ecue);
                        $pdf = Pdf::loadView('pdf.lmd.emargement-anonymat', ['e' => $e(), 'inscrits' => $inscrits])->setPaper('a4')->output();

                        return response()->streamDownload(fn () => print ($pdf), 'emargement-'.mb_strtolower((string) $e()->ecue?->code).'.pdf', ['Content-Type' => 'application/pdf']);
                    }),
                Action::make('bordereau')->label('Bordereau de notes (PDF)')->icon('heroicon-o-document-text')
                    ->visible(fn (): bool => in_array($e()->statut, ['anonymisee', 'verrouillee'], true))
                    ->action(function () use ($e): StreamedResponse {
                        $copies = $e()->copies()->where('constat', 'presente')->get()->sortBy('numero')->values();
                        $pdf = Pdf::loadView('pdf.lmd.bordereau-anonymat', ['e' => $e(), 'copies' => $copies])->setPaper('a4')->output();

                        return response()->streamDownload(fn () => print ($pdf), 'bordereau-'.mb_strtolower((string) $e()->ecue?->code).'.pdf', ['Content-Type' => 'application/pdf']);
                    }),
            ])->label('Documents')->icon('heroicon-o-document-text')->button()->color('gray'),
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
    }
}
