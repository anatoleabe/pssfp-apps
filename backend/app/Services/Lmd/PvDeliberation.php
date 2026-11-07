<?php

declare(strict_types=1);

namespace App\Services\Lmd;

use App\Models\Deliberation;
use App\Models\ResultatAnnuel;
use App\Models\ResultatScenario;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;

/**
 * PV de délibération et liste d'affichage en PDF (D35, D37). Le PV d'une
 * délibération arrêtée lit les résultats figés ; sinon le scénario retenu
 * (« projet »). L'empreinte du premier PV arrêté produit est enregistrée une fois.
 */
final class PvDeliberation
{
    public function __construct(private readonly Deliberations $deliberations) {}

    public function pv(Deliberation $d): string
    {
        $d->loadMissing(['jury', 'parcours', 'rectifiee']);
        $arretee = $d->statut !== Deliberation::SIMULATION;
        $resultats = $this->resultats($d, $arretee);
        $scenario = $d->scenarioRetenu;
        $mesures = $scenario?->mesures()->get() ?? collect();
        $participations = $d->participations()->with('membre')->get()
            ->sortBy(fn ($p): array => [array_search($p->membre->role, ['president', 'vice_president', 'rapporteur', 'membre'], true), $p->membre->rang])->values();

        $pdf = Pdf::loadView('pdf.lmd.pv-deliberation', [
            'd' => $d,
            'resultats' => $resultats,
            'mesures' => $mesures,
            'participations' => $participations,
            'ecarts' => $d->rectifie_id !== null ? $this->deliberations->ecartsRectification($d) : ['favorables' => 0, 'defavorables' => 0],
            'synthese' => [
                'effectif' => $resultats->count(),
                'AD' => $resultats->where('decision', 'AD')->count(),
                'AS' => $resultats->where('decision', 'AS')->count(),
                'AJ' => $resultats->where('decision', 'AJ')->count(),
            ],
        ])->setPaper('a4', 'landscape')->output();

        if ($arretee && $d->pv_pdf_sha256 === null) {
            $d->forceFill(['pv_pdf_sha256' => hash('sha256', $pdf)])->save();
        }

        return $pdf;
    }

    /** Liste d'affichage sans notes (ENF-12), seulement pour une délibération arrêtée. */
    public function affichage(Deliberation $d): string
    {
        $d->loadMissing('parcours');

        return Pdf::loadView('pdf.lmd.affichage-resultats', ['d' => $d, 'resultats' => $this->resultats($d, true)])
            ->setPaper('a4', 'portrait')->output();
    }

    /** @return Collection<int, ResultatAnnuel|ResultatScenario> */
    private function resultats(Deliberation $d, bool $arretee): Collection
    {
        $query = $arretee
            ? ResultatAnnuel::query()->where('deliberation_id', $d->id)
            : ResultatScenario::query()->whereIn('scenario_id', $d->scenarios()->where('retenu', true)->select('id'));

        return $query->with('inscriptionAnnuelle.auditeur.candidature')->get()
            ->sortBy(fn ($r): string => mb_strtoupper((string) $r->inscriptionAnnuelle?->auditeur?->candidature?->nom).' '.$r->inscriptionAnnuelle?->auditeur?->candidature?->prenom)
            ->values();
    }
}
