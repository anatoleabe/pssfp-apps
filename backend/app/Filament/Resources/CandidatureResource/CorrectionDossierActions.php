<?php

declare(strict_types=1);

namespace App\Filament\Resources\CandidatureResource;

use App\Filament\Resources\AuditeurResource\MessagesValidation;
use App\Models\Candidature;
use App\Models\DepartementCameroun;
use App\Models\Pays;
use App\Models\RegionCameroun;
use App\Services\CorrectionDossier\CorrectionDossier;
use Carbon\CarbonImmutable;
use Filament\Actions\MountableAction;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Support\HtmlString;
use InvalidArgumentException;

/**
 * « Modifier les informations » d'un dossier, décidé ou non, auditeur compris (24/11/2026) : réservé à l'administrateur
 * (`candidature.dossier_corriger`). Formulaire prérempli, puis récapitulatif des seuls champs changés et motif.
 * Sur la fiche candidature et, avec la candidature de l'auditeur, sur la fiche auditeur.
 */
final class CorrectionDossierActions
{
    public static function corriger(MountableAction $action): MountableAction
    {
        return $action
            ->label('Modifier les informations')
            ->icon('heroicon-o-pencil-square')
            ->color('gray')
            ->visible(fn (): bool => auth()->user()?->can(CorrectionDossier::PERMISSION) === true)
            ->modalHeading(fn (Candidature $record): string => 'Modifier les informations — dossier '.$record->numero_dossier)
            ->modalWidth(MaxWidth::FourExtraLarge)
            ->modalSubmitActionLabel('Enregistrer les corrections')
            ->fillForm(fn (Candidature $record): array => self::valeurs($record))
            ->steps([
                Forms\Components\Wizard\Step::make('Informations')
                    ->description('Identité, contact, résidence')
                    ->schema(MessagesValidation::appliquer(self::champs())),
                Forms\Components\Wizard\Step::make('Vérification')
                    ->description('Les changements et leur motif')
                    ->schema(MessagesValidation::appliquer([
                        Forms\Components\Placeholder::make('recapitulatif')->hiddenLabel()
                            ->content(fn (Candidature $record, Forms\Get $get): HtmlString => self::recapitulatif($record, $get)),
                        Forms\Components\Textarea::make('motif')->label('Motif de la correction')->required()->maxLength(500)->rows(2)
                            ->placeholder('Ex. : e-mail erroné signalé par l\'auditeur le …, acte de naissance fourni')
                            ->helperText('Conservé dans le journal avec les anciennes valeurs, jamais montré à l\'auditeur.'),
                    ])),
            ])
            ->action(function (Candidature $record, array $data, MountableAction $action): void {
                try {
                    $resultat = app(CorrectionDossier::class)->corriger($record, $data, (string) ($data['motif'] ?? ''), auth()->user());
                } catch (InvalidArgumentException $e) {
                    Notification::make()->danger()->title('Informations non corrigées')->body($e->getMessage())->send();
                    $action->halt();

                    return;
                }
                $record->refresh();
                $titre = count($resultat['changements']).' information(s) corrigée(s)';
                if (! $resultat['ecampus_ok']) {
                    Notification::make()->warning()->persistent()->title($titre.' ; eCampus n\'a pas suivi')
                        ->body($resultat['ecampus'].' Corrigez le compte sur eCampus, ou recommencez plus tard.')->send();

                    return;
                }
                Notification::make()->success()->title($titre)->body($resultat['ecampus'])->send();
            });
    }

    /** @return array<string, mixed> */
    private static function valeurs(Candidature $record): array
    {
        $valeurs = [];
        foreach (array_keys(CorrectionDossier::CHAMPS) as $champ) {
            $valeurs[$champ] = $champ === 'date_naissance' ? $record->date_naissance?->toDateString() : $record->getAttribute($champ);
        }

        return $valeurs;
    }

