<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Applications;

use App\Http\Controllers\Controller;
use App\Models\CalendrierAcademique;
use App\Models\EmploiDuTemps;
use App\Models\Seance;
use App\Services\Scolarite\DossierAuditeurConnecte;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * « Emploi du temps » de l'auditeur (spec module 4, lot 12c, §4) : séances des emplois du temps
 * publiés de son parcours, semaine par semaine. Propriété stricte.
 */
final class EmploiDuTempsController extends Controller
{
    private const SANS_CACHE = ['Cache-Control' => 'no-store, private'];

    public function show(Request $request, DossierAuditeurConnecte $dossiers): JsonResponse
    {
        $request->validate(['semaine' => ['nullable', 'date_format:Y-m-d']]);
        $inscription = $dossiers->pour($request->user())?->auditeur?->inscriptionCourante;
        if ($inscription === null) {
            return response()->json(['message' => 'Aucune inscription en cours pour ce compte.', 'code' => 'auditeur_missing'], Response::HTTP_NOT_FOUND);
        }
        $lundi = CarbonImmutable::parse((string) ($request->query('semaine') ?? now('Africa/Douala')->toDateString()))->startOfWeek();
        $publies = Seance::query()->whereHas('emploiDuTemps', fn (Builder $q) => $q->where('statut', EmploiDuTemps::PUBLIE)
            ->where('parcours_id', $inscription->parcours_id)
            ->whereHas('periode.calendrier', fn (Builder $c) => $c->where('annee_academique', $inscription->annee_academique)
                ->where('statut', '<>', CalendrierAcademique::REMPLACE)));
        $seances = (clone $publies)->whereBetween('date_seance', [$lundi->toDateString(), $lundi->addDays(6)->toDateString()])
            ->with(['ecue:id,code,intitule', 'enseignant:id,name', 'remplacement:id,date_seance,debut'])->orderBy('date_seance')->orderBy('debut')->get();

        return response()->json(['data' => [
            'semaine' => $lundi->toDateString(),
            'precedente' => $lundi->subWeek()->toDateString(),
            'suivante' => $lundi->addWeek()->toDateString(),
            'premiere' => (clone $publies)->min('date_seance'),
            'derniere' => (clone $publies)->max('date_seance'),
            'seances' => $seances->map(fn (Seance $s): array => [
                'id' => $s->uuid,
                'date' => $s->date_seance->toDateString(),
                'debut' => substr((string) $s->debut, 0, 5),
                'fin' => substr((string) $s->fin, 0, 5),
                'ecue' => $s->ecue?->code,
                'intitule' => $s->ecue?->intitule,
                'nature' => $s->nature,
                'enseignant' => $s->enseignant?->name,
                'salle' => $s->salle,
                'lien_reunion' => $s->statut === 'prevue' && $s->modalite !== 'presentiel' ? $s->lien_reunion : null,
                'modalite' => $s->modalite,
                'statut' => $s->statut,
                'reportee_au' => $s->remplacement?->date_seance?->toDateString(),
            ])->values()->all(),
        ]], Response::HTTP_OK, self::SANS_CACHE);
    }
}
