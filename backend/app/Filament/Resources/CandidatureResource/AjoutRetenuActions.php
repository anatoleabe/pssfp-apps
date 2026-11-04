<?php

declare(strict_types=1);

namespace App\Filament\Resources\CandidatureResource;

use App\Models\CampagneCandidature;
use App\Models\DepartementCameroun;
use App\Models\Pays;
use App\Models\RegionCameroun;
use App\Services\AjoutRetenu\AjoutRetenu;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * Ajout d'un retenu depuis la liste des candidatures, sans passer par le
 * portail. Réservé à la permission candidature.ajouter_retenu (admin,
 * super_admin). Sont obligatoires les champs dont les lettres et les exports
 * ont besoin. Les autres sont à saisir ici si possible, car un dossier décidé
 * n'est ensuite modifiable que par un super_admin.
 */
final class AjoutRetenuActions
{
    public static function ajouter(Action $action): Action
    {
        return $action
            ->label('Ajouter un retenu')
            ->icon('heroicon-o-user-plus')
            ->color('primary')
            ->visible(fn (): bool => auth()->user()?->can(AjoutRetenu::PERMISSION) === true)
            ->modalHeading('Ajouter un retenu')
            ->modalDescription('Pour une personne retenue qui n\'a pas déposé de dossier sur le portail. Aucun message ne lui est envoyé. Le dossier reste hors du PV du jury. Choisissez ensuite sa catégorie (EFP par défaut), puis attribuez son matricule depuis la liste.')
            ->modalSubmitActionLabel('Enregistrer le retenu')
            ->modalWidth(MaxWidth::FourExtraLarge)
            ->form(self::formulaire())
            ->action(function (array $data, Action $action): void {
                try {
                    $dossier = app(AjoutRetenu::class)->ajouter($data, auth()->user());
                } catch (InvalidArgumentException $e) {
                    Notification::make()->danger()->title('Retenu non ajouté')->body($e->getMessage())->send();
                    $action->halt();

                    return;
                }

                Notification::make()->success()
                    ->title("Dossier {$dossier->numero_dossier} créé")
                    ->body('Vérifiez sa catégorie (EFP par défaut), puis attribuez son matricule : il ne changera plus, et les lettres et les exports en dépendent.')
                    ->send();
            });
    }

    /** Mêmes campagnes que celles acceptées par AjoutRetenu. */
    private static function campagnes(): Builder
    {
        return CampagneCandidature::query()
            ->whereIn('status', AjoutRetenu::STATUTS_CAMPAGNE)
            ->orderByDesc('opens_at')
            ->orderByDesc('id');
    }

