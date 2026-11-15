<?php

declare(strict_types=1);

namespace App\Filament\Pages\Scolarite;

use App\Filament\Concerns\ChecksModuleAccess;
use App\Filament\Navigation\GroupeNavigation;
use App\Filament\Resources\AuditeurResource\MessagesValidation;
use App\Models\Auditeur;
use App\Models\BaremeScolarite;
use App\Models\User;
use App\Services\Scolarite\CriteresListe;
use App\Services\Scolarite\ListesAuditeurs;
use App\Support\BelongsToModule;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Vie académique > Listes et états : listes PDF des auditeurs d'une promotion
 * (situation financière, liste nominative). L'écran ne fait que recueillir les
 * critères et livrer le fichier ; ListesAuditeurs calcule, met en page et
 * journalise. Réservé au droit d'export de la situation financière.
 */
#[BelongsToModule('scolarite')]
class ListesEtats extends Page implements HasForms
{
    use ChecksModuleAccess;
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = GroupeNavigation::SCOLARITE;

    protected static ?int $navigationSort = 15;

    protected static ?string $navigationLabel = 'Listes et états';

    protected static ?string $title = 'Listes et états';

    protected static ?string $slug = 'scolarite/listes-etats';

    protected static string $view = 'filament.scolarite.listes-etats';

    /** @var array<string, mixed> */
    public array $criteres = [];

    public static function canAccess(): bool
    {
        return static::moduleAllows() && (bool) auth()->user()?->can(ListesAuditeurs::PERMISSION);
    }

    public function mount(): void
    {
        $this->form->fill([
            'campagne_id' => array_key_first(ListesAuditeurs::promotions()),
            'niveau' => null,
            'specialite' => null,
            'situation' => CriteresListe::TOUTES,
            'statuts' => [Auditeur::STATUT_ACTIF],
            'regroupement' => CriteresListe::PAR_SPECIALITE,
            'inclure_retires' => false,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->statePath('criteres')->schema(MessagesValidation::appliquer([
            Forms\Components\Section::make('Auditeurs à lister')->columns(3)->schema([
                Forms\Components\Select::make('campagne_id')->label('Promotion')->required()->native(false)->live()
                    ->options(fn (): array => ListesAuditeurs::promotions())
                    ->afterStateUpdated(fn (Set $set) => $set('specialite', null)),
                Forms\Components\Select::make('niveau')->label('Niveau')->native(false)->live()
                    ->options(BaremeScolarite::NIVEAUX)->placeholder('Tous les niveaux'),
                Forms\Components\Select::make('specialite')->label('Spécialité')->native(false)->live()
                    ->options(fn (Get $get): array => ListesAuditeurs::specialites(is_numeric($get('campagne_id')) ? (int) $get('campagne_id') : null))
                    ->placeholder('Toutes les spécialités'),
                Forms\Components\Select::make('situation')->label('Situation financière')->required()->native(false)->live()
                    ->options(CriteresListe::SITUATIONS)->selectablePlaceholder(false),
                Forms\Components\CheckboxList::make('statuts')->label('Statut de l\'auditeur')->required()->live()
                    ->options(Auditeur::STATUTS)->columns(3)->columnSpan(2)
                    ->helperText('Par défaut, les auditeurs actifs seulement. Cochez « Exclu » ou « Abandon » pour les ajouter.'),
                Forms\Components\Checkbox::make('inclure_retires')->label('Inclure les dossiers retirés')->live()
                    ->helperText('Décochée par défaut : un dossier retiré n\'apparaît sur aucune liste. Cochée, il est signalé « dossier retiré ».'),
            ]),
            Forms\Components\Section::make('Présentation')->schema([
                Forms\Components\Radio::make('regroupement')->label('Regrouper les auditeurs')->required()->inline()->inlineLabel(false)
                    ->options(array_map(fn (string $libelle): string => Str::ucfirst($libelle), CriteresListe::REGROUPEMENTS))
                    ->helperText('Chaque groupe porte son effectif ; la situation financière y ajoute un sous-total, puis le total général.'),
            ]),
        ]));
    }

    /** Effectif correspondant aux critères saisis, null tant qu'ils sont incomplets. */
    public function effectif(): ?int
    {
        try {
            return app(ListesAuditeurs::class)->effectif(CriteresListe::depuis($this->criteres));
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public function situationFinanciereAction(): Action
    {
        return $this->edition('situationFinanciere', ListesAuditeurs::SITUATION_FINANCIERE)
            ->label('Situation financière (PDF paysage)')->icon('heroicon-o-banknotes');
    }

    public function listeNominativeAction(): Action
    {
        return $this->edition('listeNominative', ListesAuditeurs::NOMINATIVE)
            ->label('Liste nominative (PDF portrait)')->icon('heroicon-o-queue-list')->color('gray');
    }

    private function edition(string $nom, string $liste): Action
    {
        return Action::make($nom)->action(function () use ($liste): ?StreamedResponse {
            $agent = auth()->user();
            abort_unless($agent instanceof User && $agent->can(ListesAuditeurs::PERMISSION), 403);

            try {
                $criteres = CriteresListe::depuis($this->form->getState());
            } catch (InvalidArgumentException $e) {
                Notification::make()->danger()->title($e->getMessage())->send();

                return null;
            }
            $listes = app(ListesAuditeurs::class);
            $contenu = $listes->editer($liste, $criteres, $agent);

            return response()->streamDownload(function () use ($contenu): void {
                echo $contenu;
            }, $listes->nomFichier($liste, $criteres), ['Content-Type' => 'application/pdf']);
        });
    }
}
