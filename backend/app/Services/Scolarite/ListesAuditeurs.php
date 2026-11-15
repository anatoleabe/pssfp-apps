<?php

declare(strict_types=1);

namespace App\Services\Scolarite;

use App\Models\Auditeur;
use App\Models\BaremeScolarite;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\InscriptionAnnuelle;
use App\Models\User;
use App\Services\ResultatsSelection\SpecialitesOfficielles;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Listes PDF des auditeurs d'une promotion (écran « Listes et états ») :
 * situation financière avec sous-totaux, ou liste nominative.
 *
 * Inscription courante et situation recalculée (SituationFinanciere), chargées
 * en un nombre fixe de requêtes. Ni téléphone ni e-mail : document interne.
 * Dossiers retirés exclus par défaut ; réintégrés sur demande, ils sont signalés.
 * Chaque édition est journalisée avec ses critères, jamais avec une personne.
 */
final class ListesAuditeurs
{
    public const PERMISSION = RegistreVersements::PERMISSION_EXPORT;

    public const SITUATION_FINANCIERE = 'situation_financiere';

    public const NOMINATIVE = 'nominative';

    /** liste => [titre, vue, orientation, début du nom de fichier] */
    private const LISTES = [
        self::SITUATION_FINANCIERE => ['Situation financière des auditeurs', 'pdf.scolarite.liste-situation-financiere', 'landscape', 'situation-financiere'],
        self::NOMINATIVE => ['Liste nominative des auditeurs', 'pdf.scolarite.liste-nominative', 'portrait', 'liste-nominative'],
    ];

    private const SANS_SPECIALITE = 'Spécialité non renseignée';

    private const SANS_INSCRIPTION = 'Sans inscription (barème manquant)';

    /** Pagination « Page X / Y », posée dans la marge basse de chaque page (points). */
    private const PAGINATION_TAILLE = 7.5;

    private const PAGINATION_MARGE = 34;

    /** PDF de la liste, journalisé. */
    public function editer(string $liste, CriteresListe $criteres, User $auteur): string
    {
        abort_unless($auteur->can(self::PERMISSION), 403);

        $tableau = $this->tableau($criteres);
        $pdf = $this->pdf($liste, $this->rendre($liste, $criteres, $tableau));

        activity('scolarite')->causedBy($auteur)->event('liste_editee')
            ->withProperties(['liste' => $liste] + $criteres->proprietes() + ['lignes' => $tableau['totaux']['effectif']])
            ->log('Liste des auditeurs éditée');

        return $pdf;
    }

    public function html(string $liste, CriteresListe $criteres): string
    {
        return $this->rendre($liste, $criteres, $this->tableau($criteres));
    }

    public function nomFichier(string $liste, CriteresListe $criteres): string
    {
        return self::definition($liste)[3].'-P'.$criteres->campagne->promotion_numero.'-'.CarbonImmutable::now(InscriptionAnnuelle::FUSEAU)->format('Y-m-d').'.pdf';
    }

    public function effectif(CriteresListe $criteres): int
    {
        return $this->requete($criteres)->count();
    }

    /**
     * Lignes triées par nom, réparties en groupes, avec sous-totaux et total général.
     * Montants nuls (null) pour un auditeur sans inscription : il compte dans
     * l'effectif, pas dans les sommes.
     *
     * @return array{groupes: list<array{titre: ?string, lignes: list<array<string, mixed>>, totaux: array<string, int>}>, totaux: array<string, int>}
     */
    public function tableau(CriteresListe $criteres): array
    {
        $lignes = $this->requete($criteres)
            ->with([
                'candidature',
                'inscriptionCourante' => fn ($q) => $q->with('bareme')->withSum('versementsValides', 'montant'),
            ])
            ->get()
            ->map(fn (Auditeur $a): array => self::ligne($a))
            ->sortBy(fn (array $l): string => Str::lower(Str::ascii($l['nom'])).' '.$l['matricule'])
            ->values();

        return ['groupes' => self::grouper($lignes, $criteres->regroupement), 'totaux' => self::totaux($lignes)];
    }

