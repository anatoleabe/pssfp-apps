<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Concerns\ChecksModuleAccess;
use App\Filament\Pages\Lmd\MesCours;
use App\Filament\Pages\Lmd\MesHeures;
use App\Filament\Pages\Lmd\SaisieNotes;
use App\Filament\Pages\Scolarite\ListesEtats;
use App\Filament\Pages\Scolarite\SolvabiliteAuditeurs;
use App\Filament\Pages\Scolarite\TableauDeBordFinances;
use App\Filament\Resources\ArticleResource;
use App\Filament\Resources\AuditeurResource;
use App\Filament\Resources\CampagneCandidatureResource;
use App\Filament\Resources\CandidatureResource;
use App\Filament\Resources\DeliberationResource;
use App\Filament\Resources\DemandeAutorisationResource;
use App\Filament\Resources\DocumentResource;
use App\Filament\Resources\EmploiDuTempsResource;
use App\Filament\Resources\EtatPaieResource;
use App\Filament\Resources\MaClasseResource;
use App\Filament\Resources\PageResource;
use App\Filament\Resources\PlanningExamensResource;
use App\Filament\Resources\PointageEnseignantResource;
use App\Filament\Resources\RecuVersementResource;
use App\Filament\Resources\RequeteResource;
use App\Filament\Resources\UserResource;
use App\Filament\Resources\VersementAConfirmerResource;
use App\Filament\Resources\VersementResource;
use App\Filament\Resources\VirementAnnonceResource;
use App\Models\User;
use App\Services\Lmd\EspaceEnseignant;
use App\Support\BelongsToModule;
use App\Support\ModuleAccess;
use Filament\Pages\Page;
use Filament\Widgets\Widget;

/**
 * « Raccourcis » de l'accueil (lot 34) : les écrans du métier du compte connecté, au plus huit, dans l'ordre de son
 * travail (l'enseignant ses cours, le point focal sa classe, le service financier ses encaissements, puis la scolarité,
 * les admissions, l'administration). Un raccourci n'apparaît que si le compte peut ouvrir l'écran.
 */
#[BelongsToModule(ModuleAccess::CORE)]
final class RaccourcisWidget extends Widget
{
    use ChecksModuleAccess;

    public const MAX = 8;

    protected static ?int $sort = -9;

    /** Rendu avec la page, comme « À traiter ». */
    protected static bool $isLazy = false;

    protected static string $view = 'filament.widgets.raccourcis';

    protected int|string|array $columnSpan = 'full';

    /** @var array<string, list<array{0: class-string, 1: string, 2: string}>> écran, libellé, à quoi il sert */
    private const RUBRIQUES = [
        'enseignant' => [
            [MesCours::class, 'Mes cours', 'Séances à venir et surveillances'],
            [PointageEnseignantResource::class, 'Mes émargements', 'Émarger une séance faite'],
            [SaisieNotes::class, 'Saisie des notes', 'Contrôle continu et assiduité'],
            [MesHeures::class, 'Mes heures', 'Heures validées et paiements'],
        ],
        'point_focal' => [
            [MaClasseResource::class, 'Ma classe', 'Les auditeurs de votre classe'],
            [SaisieNotes::class, 'Saisie des notes', 'Notes de votre classe'],
        ],
        'finances' => [
            [VersementResource::class, 'Versements', 'Encaisser, retrouver un versement'],
            [VirementAnnonceResource::class, 'Virements à valider', 'Virements annoncés en attente de crédit'],
            [RecuVersementResource::class, 'Reçus', 'Imprimer, renvoyer un reçu'],
            [TableauDeBordFinances::class, 'Synthèse financière', 'Versé, dû et reste à payer'],
            [SolvabiliteAuditeurs::class, 'Solvabilité', 'Auditeurs en retard de paiement'],
        ],
        'scolarite' => [
            [AuditeurResource::class, 'Auditeurs', 'Retrouver une fiche'],
            [VersementAConfirmerResource::class, 'Versements à confirmer', 'Comparer au reçu bancaire'],
            [ListesEtats::class, 'Listes et états', 'Listes de classe, états imprimables'],
            [EmploiDuTempsResource::class, 'Emplois du temps', 'Séances de la période'],
            [PlanningExamensResource::class, 'Planning d\'examens', 'Épreuves, salles, surveillants'],
            [SaisieNotes::class, 'Saisie des notes', 'Étapes ouvertes'],
            [RequeteResource::class, 'Requêtes', 'Réclamations des auditeurs'],
            [DeliberationResource::class, 'Délibérations', 'Jurys et procès-verbaux'],
        ],
        'admissions' => [
            [CandidatureResource::class, 'Candidatures', 'Dossiers de la campagne'],
            [CampagneCandidatureResource::class, 'Campagnes', 'Ouverture et suivi'],
        ],
        'administration' => [
            [DemandeAutorisationResource::class, 'Demandes', 'Vos demandes et celles à décider'],
            [UserResource::class, 'Utilisateurs', 'Comptes et rôles'],
        ],
        'autres' => [
            [EtatPaieResource::class, 'États de paie', 'Paie des enseignants'],
            [DocumentResource::class, 'Bibliothèque', 'Documents de la bibliothèque'],
            [PageResource::class, 'Pages du site', 'Contenu du site institutionnel'],
            [ArticleResource::class, 'Articles', 'Actualités du site'],
        ],
    ];

    public static function canView(): bool
    {
        return self::moduleAllows() && self::raccourcis() !== [];
    }

    /**
     * Raccourcis du compte connecté, sans doublon, au plus MAX.
     *
     * @return list<array{libelle: string, description: string, icone: string, url: string}>
     */
    public static function raccourcis(): array
    {
        $u = auth()->user();
        if (! $u instanceof User) {
            return [];
        }
        $vus = [];
        $sortie = [];
        foreach (self::ordre($u) as $rubrique) {
            foreach (self::RUBRIQUES[$rubrique] as [$ecran, $libelle, $description]) {
                if (isset($vus[$ecran]) || count($sortie) >= self::MAX || ! $ecran::canAccess()) {
                    continue;
                }
                $vus[$ecran] = true;
                $sortie[] = [
                    'libelle' => $libelle,
                    'description' => $description,
                    'icone' => (string) ($ecran::getNavigationIcon() ?? 'heroicon-o-arrow-right'),
                    'url' => is_subclass_of($ecran, Page::class) ? $ecran::getUrl() : $ecran::getUrl('index'),
                ];
            }
        }

        return $sortie;
    }

    /** @return list<string> rubriques dans l'ordre du travail du compte */
    private static function ordre(User $u): array
    {
        $gestion = $u->can('lmd.consulter');
        $tete = [];
        if (EspaceEnseignant::concerne($u)) {
            $tete[] = 'enseignant';
        }
        if (! $gestion && MaClasseResource::canAccess()) {
            $tete[] = 'point_focal';
        }
        if (! $gestion && $u->can('scolarite.recu_emettre')) {
            $tete[] = 'finances';
        }

        return array_values(array_unique([...$tete, 'scolarite', 'finances', 'admissions', 'administration', 'autres']));
    }
}
