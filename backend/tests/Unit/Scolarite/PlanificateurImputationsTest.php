<?php

declare(strict_types=1);

use App\Services\Scolarite\AnnulationMultiAnnees;
use App\Services\Scolarite\EtatCompte;
use App\Services\Scolarite\InscriptionCompte;
use App\Services\Scolarite\PlanificateurImputations;
use App\Services\Scolarite\VersementCompte;
use Tests\Support\SimulateurCompte;

/*
 * Planificateur pur des imputations (spec D1, §6 et §14.1). Barème 2026-2027 :
 * M1 = 185 000 + 500 000 + 500 000 ; M2 = 135 000 + 500 000 + 500 000. Inscription
 * 1 : M1 2026-2027 ; inscription 2 : 2027-2028 (créée à la main, comme le feront
 * les tests de D3).
 */

const PLAN_SAISIE = PlanificateurImputations::SAISIE;
const PLAN_AVANCE = PlanificateurImputations::AVANCE;

function planM1(int $id = 1, string $annee = '2026-2027', int $remise = 0): InscriptionCompte
{
    return new InscriptionCompte($id, $annee, 185000, 500000, 500000, $remise);
}

function planM2(int $id = 2, string $annee = '2027-2028'): InscriptionCompte
{
    return new InscriptionCompte($id, $annee, 135000, 500000, 500000, 0);
}

function planVersement(int $id, string $date, int $montant, bool $employeur = false, int $designee = 1): VersementCompte
{
    return new VersementCompte($id, $date, $montant, $employeur, $designee);
}

/** E1 : M1, versement libre de 2 320 000. */
function planE1(): SimulateurCompte
{
    return SimulateurCompte::vide()->ouvrir(planM1())->verser(planVersement(1, '2026-11-12', 2320000), 7);
}

/** E5 : M1, 185 000 de l'auditeur, puis 1 185 000 de l'employeur sur le M1. */
function planE5(): SimulateurCompte
{
    return SimulateurCompte::vide()->ouvrir(planM1())
        ->verser(planVersement(1, '2026-11-10', 185000))
        ->verser(planVersement(2, '2027-03-15', 1185000, employeur: true));
}

/** E8 : M1, 685 000, 500 000, puis remise de 200 000. */
function planE8(): SimulateurCompte
{
    return SimulateurCompte::vide()->ouvrir(planM1())
        ->verser(planVersement(1, '2026-11-10', 685000))
        ->verser(planVersement(2, '2026-12-10', 500000))
        ->remise(1, 200000);
}

