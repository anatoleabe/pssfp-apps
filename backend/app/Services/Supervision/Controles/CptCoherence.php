<?php

declare(strict_types=1);

namespace App\Services\Supervision\Controles;

use App\Models\Auditeur;
use App\Services\Scolarite\RecusVersements;
use App\Services\Scolarite\VerificationComptes;
use App\Services\Supervision\Controle;
use App\Services\Supervision\EscaladeDediee;
use Illuminate\Support\Collection;

/**
 * Cohérence des comptes des auditeurs (spec D1, §13 ; ADR-0013).
 *
 * - `versement:<uuid>` : imputations actives au-delà du montant, ou sur un versement annulé ;
 * - `inscription:<uuid>` : imputé au-delà du dû ;
 * - `auditeur:<uuid>` : invariant d'avance rompu (avance libre à côté d'un reste ouvert, ou
 *   avance réservée alors que son inscription désignée a un reste) ;
 * - `reglement:<uuid>` (lot A2) : les versements valides d'un règlement d'organisme ne font pas
 *   son montant, ou un règlement annulé garde un versement valide (versement annulé seul).
 *
 * Les deux premiers cas ne naissent qu'en contournant les déclencheurs ; le troisième signale
 * un oubli de R3 dans le code. Responsable : l'USI (`admin`) ; escalade au super administrateur
 * (décision du 15/11/2026). Résumé : matricule et montants, sans nom.
 */
final class CptCoherence implements Controle, EscaladeDediee
{
    public function __construct(private readonly VerificationComptes $verification) {}

    public function code(): string
    {
        return 'CPT_COHERENCE';
    }

    public function libelle(): string
    {
        return 'Cohérence des comptes des auditeurs (versements, imputations, avances)';
    }

    public function domaine(): string
    {
        return 'Scolarité';
    }

    public function criticite(): string
    {
        return self::BLOQUANT;
    }

    public function delaiJours(): int
    {
        return 1;
    }

    public function responsable(): string
    {
        return 'admin';
    }

    public function escalade(): string
    {
        return 'super_admin';
    }

    public function cas(): array
    {
        $versements = $this->verification->versementsSurImputes();
        $inscriptions = $this->verification->inscriptionsSurImputees();
        $avances = $this->verification->avancesIncoherentes();
        $matricules = $this->matricules($versements->pluck('auditeur_id')->merge($inscriptions->pluck('auditeur_id'))->merge($avances->pluck('auditeur_id')));
        $fcfa = fn ($montant): string => RecusVersements::fcfa((int) $montant);

        $cas = [];
        foreach ($versements as $v) {
            $cas['versement:'.$v->uuid] = [
                'resume' => $matricules($v->auditeur_id).' — '.($v->annule
                    ? 'versement annulé qui garde '.$fcfa($v->impute).' d\'imputations actives'
                    : 'versement de '.$fcfa($v->montant).' imputé à '.$fcfa($v->impute)),
                'versement' => (string) $v->uuid,
            ];
        }
        foreach ($inscriptions as $i) {
            $cas['inscription:'.$i->uuid] = [
                'resume' => $matricules($i->auditeur_id).' — inscription imputée à '.$fcfa($i->impute).' pour un dû de '.$fcfa($i->du),
                'inscription' => (string) $i->uuid,
            ];
        }
        foreach ($this->verification->reglementsIncoherents() as $r) {
            $cas['reglement:'.$r->uuid] = [
                'resume' => 'Règlement '.$r->reference.' — '.($r->annule
                    ? 'annulé, garde '.$fcfa($r->verse).' de versements valides'
                    : 'montant de '.$fcfa($r->montant).', versements valides de '.$fcfa($r->verse)),
                'reglement' => (string) $r->uuid,
            ];
        }
        foreach ($avances as $a) {
            $cas['auditeur:'.$a->uuid] = [
                'resume' => $matricules($a->auditeur_id).' — '.($a->reservee_en_attente > 0
                    ? 'avance réservée de '.$fcfa($a->reservee_en_attente).' alors que son année désignée a un reste'
                    : 'avance libre de '.$fcfa($a->avance_libre).' à côté d\'un reste de '.$fcfa($a->restes)),
                'auditeur' => (string) $a->uuid,
            ];
        }

        return $cas;
    }

    /**
     * Le matricule suffit à retrouver la fiche : pas de nom dans le contrôle interne.
     *
     * @param  Collection<int, mixed>  $ids
     * @return callable(mixed): string
     */
    private function matricules(Collection $ids): callable
    {
        $connus = Auditeur::query()->whereKey($ids->unique()->values()->all())->with('candidature:id,matricule')->get()
            ->mapWithKeys(fn (Auditeur $a): array => [$a->id => (string) ($a->candidature?->matricule ?: 'sans matricule')]);

        return fn ($id): string => $connus[(int) $id] ?? 'sans matricule';
    }
}
