<?php

declare(strict_types=1);

namespace App\Filament\Resources\MaquetteResource\Pages;

use App\Filament\Resources\MaquetteResource;
use App\Services\Lmd\ImportMaquettes;
use App\Services\Lmd\PermissionsLmd;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use JsonException;

class ListMaquettes extends ListRecords
{
    protected static string $resource = MaquetteResource::class;

    /** Fichiers de maquettes versionnés dans le dépôt (aucune donnée personnelle). */
    public static function fichiers(): array
    {
        return collect(File::glob(resource_path('lmd/maquettes-*.json')) ?: [])
            ->mapWithKeys(fn (string $chemin): array => [basename($chemin) => basename($chemin)])
            ->all();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('importer')
                ->label('Importer les maquettes')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->visible(fn (): bool => (bool) auth()->user()?->can(PermissionsLmd::REFERENTIEL))
                ->modalDescription('Les maquettes arrivent en brouillon. Une maquette validée n\'est jamais modifiée ; un brouillon est remplacé tant qu\'aucun auditeur n\'y est inscrit.')
                ->form([
                    Forms\Components\Select::make('fichier')->label('Fichier')->required()->native(false)->options(self::fichiers()),
                ])
                ->action(function (array $data): void {
                    abort_unless(auth()->user()?->can(PermissionsLmd::REFERENTIEL), 403);
                    $nom = basename((string) $data['fichier']);
                    abort_unless(array_key_exists($nom, self::fichiers()), 422);
                    try {
                        $document = json_decode((string) File::get(resource_path('lmd/'.$nom)), true, 32, JSON_THROW_ON_ERROR);
                        $bilan = app(ImportMaquettes::class)->importer((array) $document, true, auth()->user());
                    } catch (InvalidArgumentException|JsonException $e) {
                        Notification::make()->danger()->title('Import impossible')->body($e->getMessage())->send();

                        return;
                    }
                    $lignes = array_filter([
                        $bilan['creees'] === [] ? null : 'Créées : '.implode(', ', $bilan['creees']).'.',
                        $bilan['remplacees'] === [] ? null : 'Brouillons remplacés : '.implode(', ', $bilan['remplacees']).'.',
                        $bilan['ignorees'] === [] ? null : 'Laissées telles quelles : '.collect($bilan['ignorees'])->map(fn (string $m, string $c): string => "{$c} ({$m})")->implode(', ').'.',
                        $bilan['ecarts'] === [] ? null : count($bilan['ecarts']).' écart(s) de libellé, détail au journal.',
                    ]);
                    if ($bilan['ecarts'] !== []) {
                        activity('lmd')->causedBy(auth()->user())->event('maquettes_ecarts')->withProperties(['ecarts' => $bilan['ecarts']])
                            ->log('Écarts de libellé à l\'import des maquettes');
                    }
                    Notification::make()->success()->title('Maquettes '.$bilan['annee'])->body(implode(' ', $lignes))->persistent()->send();
                }),
            CreateAction::make()->label('Nouvelle maquette'),
        ];
    }
}
