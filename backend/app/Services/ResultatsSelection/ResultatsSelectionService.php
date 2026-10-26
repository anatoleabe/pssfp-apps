<?php

declare(strict_types=1);

namespace App\Services\ResultatsSelection;

use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\Pays;
use App\Support\TexteImprimable;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Rassemble les données du communiqué des résultats et du PV du jury.
 *
 * Règle de sélection provisoire (arbitrage du 12 oct. 2026) : est retenu tout
 * dossier soumis en ligne dont le dossier papier a été réceptionné au guichet.
 * Les dossiers retirés ou refusés n'y figurent jamais. Quand le jury
 * prononcera les admissions dans l'outil, seule `retenus()` changera.
 *
 * Une même personne retenue au titre de plusieurs dossiers est signalée à
 * l'agent ; sauf choix contraire de sa part, un seul dossier est gardé.
 */
final class ResultatsSelectionService
{
    private const NATIONAL = 'CM';

    private const NON_PRECISE = Repartition::NON_PRECISE;

    /** Colonnes lues : celles des documents, rien de plus (minimisation). */
    private const COLONNES = ['id', 'numero_dossier', 'nom', 'prenom', 'date_naissance', 'lieu_naissance', 'genre',
        'nationalite', 'region', 'specialite', 'type_etude', 'premiere_langue', 'statut_actuel', 'fonction_actuelle',
        'diplome_requis', 'domaine_diplome_requis', 'specialite_diplome_requis', 'institut_diplome_requis',
        'annee_diplome_requis', 'submitted_at', 'depot_physique_at'];

    public function __construct(
        private readonly DetecteurDoublons $detecteur,
        private readonly AnalyseurProfil $analyseur,
    ) {}

    /**
     * Résultats et dossiers retenus issus d'une seule lecture, pour la liste
     * Excel : mêmes candidats et mêmes chiffres que le titre et le journal.
     * Ordre du PV : nationaux puis étrangers, alphabétique dans chaque groupe.
     *
     * @return array{0: ResultatsSelection, 1: Collection<int, Candidature>}
     */
    public function construireAvecDossiers(CampagneCandidature $campagne, SeanceJury $seance, bool $conserverDoublons = false): array
    {
        [$tous, $retenus] = $this->charger($campagne, $conserverDoublons);

        $dossiers = $retenus
            ->sortBy(fn (Candidature $c): string => ($c->nationalite === self::NATIONAL ? '0' : '1').$this->cleTri($c))
            ->values();

        return [$this->assembler($campagne, $seance, $tous, $retenus), $dossiers];
    }

    /** @return list<Doublon> */
    public function doublons(CampagneCandidature $campagne): array
    {
        return $this->detecteur->doublons($this->retenus($campagne)->get(self::COLONNES));
    }

    public function construire(CampagneCandidature $campagne, SeanceJury $seance, bool $conserverDoublons = false): ResultatsSelection
    {
        [$tous, $retenus] = $this->charger($campagne, $conserverDoublons);

        return $this->assembler($campagne, $seance, $tous, $retenus);
    }

    /** @return array{0: Collection<int, Candidature>, 1: Collection<int, Candidature>} tous, puis retenus après dédoublonnage */
    private function charger(CampagneCandidature $campagne, bool $conserverDoublons): array
    {
        $tous = $this->retenus($campagne)->get(self::COLONNES);

        return [$tous, $conserverDoublons ? $tous : $this->detecteur->dedoublonner($tous)];
    }

