<?php

declare(strict_types=1);

namespace App\Filament\Resources\AuditeurResource;

use App\Filament\Resources\DemandeAutorisationResource;
use App\Models\Auditeur;
use App\Models\DeblocageAcces;
use App\Models\DemandeAutorisation;
use App\Models\InscriptionAnnuelle;
use App\Models\User;
use App\Services\Messagerie\Demandes;
use App\Services\Messagerie\TypesDemande;
use App\Services\Scolarite\DeblocagesAcces;
use App\Services\Scolarite\DemandeMoratoire;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Actions\Action as ActionNotification;
use Filament\Notifications\Notification;
use InvalidArgumentException;

/**
 * Demande de moratoire depuis la fiche de l'auditeur (spec module 4, lot A3, §10) : l'agent, qui n'accorde pas
 * de moratoire, le demande au responsable par le circuit du lot 14c. Le responsable garde « Débloquer l'accès ».
 * Les contrôles sont ceux de Demandes::soumettre et de DeblocagesAcces, refaits à l'exécution.
 */
final class DemandeMoratoireActions
{
    public static function demander(): Action
    {
        return Action::make('demanderMoratoire')
            ->label('Demander un moratoire')
            ->icon('heroicon-o-hand-raised')
            ->visible(fn (Auditeur $record): bool => self::peutDemander(auth()->user()) && AccesAnticipe::deblocable($record) && self::enAttente($record) === null)
            ->modalHeading('Demander un moratoire au responsable de scolarité')
            ->modalDescription('Le responsable de scolarité approuve ou rejette. S\'il approuve, le moratoire est accordé à son nom, à l\'échéance proposée, et l\'auditeur reçoit ses accès par SMS et par e-mail. Vous êtes prévenu de la décision.')
            ->modalSubmitActionLabel('Soumettre la demande')
            ->form(MessagesValidation::appliquer([
                Forms\Components\DatePicker::make('echeance')->label('Échéance proposée')->required()->native(false)->displayFormat('d/m/Y')
                    ->minDate(InscriptionAnnuelle::aujourdhui()->addDay()->toDateString())
                    // Fin de journée : le sélecteur renvoie une heure (cf. la date d'un versement).
                    ->maxDate(DeblocagesAcces::echeanceMax(DeblocageAcces::MORATOIRE)?->endOfDay()->toDateTimeString())
                    ->helperText('Obligatoire, '.DeblocagesAcces::JOURS_MAX[DeblocageAcces::MORATOIRE].' jours au plus. Le responsable l\'approuve telle quelle, ou rejette la demande.'),
                Forms\Components\Textarea::make('motif')->label('Motif')->required()
                    ->minLength(DeblocagesAcces::MOTIF_MIN)->maxLength(DemandeMoratoire::MOTIF_MAX)->rows(3),
                AuditeurActions::champJustificatif('Demande écrite de l\'auditeur (facultative)', 10,
                    'Le stockage n\'a pas répondu : la pièce n\'est pas enregistrée. Réessayez dans quelques instants.'),
            ], [
                'min' => 'Le motif compte au moins '.DeblocagesAcces::MOTIF_MIN.' caractères.',
                'after_or_equal' => 'L\'échéance doit être postérieure à aujourd\'hui.',
                'before_or_equal' => 'Échéance trop lointaine : '.DeblocagesAcces::JOURS_MAX[DeblocageAcces::MORATOIRE].' jours au plus.',
                'uploaded' => 'Le fichier n\'a pas pu être envoyé : 10 Mo au plus pour la pièce.',
            ]))
            ->action(function (Auditeur $record, array $data, Action $action): void {
                try {
                    // Seule une pièce déposée par ce formulaire, dans cette requête, se rattache à la demande.
                    if (filled($data['justificatif_path'] ?? null) && ! AuditeurActions::depose((string) $data['justificatif_path'])) {
                        throw new InvalidArgumentException('La pièce jointe est invalide : déposez-la à nouveau.');
                    }
                    $inscription = $record->inscriptionCourante ?? throw new InvalidArgumentException('Aucune inscription en cours.');
                    /** @var User $agent */
                    $agent = auth()->user();
                    $demande = app(Demandes::class)->soumettre($agent, TypesDemande::ACCORDER_MORATOIRE, $inscription->id, (string) ($data['motif'] ?? ''), null, [
                        'echeance' => $data['echeance'] ?? null, 'justificatif_path' => $data['justificatif_path'] ?? null,
                    ]);
                } catch (InvalidArgumentException $e) {
                    AuditeurActions::supprimerScanOrphelin($data['justificatif_path'] ?? null);
                    Notification::make()->danger()->title('Demande non soumise')->body($e->getMessage())->send();
                    $action->halt();

                    return;
                }
                Notification::make()->success()->title('Demande '.$demande->numero.' soumise')
                    ->body('Le responsable de scolarité est prévenu ; vous le serez de sa décision.')
                    ->actions([ActionNotification::make('voir')->label('Voir la demande')->url(Demandes::lien($demande))])
                    ->send();
            });
    }

    /** Lien vers la demande qui attend sur l'inscription courante, pour qui peut la voir (le demandeur, les décideurs). */
    public static function enAttenteAction(): Action
    {
        return Action::make('demandeMoratoireEnAttente')
            ->label(fn (Auditeur $record): string => 'Demande de moratoire en attente ('.self::enAttente($record)?->numero.')')
            ->icon('heroicon-o-clock')
            ->visible(fn (Auditeur $record): bool => ($d = self::enAttente($record)) !== null && DemandeAutorisationResource::canView($d))
            ->url(fn (Auditeur $record): ?string => ($d = self::enAttente($record)) === null ? null : Demandes::lien($d));
    }

    /** Qui peut viser le type sans détenir le droit d'accorder : l'agent. Le responsable agit directement. */
    private static function peutDemander(mixed $u): bool
    {
        return $u instanceof User && TypesDemande::peutViser($u, TypesDemande::ACCORDER_MORATOIRE)
            && ! $u->can(TypesDemande::type(TypesDemande::ACCORDER_MORATOIRE)['permission']);
    }

    private static function enAttente(Auditeur $record): ?DemandeAutorisation
    {
        $inscription = $record->inscriptionCourante;

        return $inscription === null ? null : DemandeAutorisation::query()
            ->where('type', TypesDemande::ACCORDER_MORATOIRE)->where('entite_id', $inscription->id)
            ->whereIn('statut', DemandeAutorisation::EN_ATTENTE)->first();
    }
}
