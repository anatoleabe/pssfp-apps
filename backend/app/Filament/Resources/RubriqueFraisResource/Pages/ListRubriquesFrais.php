<?php

declare(strict_types=1);

namespace App\Filament\Resources\RubriqueFraisResource\Pages;

use App\Filament\Resources\AuditeurResource\MessagesValidation;
use App\Filament\Resources\RubriqueFraisResource;
use App\Models\BaremeScolarite;
use App\Models\CampagneCandidature;
use App\Services\Scolarite\RubriquesFrais;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\HtmlString;
use InvalidArgumentException;

class ListRubriquesFrais extends ListRecords
{
    protected static string $resource = RubriqueFraisResource::class;

    /** Anomalies (somme différente du barème, couple sans rubriques, promotion sans compte), ou rappel de la règle. */
    public function getSubheading(): HtmlString
    {
        $anomalies = RubriquesFrais::anomalies();
        $regle = 'Un versement remplit les rubriques dans leur ordre. Leur somme doit égaler le total du barème ; sinon les reçus sont ventilés par palier.';
        if ($anomalies === []) {
            return new HtmlString(e($regle));
        }

        return new HtmlString(e($regle).'<br><strong>Anomalies :</strong><br>'.implode('<br>', array_map(fn (string $a): string => '— '.e($a), $anomalies)));
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            Action::make('proposer')->label('Proposer les rubriques types')->icon('heroicon-o-document-duplicate')->color('gray')
                ->visible(fn (): bool => RubriquesFrais::peutGerer(auth()->user()))
                ->modalDescription('Crée, pour un couple sans rubriques, les rubriques du plan comptable (inscription, bibliothèque, tenue de sport, périscolaires, pension selon la promotion), montants à saisir.')
                ->form(MessagesValidation::appliquer([
                    Forms\Components\TextInput::make('annee_academique')->label('Année académique')->required()->regex('/^\d{4}-\d{4}$/')->placeholder('2026-2027'),
                    Forms\Components\Select::make('niveau')->label('Niveau')->required()->options(BaremeScolarite::NIVEAUX)->native(false),
                ]))
                ->action(function (array $data, Action $action): void {
                    try {
                        $n = RubriquesFrais::proposerModele((string) $data['annee_academique'], (string) $data['niveau'], auth()->user());
                    } catch (InvalidArgumentException $e) {
                        Notification::make()->danger()->title('Rubriques non créées')->body($e->getMessage())->send();
                        $action->halt();

                        return;
                    }
                    Notification::make()->success()->title($n.' rubriques créées')->body('Saisissez leurs montants : leur somme doit égaler le barème.')->send();
                }),
            Action::make('comptesPromotions')->label('Comptes des promotions')->icon('heroicon-o-banknotes')->color('gray')
                ->visible(fn (): bool => RubriquesFrais::peutGerer(auth()->user()))
                ->modalDescription('Compte de scolarité de chaque promotion (plan comptable de la RAF) : c\'est le code de la pension sur les reçus de ses auditeurs. Le compte 710100 (pénalité de retard des soutenances) n\'en est jamais un.')
                ->fillForm(fn (): array => ['comptes' => CampagneCandidature::query()->orderBy('promotion_numero')->get()
                    ->mapWithKeys(fn (CampagneCandidature $c): array => [(string) $c->id => $c->compte_scolarite])->all()])
                ->form(fn (): array => [
                    Forms\Components\Grid::make(3)->schema(CampagneCandidature::query()->orderBy('promotion_numero')->get()
                        ->map(fn (CampagneCandidature $c): Forms\Components\TextInput => Forms\Components\TextInput::make('comptes.'.$c->id)
                            ->label('Promotion '.$c->promotion_numero.' ('.$c->slug.')')->regex('/^\d{6}$/')->notIn([RubriquesFrais::COMPTE_INTERDIT])
                            ->placeholder('À préciser'))->all()),
                ])
                ->action(function (array $data): void {
                    abort_unless(RubriquesFrais::peutGerer(auth()->user()), 403);
                    $modifiees = 0;
                    foreach (CampagneCandidature::query()->get() as $c) {
                        $compte = filled($data['comptes'][(string) $c->id] ?? null) ? (string) $data['comptes'][(string) $c->id] : null;
                        if ($compte !== $c->compte_scolarite && RubriquesFrais::compteValide($compte)) {
                            $ancien = $c->compte_scolarite;
                            $c->forceFill(['compte_scolarite' => $compte])->save();
                            activity('finances')->causedBy(auth()->user())->performedOn($c)->event('compte_scolarite_modifie')
                                ->withProperties(['promotion' => $c->promotion_numero, 'ancien' => $ancien, 'nouveau' => $compte])->log('Compte de scolarité de la promotion modifié');
                            $modifiees++;
                        }
                    }
                    Notification::make()->success()->title($modifiees === 0 ? 'Aucun compte modifié' : $modifiees.' compte(s) modifié(s)')->send();
                }),
        ];
    }
}