    /** @return list<Forms\Components\Component> */
    private static function champs(): array
    {
        $pays = fn () => Pays::query()->orderBy('nom')->pluck('nom', 'code_iso');
        $auCameroun = fn (Forms\Get $get): bool => $get('pays_residence') === 'CM';

        return [
            Forms\Components\Section::make('Identité')->columns(3)->schema([
                Forms\Components\Select::make('civilite')->label('Civilité')->options(['M.' => 'M.', 'Mme' => 'Mme', 'Mlle' => 'Mlle'])->native(false),
                Forms\Components\TextInput::make('nom')->label('Nom')->required()->maxLength(100),
                Forms\Components\TextInput::make('prenom')->label('Prénom')->required()->maxLength(100),
                Forms\Components\TextInput::make('epouse')->label('Nom de naissance')->maxLength(100),
                Forms\Components\DatePicker::make('date_naissance')->label('Date de naissance')->required()->native(false)->displayFormat('d/m/Y')
                    ->maxDate(CarbonImmutable::now('Africa/Douala')->subYears(18)->toDateString()),
                Forms\Components\TextInput::make('lieu_naissance')->label('Lieu de naissance')->required()->maxLength(100),
                Forms\Components\Select::make('genre')->label('Genre')->options(['M' => 'Masculin', 'F' => 'Féminin', 'autre' => 'Autre'])->native(false),
                Forms\Components\TextInput::make('statut_matrimonial')->label('Situation matrimoniale')->maxLength(20),
                Forms\Components\Select::make('nationalite')->label('Nationalité')->options($pays)->searchable(),
            ]),
            Forms\Components\Section::make('Contact')->columns(4)
                ->description('Le téléphone de connexion se corrige par « Corriger le téléphone ».')
                ->schema([
                    Forms\Components\TextInput::make('email')->label('E-mail')->email()->required()->maxLength(150)->columnSpanFull(),
                    Forms\Components\TextInput::make('indicatif1')->label('Indicatif 1')->maxLength(10),
                    Forms\Components\TextInput::make('telephone1')->label('Téléphone 1')->maxLength(20),
                    Forms\Components\TextInput::make('indicatif2')->label('Indicatif 2')->maxLength(10),
                    Forms\Components\TextInput::make('telephone2')->label('Téléphone 2')->maxLength(20),
                ]),
            Forms\Components\Section::make('Résidence')->columns(3)->schema([
                Forms\Components\Select::make('pays_origine')->label('Pays d\'origine')->options($pays)->searchable(),
                Forms\Components\Select::make('pays_residence')->label('Pays de résidence')->options($pays)->searchable()->live(),
                Forms\Components\TextInput::make('ville_residence')->label('Ville de résidence')->maxLength(100),
                Forms\Components\Select::make('region')->label('Région')->searchable()->live()->visible($auCameroun)
                    ->options(fn () => RegionCameroun::query()->orderBy('order')->pluck('nom', 'code')),
                Forms\Components\Select::make('departement')->label('Département')->searchable()->visible($auCameroun)
                    ->options(fn (Forms\Get $get) => blank($get('region')) ? [] : DepartementCameroun::query()->where('region_code', $get('region'))->orderBy('nom')->pluck('nom', 'code')),
                Forms\Components\TextInput::make('adresse')->label('Adresse')->maxLength(200)->columnSpanFull(),
            ]),
        ];
    }

    private static function recapitulatif(Candidature $record, Forms\Get $get): HtmlString
    {
        $donnees = [];
        foreach (array_keys(CorrectionDossier::CHAMPS) as $champ) {
            $donnees[$champ] = $get($champ);
        }
        $changements = app(CorrectionDossier::class)->changements($record, $donnees);
        if ($changements === []) {
            return new HtmlString('<p style="font-size:.875rem;">Aucune information n\'a changé : revenez à l\'étape précédente pour corriger.</p>');
        }

        // Styles en ligne : le panneau n'a pas de thème compilé, une classe Tailwind absente de Filament ne s'appliquerait pas.
        $cellule = 'padding:.35rem .5rem;border-bottom:1px solid rgba(127,127,127,.25);text-align:start;vertical-align:top;';
        $html = '<table style="width:100%;border-collapse:collapse;font-size:.875rem;"><thead><tr>'
            .'<th style="'.$cellule.'">Information</th><th style="'.$cellule.'">Avant</th><th style="'.$cellule.'">Après</th></tr></thead><tbody>';
        foreach ($changements as $champ => [$avant, $apres]) {
            $html .= '<tr><td style="'.$cellule.'font-weight:600;">'.e(CorrectionDossier::CHAMPS[$champ]).'</td>'
                .'<td style="'.$cellule.'">'.e(self::afficher($champ, $avant)).'</td>'
                .'<td style="'.$cellule.'">'.e(self::afficher($champ, $apres)).'</td></tr>';
        }
        $html .= '</tbody></table>';
        if (array_intersect(array_keys($changements), ['nom', 'prenom', 'email']) !== [] && $record->matricule !== null) {
            $html .= '<p style="margin-top:.5rem;font-size:.875rem;">Le compte eCampus de l\'auditeur sera corrigé lui aussi (nom, prénom, e-mail).</p>';
        }

        return new HtmlString($html.'<p style="margin-top:.5rem;font-size:.875rem;">Les documents déjà remis ne changent pas ; ceux produits ensuite porteront ces informations.</p>');
    }

    private static function afficher(string $champ, ?string $valeur): string
    {
        if ($valeur === null) {
            return '—';
        }

        return $champ === 'date_naissance' ? CarbonImmutable::parse($valeur)->format('d/m/Y') : $valeur;
    }
}
