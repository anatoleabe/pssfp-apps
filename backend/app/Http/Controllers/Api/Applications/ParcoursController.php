<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Applications;

use App\Http\Controllers\Controller;
use App\Http\Resources\ParcoursAuditeurResource;
use App\Models\RegleAssiduite;
use App\Services\Lmd\Assiduite;
use App\Services\Scolarite\DossierAuditeurConnecte;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * « Mon parcours » (spec module 4, lot 4) : parcours et maquette validée de
 * l'inscription en cours de l'auditeur connecté. Propriété stricte.
 */
final class ParcoursController extends Controller
{
    public function show(Request $request, DossierAuditeurConnecte $dossiers): JsonResponse
    {
        $dossier = $dossiers->pour($request->user(), ['auditeur.inscriptionCourante.parcours']);
        $inscription = $dossier?->auditeur?->inscriptionCourante;
        if ($inscription === null) {
            return response()->json([
                'message' => 'Aucune inscription en cours pour ce compte.',
                'code' => 'auditeur_missing',
            ], Response::HTTP_NOT_FOUND);
        }

        return ParcoursAuditeurResource::make($inscription)->response()
            ->withHeaders(['Cache-Control' => 'no-store, private', 'Pragma' => 'no-cache']);
    }

    /** Assiduité provisoire de l'année, par ECUE (spec lot 10) : séances tenues, présences, note calculée. */
    public function assiduite(Request $request, DossierAuditeurConnecte $dossiers, Assiduite $assiduite): JsonResponse
    {
        $inscription = $dossiers->pour($request->user())?->auditeur?->inscriptionCourante;
        if ($inscription === null) {
            return response()->json(['message' => 'Aucune inscription en cours pour ce compte.', 'code' => 'auditeur_missing'], Response::HTTP_NOT_FOUND);
        }
        $regle = RegleAssiduite::pourAnnee($inscription->annee_academique);
        $lignes = [];
        foreach ($inscription->inscriptionsPedagogiques()->with('maquetteUe.ecues')->get() as $ip) {
            foreach ($ip->maquetteUe->ecues as $ecue) {
                $b = $assiduite->bilan($ip, $ecue, $regle);
                if ($b['tenues'] > 0) {
                    $lignes[] = ['ecue' => $ecue->code, 'intitule' => $ecue->intitule, ...$b];
                }
            }
        }

        return response()->json(['data' => $lignes], Response::HTTP_OK, ['Cache-Control' => 'no-store, private']);
    }
}