    /** @return array<int, string> promotions qui ont des auditeurs, la plus récente d'abord */
    public static function promotions(): array
    {
        return CampagneCandidature::query()->whereHas('candidatures.auditeur')->orderByDesc('promotion_numero')->get()
            ->mapWithKeys(fn (CampagneCandidature $c): array => [$c->id => CriteresListe::libellePromotion($c)])
            ->all();
    }

    /** @return array<string, string> spécialités des auditeurs de la promotion, dans l'ordre officiel */
    public static function specialites(?int $campagneId): array
    {
        if ($campagneId === null) {
            return [];
        }
        $ordre = array_values(SpecialitesOfficielles::ordonnees());

        return Candidature::query()->where('campagne_id', $campagneId)->whereHas('auditeur')
            ->whereNotNull('specialite')->where('specialite', '<>', '')
            ->distinct()->pluck('specialite')
            ->sort(fn (string $a, string $b): int => self::comparer($a, $b, $ordre))
            ->mapWithKeys(fn (string $s): array => [$s => $s])
            ->all();
    }

    private function requete(CriteresListe $c): Builder
    {
        return Auditeur::query()
            ->whereIn('statut', $c->statuts)
            ->whereHas('candidature', fn (Builder $q) => $q->where('campagne_id', $c->campagne->id)
                ->when(! $c->inclureRetires, fn (Builder $r) => $r->whereNull('withdrawn_at'))
                ->when($c->specialite !== null, fn (Builder $s) => $s->where('specialite', $c->specialite)))
            ->when($c->niveau !== null, fn (Builder $q) => $q->whereHas('inscriptionCourante', fn (Builder $i) => $i->where('niveau', $c->niveau)))
            // Même logique que le filtre « Situation » de la liste des auditeurs (scopes d'InscriptionAnnuelle).
            ->when($c->situation !== CriteresListe::TOUTES, fn (Builder $q) => $q->whereHas('inscriptionCourante', fn (Builder $i) => match ($c->situation) {
                CriteresListe::SOLDE => $i->entierementSoldee(),
                CriteresListe::NON_SOLDE => $i->whereNot(fn (Builder $n) => $n->entierementSoldee()),
                default => $i->inscriptionSoldee()->enRetard(),
            }));
    }

