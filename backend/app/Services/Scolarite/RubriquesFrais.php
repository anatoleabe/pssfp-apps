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
 * Rubriques de frais et comptes de scolarité des promotions (reçu A4, R2). Les rubriques d'un couple (année, niveau) ne
 * servent à la ventilation des reçus que si leur somme égale le total du barème ; sinon l'écran les signale en anomalie
 * et la ventilation retombe sur les paliers. Lecture : `finances.consulter` ; modification : `finances.rubriques_gerer`.
 */
final class RubriquesFrais
{
    public const PERMISSION = 'finances.rubriques_gerer';

    /** Compte de la pénalité de retard des soutenances (RAF) : jamais un compte de promotion. */
    public const COMPTE_INTERDIT = '710100';

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
        $rubriques = RubriqueFrais::query()->duCouple($annee, $niveau)->get();

        return $rubriques->isNotEmpty() && (int) $rubriques->sum('montant') === $totalBareme ? $rubriques : null;
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
        $baremes = BaremeScolarite::query()->get()->keyBy(fn (BaremeScolarite $b): string => $b->annee_academique.'|'.$b->niveau);
        $couples = RubriqueFrais::query()->where('actif', true)->get()->groupBy(fn (RubriqueFrais $r): string => $r->annee_academique.'|'.$r->niveau);
        foreach ($couples as $cle => $rubriques) {
            [$annee, $niveau] = explode('|', $cle);
            $bareme = $baremes->get($cle);
            $somme = (int) $rubriques->sum('montant');
            if ($bareme === null) {
                $anomalies[] = "{$annee} {$niveau} : aucun barème pour ces rubriques.";
            } elseif ($somme !== self::total($bareme)) {
                $anomalies[] = "{$annee} {$niveau} : les rubriques totalisent ".RecusVersements::fcfa($somme).', le barème '.RecusVersements::fcfa(self::total($bareme))
                    .'. Les reçus de ce couple sont ventilés par palier.';
            }
        }
        foreach ($baremes as $cle => $bareme) {
            if (! $couples->has($cle)) {
                $anomalies[] = "{$bareme->annee_academique} {$bareme->niveau} : pas de rubriques. Les reçus de ce couple sont ventilés par palier.";
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
     * Crée les rubriques types pour un couple vide (montants à zéro, à saisir) : l'écran les signale en anomalie tant que
     * leur somme n'égale pas le barème.
     *
     * @throws InvalidArgumentException
     */
    public static function proposerModele(string $annee, string $niveau, User $auteur): int
    {
        abort_unless(self::peutGerer($auteur), 403);
        if (preg_match('/^\d{4}-\d{4}$/', $annee) !== 1 || ! array_key_exists($niveau, BaremeScolarite::NIVEAUX)) {
            throw new InvalidArgumentException('Choisissez une année académique et un niveau.');
        }

        return DB::transaction(function () use ($annee, $niveau): int {
            if (RubriqueFrais::query()->where('annee_academique', $annee)->where('niveau', $niveau)->lockForUpdate()->exists()) {
                throw new InvalidArgumentException("{$annee} {$niveau} a déjà des rubriques : modifiez-les.");
            }
            foreach (self::MODELE as $ordre => $r) {
                RubriqueFrais::query()->create($r + ['annee_academique' => $annee, 'niveau' => $niveau, 'montant' => 0, 'ordre' => $ordre + 1, 'actif' => true]);
            }

            return count(self::MODELE);
        });
    }

    /** Compte valide pour une promotion : six chiffres, jamais celui de la pénalité de retard. */
    public static function compteValide(?string $compte): bool
    {
        return $compte === null || (preg_match('/^\d{6}$/', $compte) === 1 && $compte !== self::COMPTE_INTERDIT);
    }
}
