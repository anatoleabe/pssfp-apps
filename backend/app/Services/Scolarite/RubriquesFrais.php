<?php

declare(strict_types=1);

namespace App\Services\Scolarite;

use App\Models\BaremeScolarite;
use App\Models\CampagneCandidature;
use App\Models\RubriqueFrais;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Rubriques de frais et comptes de scolarité des promotions (reçu A4, R2). Les rubriques d'un couple (année, niveau)
 * s'enregistrent toutes ensemble, et seulement si leur somme égale le total du barème. Un couple dont les rubriques ne
 * servent pas (barème modifié depuis, montants de l'inscription différents) est signalé à l'écran, et l'émission d'un reçu
 * le journalise avant de ventiler par palier. Lecture : `finances.consulter` ; modification : `finances.rubriques_gerer`.
 */
final class RubriquesFrais
{
    public const PERMISSION = 'finances.rubriques_gerer';

    /** Compte de la pénalité de retard des soutenances (RAF) : jamais un compte de promotion. */
    public const COMPTE_INTERDIT = '710100';

    /**
     * Compte de scolarité de chaque promotion (plan comptable des produits de la RAF, 16/11/2026) : la pension s'impute sur
     * le compte de la promotion. Appliqué à la création d'une promotion (promotions antérieures, import des auditeurs) et
     * par la migration qui a ajouté la colonne.
     */
    public const COMPTES_PROMOTIONS = [
        4 => '710070', 5 => '710080', 6 => '710090', 7 => '710110', 8 => '710120', 9 => '710130',
        10 => '710140', 11 => '710150', 12 => '710160', 13 => '710170', 14 => '710180',
    ];

    public static function comptePromotion(int $promotion): ?string
    {
        return self::COMPTES_PROMOTIONS[$promotion] ?? null;
    }

    /**
     * Rubriques types proposées pour un couple vide (plan comptable de la RAF) : codes fixes, pension selon la promotion,
     * montants à saisir.
     *
     * @var list<array{libelle: string, code_imputation: ?string, compte_selon_promotion: bool}>
     */
    public const MODELE = [
        ['libelle' => 'Frais d\'inscription', 'code_imputation' => '710010', 'compte_selon_promotion' => false],
        ['libelle' => 'Frais de bibliothèque', 'code_imputation' => '770830', 'compte_selon_promotion' => false],
        ['libelle' => 'Tenue de sport', 'code_imputation' => '770820', 'compte_selon_promotion' => false],
        ['libelle' => 'Frais périscolaires', 'code_imputation' => '770810', 'compte_selon_promotion' => false],
        ['libelle' => 'Pension (frais de scolarité)', 'code_imputation' => null, 'compte_selon_promotion' => true],
    ];

    public static function peutGerer(?User $u): bool
    {
        return (bool) $u?->can(self::PERMISSION);
    }

    /**
     * Rubriques utilisables pour ventiler un reçu du couple : actives, et de somme égale au total du barème. Sinon null.
     *
     * @return Collection<int, RubriqueFrais>|null
     */
    public static function utilisables(string $annee, string $niveau, int $totalBareme): ?Collection
    {
        $etat = self::etat($annee, $niveau);

        return $etat['raison'] === null && $etat['somme'] === $totalBareme ? $etat['rubriques'] : null;
    }

    /**
     * État d'un couple (année, niveau), seule définition partagée par la ventilation et l'écran : rubriques actives, total
     * du barème, somme, et la raison pour laquelle elles ne servent pas aux reçus (null si elles servent).
     *
     * @return array{rubriques: Collection<int, RubriqueFrais>, total_bareme: ?int, somme: int, raison: ?string}
     */
    public static function etat(string $annee, string $niveau): array
    {
        $rubriques = RubriqueFrais::query()->duCouple($annee, $niveau)->get();
        $bareme = BaremeScolarite::query()->where('annee_academique', $annee)->where('niveau', $niveau)->first();
        $total = $bareme === null ? null : self::total($bareme);
        $somme = (int) $rubriques->sum('montant');
        $raison = match (true) {
            $rubriques->isEmpty() => 'pas de rubriques',
            $total === null => 'aucun barème pour ces rubriques',
            $somme !== $total => 'les rubriques totalisent '.RecusVersements::fcfa($somme).', le barème '.RecusVersements::fcfa($total),
            default => null,
        };

        return ['rubriques' => $rubriques, 'total_bareme' => $total, 'somme' => $somme, 'raison' => $raison];
    }

    /** Nombre d'anomalies, pour la pastille de l'écran Rubriques. */
    public static function nombreAnomalies(): int
    {
        return count(self::anomalies());
    }

    /**
     * Enregistre en une fois toutes les rubriques d'un couple (année, niveau), dans l'ordre donné (R2, relecture M2) :
     * refusé si la somme des rubriques actives n'égale pas le total du barème. Une ligne absente n'est pas supprimée : elle
     * est désactivée. Une rubrique « selon la promotion » n'a jamais de code fixe.
     *
     * @param  list<array{id?: ?int, libelle: string, code_imputation?: ?string, compte_selon_promotion?: bool, montant: int|string, actif?: bool}>  $lignes
     *
     * @throws InvalidArgumentException
     */
    public static function enregistrerCouple(string $annee, string $niveau, array $lignes, User $auteur): void
    {
        abort_unless(self::peutGerer($auteur), 403);
        if (preg_match('/^\d{4}-\d{4}$/', $annee) !== 1 || ! array_key_exists($niveau, BaremeScolarite::NIVEAUX)) {
            throw new InvalidArgumentException('Choisissez une année académique et un niveau.');
        }
        $bareme = BaremeScolarite::query()->where('annee_academique', $annee)->where('niveau', $niveau)->first();
        if ($bareme === null) {
            throw new InvalidArgumentException("Aucun barème {$annee} {$niveau} : créez-le d'abord.");
        }
        $propres = [];
        $libelles = [];
        foreach (array_values($lignes) as $k => $l) {
            $libelle = trim((string) ($l['libelle'] ?? ''));
            $selonPromotion = (bool) ($l['compte_selon_promotion'] ?? false);
            $code = $selonPromotion ? null : (filled($l['code_imputation'] ?? null) ? trim((string) $l['code_imputation']) : null);
            $montant = filter_var($l['montant'] ?? null, FILTER_VALIDATE_INT);
            if ($libelle === '' || mb_strlen($libelle) > 120) {
                throw new InvalidArgumentException('Ligne '.($k + 1).' : indiquez un libellé (120 caractères au plus).');
            }
            if (isset($libelles[mb_strtolower($libelle)])) {
                throw new InvalidArgumentException("Le libellé « {$libelle} » figure deux fois.");
            }
            $libelles[mb_strtolower($libelle)] = true;
            if ($montant === false || $montant < 0 || $montant > 100000000) {
                throw new InvalidArgumentException("« {$libelle} » : le montant doit être un nombre entier de FCFA, positif ou nul.");
            }
            if ($code !== null && ! self::compteValide($code)) {
                throw new InvalidArgumentException("« {$libelle} » : le code d'imputation compte six chiffres, et 710100 n'est pas un code de rubrique.");
            }
            $propres[] = ['id' => filled($l['id'] ?? null) ? (int) $l['id'] : null, 'libelle' => $libelle, 'code_imputation' => $code,
                'compte_selon_promotion' => $selonPromotion, 'montant' => $montant, 'actif' => (bool) ($l['actif'] ?? true)];
        }
        $actives = array_filter($propres, fn (array $l): bool => $l['actif']);
        if (count(array_filter($actives, fn (array $l): bool => $l['compte_selon_promotion'])) > 1) {
            throw new InvalidArgumentException('Une seule rubrique suit le compte de la promotion : la pension.');
        }
        $somme = (int) array_sum(array_column($actives, 'montant'));
        if ($somme !== self::total($bareme)) {
            throw new InvalidArgumentException('Les rubriques actives totalisent '.RecusVersements::fcfa($somme).' ; elles doivent égaler le total du barème, '
                .RecusVersements::fcfa(self::total($bareme)).'.');
        }

        DB::transaction(function () use ($annee, $niveau, $propres): void {
            // Unicités (ordre, libellé) contrôlées à la validation : un réordonnancement ou un échange de libellés passe.
            // Instruction PostgreSQL propre aux contraintes différables de la migration 2026_12_12_100200.
            DB::statement('SET CONSTRAINTS rubriques_frais_ordre_unique, rubriques_frais_libelle_unique DEFERRED');
            $existantes = RubriqueFrais::query()->where('annee_academique', $annee)->where('niveau', $niveau)->lockForUpdate()->get()->keyBy('id');
            $plan = [];
            $gardees = [];
            foreach ($propres as $k => $l) {
                $libre = fn (?RubriqueFrais $r): ?RubriqueFrais => $r !== null && ! in_array($r->id, $gardees, true) ? $r : null;
                $rubrique = $libre($l['id'] !== null ? $existantes->get($l['id']) : null)
                    ?? $libre($existantes->first(fn (RubriqueFrais $r): bool => mb_strtolower($r->libelle) === mb_strtolower($l['libelle']) && ! in_array($r->id, $gardees, true)))
                    ?? new RubriqueFrais(['annee_academique' => $annee, 'niveau' => $niveau]);
                if ($rubrique->exists) {
                    $gardees[] = $rubrique->id;
                }
                $plan[] = [$rubrique, ['libelle' => $l['libelle'], 'code_imputation' => $l['code_imputation'], 'compte_selon_promotion' => $l['compte_selon_promotion'],
                    'montant' => $l['montant'], 'actif' => $l['actif'], 'ordre' => $k + 1]];
            }
            // Lignes retirées du formulaire : désactivées, rangées après les autres, jamais supprimées.
            $suivant = count($propres) + 1;
            foreach ($existantes->except($gardees) as $r) {
                $plan[] = [$r, ['actif' => false, 'ordre' => $suivant++]];
            }
            // La pension active en dernier : l'index « une pension active par couple » est partiel, donc pas différable.
            $pensionActive = fn (array $e): bool => ($e[1]['compte_selon_promotion'] ?? $e[0]->compte_selon_promotion) && $e[1]['actif'];
            usort($plan, fn (array $a, array $b): int => (int) $pensionActive($a) <=> (int) $pensionActive($b));
            foreach ($plan as [$rubrique, $attributs]) {
                $rubrique->fill($attributs)->save();
            }
        });
    }

    /**
     * Anomalies, pour l'écran Rubriques : couple dont la somme diffère du barème (ou sans barème), barème sans rubriques,
     * promotion sans compte de scolarité.
     *
     * @return list<string>
     */
    public static function anomalies(): array
    {
        $anomalies = [];
        // Même définition que la ventilation (etat()) : tout couple qui a un barème ou des rubriques.
        $couples = BaremeScolarite::query()->get(['annee_academique', 'niveau'])->toBase()->map(fn ($b): string => $b->annee_academique.'|'.$b->niveau)
            ->merge(RubriqueFrais::query()->where('actif', true)->get(['annee_academique', 'niveau'])->toBase()->map(fn ($r): string => $r->annee_academique.'|'.$r->niveau))
            ->unique()->sort()->values();
        foreach ($couples as $cle) {
            [$annee, $niveau] = explode('|', $cle);
            $raison = self::etat($annee, $niveau)['raison'];
            if ($raison !== null) {
                $anomalies[] = "{$annee} {$niveau} : {$raison}. Les reçus de ce couple sont ventilés par palier.";
            }
        }
        foreach (CampagneCandidature::query()->whereNull('compte_scolarite')->whereHas('candidatures', fn ($q) => $q->whereHas('auditeur'))->orderBy('promotion_numero')->get() as $c) {
            $anomalies[] = 'Promotion '.$c->promotion_numero.' : pas de compte de scolarité. La pension de ses reçus s\'affiche « à préciser ».';
        }

        return $anomalies;
    }

    public static function total(BaremeScolarite $bareme): int
    {
        return (int) $bareme->montant_inscription + (int) $bareme->montant_tranche1 + (int) $bareme->montant_tranche2;
    }

    /**
     * Lignes du formulaire d'un couple : ses rubriques (actives d'abord, dans l'ordre), ou, pour un couple vide, les
     * rubriques types du plan comptable, montants à saisir. Rien n'est écrit : l'enregistrement passe par enregistrerCouple().
     *
     * @return list<array{id: ?int, libelle: string, code_imputation: ?string, compte_selon_promotion: bool, montant: int, actif: bool}>
     */
    public static function lignesDuCouple(string $annee, string $niveau): array
    {
        $rubriques = RubriqueFrais::query()->where('annee_academique', $annee)->where('niveau', $niveau)
            ->orderByDesc('actif')->orderBy('ordre')->get();
        if ($rubriques->isEmpty()) {
            return array_map(fn (array $r): array => ['id' => null] + $r + ['montant' => 0, 'actif' => true], self::MODELE);
        }

        return $rubriques->map(fn (RubriqueFrais $r): array => ['id' => $r->id, 'libelle' => $r->libelle, 'code_imputation' => $r->code_imputation,
            'compte_selon_promotion' => (bool) $r->compte_selon_promotion, 'montant' => (int) $r->montant, 'actif' => (bool) $r->actif])->values()->all();
    }

    /** Compte valide pour une promotion : six chiffres, jamais celui de la pénalité de retard. */
    public static function compteValide(?string $compte): bool
    {
        return $compte === null || (preg_match('/^\d{6}$/', $compte) === 1 && $compte !== self::COMPTE_INTERDIT);
    }
}