    /** @return array<string, mixed> */
    private static function ligne(Auditeur $a): array
    {
        $c = $a->candidature;
        $i = $a->inscriptionCourante;
        $s = $i?->situation();

        return [
            'matricule' => (string) $c?->matricule,
            'nom' => trim(mb_strtoupper((string) $c?->nom).' '.$c?->prenom),
            'niveau' => $i?->niveau,
            'specialite' => blank($c?->specialite) ? null : (string) $c->specialite,
            'statut' => (Auditeur::STATUTS[$a->statut] ?? (string) $a->statut).($c?->withdrawn_at === null ? '' : ' (dossier retiré)'),
            'du' => $s?->duTotal,
            'remise' => $s?->remise,
            'verse' => $s?->verse,
            'reste' => $s?->reste,
            'situation' => LibelleSituation::libelle($i),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $lignes
     * @return list<array{titre: ?string, lignes: list<array<string, mixed>>, totaux: array<string, int>}>
     */
    private static function grouper(Collection $lignes, string $regroupement): array
    {
        if ($regroupement === CriteresListe::SANS_REGROUPEMENT) {
            return [self::groupe(null, $lignes)];
        }
        [$titre, $ordre, $dernier] = $regroupement === CriteresListe::PAR_NIVEAU
            ? [fn (array $l): string => $l['niveau'] === null ? self::SANS_INSCRIPTION : (BaremeScolarite::NIVEAUX[$l['niveau']] ?? $l['niveau']), array_values(BaremeScolarite::NIVEAUX), self::SANS_INSCRIPTION]
            : [fn (array $l): string => $l['specialite'] ?? self::SANS_SPECIALITE, array_values(SpecialitesOfficielles::ordonnees()), self::SANS_SPECIALITE];

        return $lignes->groupBy($titre)
            ->sortKeysUsing(fn (string $a, string $b): int => self::comparer($a, $b, $ordre, $dernier))
            ->map(fn (Collection $g, string $t): array => self::groupe($t, $g))
            ->values()
            ->all();
    }

    /**
     * Ordre de référence d'abord, puis les autres libellés par ordre alphabétique, `$dernier` en fin.
     *
     * @param  list<string>  $ordre
     */
    private static function comparer(string $a, string $b, array $ordre, ?string $dernier = null): int
    {
        $rang = function (string $libelle) use ($ordre, $dernier): int {
            $position = array_search($libelle, $ordre, true);

            return match (true) {
                $position !== false => (int) $position,
                $libelle === $dernier => PHP_INT_MAX,
                default => PHP_INT_MAX - 1,
            };
        };

        return [$rang($a), Str::lower(Str::ascii($a))] <=> [$rang($b), Str::lower(Str::ascii($b))];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $lignes
     * @return array{titre: ?string, lignes: list<array<string, mixed>>, totaux: array<string, int>}
     */
    private static function groupe(?string $titre, Collection $lignes): array
    {
        return ['titre' => $titre, 'lignes' => $lignes->values()->all(), 'totaux' => self::totaux($lignes)];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $lignes
     * @return array{effectif: int, du: int, remise: int, verse: int, reste: int}
     */
    private static function totaux(Collection $lignes): array
    {
        return [
            'effectif' => $lignes->count(),
            'du' => (int) $lignes->sum('du'),
            'remise' => (int) $lignes->sum('remise'),
            'verse' => (int) $lignes->sum('verse'),
            'reste' => (int) $lignes->sum('reste'),
        ];
    }

    /** @param  array<string, mixed>  $tableau */
    private function rendre(string $liste, CriteresListe $criteres, array $tableau): string
    {
        [$titre, $vue] = self::definition($liste);

        return view($vue, [
            'titre' => $titre,
            'criteres' => $criteres->libelles(),
            'tableau' => $tableau,
            'edite_le' => CarbonImmutable::now(InscriptionAnnuelle::FUSEAU),
            'logo' => self::logo(),
        ])->render();
    }

    private function pdf(string $liste, string $html): string
    {
        $pdf = Pdf::loadHTML($html)->setPaper('a4', self::definition($liste)[2]);
        $pdf->render();

        $dompdf = $pdf->getDomPDF();
        $canvas = $dompdf->getCanvas();
        $metriques = $dompdf->getFontMetrics();
        $police = $metriques->getFont('Source Sans 3', 'normal') ?: $metriques->getFont('DejaVu Sans', 'normal');
        $largeur = $metriques->getTextWidth('Page 99 / 99', $police, self::PAGINATION_TAILLE);
        $canvas->page_text(
            $canvas->get_width() - self::PAGINATION_MARGE - $largeur,
            $canvas->get_height() - 28,
            'Page {PAGE_NUM} / {PAGE_COUNT}',
            $police,
            self::PAGINATION_TAILLE,
            [0.37, 0.37, 0.37],
        );

        return $pdf->output();
    }

    /** @return array{0: string, 1: string, 2: string, 3: string} */
    private static function definition(string $liste): array
    {
        return self::LISTES[$liste] ?? throw new InvalidArgumentException('Liste inconnue : '.$liste);
    }

    /** Logo de l'en-tête officiel, comme sur la lettre d'accès FOAD. */
    private static function logo(): ?string
    {
        $chemin = resource_path('images/pdf/logo.png');

        return is_file($chemin) ? 'data:image/png;base64,'.base64_encode((string) file_get_contents($chemin)) : null;
    }
}
