<?php

declare(strict_types=1);

namespace App\Filament\Resources\AuditeurResource;

use App\Models\Auditeur;
use App\Models\DocumentDelivre;
use App\Models\DossierMemoire;
use App\Services\Lmd\DocumentsOfficiels;
use App\Services\Lmd\PermissionsLmd;
use Closure;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use InvalidArgumentException;

/**
 * Documents administratifs rédigés à la délivrance (spec lot 26) : attestation de recherche et
 * lettre de stage. La scolarité saisit ce que le dossier ne porte pas, le document est numéroté et scellé.
 */
final class DocumentsAdministratifsActions
{
    public static function attestationRecherche(): Action
    {
        return self::action('attestationRecherche', 'Attestation de recherche', 'heroicon-o-magnifying-glass',
            'Une seule attestation valide par inscription : pour changer le thème, révoquez-la dans « Documents délivrés » puis délivrez-la à nouveau.',
            fn (Auditeur $record, array $data): DocumentDelivre => app(DocumentsOfficiels::class)->delivrerAttestationRecherche($record, $data, auth()->user()))
            ->fillForm(function (Auditeur $record): array {
                $memoire = DossierMemoire::query()->where('auditeur_id', $record->id)->latest('id')->first();

                return ['theme' => $memoire?->theme, 'encadreur' => $memoire?->encadreur_academique];
            })
            ->form(MessagesValidation::appliquer([
                Forms\Components\Textarea::make('theme')->label('Thème du mémoire')->required()->minLength(3)->maxLength(500)->rows(3),
                Forms\Components\TextInput::make('encadreur')->label('Encadreur')->required()->minLength(3)->maxLength(150)
                    ->helperText('Avec son titre, tel qu\'il doit figurer : « Dr … », « Pr … ».'),
            ]));
    }

    public static function lettreStage(): Action
    {
        return self::action('lettreStage', 'Lettre de stage', 'heroicon-o-envelope',
            'Une lettre par structure d\'accueil. Le numéro de courrier et la date se complètent à la main, à la signature.',
            fn (Auditeur $record, array $data): DocumentDelivre => app(DocumentsOfficiels::class)->delivrerLettreStage($record, $data, auth()->user()))
            ->fillForm(fn (): array => ['nature' => 'stage d\'imprégnation'])
            ->form(MessagesValidation::appliquer([
                Forms\Components\TextInput::make('destinataire')->label('Destinataire')->required()->minLength(3)->maxLength(250)
                    ->helperText('Tel qu\'il figure après « À » : « Monsieur le Directeur Général de … ».'),
                Forms\Components\TextInput::make('appel')->label('Formule d\'appel')->required()->minLength(3)->maxLength(120)
                    ->helperText('« Monsieur le Directeur Général », « Madame la Directrice »…'),
                Forms\Components\Grid::make(2)->schema([
                    Forms\Components\TextInput::make('nature')->label('Nature du stage')->required()->minLength(3)->maxLength(120),
                    Forms\Components\TextInput::make('duree')->label('Durée')->required()->minLength(3)->maxLength(80)->placeholder('trois (03) mois'),
                ]),
                Forms\Components\Textarea::make('theme')->label('Thème (facultatif)')->maxLength(500)->rows(3),
            ]));
    }

    /** @param  Closure(Auditeur, array<string, mixed>): DocumentDelivre  $delivrer */
    private static function action(string $nom, string $libelle, string $icone, string $aide, Closure $delivrer): Action
    {
        return Action::make($nom)->label($libelle)->icon($icone)->color('gray')
            ->visible(fn (Auditeur $record): bool => (bool) auth()->user()?->can(PermissionsLmd::CERTIFICAT_DELIVRER) && $record->inscriptionCourante !== null)
            ->modalDescription($aide)->modalSubmitActionLabel('Délivrer')
            ->action(function (Auditeur $record, array $data) use ($delivrer, $libelle) {
                try {
                    $document = $delivrer($record, $data);
                    $pdf = app(DocumentsOfficiels::class)->contenu($document);
                } catch (InvalidArgumentException $e) {
                    Notification::make()->danger()->title($libelle.' non délivrée')->body($e->getMessage())->send();

                    return null;
                }

                return response()->streamDownload(fn () => print ($pdf), mb_strtolower($document->numero).'.pdf', ['Content-Type' => 'application/pdf']);
            });
    }
}
