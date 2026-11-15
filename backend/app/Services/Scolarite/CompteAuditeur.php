<?php

declare(strict_types=1);

namespace App\Services\Scolarite;

use App\Models\Auditeur;
use App\Models\ImputationVersement;
use App\Models\InscriptionAnnuelle;
use App\Models\Versement;
use Illuminate\Database\Eloquent\Collection;

/**
 * Compte de l'auditeur en lecture seule (spec D1, §7.3) : situation de chaque inscription,
 * avance (libre ou réservée à l'employeur), arriérés et reste sur le parcours. Trois requêtes
 * au plus. Source de l'avance pour la fiche, l'espace, le relevé et l'export.
 */
final readonly class CompteAuditeur
{
    /**
     * @param  Collection<int, InscriptionAnnuelle>  $inscriptions  par année croissante, imputé préchargé
     * @param  Collection<int, Versement>  $versements  valides, ordre chronologique, imputé préchargé
     */
    private function __construct(
        public int $auditeurId,
        public Collection $inscriptions,
        public Collection $versements,
    ) {}

    public static function pour(Auditeur|int $auditeur): self
    {
        $id = $auditeur instanceof Auditeur ? (int) $auditeur->getKey() : $auditeur;

        return new self(
            $id,
            InscriptionAnnuelle::query()->where('auditeur_id', $id)->with('bareme')->withSum('imputationsActives', 'montant')->withSum('imputationsConfirmees', 'montant')
                ->orderBy('annee_academique')->get(),
            Versement::query()->where('auditeur_id', $id)->whereNull('annule_at')->withSum('imputationsActives', 'montant')
                ->orderBy('date_versement')->orderBy('id')->get(),
        );
    }

    public function situation(InscriptionAnnuelle $inscription): ?SituationFinanciere
    {
        return $this->inscriptions->firstWhere('id', $inscription->id)?->situation();
    }

    public function courante(): ?InscriptionAnnuelle
    {
        return $this->inscriptions->last();
    }

    public function avance(): int
    {
        return $this->avanceLibre() + $this->avanceReservee();
    }

    public function avanceLibre(): int
    {
        return $this->avanceDe(false);
    }

    /** Excédent des versements d'employeur, jamais imputé d'office sur une autre année (décision 7). */
    public function avanceReservee(): int
    {
        return $this->avanceDe(true);
    }

    /** Restes des inscriptions antérieures à la courante. */
    public function arrieres(): int
    {
        return $this->resteParcours() - ($this->courante()?->situation()->reste ?? 0);
    }

    public function resteParcours(): int
    {
        return (int) $this->inscriptions->sum(fn (InscriptionAnnuelle $i): int => $i->situation()->reste);
    }

    /**
     * Reste qu'un versement de ce payeur peut régler, le reste formant l'avance :
     * tout le parcours pour un versement libre, l'inscription désignée pour un employeur.
     */
    public function resteRegle(string $payeur, InscriptionAnnuelle $designee): int
    {
        return in_array($payeur, Versement::PAYEURS_RESERVES, true)
            ? ($this->situation($designee)?->reste ?? 0)
            : $this->resteParcours();
    }

    /** @return Collection<int, InscriptionAnnuelle> inscriptions touchées par les imputations actives du versement, par année */
    public function inscriptionsTouchees(Versement $versement): Collection
    {
        $ids = ImputationVersement::query()->where('versement_id', $versement->id)->whereNull('annule_at')
            ->distinct()->pluck('inscription_annuelle_id')->all();

        return $this->inscriptions->whereIn('id', $ids)->values();
    }

    private function avanceDe(bool $reserve): int
    {
        return (int) $this->versements
            ->filter(fn (Versement $v): bool => $v->estReserve() === $reserve)
            ->sum(fn (Versement $v): int => $v->montant - (int) $v->imputations_actives_sum_montant);
    }
}
