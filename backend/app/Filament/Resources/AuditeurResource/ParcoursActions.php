<?php

declare(strict_types=1);

namespace App\Filament\Resources\AuditeurResource;

use App\Models\Auditeur;
use App\Models\DocumentDelivre;
use App\Models\InscriptionPedagogique;
use App\Models\Maquette;
use App\Models\Parcours;
use App\Services\Lmd\DerogationsRattrapage;
use App\Services\Lmd\DocumentsOfficiels;
use App\Services\Lmd\InscriptionsPedagogiques;
use App\Services\Lmd\PermissionsLmd;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use InvalidArgumentException;

/** Gestes LMD sur la fiche auditeur (spec module 4, lot 4) : inscription pédagogique et changement de parcours. */
final class ParcoursActions
{
    public static function inscrire(): Action
    {
        return Action::make('inscrirePedagogique')
            ->label('Inscrire au parcours')
            ->icon('heroicon-o-academic-cap')
            ->visible(fn (Auditeur $record): bool => $record->inscriptionCourante !== null
                && (bool) auth()->user()?->can(PermissionsLmd::INSCRIRE))
            ->requiresConfirmation()
            ->modalDescription('Inscrit l\'auditeur à toutes les UE de la maquette validée de son parcours, pour l\'année en cours. Sans effet sur les UE déjà inscrites.')
            ->action(function (Auditeur $record): void {
                $r = app(InscriptionsPedagogiques::class)->inscrire($record->inscriptionCourante, auth()->user());
                match ($r['issue']) {
                    InscriptionsPedagogiques::SANS_PARCOURS => Notification::make()->warning()->title('Parcours introuvable')
                        ->body('La spécialité du dossier ne correspond à aucune option active : choisissez le parcours avec « Changer de parcours ».')->send(),
                    InscriptionsPedagogiques::SANS_MAQUETTE => Notification::make()->warning()->title('Maquette non validée')
                        ->body("Le parcours {$r['parcours']} n'a pas encore de maquette validée pour cette année.")->send(),
                    default => Notification::make()->success()->title('Inscription pédagogique')
                        ->body($r['ues'] > 0 ? "{$r['ues']} UE inscrites ({$r['parcours']})." : 'L\'auditeur était déjà inscrit à toutes les UE.')->send(),
                };
            });
    }

    public static function changerParcours(): Action
    {
        return Action::make('changerParcours')
            ->label('Changer de parcours')
            ->icon('heroicon-o-arrows-right-left')
            ->visible(fn (Auditeur $record): bool => $record->inscriptionCourante !== null
                && (bool) auth()->user()?->can(PermissionsLmd::CHANGER_PARCOURS))
            ->modalDescription('Les UE de l\'ancien parcours sont retirées et l\'auditeur est inscrit à celles du nouveau, si sa maquette est validée.')
            ->form(fn (Auditeur $record): array => MessagesValidation::appliquer([
                Forms\Components\Select::make('parcours_id')->label('Nouveau parcours')->required()->native(false)
                    ->options(Parcours::query()->where('actif', true)->where('niveau', $record->inscriptionCourante?->niveau)
                        ->whereKeyNot((int) $record->inscriptionCourante?->parcours_id)->orderBy('ordre')->get()
                        ->mapWithKeys(fn (Parcours $p): array => [$p->id => $p->libelleComplet()])->all()),
                Forms\Components\Textarea::make('motif')->label('Motif')->required()->minLength(10)->maxLength(500),
            ]))
            ->action(function (Auditeur $record, array $data): void {
                try {
                    $r = app(InscriptionsPedagogiques::class)->changerParcours(
                        $record->inscriptionCourante, Parcours::query()->findOrFail((int) $data['parcours_id']), auth()->user(), (string) $data['motif']);
                } catch (InvalidArgumentException $e) {
                    Notification::make()->danger()->title('Changement refusé')->body($e->getMessage())->send();

                    return;
                }
                Notification::make()->success()->title('Parcours changé')
                    ->body($r['issue'] === InscriptionsPedagogiques::INSCRITE ? "{$r['ues']} UE inscrites ({$r['parcours']})." : 'La maquette de ce parcours n\'est pas encore validée : l\'inscription pédagogique se fera à sa validation.')
                    ->send();
            });
    }

