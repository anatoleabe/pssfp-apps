<?php

declare(strict_types=1);

namespace App\Filament\Resources\InscriptionResource\Pages;

use App\Filament\Resources\AuditeurResource\MessagesValidation;
use App\Filament\Resources\InscriptionResource;
use App\Services\Scolarite\AnneeEnCours;
use App\Services\Scolarite\PassageEnGroupe;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use InvalidArgumentException;

class ListInscriptions extends ListRecords
{
    protected static string $resource = InscriptionResource::class;

    public function getSubheading(): ?string
    {
        $annee = AnneeEnCours::annee();

        return $annee === null ? 'Année en cours non réglée.' : 'Année en cours : '.$annee.'.';
    }

    protected function getHeaderActions(): array
    {
        return [$this->passerEnM2(), $this->changerAnnee()];
    }

    /** Spec lot 18, règle 2 : l'aperçu tient lieu de confirmation. */
    private function passerEnM2(): Action
    {
        return Action::make('passerEnM2')
            ->label('Passer en M2 les admis du M1')
            ->icon('heroicon-o-arrow-up-circle')
            ->visible(fn (): bool => PassageEnGroupe::peutPasser(auth()->user()) && AnneeEnCours::annee() !== null)
            ->requiresConfirmation()
            ->modalHeading('Passage en M2')
            ->modalDescription(function (): string {
                $a = app(PassageEnGroupe::class)->apercu();
                $ecartes = array_filter([
                    $a['deja_inscrits'] > 0 ? $a['deja_inscrits'].' déjà inscrit(s) en '.$a['annee'] : null,
                    count($a['sans_option']) > 0 ? count($a['sans_option']).' sans option reconnue, à inscrire depuis leur fiche' : null,
                    $a['inactifs'] > 0 ? $a['inactifs'].' qui ne sont pas « actifs »' : null,
                    $a['retires'] > 0 ? $a['retires'].' au dossier retiré' : null,
                ]);

                return sprintf('%d admis du M1 de %s seront inscrits en M2 pour %s, dans l\'option de leur spécialité, au barème de l\'année.%s',
                    count($a['a_inscrire']), $a['precedente'], $a['annee'], $ecartes === [] ? '' : ' Écartés : '.implode(' ; ', $ecartes).'.');
            })
            ->modalSubmitActionLabel('Inscrire en M2')
            ->action(function (): void {
                try {
                    $bilan = app(PassageEnGroupe::class)->executer(auth()->user());
                } catch (InvalidArgumentException $e) {
                    Notification::make()->danger()->title('Passage non fait')->body($e->getMessage())->persistent()->send();

                    return;
                }
                $corps = array_filter([
                    $bilan['sans_option'] > 0 ? $bilan['sans_option'].' sans option reconnue : à inscrire depuis leur fiche.' : null,
                    $bilan['echecs'] === [] ? null : count($bilan['echecs']).' échec(s) : '.implode(' ', array_slice($bilan['echecs'], 0, 5)),
                ]);
                Notification::make()->title($bilan['inscrits'].' auditeur(s) inscrit(s) en M2')->body(implode(' ', $corps))
                    ->{$bilan['echecs'] === [] ? 'success' : 'warning'}()->persistent()->send();
            });
    }

    /** Spec lot 18, règle 5. */
    private function changerAnnee(): Action
    {
        return Action::make('changerAnnee')
            ->label('Changer l\'année en cours')
            ->icon('heroicon-o-calendar')
            ->color('gray')
            ->visible(fn (): bool => AnneeEnCours::peutChanger(auth()->user()))
            ->modalHeading('Année en cours')
            ->modalDescription('L\'année en cours est celle des nouvelles inscriptions, des réinscriptions et du passage en M2. Aucune inscription existante ne change. Le barème de la nouvelle année doit être saisi.')
            ->modalSubmitActionLabel('Changer l\'année')
            ->form(MessagesValidation::appliquer([
                Forms\Components\Select::make('annee')->label('Nouvelle année en cours')->required()->native(false)
                    ->options(function (): array {
                        $annee = AnneeEnCours::annee();
                        if ($annee === null) {
                            return [];
                        }
                        $suivante = AnneeEnCours::suivante($annee, 1);
                        $precedente = AnneeEnCours::suivante($annee, -1);

                        return [$suivante => $suivante.' (année suivante)', $precedente => $precedente.' (retour à l\'année précédente)'];
                    }),
                Forms\Components\Checkbox::make('confirmer')->label('Je confirme le changement de l\'année en cours')->accepted(),
            ]))
            ->action(function (array $data, Action $action): void {
                try {
                    AnneeEnCours::changer((string) $data['annee'], auth()->user());
                } catch (InvalidArgumentException $e) {
                    Notification::make()->danger()->title('Année inchangée')->body($e->getMessage())->persistent()->send();
                    $action->halt();

                    return;
                }
                Notification::make()->success()->title('Année en cours : '.$data['annee'])->send();
            });
    }
}