describe('exemples de référence (spec §6.10)', function (): void {
    it('E1 : solde le M1 et garde l\'excédent en avance libre, sans ligne', function (): void {
        $c = planE1();

        expect($c->actives())->toBe([[1, 1, 1185000, PLAN_SAISIE]])
            ->and($c->etat->avanceLibre())->toBe(1135000)
            ->and($c->etat->avanceReservee())->toBe(0)
            ->and(array_values($c->etat->imputations)[0]->creePar)->toBe(7);
    });

    it('E2 : impute l\'avance d\'elle-même à l\'ouverture de la M2', function (): void {
        $c = planE1()->ouvrir(planM2());

        expect($c->actives())->toBe([[1, 1, 1185000, PLAN_SAISIE], [1, 2, 1135000, PLAN_AVANCE]])
            ->and($c->etat->avance())->toBe(0)
            ->and($c->etat->reste(2))->toBe(0)
            ->and(array_values($c->etat->imputations)[1]->creePar)->toBeNull();
    });

    it('E3 : solde les arriérés avant l\'année en cours', function (): void {
        $c = SimulateurCompte::vide()->ouvrir(planM1())
            ->verser(planVersement(1, '2026-11-10', 185000))
            ->verser(planVersement(2, '2027-01-15', 500000))
            ->ouvrir(planM2())
            ->verser(planVersement(3, '2027-11-10', 300000));

        expect($c->actives())->toBe([[1, 1, 185000, PLAN_SAISIE], [2, 1, 500000, PLAN_SAISIE], [3, 1, 300000, PLAN_SAISIE]])
            ->and($c->etat->imp(2))->toBe(0)
            ->and($c->etat->arrieres())->toBe(200000)
            ->and($c->etat->resteParcours())->toBe(1335000);
    });

    it('E4 : impute l\'avance sur le M1 redoublé, au barème de la nouvelle année', function (): void {
        $c = SimulateurCompte::vide()->ouvrir(planM1())
            ->verser(planVersement(1, '2026-11-10', 1685000))
            ->ouvrir(planM1(2, '2027-2028'));

        expect($c->actives())->toBe([[1, 1, 1185000, PLAN_SAISIE], [1, 2, 500000, PLAN_AVANCE]])
            ->and($c->etat->reste(2))->toBe(685000);
    });

    it('E5 : réserve l\'excédent de l\'employeur', function (): void {
        $c = planE5();

        expect($c->actives())->toBe([[1, 1, 185000, PLAN_SAISIE], [2, 1, 1000000, PLAN_SAISIE]])
            ->and($c->etat->avanceReservee())->toBe(185000)
            ->and($c->etat->avanceLibre())->toBe(0);
    });

    it('E6 : après l\'annulation des 185 000, l\'employeur comble le M1 (passe 1)', function (): void {
        $c = planE5()->annuler(1);

        expect($c->actives())->toBe([[2, 1, 1000000, PLAN_SAISIE], [2, 1, 185000, PLAN_AVANCE]])
            ->and($c->etat->avance())->toBe(0)
            ->and($c->etat->reste(1))->toBe(0)
            ->and($c->dernierPlan->desimputations[0]->motif)->toBe('Versement annulé : Bordereau rejeté');
    });

    it('E7 : n\'impute jamais d\'office l\'excédent de l\'employeur sur une autre année', function (): void {
        $c = planE5()->ouvrir(planM2());

        expect($c->dernierPlan->imputations)->toBe([])
            ->and($c->etat->avanceReservee())->toBe(185000)
            ->and($c->etat->reste(2))->toBe(1135000);
    });

    it('E8 : une hausse de remise désimpute le plus récent, en gardant la part conservée', function (): void {
        $c = planE8();
        $remplacee = $c->dernierPlan->desimputations[0]->id;

        expect($c->actives())->toBe([[1, 1, 685000, PLAN_SAISIE], [2, 1, 300000, PLAN_SAISIE]])
            ->and($c->etat->avanceLibre())->toBe(200000)
            ->and($c->dernierPlan->imputations[0]->remplace)->toBe($remplacee)
            ->and($c->dernierPlan->desimputations[0]->motif)->toBe('Remise portée à 200 000 FCFA : Bourse d\'études');
    });

    it('E9 : la remise retirée, l\'avance revient sur le M1 depuis le même versement', function (): void {
        $c = planE8()->remise(1, 0, 'Bourse retirée');

        expect($c->actives())->toBe([[1, 1, 685000, PLAN_SAISIE], [2, 1, 300000, PLAN_SAISIE], [2, 1, 200000, PLAN_AVANCE]])
            ->and($c->etat->avance())->toBe(0);
    });

    it('E10 : une exonération totale annule l\'imputation et laisse l\'argent en avance', function (): void {
        $c = SimulateurCompte::vide()->ouvrir(planM1())
            ->verser(planVersement(1, '2026-11-10', 185000))
            ->remise(1, 1185000, 'Exonération totale');

        expect($c->actives())->toBe([])
            ->and($c->etat->avanceLibre())->toBe(185000)
            ->and($c->etat->reste(1))->toBe(0);
    });

    it('E11 : refuse en D1 l\'annulation d\'un versement imputé sur deux années', function (): void {
        $c = planE1()->ouvrir(planM2());

        expect(fn () => $c->annuler(1))->toThrow(
            AnnulationMultiAnnees::class,
            'Ce versement est imputé sur plusieurs années (2026-2027, 2027-2028) : son annulation passera par une demande d\'autorisation (sous-lot D4). Prévenez l\'USI.',
        );
    });
});

