<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Applications;

use App\Http\Controllers\Controller;
use App\Models\CalendrierAcademique;
use App\Models\EpreuveExamen;
use App\Models\PlanningExamens;
use App\Services\Lmd\CandidatsExamen;
use App\Services\Lmd\DocumentsOfficiels;
use App\Services\Scolarite\DossierAuditeurConnecte;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * « Mes examens » de l'auditeur (spec module 4, lot 27) : épreuves des plannings publiés de son année
 * où il est candidat, avec sa salle. Propriété stricte.
 */
final class ExamensController extends Controller
{
    private const SANS_CACHE = ['Cache-Control' => 'no-store, private'];

    public function index(Request $request, DossierAuditeurConnecte $dossiers, CandidatsExamen $candidats, DocumentsOfficiels $documents): JsonResponse
    {
        $inscription = $dossiers->pour($request->user())?->auditeur?->inscriptionCourante;
        if ($inscription === null) {
            return response()->json(['message' => 'Aucune inscription en cours pour ce compte.', 'code' => 'auditeur_missing'], Response::HTTP_NOT_FOUND);
        }
        $epreuves = EpreuveExamen::query()
            ->whereHas('planning', fn (Builder $q) => $q->where('statut', PlanningExamens::PUBLIE)
                ->whereHas('session.calendrier', fn (Builder $c) => $c->where('annee_academique', $inscription->annee_academique)
                    ->where('statut', '<>', CalendrierAcademique::REMPLACE)))
            ->with(['planning.session.calendrier', 'salles', 'surveillants'])->orderBy('date_epreuve')->orderBy('debut')->get();

        $miennes = [];
        foreach ($epreuves as $e) {
            if (! $candidats->pour($e)->contains('inscription_annuelle_id', $inscription->id)) {
                continue;
            }
            $salle = collect($candidats->repartition($e)['salles'])
                ->first(fn (array $s): bool => $s['candidats']->contains('inscription_annuelle_id', $inscription->id))['salle'] ?? null;
            $miennes[] = [
                'id' => $e->uuid,
                'session' => $e->planning->libelle(),
                'rattrapage' => $e->planning->estRattrapage(),
                'date' => $e->date_epreuve->toDateString(),
                'debut' => substr((string) $e->debut, 0, 5),
                'fin' => substr((string) $e->fin, 0, 5),
                'ecue' => $e->code_ecue,
                'intitule' => $e->intitule,
                'salle' => $salle?->libelle,
            ];
        }

        return response()->json(['data' => [
            // Décision D5 (lot 19) : en retard de paiement sans levée, l'auditeur n'est pas autorisé à composer.
            'autorise' => $documents->nonEnRegle([$inscription->id]) === [],
            'epreuves' => $miennes,
        ]], Response::HTTP_OK, self::SANS_CACHE);
    }
}
