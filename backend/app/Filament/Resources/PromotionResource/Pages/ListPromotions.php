<?php

declare(strict_types=1);

namespace App\Filament\Resources\PromotionResource\Pages;

use App\Filament\Resources\PromotionResource;
use App\Services\Scolarite\AnneeEnCours;
use App\Services\Scolarite\PromotionsAnterieures;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use InvalidArgumentException;

class ListPromotions extends ListRecords
{
    protected static string $resource = PromotionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Spec de la réinscription des anciens, §5.2 : à la demande, pour rattacher un ancien réinscrit.
            Actions\Action::make('creerAnterieure')
                ->label('Créer une promotion antérieure')
                ->icon('heroicon-o-plus')
                ->color('gray')
                ->visible(fn (): bool => (bool) auth()->user()?->can(PromotionsAnterieures::PERMISSION))
                ->modalDescription(fn (): string => 'Une promotion close, sans appel ni communiqué, invisible sur le portail. Elle sert à rattacher un ancien auditeur réinscrit depuis l\'archive ACMA ; aucun matricule n\'y est attribué. Promotion en cours : '
                    .(AnneeEnCours::promotionCourante()?->promotion_numero ?? '—').'e ('.(AnneeEnCours::annee() ?? '—').').')
                ->form([
                    Forms\Components\TextInput::make('numero')->label('Numéro de la promotion')->integer()->minValue(1)->required()->live(onBlur: true)
                        ->afterStateUpdated(fn (Forms\Set $set, $state) => filled($state) ? $set('annee', PromotionsAnterieures::anneeProposee((int) $state)) : null),
                    Forms\Components\TextInput::make('annee')->label('Année académique d\'entrée')->required()->regex('/^\d{4}-\d{4}$/')
                        ->helperText('Proposée : 2012 + numéro (P13 = 2025-2026). Modifiable.'),
                    Forms\Components\Textarea::make('motif')->label('Motif')->required()->minLength(PromotionsAnterieures::MOTIF_MINIMUM)->maxLength(500)->rows(2)
                        ->helperText('Gardé au journal : n\'y écrivez aucune donnée d\'identité.'),
                ])
                ->action(function (array $data, Actions\Action $action): void {
                    try {
                        $p = app(PromotionsAnterieures::class)->creer((int) $data['numero'], (string) $data['annee'], (string) $data['motif'], auth()->user());
                    } catch (InvalidArgumentException $e) {
                        Notification::make()->danger()->title('Création impossible')->body($e->getMessage())->send();
                        $action->halt();

                        return;
                    }
                    Notification::make()->success()->title($p->promotion_numero.'e promotion créée')->body('Réglez ses lettres si besoin avant d\'y rattacher un ancien.')->send();
                }),
        ];
    }
}
