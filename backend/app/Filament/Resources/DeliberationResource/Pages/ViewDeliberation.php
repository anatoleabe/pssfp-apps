<?php

declare(strict_types=1);

namespace App\Filament\Resources\DeliberationResource\Pages;

use App\Filament\Resources\AuditeurResource\MessagesValidation;
use App\Filament\Resources\DeliberationResource;
use App\Models\Deliberation;
use App\Models\MembreJury;
use App\Models\Scenario;
use App\Models\User;
use App\Services\Lmd\Deliberations;
use App\Services\Lmd\PermissionsLmd;
use App\Services\Lmd\PvDeliberation;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Fiche d'une délibération : chaque geste passe par App\Services\Lmd\Deliberations. */
class ViewDeliberation extends ViewRecord
{
    protected static string $resource = DeliberationResource::class;

    public function getTitle(): string
    {
        /** @var Deliberation $d */
        $d = $this->getRecord();

        return 'Délibération '.$d->niveau.($d->parcours ? ' '.$d->parcours->code : '');
    }

    public function getSubheading(): ?string
    {
        /** @var Deliberation $d */
        $d = $this->getRecord();

        return $d->libelle().($d->numero_pv ? ' · '.$d->numero_pv : '');
    }

    protected function getHeaderActions(): array
    {
        $d = fn (): Deliberation => $this->getRecord();
        $peut = fn (string $permission): bool => (bool) auth()->user()?->can($permission);

        return [
            Action::make('calculer')->label('Calculer')->icon('heroicon-o-calculator')
                ->visible(fn (): bool => $d()->estModifiable() && $peut(PermissionsLmd::DELIBERATION_PREPARER))
                ->form([Forms\Components\Select::make('scenario')->label('Scénario')->required()->native(false)
                    ->options(fn (): array => $d()->scenarios()->pluck('libelle', 'id')->all())
                    ->default(fn (): ?int => $d()->scenarios()->value('id'))])
                ->action(fn (array $data, array $arguments) => $this->executer(function () use ($data, $arguments, $d): string {
                    $s = $this->scenario($d(), (int) ($arguments['scenario'] ?? $data['scenario']));
                    $r = app(Deliberations::class)->calculer($s, $this->utilisateur());

                    return sprintf('%s : %d auditeurs, %d AD, %d AS, %d AJ', $s->libelle, $r['effectif'], $r['admis'], $r['autorises'], $r['ajournes']);
                })),
            ActionGroup::make([
                Action::make('dupliquer')->label('Nouvelle variante')->icon('heroicon-o-document-duplicate')->color('gray')
                    ->visible(fn (): bool => $d()->estModifiable() && $peut(PermissionsLmd::DELIBERATION_PREPARER))
                    ->form(MessagesValidation::appliquer([
                        Forms\Components\Select::make('source')->label('À partir du scénario')->required()->native(false)
                            ->options(fn (): array => $d()->scenarios()->pluck('libelle', 'id')->all())->default(fn (): ?int => $d()->scenarios()->value('id')),
                        Forms\Components\TextInput::make('libelle')->label('Nom de la variante')->required()->maxLength(120),
                    ]))
                    ->action(fn (array $data) => $this->executer(function () use ($data, $d): string {
                        app(Deliberations::class)->dupliquer($this->scenario($d(), (int) $data['source']), (string) $data['libelle'], $this->utilisateur());

                        return 'Variante créée : ajoutez ses mesures, puis calculez-la.';
                    })),
                Action::make('seance')->label('Ouvrir la séance')->icon('heroicon-o-user-group')->color('gray')
                    ->visible(fn (): bool => $d()->estModifiable() && $d()->jury_id !== null && $peut(PermissionsLmd::DELIBERATION_PREPARER))
                    ->modalDescription('Indiquez qui est présent et le motif de chaque absence. Le quorum est calculé selon les règles de l\'année.')
                    ->form(fn (): array => MessagesValidation::appliquer($d()->jury->membres()->get()->flatMap(fn (MembreJury $m): array => [
                        Forms\Components\Toggle::make("presences.{$m->id}.present")->label($m->libelle())->default(true)->live()->inline(false),
                        Forms\Components\TextInput::make("presences.{$m->id}.motif_absence")->label('Motif de l\'absence')->maxLength(255)
                            ->visible(fn (Forms\Get $get): bool => ! $get("presences.{$m->id}.present"))
                            ->required(fn (Forms\Get $get): bool => ! $get("presences.{$m->id}.present")),
                    ])->all()))
                    ->action(fn (array $data) => $this->executer(function () use ($data, $d): string {
                        $presences = collect($data['presences'] ?? [])->map(fn (array $p): array => ['present' => (bool) ($p['present'] ?? false), 'motif_absence' => $p['motif_absence'] ?? null])->all();
                        $quorum = app(Deliberations::class)->ouvrirSeance($d(), $presences, $this->utilisateur());

                        return $quorum ? 'Séance ouverte : quorum atteint.' : 'Séance ouverte, mais le quorum n\'est PAS atteint.';
                    })),
                Action::make('retenir')->label('Retenir un scénario')->icon('heroicon-o-hand-thumb-up')->color('gray')
                    ->visible(fn (): bool => $d()->estModifiable() && $d()->seance_ouverte_at !== null && $peut(PermissionsLmd::DELIBERATION_PREPARER))
                    ->form([Forms\Components\Select::make('scenario')->label('Scénario retenu par le jury')->required()->native(false)
                        ->options(fn (): array => $d()->scenarios()->whereNotNull('calcule_at')->pluck('libelle', 'id')->all())])
                    ->action(fn (array $data) => $this->executer(function () use ($data, $d): string {
                        app(Deliberations::class)->retenir($this->scenario($d(), (int) $data['scenario']), $this->utilisateur());

                        return 'Scénario retenu.';
                    })),
            ])->label('Séance et scénarios')->icon('heroicon-o-user-group')->button()->color('gray'),
            Action::make('arreter')->label('Arrêter les résultats')->icon('heroicon-o-lock-closed')->color('warning')
                ->visible(fn (): bool => $d()->estModifiable() && $peut(PermissionsLmd::DELIBERATION_ARRETER))
                ->requiresConfirmation()
                ->modalDescription('Les résultats du scénario retenu sont figés, les UE acquises capitalisées, le PV numéroté et scellé. Seule une rectification motivée les changera ensuite.')
                ->action(fn () => $this->executer(fn (): string => 'Délibération arrêtée : '.app(Deliberations::class)->arreter($d(), $this->utilisateur()))),
            Action::make('signer')->label('Signer le PV')->icon('heroicon-o-pencil')->color('success')
                ->visible(fn (): bool => $d()->statut === Deliberation::ARRETEE && $d()->participations()->where('present', true)->whereNull('signe_at')
                    ->whereHas('membre', fn ($q) => $q->where('user_id', auth()->id()))->exists())
                ->requiresConfirmation()
                ->modalDescription('Votre signature atteste que le PV reproduit les décisions prises en séance.')
                ->action(fn () => $this->executer(function () use ($d): string {
                    app(Deliberations::class)->signer($d(), $this->utilisateur());

                    return 'PV signé.';
                })),
            Action::make('publier')->label('Publier')->icon('heroicon-o-megaphone')->color('success')
                ->visible(fn (): bool => $d()->statut === Deliberation::ARRETEE && $d()->publiee_at === null && $peut(PermissionsLmd::DELIBERATION_PUBLIER))
                ->requiresConfirmation()
                ->modalDescription('Les auditeurs voient leurs résultats dans leur espace ; la date limite des requêtes est fixée.')
                ->action(fn () => $this->executer(fn (): string => 'Résultats publiés ; requêtes jusqu\'au '.app(Deliberations::class)->publier($d(), $this->utilisateur())->format('d/m/Y').'.')),
            ActionGroup::make([
                Action::make('pv')->label('PV (PDF)')->icon('heroicon-o-document-arrow-down')
                    ->action(function () use ($d): StreamedResponse {
                        $pdf = app(PvDeliberation::class)->pv($d());

                        return response()->streamDownload(fn () => print ($pdf), mb_strtolower(($d()->numero_pv ?? 'projet-pv')).'.pdf', ['Content-Type' => 'application/pdf']);
                    }),
                Action::make('affichage')->label('Liste d\'affichage (PDF)')->icon('heroicon-o-list-bullet')
                    ->visible(fn (): bool => ! $d()->estModifiable())
                    ->action(function () use ($d): StreamedResponse {
                        $pdf = app(PvDeliberation::class)->affichage($d());

                        return response()->streamDownload(fn () => print ($pdf), 'resultats-'.mb_strtolower((string) $d()->numero_pv).'.pdf', ['Content-Type' => 'application/pdf']);
                    }),
                Action::make('rectifier')->label('Rectifier')->icon('heroicon-o-arrow-uturn-left')->color('danger')
                    ->visible(fn (): bool => $d()->statut === Deliberation::ARRETEE && $peut(PermissionsLmd::DELIBERATION_ARRETER))
                    ->modalDescription('Crée une délibération rectificative liée (même jury, mêmes mesures) : nouvelle séance, nouvel arrêt, nouvelles signatures.')
                    ->form(MessagesValidation::appliquer([
                        Forms\Components\TextInput::make('acte')->label('Référence de l\'acte rectificatif')->required()->maxLength(120),
                        Forms\Components\Textarea::make('motif')->label('Motif')->required()->minLength(10)->maxLength(500),
                    ]))
                    ->action(function (array $data) use ($d): void {
                        try {
                            $nouvelle = app(Deliberations::class)->rectifier($d(), (string) $data['motif'], (string) $data['acte'], $this->utilisateur());
                        } catch (InvalidArgumentException $e) {
                            Notification::make()->danger()->title('Rectification refusée')->body($e->getMessage())->send();

                            return;
                        }
                        $this->redirect(DeliberationResource::getUrl('view', ['record' => $nouvelle]));
                    }),
            ])->label('Documents')->icon('heroicon-o-document-text')->button()->color('gray'),
        ];
    }

    private function scenario(Deliberation $d, int $id): Scenario
    {
        return $d->scenarios()->findOrFail($id);
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

    private function utilisateur(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
