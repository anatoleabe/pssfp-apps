<?php

declare(strict_types=1);

namespace App\Filament\Resources\AuditeurResource;

use App\Models\Auditeur;
use App\Models\BaremeScolarite;
use App\Services\Lmd\ChangementSpecialite;
use App\Services\Lmd\InscriptionsPedagogiques;
use Filament\Forms;
use Filament\Infolists\Components\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use InvalidArgumentException;

/**
 * « Changer la spécialité » (27/11/2026), sur l'entrée Spécialité de l'onglet Identité : réservé à l'administrateur.
 * En M2, le parcours de l'année suit et l'inscription pédagogique est refaite (cf. ChangementSpecialite).
 */
final class SpecialiteActions
{
    /** Entrée Spécialité de la fiche, avec le crayon du changement. */
    public static function entree(): TextEntry
    {
        return TextEntry::make('candidature.specialite')->label('Spécialité')->placeholder('—')
            ->suffixAction(self::changer());
    }

    public static function changer(): Action
    {
        return Action::make('changerSpecialite')
            ->label('Changer la spécialité')
            ->tooltip('Changer la spécialité')
            ->icon('heroicon-o-pencil-square')
            ->visible(fn (Auditeur $record): bool => $record->candidature !== null
                && auth()->user()?->can(ChangementSpecialite::PERMISSION) === true)
            ->modalHeading('Changer la spécialité')
            ->modalDescription('La spécialité du dossier change. En Master 2, le parcours de l\'année suit : les UE de l\'ancienne option sont retirées et l\'auditeur est inscrit à celles de la nouvelle, si sa maquette est validée.')
            ->modalSubmitActionLabel('Changer la spécialité')
            ->fillForm(fn (Auditeur $record): array => ['specialite' => $record->candidature?->specialite])
            ->form(fn (Auditeur $record): array => MessagesValidation::appliquer([
                Forms\Components\Select::make('specialite')->label('Nouvelle spécialité')->required()->native(false)->live()
                    ->options(ChangementSpecialite::options())
                    ->helperText(fn (Forms\Get $get): ?string => self::consequence($record, (string) $get('specialite'))),
                Forms\Components\Textarea::make('motif')->label('Motif')->required()->minLength(10)->maxLength(500)->rows(2)
                    ->placeholder('Ex. : erreur de saisie à l\'inscription, demande écrite de l\'auditeur du …')
                    ->helperText('Conservé dans le journal avec l\'ancienne spécialité.'),
            ]))
            ->action(function (Auditeur $record, array $data, Action $action): void {
                try {
                    $r = app(ChangementSpecialite::class)->changer($record, (string) $data['specialite'], (string) $data['motif'], auth()->user());
                } catch (InvalidArgumentException $e) {
                    Notification::make()->danger()->title('Spécialité non changée')->body($e->getMessage())->send();
                    $action->halt();

                    return;
                }
                $record->refresh();
                Notification::make()->success()->title('Spécialité changée')->body(self::bilan($r))->send();
            });
    }

    private static function consequence(Auditeur $auditeur, string $specialite): ?string
    {
        if ($specialite === '') {
            return null;
        }
        $cible = app(ChangementSpecialite::class)->parcoursCible($auditeur, $specialite);
        if ($cible === null) {
            return $auditeur->inscriptionCourante?->niveau === BaremeScolarite::NIVEAU_M2
                ? 'Aucune option de Master 2 active ne correspond à cette spécialité.'
                : 'Seul le dossier change : l\'auditeur n\'est pas inscrit cette année en Master 2.';
        }

        return $cible->id === $auditeur->inscriptionCourante?->parcours_id
            ? 'Le parcours de l\'année est déjà '.$cible->code.' : seul le dossier change.'
            : 'Le parcours de l\'année deviendra '.$cible->code.'.';
    }

    /** @param  array{specialite: string, parcours: ?string, issue: ?string, ues: int}  $r */
    private static function bilan(array $r): string
    {
        return match ($r['issue']) {
            null => $r['specialite'].'.',
            InscriptionsPedagogiques::SANS_MAQUETTE => $r['specialite'].'. Parcours '.$r['parcours'].' : la maquette n\'est pas encore validée, l\'inscription pédagogique se fera à sa validation.',
            default => $r['specialite'].'. Parcours '.$r['parcours'].' : '.$r['ues'].' UE inscrites.',
        };
    }
}
