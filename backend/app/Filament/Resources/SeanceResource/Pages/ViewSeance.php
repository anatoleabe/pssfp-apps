<?php

declare(strict_types=1);

namespace App\Filament\Resources\SeanceResource\Pages;

use App\Filament\Resources\AuditeurResource\MessagesValidation;
use App\Filament\Resources\SeanceResource;
use App\Models\Pointage;
use App\Models\Seance;
use App\Models\User;
use App\Services\Lmd\Assiduite;
use App\Services\Lmd\SaisieNotes;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use InvalidArgumentException;

class ViewSeance extends ViewRecord
{
    protected static string $resource = SeanceResource::class;

    protected function getHeaderActions(): array
    {
        $s = fn (): Seance => $this->getRecord()->refresh()->load('ecue');
        $u = fn (): User => auth()->user();
        $peut = fn (): bool => ! in_array($s()->statut, ['annulee', 'reportee'], true) && app(Assiduite::class)->peutGerer($s()->ecue, $u());
        $options = fn (): array => app(SaisieNotes::class)->inscrits($s()->ecue)->mapWithKeys(fn ($ip): array => [$ip->id => $ip->inscriptionAnnuelle?->auditeur?->candidature?->matricule
            .' — '.trim(mb_strtoupper((string) $ip->inscriptionAnnuelle?->auditeur?->candidature?->nom).' '.$ip->inscriptionAnnuelle?->auditeur?->candidature?->prenom)])->all();

        return [
            Action::make('pointer')->label('Feuille de présence')->icon('heroicon-o-check-circle')->color('success')
                ->visible($peut)->modalWidth('3xl')
                ->fillForm(fn (): array => [
                    'presents' => Pointage::query()->where('seance_id', $s()->id)->where('statut', 'present')->pluck('inscription_pedagogique_id')->all(),
                    'justifies' => Pointage::query()->where('seance_id', $s()->id)->where('statut', 'justifie')->pluck('inscription_pedagogique_id')->all(),
                ])
                ->form(fn (): array => MessagesValidation::appliquer([
                    Forms\Components\CheckboxList::make('presents')->label('Présents')->options($options())->columns(2)->bulkToggleable()->searchable(),
                    Forms\Components\CheckboxList::make('justifies')->label('Absences justifiées')->options($options())->columns(2)->searchable(),
                    Forms\Components\TextInput::make('motif')->label('Motif des absences justifiées')->maxLength(500),
                ]))
                ->action(fn (array $data) => $this->executer(fn (): string => app(Assiduite::class)->pointer($s(),
                    array_map('intval', (array) ($data['presents'] ?? [])), array_map('intval', array_diff((array) ($data['justifies'] ?? []), (array) ($data['presents'] ?? []))),
                    $data['motif'] ?? null, $u()).' auditeur(s) pointé(s).')),
            Action::make('zoom')->label('Importer le rapport Zoom')->icon('heroicon-o-video-camera')->color('gray')
                ->visible($peut)
                ->modalDescription('Rapport des participants (CSV) exporté du portail Zoom. Les connexions d\'un même participant s\'additionnent.')
                ->form(MessagesValidation::appliquer([
                    Forms\Components\FileUpload::make('fichier')->label('Rapport CSV')->required()->storeFiles(false)->maxSize(2048)
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel']),
                    Forms\Components\Toggle::make('simulation')->label('Contrôler seulement')->default(true),
                ], ['mimetypes' => 'Format accepté : CSV.']))
                ->action(function (array $data) use ($s, $u): void {
                    try {
                        $r = app(Assiduite::class)->importerZoom($s(), (string) $data['fichier']->getRealPath(), $u(), ! (bool) $data['simulation']);
                    } catch (InvalidArgumentException $e) {
                        Notification::make()->danger()->title('Import refusé')->body($e->getMessage())->send();

                        return;
                    }
                    Notification::make()->success()->persistent()
                        ->title(($data['simulation'] ? 'Contrôle : ' : 'Import : ').$r['presents'].' présent(s), '.$r['absents'].' absent(s)')
                        ->body($r['non_reconnus'] === [] ? 'Tous les participants sont reconnus.' : 'Non reconnus (non rattachés) : '.implode(', ', array_slice($r['non_reconnus'], 0, 15)).'.')->send();
                }),
            Action::make('annuler')->label('Annuler la séance')->icon('heroicon-o-x-circle')->color('danger')
                ->visible(fn (): bool => $peut() && $s()->statut === 'prevue')
                ->form(MessagesValidation::appliquer([Forms\Components\Textarea::make('motif')->label('Motif')->required()->minLength(5)->maxLength(500)]))
                ->action(fn (array $data) => $this->executer(function () use ($s, $u, $data): string {
                    app(Assiduite::class)->annuler($s(), (string) $data['motif'], $u());

                    return 'Séance annulée.';
                })),
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