describe('règles', function (): void {
    it('R1 : remplit les paliers de l\'année la plus ancienne d\'abord, même pour un versement saisi sur la courante', function (): void {
        $c = SimulateurCompte::vide()->ouvrir(planM1())->ouvrir(planM2())
            ->verser(planVersement(1, '2027-11-10', 1300000, designee: 2));

        expect($c->actives())->toBe([[1, 1, 1185000, PLAN_SAISIE], [1, 2, 115000, PLAN_SAISIE]]);
    });

    it('R2 : un versement d\'employeur ne règle que l\'inscription qu\'il désigne', function (): void {
        $c = SimulateurCompte::vide()->ouvrir(planM1())->ouvrir(planM2())
            ->verser(planVersement(1, '2027-11-10', 200000, employeur: true, designee: 2));

        expect($c->actives())->toBe([[1, 2, 200000, PLAN_SAISIE]])
            ->and($c->etat->reste(1))->toBe(1185000);
    });

    it('R3 : consomme d\'abord le versement libre le plus ancien', function (): void {
        $c = SimulateurCompte::vide()->ouvrir(planM1())
            ->verser(planVersement(1, '2026-11-10', 1385000))
            ->verser(planVersement(2, '2026-11-01', 300000))
            ->ouvrir(planM2());

        // Le versement 2, saisi après le 1 mais daté avant lui, passe le premier.
        expect(array_map(fn ($x): array => [$x->versement, $x->inscription, $x->montant], $c->dernierPlan->imputations))
            ->toBe([[2, 2, 300000], [1, 2, 200000]]);
    });

    it('R3 : l\'employeur comble d\'abord l\'année qu\'il couvre, l\'argent de l\'auditeur reste libre', function (): void {
        $c = SimulateurCompte::vide()->ouvrir(planM1())
            ->verser(planVersement(1, '2026-11-10', 1185000, employeur: true))
            ->verser(planVersement(2, '2026-11-20', 300000))
            ->remise(1, 300000, 'Bourse partielle')
            ->remise(1, 0, 'Bourse retirée');

        expect($c->actives())->toBe([[1, 1, 885000, PLAN_SAISIE], [1, 1, 300000, PLAN_AVANCE]])
            ->and($c->etat->avanceLibre())->toBe(300000)
            ->and($c->etat->avanceReservee())->toBe(0);
    });

    it('R5 : départage deux versements du même jour par l\'ordre d\'enregistrement, le plus récent désimputé d\'abord', function (): void {
        $c = SimulateurCompte::vide()->ouvrir(planM1())
            ->verser(planVersement(4, '2026-11-10', 600000))
            ->verser(planVersement(9, '2026-11-10', 400000))
            ->remise(1, 500000, 'Bourse partielle');

        expect($c->actives())->toBe([[4, 1, 600000, PLAN_SAISIE], [9, 1, 85000, PLAN_SAISIE]])
            ->and($c->etat->avanceLibre())->toBe(315000);
    });

    it('R5 : annule en entier les imputations plus petites que l\'excédent, puis découpe la suivante', function (): void {
        $c = SimulateurCompte::vide()->ouvrir(planM1())
            ->verser(planVersement(1, '2026-11-10', 185000))
            ->verser(planVersement(2, '2026-11-20', 100000))
            ->verser(planVersement(3, '2026-11-30', 100000))
            ->remise(1, 1100000, 'Bourse');

        // Dû ramené à 85 000 : 300 000 désimputés (100 000, 100 000, puis 100 000 sur les 185 000).
        expect($c->actives())->toBe([[1, 1, 85000, PLAN_SAISIE]])
            ->and(count($c->dernierPlan->desimputations))->toBe(3)
            ->and($c->etat->avanceLibre())->toBe(300000);
    });

    it('n\'écrit rien quand il n\'y a ni avance ni reste', function (): void {
        $c = SimulateurCompte::vide()->ouvrir(planM1())->verser(planVersement(1, '2026-11-10', 185000));
        $avance = (new PlanificateurImputations)->avance($c->etat);

        expect($avance->imputations)->toBe([])
            ->and($avance->desimputations)->toBe([]);
    });

    it('reprend les versements sur l\'inscription qu\'ils désignent, jusqu\'au dû, sans toucher l\'excédent', function (): void {
        $etat = new EtatCompte([planM1()], [planVersement(2, '2026-11-12', 1000000), planVersement(1, '2026-11-10', 285000)], []);
        $plan = (new PlanificateurImputations)->reprise($etat);

        expect(array_map(fn ($x): array => [$x->versement, $x->montant, $x->origine], $plan->imputations))
            ->toBe([[1, 285000, PlanificateurImputations::REPRISE], [2, 900000, PlanificateurImputations::REPRISE]])
            ->and($plan->etat->avanceLibre())->toBe(100000);
    });
});

