<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Applications;

use App\Http\Controllers\Controller;
use App\Http\Resources\ParcoursAuditeurResource;
use App\Models\Acte;
use App\Models\CalendrierAcademique;
use App\Models\EvenementCalendrier;
use App\Models\InscriptionAnnuelle;
use App\Models\RegleAssiduite;
use App\Services\Lmd\Actes;
use App\Services\Lmd\Assiduite;
use App\Services\Lmd\Calendriers;
use App\Services\Scolarite\DossierAuditeurConnecte;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * Calendrier publié de l'année de l'auditeur (lot 12a) : événements de son niveau et événements communs.
     * `data` null tant qu'aucun calendrier n'est publié.
     */
    public function calendrier(Request $request, DossierAuditeurConnecte $dossiers, Calendriers $calendriers): JsonResponse
    {
        $inscription = $dossiers->pour($request->user())?->auditeur?->inscriptionCourante;
        if ($inscription === null) {
            return response()->json(['message' => 'Aucune inscription en cours pour ce compte.', 'code' => 'auditeur_missing'], Response::HTTP_NOT_FOUND);
        }
        $c = $calendriers->publie($inscription->annee_academique);
        if ($c === null) {
            return response()->json(['data' => null], Response::HTTP_OK, ['Cache-Control' => 'no-store, private']);
        }
        $evenements = $c->evenements()->where('type', '<>', 'phase')
            ->where(fn ($q) => $q->whereNull('niveau')->orWhere('niveau', $inscription->niveau))
            ->where(fn ($q) => $q->whereNull('parcours_id')->orWhere('parcours_id', $inscription->parcours_id))
            ->orderBy('debut_prevu')->orderBy('id')->get();

        return response()->json(['data' => [
            'annee_academique' => $c->annee_academique,
            'version' => $c->version,
            'publie_le' => $c->publie_at?->timezone('Africa/Douala')->toDateString(),
            'evenements' => $evenements->map(fn (EvenementCalendrier $e): array => [
                'libelle' => $e->libelle,
                'type' => $e->type,
                'type_libelle' => EvenementCalendrier::TYPES[$e->type] ?? $e->type,
                'debut' => $e->debut_prevu->toDateString(),
                'fin' => $e->fin_prevue->toDateString(),
                'jalon' => $e->estJalon(),
                'statut' => $e->statut,
                'statut_libelle' => EvenementCalendrier::STATUTS[$e->statut] ?? $e->statut,
                'reporte' => $e->debut_signe !== null && ! $e->debut_signe->equalTo($e->debut_prevu),
            ])->values()->all(),
            'communiques' => $this->communiques($c, $inscription)->get()->map(fn (Acte $a): array => [
                'uuid' => $a->uuid,
                'objet' => $a->objet,
                'numero' => $a->numero,
                'date_signature' => $a->date_signature?->toDateString(),
            ])->values()->all(),
        ]], Response::HTTP_OK, ['Cache-Control' => 'no-store, private']);
    }

    /** Communiqué diffusé du calendrier publié (spec lot 12b, §4) : acte signé, vérifié par son empreinte. */
    public function communique(Request $request, string $uuid, DossierAuditeurConnecte $dossiers, Calendriers $calendriers, Actes $actes): Response
    {
        $inscription = $dossiers->pour($request->user())?->auditeur?->inscriptionCourante;
        $c = $inscription === null ? null : $calendriers->publie($inscription->annee_academique);
        $acte = $c === null ? null : $this->communiques($c, $inscription)->where('uuid', $uuid)->first();
        if ($acte === null) {
            return response()->json(['message' => 'Communiqué introuvable.', 'code' => 'document_indisponible'], Response::HTTP_NOT_FOUND);
        }
        $pdf = $actes->piece($acte);
        if ($pdf === null) {
            return response()->json(['message' => 'Document momentanément indisponible : réessayez plus tard.', 'code' => 'document_indisponible'],
                Response::HTTP_CONFLICT, ['Cache-Control' => 'no-store, private']);
        }

        return response($pdf, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="communique-'.$acte->date_signature?->format('Y-m-d').'.pdf"',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /** @return Builder<Acte> actes diffusés aux auditeurs, de leur niveau ou communs, sur le calendrier publié */
    private function communiques(CalendrierAcademique $c, InscriptionAnnuelle $inscription): Builder
    {
        return Acte::query()->where('statut', Acte::DIFFUSE)
            ->whereHas('modele', fn (Builder $q) => $q->where('diffusion_auditeurs', true))
            ->whereHas('evenement', fn (Builder $q) => $q->where('calendrier_id', $c->id)
                ->where(fn (Builder $n) => $n->whereNull('niveau')->orWhere('niveau', $inscription->niveau))
                ->where(fn (Builder $p) => $p->whereNull('parcours_id')->orWhere('parcours_id', $inscription->parcours_id)))
            ->orderByDesc('date_signature')->orderByDesc('id');
    }
}