    public static function derogationRattrapage(): Action
    {
        return Action::make('derogationRattrapage')
            ->label('Dérogation au rattrapage')
            ->icon('heroicon-o-document-check')
            ->visible(fn (Auditeur $record): bool => $record->inscriptionCourante !== null
                && (bool) auth()->user()?->can(PermissionsLmd::DEROGATION_RATTRAPAGE))
            ->modalDescription('Autorise le rattrapage d\'une UE malgré des notes incomplètes (D11). L\'acte est consigné.')
            ->form(fn (Auditeur $record): array => MessagesValidation::appliquer([
                Forms\Components\Select::make('inscription_pedagogique_id')->label('UE')->required()->native(false)
                    ->options($record->inscriptionCourante?->inscriptionsPedagogiques()->doesntHave('derogationRattrapage')
                        ->with('maquetteUe.ue')->get()
                        ->mapWithKeys(fn (InscriptionPedagogique $ip): array => [$ip->id => $ip->maquetteUe?->ue?->code.' — '.$ip->maquetteUe?->ue?->intitule])->all() ?? []),
                Forms\Components\TextInput::make('accordee_par')->label('Autorité')->required()->maxLength(255)->default('Le Chef de la structure'),
                Forms\Components\TextInput::make('reference_acte')->label('Référence de l\'acte')->required()->maxLength(120),
                Forms\Components\DatePicker::make('date_acte')->label('Date de l\'acte')->required()->native(false)->displayFormat('d/m/Y')
                    ->maxDate(now()->endOfDay()),
                Forms\Components\Textarea::make('motif')->label('Motif')->required()->minLength(10)->maxLength(500),
            ]))
            ->action(function (Auditeur $record, array $data): void {
                $ip = $record->inscriptionCourante?->inscriptionsPedagogiques()->findOrFail((int) $data['inscription_pedagogique_id']);
                try {
                    app(DerogationsRattrapage::class)->accorder($ip, $data, auth()->user());
                } catch (InvalidArgumentException $e) {
                    Notification::make()->danger()->title('Dérogation refusée')->body($e->getMessage())->send();

                    return;
                }
                Notification::make()->success()->title('Dérogation enregistrée')->send();
            });
    }

    public static function leverRestriction(): Action
    {
        return Action::make('leverRestriction')
            ->label('Lever la restriction sur les documents')
            ->icon('heroicon-o-lock-open')
            ->visible(fn (Auditeur $record): bool => $record->inscriptionCourante !== null
                && (bool) auth()->user()?->can(PermissionsLmd::DOCUMENTS_LEVER))
            ->modalDescription('Autorise l\'attestation de réussite et le certificat de scolarité malgré une échéance en retard (D9). La levée est tracée.')
            ->form(MessagesValidation::appliquer([
                Forms\Components\Textarea::make('motif')->label('Motif')->required()->minLength(10)->maxLength(500),
            ]))
            ->action(function (Auditeur $record, array $data): void {
                try {
                    app(DocumentsOfficiels::class)->leverRestriction($record->inscriptionCourante, (string) $data['motif'], auth()->user());
                } catch (InvalidArgumentException $e) {
                    Notification::make()->danger()->title('Levée refusée')->body($e->getMessage())->send();

                    return;
                }
                Notification::make()->success()->title('Restriction levée pour l\'année en cours')->send();
            });
    }

    public static function revoquerDocument(): Action
    {
        return Action::make('revoquerDocument')
            ->label('Révoquer un document')
            ->icon('heroicon-o-no-symbol')
            ->color('danger')
            ->visible(fn (Auditeur $record): bool => (bool) auth()->user()?->can(PermissionsLmd::DOCUMENTS_REVOQUER)
                && DocumentDelivre::query()->where('auditeur_id', $record->id)->whereNull('revoque_at')->exists())
            ->modalDescription('Le document reste enregistré, marqué « révoqué » à la vérification. L\'auditeur pourra en obtenir un nouveau.')
            ->form(fn (Auditeur $record): array => MessagesValidation::appliquer([
                Forms\Components\Select::make('document')->label('Document')->required()->native(false)
                    ->options(DocumentDelivre::query()->where('auditeur_id', $record->id)->whereNull('revoque_at')->latest('id')->get()
                        ->mapWithKeys(fn (DocumentDelivre $d): array => [$d->id => $d->numero.' — '.DocumentDelivre::TYPES[$d->type]])->all()),
                Forms\Components\Textarea::make('motif')->label('Motif')->required()->minLength(10)->maxLength(500),
            ]))
            ->action(function (Auditeur $record, array $data): void {
                $document = DocumentDelivre::query()->where('auditeur_id', $record->id)->findOrFail((int) $data['document']);
                try {
                    app(DocumentsOfficiels::class)->revoquer($document, (string) $data['motif'], auth()->user());
                } catch (InvalidArgumentException $e) {
                    Notification::make()->danger()->title('Révocation refusée')->body($e->getMessage())->send();

                    return;
                }
                Notification::make()->success()->title('Document '.$document->numero.' révoqué')->send();
            });
    }

    /** Ligne « Parcours LMD » de la fiche : parcours, maquette, UE inscrites. */
    public static function resume(Auditeur $auditeur): string
    {
        $inscription = $auditeur->inscriptionCourante;
        if ($inscription === null) {
            return 'Aucune inscription pour l\'année en cours.';
        }
        $parcours = $inscription->parcours;
        $ues = $inscription->inscriptionsPedagogiques()->count();
        if ($parcours === null) {
            return 'Pas encore inscrit à un parcours.';
        }
        $maquette = Maquette::reference($inscription->annee_academique, $parcours->id);

        return $parcours->libelleComplet().' · '.($maquette === null ? 'maquette '.$inscription->annee_academique.' non validée' : $ues.' UE inscrites');
    }
}