    /**
     * @param  Collection<int, Candidature>  $tous
     * @param  Collection<int, Candidature>  $retenus
     */
    private function assembler(CampagneCandidature $campagne, SeanceJury $seance, Collection $tous, Collection $retenus): ResultatsSelection
    {
        $nomsPays = $this->nomsPays($retenus);
        $candidats = $retenus
            ->map(fn (Candidature $c): array => ['candidature' => $c, 'ligne' => $this->ligne($c, $nomsPays)])
            // Numéro de dossier en second critère : homonymes numérotés à
            // l'identique dans le communiqué et dans le PV.
            ->sortBy(fn (array $r): string => $this->cleTri($r['candidature']))
            ->values();

        [$nationaux, $etrangers] = $candidats->partition(
            fn (array $r): bool => $r['candidature']->nationalite === self::NATIONAL,
        );

        return new ResultatsSelection(
            promotionNumero: (int) $campagne->promotion_numero,
            anneeAcademique: $this->anneeAcademique($campagne),
            totalEnLigne: Candidature::query()->forCampagne($campagne->id)->soumises()->count(),
            parPays: $this->parPays($retenus, $nomsPays),
            parGenre: SpecialitesOfficielles::repartir($retenus, fn (Candidature $c): ?string => $c->genre,
                ['M' => 'HOMMES', 'F' => 'FEMMES'], ['autre' => 'AUTRE']),
            parMode: SpecialitesOfficielles::repartir($retenus, fn (Candidature $c): ?string => $c->type_etude,
                ['presentiel' => 'PRÉSENTIEL', 'distanciel' => 'DISTANCIEL']),
            nationaux: $nationaux->pluck('ligne')->values()->all(),
            etrangers: $etrangers->pluck('ligne')->values()->all(),
            jury: (array) config('resultats_selection.jury', []),
            seance: $seance,
            doublonsRetires: $tous->count() - $retenus->count(),
            profil: $this->analyseur->analyser($retenus, $seance->dateDeliberation ?? CarbonImmutable::now()),
        );
    }

    /** Ordre alphabétique sans accents ; numéro de dossier entre homonymes. */
    private function cleTri(Candidature $c): string
    {
        return Str::upper(Str::ascii($this->majuscules($c->nom).' '.$this->majuscules($c->prenom))).'|'.$c->numero_dossier;
    }

    /** @return Builder<Candidature> */
    private function retenus(CampagneCandidature $campagne): Builder
    {
        return Candidature::query()
            ->forCampagne($campagne->id)
            ->soumises()
            ->deposePhysiquement()
            ->whereNull('withdrawn_at')
            ->where('statut', '!=', Candidature::STATUT_REFUSE);
    }

    /** @param  array<string, string>  $nomsPays */
    private function ligne(Candidature $c, array $nomsPays): CandidatRetenu
    {
        return new CandidatRetenu(
            nom: $this->majuscules($c->nom),
            prenoms: $this->majuscules($c->prenom),
            mode: match ($c->type_etude) {
                'presentiel' => 'P',
                'distanciel' => 'D',
                default => self::NON_PRECISE,
            },
            nationalite: $nomsPays[$this->pays($c, $nomsPays)] ?? 'NON PRÉCISÉE',
        );
    }

    /**
     * @param  Collection<int, Candidature>  $retenus
     * @return array<string, string> code ISO => nom du pays en majuscules
     */
    private function nomsPays(Collection $retenus): array
    {
        $codes = $retenus->pluck('nationalite')->filter()->unique()->values()->all();

        return Pays::query()->whereIn('code_iso', $codes)->pluck('nom', 'code_iso')
            ->map(fn (string $nom): string => $this->majuscules($nom))
            ->all();
    }

    /**
     * Cameroun en première colonne, puis les autres nationalités présentes
     * par effectif décroissant.
     *
     * @param  Collection<int, Candidature>  $retenus
     * @param  array<string, string>  $nomsPays
     */
    private function parPays(Collection $retenus, array $nomsPays): Repartition
    {
        $pays = fn (Candidature $c): string => $this->pays($c, $nomsPays);
        $autres = $retenus->countBy($pays)->except(self::NATIONAL)->sortDesc()->keys();

        $colonnes = [self::NATIONAL => 'CAMEROUN'];
        foreach ($autres as $code) {
            $colonnes[$code] = $nomsPays[$code] ?? 'NON PRÉCISÉE';
        }

        return SpecialitesOfficielles::repartir($retenus, $pays, $colonnes);
    }

    /**
     * Code pays de la nationalité ; nationalité vide ou code inconnu de la
     * table `pays` regroupés sous une seule colonne « non précisée ».
     *
     * @param  array<string, string>  $nomsPays
     */
    private function pays(Candidature $c, array $nomsPays): string
    {
        $code = (string) $c->nationalite;

        return ($code === self::NATIONAL || array_key_exists($code, $nomsPays)) ? $code : self::NON_PRECISE;
    }

    /**
     * « Année académique 2026-2027 » → « 2026-2027 ». À défaut, l'année
     * d'ouverture de la campagne et la suivante.
     */
    private function anneeAcademique(CampagneCandidature $campagne): string
    {
        return $campagne->anneeAcademique();
    }

    private function majuscules(?string $texte): string
    {
        return TexteImprimable::majuscules($texte);
    }
}
