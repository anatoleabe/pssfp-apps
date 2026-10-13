<?php

declare(strict_types=1);

namespace App\Services\ResultatsSelection;

use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\Pays;
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

    private const NON_PRECISE = '—';

    private const COLONNES = ['id', 'numero_dossier', 'nom', 'prenom', 'date_naissance', 'genre', 'nationalite',
        'specialite', 'type_etude', 'submitted_at', 'depot_physique_at'];

    public function __construct(private readonly DetecteurDoublons $detecteur) {}

    /** @return list<Doublon> */
    public function doublons(CampagneCandidature $campagne): array
    {
        return $this->detecteur->doublons($this->retenus($campagne)->get(self::COLONNES));
    }

    public function construire(CampagneCandidature $campagne, SeanceJury $seance, bool $conserverDoublons = false): ResultatsSelection
    {
        $tous = $this->retenus($campagne)->get(self::COLONNES);
        $retenus = $conserverDoublons ? $tous : $this->detecteur->dedoublonner($tous);

        $nomsPays = $this->nomsPays($retenus);
        $candidats = $retenus
            ->map(fn (Candidature $c): array => ['candidature' => $c, 'ligne' => $this->ligne($c, $nomsPays)])
            // Numéro de dossier en second critère : homonymes numérotés à
            // l'identique dans le communiqué et dans le PV.
            ->sortBy(fn (array $r): string => Str::upper(Str::ascii($r['ligne']->nomComplet())).'|'.$r['candidature']->numero_dossier)
            ->values();

        [$nationaux, $etrangers] = $candidats->partition(
            fn (array $r): bool => $r['candidature']->nationalite === self::NATIONAL,
        );

        return new ResultatsSelection(
            promotionNumero: (int) $campagne->promotion_numero,
            anneeAcademique: $this->anneeAcademique($campagne),
            totalEnLigne: Candidature::query()->forCampagne($campagne->id)->soumises()->count(),
            parPays: $this->parPays($retenus, $nomsPays),
            parGenre: $this->repartir($retenus, 'genre', ['M' => 'HOMMES', 'F' => 'FEMMES'], ['autre' => 'AUTRE']),
            parMode: $this->repartir($retenus, 'type_etude', ['presentiel' => 'PRÉSENTIEL', 'distanciel' => 'DISTANCIEL']),
            nationaux: $nationaux->pluck('ligne')->values()->all(),
            etrangers: $etrangers->pluck('ligne')->values()->all(),
            jury: (array) config('resultats_selection.jury', []),
            seance: $seance,
            doublonsRetires: $tous->count() - $retenus->count(),
        );
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

        return new Repartition($colonnes, $this->lignes($retenus, $pays));
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
     * @param  Collection<int, Candidature>  $retenus
     * @param  array<string, string>  $colonnes  valeurs attendues => libellé
     * @param  array<string, string>  $optionnelles  colonnes affichées seulement si non vides
     */
    private function repartir(Collection $retenus, string $attribut, array $colonnes, array $optionnelles = []): Repartition
    {
        $connues = $colonnes + $optionnelles;
        $cle = fn (Candidature $c): string => array_key_exists((string) $c->{$attribut}, $connues)
            ? (string) $c->{$attribut}
            : self::NON_PRECISE;

        // Colonnes facultatives et « non précisé » seulement si un dossier en
        // relève : les tableaux restent identiques aux PV précédents.
        $presentes = $retenus->map($cle)->unique()->all();
        foreach ($optionnelles + [self::NON_PRECISE => 'NON PRÉCISÉ'] as $valeur => $libelle) {
            if (in_array($valeur, $presentes, true)) {
                $colonnes[$valeur] = $libelle;
            }
        }

        return new Repartition($colonnes, $this->lignes($retenus, $cle));
    }

    /**
     * Une ligne par spécialité officielle, dans l'ordre des PV, même vide ;
     * un intitulé hors liste est rattaché à l'une d'elles.
     *
     * @param  Collection<int, Candidature>  $retenus
     * @param  callable(Candidature): string  $colonne
     * @return array<string, array<string, int>>
     */
    private function lignes(Collection $retenus, callable $colonne): array
    {
        $lignes = array_fill_keys(array_values(SpecialitesOfficielles::ordonnees()), []);

        foreach ($retenus as $c) {
            $ligne = SpecialitesOfficielles::libelle($c->specialite);
            $col = $colonne($c);
            $lignes[$ligne][$col] = ($lignes[$ligne][$col] ?? 0) + 1;
        }

        return $lignes;
    }

    /**
     * « Année académique 2026-2027 » → « 2026-2027 ». À défaut, l'année
     * d'ouverture de la campagne et la suivante.
     */
    private function anneeAcademique(CampagneCandidature $campagne): string
    {
        $nom = (string) $campagne->getTranslation('nom', 'fr', true);
        if (preg_match('/(\d{4})\s*[-–\/]\s*(\d{4})/u', $nom, $m) === 1) {
            return $m[1].'-'.$m[2];
        }

        $debut = ($campagne->opens_at ?? now())->year;

        return $debut.'-'.($debut + 1);
    }

    /**
     * Majuscules, espaces normalisés. Les caractères de contrôle et de format
     * (saisis par erreur ou à dessein) sont retirés : interdits en XML, ils
     * rendraient le .docx illisible dans Word.
     */
    private function majuscules(?string $texte): string
    {
        $texte = (string) preg_replace('/\s+/u', ' ', (string) $texte);
        $texte = (string) preg_replace('/[\p{Cc}\p{Cf}]/u', '', $texte);

        return mb_strtoupper(trim($texte));
    }
}