    /** @return list<Forms\Components\Component> */
    private static function formulaire(): array
    {
        $pays = fn () => Pays::orderBy('nom')->pluck('nom', 'code_iso');

        return [
            Forms\Components\Section::make('Décision')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('campagne_id')
                        ->label('Campagne')
                        ->options(fn () => self::campagnes()->get()
                            ->mapWithKeys(fn (CampagneCandidature $c): array => [$c->id => $c->nom]))
                        ->default(fn () => self::campagnes()->value('id'))
                        ->required(),
                    Forms\Components\Textarea::make('motif')
                        ->label('Motif de l\'ajout')
                        ->helperText('Conservé dans le journal, jamais montré au candidat. Exemple : référence de la décision.')
                        ->rows(2)
                        ->maxLength(500)
                        ->required(),
                ]),
            Forms\Components\Section::make('Identité')
                ->columns(3)
                ->schema([
                    Forms\Components\Select::make('civilite')->label('Civilité')->options(['M.' => 'M.', 'Mme' => 'Mme'])->required(),
                    Forms\Components\Select::make('genre')->label('Genre')->options(['M' => 'Masculin', 'F' => 'Féminin'])->required(),
                    Forms\Components\Select::make('nationalite')->label('Nationalité')->options($pays)->default('CM')->searchable()->required(),
                    Forms\Components\TextInput::make('nom')->label('Nom')->maxLength(100)->required(),
                    Forms\Components\TextInput::make('prenom')->label('Prénom')->maxLength(100)->required(),
                    Forms\Components\TextInput::make('epouse')->label('Nom de naissance')->maxLength(100),
                    Forms\Components\DatePicker::make('date_naissance')->label('Date de naissance')->native(false)->maxDate(now()->subYears(18))->required(),
                    Forms\Components\TextInput::make('lieu_naissance')->label('Lieu de naissance')->maxLength(100)->required(),
                    Forms\Components\TextInput::make('statut_matrimonial')->label('Statut matrimonial')->maxLength(20),
                ]),
            Forms\Components\Section::make('Contact')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('phone_e164')
                        ->label('Téléphone')
                        ->helperText('Au format international. Il sert à la connexion et à l\'envoi des identifiants.')
                        ->placeholder('+237699000000')
                        ->tel()
                        ->regex('/^\+[1-9][\d\s.\-()]{6,24}$/')
                        ->required(),
                    // Une erreur d'un chiffre donnerait le dossier au titulaire de ce numéro.
                    Forms\Components\TextInput::make('phone_e164_confirmation')
                        ->label('Confirmez le téléphone')
                        ->tel()
                        ->same('phone_e164')
                        ->dehydrated(false)
                        ->required(),
                    Forms\Components\TextInput::make('email')
                        ->label('E-mail')
                        ->helperText('Moodle l\'exige pour créer le compte eCampus.')
                        ->email()
                        ->maxLength(150)
                        ->required(),
                ]),
            Forms\Components\Section::make('Formation')
                ->columns(3)
                ->schema([
                    Forms\Components\Select::make('specialite')
                        ->label('Spécialité')
                        ->options(fn (): array => collect((array) config('specialites'))->mapWithKeys(fn ($libelle): array => [$libelle => $libelle])->all())
                        ->searchable()
                        ->required(),
                    Forms\Components\Select::make('type_etude')->label('Mode d\'étude')->options(['presentiel' => 'Présentiel', 'distanciel' => 'Distanciel'])->required(),
                    Forms\Components\Select::make('premiere_langue')->label('Première langue')->options(['fr' => 'Français', 'en' => 'Anglais'])->default('fr'),
                ]),
            Forms\Components\Section::make('Compléments pour les exports (facultatif)')
                ->columns(3)
                ->collapsible()
                ->collapsed()
                ->schema([
                    Forms\Components\Select::make('pays_origine')->label('Pays d\'origine')->options($pays)->searchable(),
                    Forms\Components\Select::make('pays_residence')->label('Pays de résidence')->options($pays)->searchable(),
                    Forms\Components\TextInput::make('ville_residence')->label('Ville de résidence')->maxLength(100),
                    Forms\Components\Select::make('region')
                        ->label('Région d\'origine')
                        ->options(fn () => RegionCameroun::orderBy('order')->pluck('nom', 'code'))
                        ->searchable()
                        ->live()
                        ->afterStateUpdated(fn (Forms\Set $set) => $set('departement', null)),
                    Forms\Components\Select::make('departement')
                        ->label('Département d\'origine')
                        ->options(fn (Forms\Get $get) => filled($get('region'))
                            ? DepartementCameroun::where('region_code', $get('region'))->orderBy('nom')->pluck('nom', 'code')
                            : [])
                        ->searchable(),
                    Forms\Components\TextInput::make('adresse')->label('Adresse')->maxLength(200),
                    Forms\Components\TextInput::make('diplome_obtenu')->label('Dernier diplôme')->maxLength(100),
                    Forms\Components\TextInput::make('annee_diplome')->label('Année du dernier diplôme')->numeric()->minValue(1950)->maxValue(now()->year),
                    Forms\Components\Select::make('diplome_requis')->label('Diplôme requis')->options(config('diplome_requis'))->native(false),
                    Forms\Components\TextInput::make('annee_diplome_requis')->label('Année du diplôme requis')->numeric()->minValue(1950)->maxValue(now()->year),
                    Forms\Components\Select::make('domaine_diplome_requis')->label('Domaine du diplôme requis')->options(config('domaines_diplome'))->native(false)->live(),
                    Forms\Components\TextInput::make('specialite_diplome_requis')
                        ->label('Spécialité du diplôme requis')
                        ->maxLength(100)
                        ->visible(fn (Forms\Get $get): bool => $get('domaine_diplome_requis') === 'autres'),
                    Forms\Components\TextInput::make('institut_diplome_requis')->label('Établissement du diplôme requis')->maxLength(150),
                    Forms\Components\Select::make('statut_actuel')->label('Situation professionnelle')->options(fn (): array => (array) config('statuts_actuels')),
                    Forms\Components\TextInput::make('fonction_actuelle')->label('Fonction ou poste')->maxLength(150),
                    Forms\Components\TextInput::make('employeur')->label('Employeur')->maxLength(150),
                    Forms\Components\TextInput::make('adresse_employeur')->label('Adresse de l\'employeur')->maxLength(200),
                ]),
        ];
    }
}