describe('propriétés (suites aléatoires)', function (): void {
    /**
     * Suite d'événements tirée au sort, rejouable par sa graine. `$uneInscription` : jamais de
     * deuxième année (équivalence avec l'ancienne formule).
     *
     * @return list<array{0: string, 1: mixed}>
     */
    $tirer = function (int $graine, bool $uneInscription): array {
        mt_srand($graine);
        $evenements = [];
        $versement = 0;
        $annees = 1;
        $longueur = mt_rand(4, 30);
        for ($n = 0; $n < $longueur; $n++) {
            $coup = mt_rand(1, 100);
            if ($coup <= 50) {
                $versement++;
                $evenements[] = ['verser', [$versement, sprintf('2026-%02d-%02d', mt_rand(1, 12), mt_rand(1, 28)),
                    [5000, 135000, 185000, 300000, 500000, 685000, 1185000, 1300000, 2320000][mt_rand(0, 8)] + mt_rand(0, 3) * 1000,
                    mt_rand(1, 5) === 1, mt_rand(1, $annees)]];
            } elseif ($coup <= 68) {
                $evenements[] = ['annuler', mt_rand(1, max(1, $versement))];
            } elseif ($coup <= 88) {
                $evenements[] = ['remise', [mt_rand(1, $annees), [0, 0, 100000, 200000, 500000, 1000000, 1185000][mt_rand(0, 6)]]];
            } elseif (! $uneInscription && $annees < 4) {
                $annees++;
                $evenements[] = ['ouvrir', [$annees, mt_rand(0, 1) === 1]];
            }
        }

        return $evenements;
    };

    /** @param  list<array{0: string, 1: mixed}>  $evenements */
    $rejouer = function (array $evenements, callable $apres): array {
        $c = SimulateurCompte::vide()->ouvrir(planM1());
        $valides = [];
        $plans = [];
        foreach ($evenements as [$type, $p]) {
            try {
                $c = match ($type) {
                    'verser' => $c->verser(planVersement($p[0], $p[1], $p[2], $p[3], $p[4])),
                    'annuler' => isset($c->etat->versements[$p]) ? $c->annuler($p) : $c,
                    'remise' => $c->remise($p[0], min($p[1], $c->etat->inscriptions[$p[0]]->total())),
                    'ouvrir' => $c->ouvrir($p[1] ? planM1($p[0], (2025 + $p[0]).'-'.(2026 + $p[0])) : planM2($p[0], (2025 + $p[0]).'-'.(2026 + $p[0]))),
                };
            } catch (AnnulationMultiAnnees) {
                // Refus attendu en D1 : rien n'a changé.
            }
            if ($type === 'verser') {
                $valides[$p[0]] = $p[2];
            }
            if ($type === 'annuler' && ! isset($c->etat->versements[$p])) {
                unset($valides[$p]);
            }
            $plans[] = $c->dernierPlan === null ? null : serialize([$c->dernierPlan->desimputations, $c->dernierPlan->imputations]);
            $apres($c, $valides);
        }

        return $plans;
    };

    $invariants = function (SimulateurCompte $c, string $contexte): void {
        $e = $c->etat;
        foreach ($e->imputations as $x) {
            expect($x->montant)->toBeGreaterThan(0, $contexte)
                ->and(isset($e->versements[$x->versement]))->toBeTrue($contexte.' : imputation sur un versement annulé');
        }
        foreach ($e->versements as $v) {
            expect($e->impute($v->id))->toBeLessThanOrEqual($v->montant, $contexte);
        }
        $restes = 0;
        foreach ($e->inscriptions as $i) {
            expect($e->imp($i->id))->toBeLessThanOrEqual($i->du(), $contexte)->and($e->reste($i->id))->toBeGreaterThanOrEqual(0, $contexte);
            $restes += $e->reste($i->id);
        }
        // Invariant d'avance (§6.7).
        if ($e->avanceLibre() > 0) {
            expect($restes)->toBe(0, $contexte.' : avance libre à côté d\'un reste');
        }
        foreach ($e->versements as $v) {
            if ($v->reserve && $e->dispo($v->id) > 0 && isset($e->inscriptions[$v->designee])) {
                expect($e->reste($v->designee))->toBe(0, $contexte.' : avance réservée à côté d\'un reste de son année');
            }
        }
        // Conservation : versé = imputé + avance.
        $verse = array_sum(array_map(fn ($v): int => $v->montant, $e->versements));
        $impute = array_sum(array_map(fn ($i): int => $e->imp($i->id), $e->inscriptions));
        expect($verse)->toBe($impute + $e->avance(), $contexte.' : conservation');
    };

    it('tiennent les garde-fous, l\'invariant d\'avance et la conservation, de façon déterministe', function () use ($tirer, $rejouer, $invariants): void {
        $base = (int) (getenv('D1_GRAINE') ?: random_int(1, 1_000_000));
        for ($k = 0; $k < 1000; $k++) {
            $graine = $base + $k;
            $evenements = $tirer($graine, false);
            $contexte = 'graine '.$graine.' (D1_GRAINE='.$graine.' pour rejouer)';
            $premier = $rejouer($evenements, fn (SimulateurCompte $c) => $invariants($c, $contexte));
            $second = $rejouer($evenements, fn () => null);
            expect($second)->toBe($premier, $contexte.' : déterminisme');
        }
    });

    it('équivalent à l\'ancienne formule pour un auditeur à une seule inscription', function () use ($tirer, $rejouer): void {
        $base = (int) (getenv('D1_GRAINE') ?: random_int(1, 1_000_000));
        for ($k = 0; $k < 1000; $k++) {
            $graine = $base + $k;
            $contexte = 'graine '.$graine.' (D1_GRAINE='.$graine.' pour rejouer)';
            $rejouer($tirer($graine, true), function (SimulateurCompte $c, array $valides) use ($contexte): void {
                $somme = array_sum($valides);
                $du = $c->etat->inscriptions[1]->du();
                expect($c->etat->imp(1))->toBe(min($somme, $du), $contexte)
                    ->and($c->etat->avance())->toBe($somme - min($somme, $du), $contexte);
            });
        }
    });
});
