<?php

declare(strict_types=1);

namespace App\Filament\Resources\PointageEnseignantResource\Pages;

use App\Filament\Resources\AuditeurResource\MessagesValidation;
use App\Filament\Resources\PointageEnseignantResource;
use App\Filament\Resources\QuotaHoraireResource\ChampsProgrammation;
use App\Models\Seance;
use App\Models\User;
use App\Services\Lmd\PermissionsLmd;
use App\Services\Lmd\PointagesEnseignant;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Str;

class ListPointagesEnseignant extends ListRecords
{
    protected static string $resource = PointageEnseignantResource::class;

    public function getTitle(): string
    {
        return PointageEnseignantResource::getNavigationLabel();
    }

    public function getSubheading(): ?string
    {
        return PointageEnseignantResource::enseignantSeul()
            ? 'Pointez chaque séance faite : heures d\'arrivée et de départ, contenu enseigné. La Scolarité valide vos pointages ; seules les heures validées sont payées.'
            : null;
    }

    protected function getHeaderActions(): array
    {
        $messages = ['after' => 'Le départ suit l\'arrivée.'];

        return [
            Action::make('emarger')->label('Émarger un cours')->icon('heroicon-o-finger-print')
                ->visible(fn (): bool => $this->moi()?->can(PermissionsLmd::EMARGER) === true && $this->moi()->hasRole('enseignant'))
                ->form(fn (): array => MessagesValidation::appliquer([
                    Forms\Components\Select::make('seance_id')->label('Séance')->required()->native(false)->searchable()->live()
                        ->options(fn (): array => $this->aEmarger())
                        ->placeholder(fn (): string => $this->aEmarger() === [] ? 'Aucune séance à pointer pour le moment' : 'Choisissez la séance')
                        ->noSearchResultsMessage('Aucune séance à pointer.')
                        ->helperText('Vos séances passées ou du jour, et celles des cours où vous êtes programmé (remplacement).')
                        ->afterStateUpdated(fn (?string $state, Forms\Set $set) => PointageEnseignantResource::remplirDepuisSeance($state, $set)),
                    ...PointageEnseignantResource::champsHoraires(false),
                ], $messages))
                ->action(fn (array $data) => ChampsProgrammation::executer(function () use ($data): string {
                    app(PointagesEnseignant::class)->emarger(Seance::query()->findOrFail((int) $data['seance_id']), $this->moi(), $data, $this->moi());

                    return 'Séance pointée : la Scolarité va la valider.';
                })),
            Action::make('saisir')->label('Saisir un pointage')->icon('heroicon-o-plus')
                ->visible(fn (): bool => $this->moi()?->can(PermissionsLmd::POINTAGE) === true)
                ->form(fn (): array => MessagesValidation::appliquer([
                    Forms\Components\Select::make('enseignant_id')->label('Enseignant qui a fait cours')->required()->native(false)->searchable()->live()
                        ->options(fn (): array => ChampsProgrammation::enseignants())
                        ->afterStateUpdated(fn (Forms\Set $set) => $set('seance_id', null)),
                    Forms\Components\Select::make('seance_id')->label('Séance')->required()->native(false)->searchable()->live()
                        ->options(fn (Forms\Get $get): array => $this->pointablesPour($get('enseignant_id')))
                        ->getSearchResultsUsing(fn (string $search, Forms\Get $get): array => $this->pointablesPour($get('enseignant_id'), $search))
                        ->getOptionLabelUsing(fn ($value): ?string => ($s = Seance::query()->with(['ecue', 'enseignant'])->find((int) $value)) === null ? null : $this->libelle($s))
                        ->helperText('Proposées d\'abord : les séances de l\'enseignant et des cours où il est programmé. Recherchez un autre cours par son code, ou une date (JJ/MM).')
                        ->afterStateUpdated(fn (?string $state, Forms\Set $set) => PointageEnseignantResource::remplirDepuisSeance($state, $set)),
                    ...PointageEnseignantResource::champsHoraires(true),
                ], $messages))
                ->action(fn (array $data) => ChampsProgrammation::executer(function () use ($data): string {
                    app(PointagesEnseignant::class)->emarger(Seance::query()->findOrFail((int) $data['seance_id']),
                        User::query()->findOrFail((int) $data['enseignant_id']), PointageEnseignantResource::donnees($data), $this->moi());

                    return 'Pointage enregistré.';
                })),
        ];
    }

    /** @var array<int, string>|null Séances à émarger, calculées une fois par requête. */
    private ?array $aEmarger = null;

    private function moi(): ?User
    {
        return auth()->user();
    }

    /** @return array<int, string> */
    private function aEmarger(): array
    {
        return $this->aEmarger ??= $this->options(app(PointagesEnseignant::class)->seancesAEmarger($this->moi()));
    }

    /** @return array<int, string> */
    private function pointablesPour(mixed $enseignantId, ?string $recherche = null): array
    {
        $e = blank($enseignantId) ? null : User::query()->find((int) $enseignantId);

        return $e === null ? [] : $this->options(app(PointagesEnseignant::class)->seancesPointablesPour($e, $recherche));
    }

    /** @param iterable<Seance> $seances @return array<int, string> */
    private function options(iterable $seances): array
    {
        $o = [];
        foreach ($seances as $s) {
            $o[$s->id] = $this->libelle($s);
        }

        return $o;
    }

    private function libelle(Seance $s): string
    {
        return $s->libelle().' · '.Str::limit((string) $s->ecue->intitule, 50).($s->enseignant !== null ? ' (titulaire : '.$s->enseignant->name.')' : '');
    }
}
