<?php

declare(strict_types=1);

namespace App\Filament\Resources\ArchiveAcmaResource;

use App\Filament\Resources\AuditeurResource;
use App\Models\AcmaDossier;
use App\Models\AcmaInscription;
use App\Models\AcmaRapprochement;
use App\Models\Auditeur;
use App\Services\Acma\ArchivesAcma;
use App\Services\Acma\ErreurAcma;
use App\Services\Acma\Reprise\RapportsReprise;
use App\Support\Journaux;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action as TableAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Gestes de l'écran « Archives ACMA » (lot 15d) : arbitrer le rapprochement, compléter (date de naissance, décision
 * transcrite d'un PV signé), télécharger les classeurs de la reprise. Chaque geste revérifie sa permission dans
 * ArchivesAcma ; aucun ne modifie l'archive elle-même, aucun n'envoie quoi que ce soit.
 */
final class ArchiveAcmaActions
{
    public const PERMISSION_CLASSEUR_RAPPROCHEMENT = ArchivesAcma::RAPPROCHER;

    /** Le rapport des écarts 2026-2027 se télécharge avec la permission d'export de la scolarité (§7.10). */
    public const PERMISSION_CLASSEUR_ECARTS = 'scolarite.export';

    public static function ficheAuditeur(): Action
    {
        return Action::make('ficheAuditeur')
            ->label('Fiche de l\'auditeur')
            ->icon('heroicon-o-user')
            ->color('gray')
            ->visible(fn (AcmaDossier $record): bool => $record->estLie() && $record->auditeur !== null && AuditeurResource::canAccess())
            ->url(fn (AcmaDossier $record): ?string => $record->auditeur === null ? null : AuditeurResource::getUrl('view', ['record' => $record->auditeur]));
    }

    public static function arbitrer(): Action
    {
        return Action::make('arbitrer')
            ->label('Arbitrer le rapprochement')
            ->icon('heroicon-o-link')
            ->visible(fn (): bool => (bool) auth()->user()?->can(ArchivesAcma::RAPPROCHER))
            ->modalHeading('Arbitrer le rapprochement avec la plateforme')
            ->modalDescription('Un lien met les notes de ce dossier sur la fiche d\'un auditeur : il ne se pose qu\'après vérification de l\'identité (pièce, dossier papier). La décision et son motif restent dans l\'historique.')
            ->modalSubmitActionLabel('Enregistrer l\'arbitrage')
            ->fillForm(fn (AcmaDossier $record): array => [
                'auditeur' => AcmaRapprochement::query()->where('dossier_id', $record->id)->whereNotNull('auditeur_id')->latest('id')->first()?->auditeur?->uuid,
            ])
            ->form([
                Forms\Components\Radio::make('decision')->label('Décision')->required()->options(AcmaRapprochement::DECISIONS)->live(),
                Forms\Components\Select::make('auditeur')->label('Auditeur de la plateforme (matricule)')
                    ->searchable()->native(false)
                    ->required(fn (Forms\Get $get): bool => $get('decision') === 'lie')
                    // Chercher un auditeur, c'est lire le module Scolarité : il faut y avoir accès.
                    ->visible(fn (Forms\Get $get): bool => $get('decision') === 'lie' && AuditeurResource::canAccess())
                    ->helperText(fn (): ?string => AuditeurResource::canAccess() ? null : 'Lier exige l\'accès aux auditeurs (module Scolarité).')
                    ->getSearchResultsUsing(fn (string $search): array => AuditeurResource::canAccess() ? self::auditeurs($search) : [])
                    ->getOptionLabelUsing(fn (?string $value): ?string => self::libelleAuditeur(Auditeur::query()->where('uuid', $value)->first())),
                Forms\Components\Textarea::make('motif')->label('Motif')->required()->minLength(ArchivesAcma::MOTIF_MINIMUM)->maxLength(500)->rows(3),
            ])
            ->action(function (AcmaDossier $record, array $data, Action $action): void {
                $auditeur = filled($data['auditeur'] ?? null) ? Auditeur::query()->where('uuid', $data['auditeur'])->first() : null;
                self::executer($action, fn () => app(ArchivesAcma::class)->arbitrer($record, (string) $data['decision'], $auditeur, (string) $data['motif'], auth()->user()),
                    'Rapprochement arbitré.');
            });
    }

