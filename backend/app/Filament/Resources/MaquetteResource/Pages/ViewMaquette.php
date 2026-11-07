<?php

declare(strict_types=1);

namespace App\Filament\Resources\MaquetteResource\Pages;

use App\Filament\Resources\AuditeurResource\MessagesValidation;
use App\Filament\Resources\MaquetteResource;
use App\Models\Maquette;
use App\Services\Lmd\InscriptionsPedagogiques;
use App\Services\Lmd\PermissionsLmd;
use App\Services\Lmd\ReconductionMaquette;
use App\Services\Lmd\ValidationMaquette;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use InvalidArgumentException;

class ViewMaquette extends ViewRecord
{
    protected static string $resource = MaquetteResource::class;

    public function getTitle(): string
    {
        /** @var Maquette $maquette */
        $maquette = $this->getRecord();

        return 'Maquette '.$maquette->libelle();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('valider')
                ->label('Valider la maquette')
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->visible(fn (Maquette $record): bool => $record->statut === Maquette::BROUILLON && (bool) auth()->user()?->can(PermissionsLmd::VALIDER_MAQUETTE))
                ->requiresConfirmation()
                ->modalDescription('Validée, la maquette ne se modifie plus et sert de référence aux inscriptions pédagogiques de l\'année.')
                ->action(function (Maquette $record): void {
                    try {
                        app(ValidationMaquette::class)->valider($record, auth()->user());
                    } catch (InvalidArgumentException $e) {
                        Notification::make()->danger()->title('Validation refusée')->body($e->getMessage())->persistent()->send();

                        return;
                    }
                    $r = app(InscriptionsPedagogiques::class)->inscrireMaquette($record->refresh(), auth()->user()?->can(PermissionsLmd::INSCRIRE) ? auth()->user() : null);
                    Notification::make()->success()->title('Maquette validée')
                        ->body($r['inscrites'] > 0 ? "{$r['inscrites']} auditeur(s) inscrit(s) aux UE de la maquette." : 'Aucun auditeur de l\'année n\'était à inscrire sur ce parcours.')->send();
                    $this->refreshFormData(['statut']);
                }),
            Action::make('rouvrir')
                ->label('Rouvrir en brouillon')
                ->icon('heroicon-o-lock-open')
                ->color('gray')
                ->visible(fn (Maquette $record): bool => $record->statut === Maquette::VALIDEE && (bool) auth()->user()?->can(PermissionsLmd::VALIDER_MAQUETTE))
                ->modalDescription('Possible tant qu\'aucun auditeur n\'est inscrit sur cette maquette.')
                ->form(MessagesValidation::appliquer([
                    Forms\Components\Textarea::make('motif')->label('Motif')->required()->minLength(10)->maxLength(500),
                ]))
                ->action(function (Maquette $record, array $data): void {
                    try {
                        app(ValidationMaquette::class)->rouvrir($record, auth()->user(), (string) $data['motif']);
                    } catch (InvalidArgumentException $e) {
                        Notification::make()->danger()->title('Réouverture refusée')->body($e->getMessage())->send();

                        return;
                    }
                    Notification::make()->success()->title('Maquette rouverte en brouillon')->send();
                    $this->refreshFormData(['statut']);
                }),
            Action::make('reconduire')
                ->label('Reconduire')
                ->icon('heroicon-o-document-duplicate')
                ->color('gray')
                ->visible(fn (): bool => (bool) auth()->user()?->can(PermissionsLmd::REFERENTIEL))
                ->modalDescription('Copie cette maquette, en brouillon, pour une autre année.')
                ->form(MessagesValidation::appliquer([
                    Forms\Components\TextInput::make('annee')->label('Année académique')->required()->regex('/^(\d{4})-(\d{4})$/')
                        ->default(fn (Maquette $record): string => self::anneeSuivante($record->annee_academique)),
                ]))
                ->action(function (Maquette $record, array $data): void {
                    try {
                        $copie = app(ReconductionMaquette::class)->reconduire($record, (string) $data['annee'], auth()->user());
                    } catch (InvalidArgumentException $e) {
                        Notification::make()->danger()->title('Reconduction refusée')->body($e->getMessage())->send();

                        return;
                    }
                    Notification::make()->success()->title('Maquette reconduite en brouillon')->send();
                    $this->redirect(MaquetteResource::getUrl('view', ['record' => $copie]));
                }),
        ];
    }

    public static function anneeSuivante(string $annee): string
    {
        [$debut] = explode('-', $annee) + [0 => '2026'];

        return ((int) $debut + 1).'-'.((int) $debut + 2);
    }
}
