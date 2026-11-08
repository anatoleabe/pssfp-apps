<?php

declare(strict_types=1);

namespace App\Filament\Resources\AuditeurResource;

use App\Models\RecuVersement;
use App\Models\Versement;
use App\Services\Scolarite\RecusVersements;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Actions\Action as TableAction;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Actions sur les reçus des versements (Scolarité lot 4), communes à la fiche auditeur et à la liste des reçus.
 * L'émission et l'annulation exigent `scolarite.recu_emettre` (service financier) ; le téléchargement, la lecture.
 */
final class RecuActions
{
    public static function peutEmettre(): bool
    {
        return (bool) auth()->user()?->can(RecusVersements::PERMISSION_EMETTRE);
    }

    public static function emettre(): TableAction
    {
        return TableAction::make('emettreRecu')->label('Émettre le reçu')->icon('heroicon-o-document-check')->color('success')
            ->visible(fn (Versement $record): bool => self::peutEmettre() && app(RecusVersements::class)->raisonRefus($record) === null)
            ->requiresConfirmation()->modalHeading('Émettre le reçu de ce versement')
            ->modalDescription(fn (Versement $record): string => 'Un reçu numéroté de '.RecusVersements::fcfa($record->montant).' est émis à votre nom. Il ne se modifie plus : une erreur se corrige par une écriture d\'annulation.')
            ->action(function (Versement $record): void {
                $recu = self::executer(fn (): RecuVersement => app(RecusVersements::class)->emettre($record, auth()->user()));
                Notification::make()->success()->title('Reçu '.$recu->numero.' émis')->body('L\'auditeur peut le télécharger dans son espace.')->send();
            });
    }

    /** Télécharge le dernier reçu du versement (en vigueur, ou annulé avec sa mention). */
    public static function telechargerDepuisVersement(): TableAction
    {
        return TableAction::make('telechargerRecu')->label('Reçu (PDF)')->icon('heroicon-o-arrow-down-tray')->color('gray')
            ->visible(fn (Versement $record): bool => $record->recus->isNotEmpty())
            ->action(fn (Versement $record): StreamedResponse => self::reponse($record->recus->sortByDesc('id')->first()));
    }

    public static function annulerDepuisVersement(): TableAction
    {
        return TableAction::make('annulerRecu')->label('Annuler le reçu')->icon('heroicon-o-document-minus')->color('danger')
            ->visible(fn (Versement $record): bool => self::peutEmettre() && $record->recuEnVigueur() !== null)
            ->modalHeading(fn (Versement $record): string => 'Annuler le reçu '.$record->recuEnVigueur()?->numero)
            ->modalDescription('Le reçu n\'est pas modifié : une écriture d\'annulation numérotée est passée à votre nom. Un nouveau reçu peut ensuite être émis.')
            ->form(self::formulaireMotif())
            ->action(function (Versement $record, array $data): void {
                $a = self::executer(fn () => app(RecusVersements::class)->annuler($record->recuEnVigueur(), (string) $data['motif'], auth()->user()));
                Notification::make()->success()->title('Écriture '.$a->numero.' passée')->send();
            });
    }

    /** @return list<Forms\Components\Component> */
    public static function formulaireMotif(): array
    {
        return MessagesValidation::appliquer([
            Forms\Components\Textarea::make('motif')->label('Motif de l\'annulation')->required()->minLength(5)->maxLength(500)->rows(3),
        ], ['min' => 'Le motif compte au moins 5 caractères.']);
    }

    public static function reponse(RecuVersement $recu): StreamedResponse
    {
        $pdf = app(RecusVersements::class)->pdf($recu);

        return response()->streamDownload(function () use ($pdf): void {
            echo $pdf;
        }, $recu->nomFichier(), ['Content-Type' => 'application/pdf']);
    }

    /**
     * Exécute une opération du service ; un refus motivé garde la fenêtre ouverte.
     *
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    public static function executer(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (InvalidArgumentException $e) {
            Notification::make()->danger()->title('Opération refusée')->body($e->getMessage())->persistent()->send();

            throw new Halt;
        }
    }
}