    public static function transcrireDecision(): Action
    {
        return Action::make('transcrireDecision')
            ->label('Transcrire une décision d\'un PV signé')
            ->icon('heroicon-o-document-check')
            ->visible(fn (AcmaDossier $record): bool => (bool) auth()->user()?->can(ArchivesAcma::COMPLETER)
                && $record->inscriptions()->whereNull('retire_de_la_source_at')->where('decision', 'non_enregistree')->exists())
            ->modalHeading('Transcrire la décision d\'un PV signé')
            ->modalDescription('Pour une année sans décision enregistrée dans ACMA. On recopie un acte qui existe : ce n\'est pas une délibération. La moyenne du PV (ou son absence) s\'écrit toujours avec la décision.')
            ->modalSubmitActionLabel('Transcrire')
            ->form(fn (AcmaDossier $record): array => [
                Forms\Components\Select::make('inscription')->label('Année et niveau')->required()->native(false)
                    ->options($record->inscriptions()->whereNull('retire_de_la_source_at')->where('decision', 'non_enregistree')->orderBy('annee')->get()
                        ->mapWithKeys(fn (AcmaInscription $i): array => [$i->uuid => $i->annee.' · '.$i->niveau.' ('.$i->niveau_acma.')'])->all()),
                Forms\Components\Radio::make('decision')->label('Décision du PV')->required()->options(['admis' => 'Admis', 'non_admis' => 'Non admis']),
                Forms\Components\Checkbox::make('sans_moyenne')->label('Le PV ne porte pas de moyenne')->live(),
                Forms\Components\TextInput::make('moyenne')->label('Moyenne portée au PV')->numeric()->minValue(0)->maxValue(20)
                    ->required(fn (Forms\Get $get): bool => ! $get('sans_moyenne'))->hidden(fn (Forms\Get $get): bool => (bool) $get('sans_moyenne')),
                Forms\Components\TextInput::make('reference')->label('Référence du PV signé')->required()->maxLength(100)->placeholder('PV n° …'),
                Forms\Components\Textarea::make('motif')->label('Motif')->required()->minLength(ArchivesAcma::MOTIF_MINIMUM)->maxLength(500)->rows(2),
            ])
            ->action(function (AcmaDossier $record, array $data, Action $action): void {
                $inscription = $record->inscriptions()->where('uuid', $data['inscription'] ?? null)->first();
                self::executer($action, function () use ($inscription, $data): void {
                    if ($inscription === null) {
                        throw new InvalidArgumentException('Choisissez l\'année à compléter.');
                    }
                    $sans = (bool) ($data['sans_moyenne'] ?? false);
                    app(ArchivesAcma::class)->transcrireDecision($inscription, (string) $data['decision'], $sans ? null : (isset($data['moyenne']) ? (string) $data['moyenne'] : null),
                        $sans, (string) $data['reference'], (string) $data['motif'], auth()->user());
                }, 'Décision transcrite.');
            });
    }

    public static function completerNaissance(): Action
    {
        return Action::make('completerNaissance')
            ->label('Compléter la date de naissance')
            ->icon('heroicon-o-calendar')
            ->visible(fn (): bool => (bool) auth()->user()?->can(ArchivesAcma::COMPLETER))
            ->modalHeading('Compléter la date de naissance d\'après le dossier papier')
            ->modalSubmitActionLabel('Compléter')
            ->form([
                Forms\Components\DatePicker::make('date')->label('Date de naissance')->required()->native(false)->displayFormat('d/m/Y')
                    ->minDate('1920-01-01')->maxDate(now()->toDateString()),
                Forms\Components\TextInput::make('reference')->label('Pièce (référence du dossier papier)')->required()->maxLength(100),
                Forms\Components\Textarea::make('motif')->label('Motif')->required()->minLength(ArchivesAcma::MOTIF_MINIMUM)->maxLength(500)->rows(2),
            ])
            ->action(function (AcmaDossier $record, array $data, Action $action): void {
                self::executer($action, fn () => app(ArchivesAcma::class)->completerDateNaissance($record, substr((string) $data['date'], 0, 10), (string) $data['reference'], (string) $data['motif'], auth()->user()),
                    'Date de naissance complétée.');
            });
    }

    /** Dernier classeur de la reprise (stockage privé), téléchargé par un titulaire de la permission ; journalisé. */
    public static function classeur(string $nom, string $libelle, string $prefixe, string $permission): Action
    {
        $dernier = fn (): ?string => collect(Storage::disk(RapportsReprise::DISQUE)->files(RapportsReprise::DOSSIER))
            ->filter(fn (string $f): bool => str_starts_with(basename($f), $prefixe) && str_ends_with($f, '.xlsx'))->sort()->last();

        return Action::make($nom)
            ->label($libelle)
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->visible(fn (): bool => (bool) auth()->user()?->can($permission) && $dernier() !== null)
            ->action(function () use ($dernier, $permission): StreamedResponse {
                abort_unless((bool) auth()->user()?->can($permission), 403);
                $chemin = (string) $dernier();
                activity(Journaux::ARCHIVES)->causedBy(auth()->user())->event('classeur_telecharge')
                    ->withProperties(['fichier' => basename($chemin)])->log('Classeur de la reprise ACMA téléchargé');

                return Storage::disk(RapportsReprise::DISQUE)->download($chemin);
            });
    }

    /** @return array<string, string> uuid → libellé */
    private static function auditeurs(string $recherche): array
    {
        $terme = '%'.addcslashes(trim($recherche), '%_\\').'%';

        return Auditeur::query()->with('candidature:id,matricule,nom,prenom')
            ->whereHas('candidature', fn ($q) => $q->where('matricule', 'ilike', $terme)->orWhere('nom', 'ilike', $terme))
            ->limit(20)->get()->mapWithKeys(fn (Auditeur $a): array => [$a->uuid => (string) self::libelleAuditeur($a)])->all();
    }

    private static function libelleAuditeur(?Auditeur $a): ?string
    {
        return $a === null ? null : trim($a->candidature?->matricule.' — '.mb_strtoupper((string) $a->candidature?->nom).' '.$a->candidature?->prenom);
    }

    /** Exécute un geste ; un refus (règle, droit, base) s'affiche clairement, sans donnée ni erreur 500. */
    public static function executer(Action|TableAction $action, callable $geste, string $succes): void
    {
        try {
            $geste();
        } catch (InvalidArgumentException|AuthorizationException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
            $action->halt();
        } catch (QueryException $e) {
            Notification::make()->danger()->title('La base a refusé l\'enregistrement')
                ->body('Rien n\'a été écrit. '.ucfirst(ErreurAcma::resume($e)).'. Rechargez la fiche et réessayez.')->send();
            $action->halt();
        }
        Notification::make()->success()->title($succes)->send();
    }
}
