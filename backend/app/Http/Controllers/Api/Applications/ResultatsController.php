<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Applications;

use App\Http\Controllers\Controller;
use App\Models\Auditeur;
use App\Models\Deliberation;
use App\Models\DocumentDelivre;
use App\Models\ResultatAnnuel;
use App\Services\Lmd\DocumentsOfficiels;
use App\Services\Lmd\ResultatsAuditeur;
use App\Services\Scolarite\DossierAuditeurConnecte;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * « Mes résultats » et documents officiels de l'auditeur connecté (spec module 4, lot 7).
 * Propriété stricte : l'auditeur est toujours celui du jeton ; seuls les résultats
 * publiés et non rectifiés sont servis.
 */
final class ResultatsController extends Controller
{
    private const SANS_CACHE = ['Cache-Control' => 'no-store, private', 'Pragma' => 'no-cache'];

    public function __construct(
        private readonly DossierAuditeurConnecte $dossiers,
        private readonly ResultatsAuditeur $resultats,
        private readonly DocumentsOfficiels $documents,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $auditeur = $this->auditeur($request);
        if ($auditeur === null) {
            return $this->absent();
        }

        $certificat = $this->documents->indisponibilite(DocumentDelivre::CERTIFICAT, $auditeur, null);

        return response()->json(['data' => [
            'resultats' => $this->resultats->publies($auditeur)->map(fn (ResultatAnnuel $r): array => [
                'deliberation' => $r->deliberation->uuid,
                'annee_academique' => $r->deliberation->annee_academique,
                'niveau' => $r->inscriptionAnnuelle->niveau,
                'parcours' => $r->inscriptionAnnuelle->parcours?->libelle,
                'session' => $r->deliberation->session,
                'publie_le' => $r->deliberation->publiee_at?->timezone('Africa/Douala')->toDateString(),
                'requetes_jusqu_au' => $r->deliberation->requetes_jusqu_au?->toDateString(),
                'decision' => $r->decision,
                'credits' => $r->credits_acquis,
                'moyenne' => $r->moyenne === null ? null : (float) $r->moyenne,
                'mgp' => $r->mgp === null ? null : (float) $r->mgp,
                'mention' => $r->mention,
                'ues' => $this->resultats->ues($r),
                'documents' => [
                    'releve_notes' => null,
                    'attestation_reussite' => $this->documents->indisponibilite(DocumentDelivre::ATTESTATION, $auditeur, $r->deliberation),
                ],
            ])->values()->all(),
            'certificat_scolarite' => $certificat,
        ]], Response::HTTP_OK, self::SANS_CACHE);
    }

    /** PDF d'un document officiel : délivré à la première demande, puis toujours le même fichier. */
    public function document(Request $request, string $type): HttpResponse|JsonResponse
    {
        $auditeur = $this->auditeur($request);
        if ($auditeur === null) {
            return $this->absent();
        }
        $d = $type === DocumentDelivre::CERTIFICAT ? null
            : Deliberation::query()->where('uuid', (string) $request->query('deliberation'))->first();

        try {
            $document = $this->documents->obtenir($type, $auditeur, $d);
            $pdf = $this->documents->contenu($document);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'document_indisponible'], Response::HTTP_CONFLICT, self::SANS_CACHE);
        }

        return response($pdf, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.mb_strtolower($document->numero).'.pdf"',
        ] + self::SANS_CACHE);
    }

    private function auditeur(Request $request): ?Auditeur
    {
        return $this->dossiers->pour($request->user())?->auditeur;
    }

    private function absent(): JsonResponse
    {
        return response()->json(['message' => 'Aucune scolarité ouverte pour ce compte.', 'code' => 'auditeur_missing'], Response::HTTP_NOT_FOUND);
    }
}
