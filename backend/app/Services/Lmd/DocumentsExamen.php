<?php

declare(strict_types=1);

namespace App\Services\Lmd;

use App\Models\EpreuveExamen;
use App\Models\InscriptionPedagogique;
use App\Models\PlanningExamens;
use App\Models\Salle;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;

/** Documents d'un planning d'examens (spec lot 27) : planning de la session, listes d'émargement par salle. */
final class DocumentsExamen
{
    public function __construct(private readonly CandidatsExamen $candidats, private readonly DocumentsOfficiels $documents) {}

    /**
     * Une page par salle de chaque épreuve : candidats, surveillants, auditeurs non autorisés à composer (décision D5).
     *
     * @param  Collection<int, EpreuveExamen>  $epreuves
     * @return list<array{epreuve: EpreuveExamen, salle: Salle, candidats: Collection<int, InscriptionPedagogique>, surveillants: Collection<int, User>, nonAutorises: array<int, bool>}>
     */
    public function pages(Collection $epreuves): array
    {
        $pages = [];
        foreach ($epreuves as $e) {
            foreach ($this->candidats->repartition($e)['salles'] as $s) {
                $pages[] = ['epreuve' => $e, 'salle' => $s['salle'], 'candidats' => $s['candidats'], 'surveillants' => $s['surveillants'],
                    'nonAutorises' => $this->documents->nonEnRegle($s['candidats']->pluck('inscription_annuelle_id'))];
            }
        }

        return $pages;
    }

    /** @param  Collection<int, EpreuveExamen>  $epreuves */
    public function emargements(PlanningExamens $p, Collection $epreuves): string
    {
        return Pdf::loadView('pdf.lmd.emargement-salle', ['p' => $p, 'pages' => $this->pages($epreuves)])->setPaper('a4')->output();
    }

    /**
     * Lignes du planning : une par épreuve, avec ses salles, leurs effectifs et leurs surveillants.
     *
     * @return list<array{epreuve: EpreuveExamen, effectif: int, salles: list<array{salle: Salle, candidats: Collection<int, InscriptionPedagogique>, surveillants: Collection<int, User>}>}>
     */
    public function lignes(PlanningExamens $p): array
    {
        return $p->epreuves()->with(['salles', 'surveillants', 'planning.session.calendrier'])->get()
            ->map(fn (EpreuveExamen $e): array => ['epreuve' => $e, 'effectif' => $this->candidats->pour($e)->count(), 'salles' => $this->candidats->repartition($e)['salles']])->all();
    }

    public function planning(PlanningExamens $p): string
    {
        return Pdf::loadView('pdf.lmd.planning-examens', ['p' => $p, 'lignes' => $this->lignes($p)])->setPaper('a4', 'landscape')->output();
    }
}
