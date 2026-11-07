<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Applications;

use App\Http\Controllers\Controller;
use App\Models\DossierMemoire;
use App\Models\EtapeMemoire;
use App\Services\EspaceCandidat\ConsultationEspaceCandidat;
use App\Services\Lmd\DocumentsOfficiels;
use App\Services\Lmd\Memoires;
use App\Services\Scolarite\DossierAuditeurConnecte;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * « Mon mémoire » de l'auditeur connecté (spec module 4, lot 11) : étapes, avancement, conditions de
 * l'attestation, dépôt des livrables (PDF) et attestation téléchargeable. Propriété stricte.
 */
final class MemoireController extends Controller
{
    private const SANS_CACHE = ['Cache-Control' => 'no-store, private', 'Pragma' => 'no-cache'];

    public function __construct(
        private readonly DossierAuditeurConnecte $dossiers,
        private readonly Memoires $memoires,
        private readonly DocumentsOfficiels $documents,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $dossier = $this->dossier($request);
        if ($dossier === null) {
            return response()->json(['data' => null], Response::HTTP_OK, self::SANS_CACHE);
        }
        $attestation = $this->memoires->attestationValide($dossier);
        $avancement = $this->memoires->avancement($dossier);

        return response()->json(['data' => [
            'theme' => $dossier->theme,
            'structure_accueil' => $dossier->structure_accueil,
            'encadreur_academique' => $dossier->encadreur_academique,
            'encadreur_professionnel' => $dossier->encadreur_professionnel,
            'statut' => $dossier->statut,
            'statut_libelle' => DossierMemoire::STATUTS[$dossier->statut] ?? $dossier->statut,
            'date_soutenance' => $dossier->date_soutenance?->toDateString(),
            'avancement' => $avancement,
            'etapes' => $dossier->etapes()->get()->map(fn (EtapeMemoire $e): array => [
                'etape' => $e->etape,
                'libelle' => $e->libelle(),
                'poids' => $e->poids,
                'date_prevue' => $e->date_prevue?->toDateString(),
                'statut' => $e->statut,
                'statut_libelle' => EtapeMemoire::STATUTS[$e->statut] ?? $e->statut,
                'realisee_le' => $e->realisee_at?->timezone('Africa/Douala')->toDateString(),
                'note' => $e->note === null ? null : (float) $e->note,
                'appreciation' => $e->appreciation,
                'document_depose' => $e->document_sha256 !== null,
                'deposable' => in_array($e->etape, Memoires::DEPOTS_AUDITEUR, true) && ! in_array($e->statut, ['valide', 'dispense'], true)
                    && ! in_array($dossier->statut, ['soutenu', 'suspendu', 'abandon'], true)
                    && ($e->etape !== 'depot_final' || $attestation !== null),
            ])->values()->all(),
            'conditions' => array_map(fn (array $c): array => ['libelle' => $c['libelle'], 'ok' => $c['ok']], $this->memoires->conditions($dossier)),
            'attestation' => $attestation === null ? null : [
                'numero' => $attestation->numero,
                'delivree_le' => $attestation->created_at?->timezone('Africa/Douala')->toDateString(),
            ],
        ]], Response::HTTP_OK, self::SANS_CACHE);
    }

    public function deposer(Request $request, string $etape): JsonResponse
    {
        if ($this->lectureSeule($request)) {
            return response()->json(['message' => 'Consultation en lecture seule.'], Response::HTTP_FORBIDDEN);
        }
        $dossier = $this->dossier($request);
        if ($dossier === null) {
            return $this->absent();
        }
        $request->validate(['fichier' => ['required', 'file', 'max:10240', 'mimetypes:application/pdf']]);
        $fichier = $request->file('fichier');

        try {
            $e = $this->memoires->deposer($dossier, $etape, $fichier instanceof UploadedFile ? $fichier : throw new InvalidArgumentException('Fichier manquant.'));
        } catch (InvalidArgumentException $ex) {
            return response()->json(['message' => $ex->getMessage(), 'code' => 'depot_refuse'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json(['data' => ['etape' => $e->etape, 'statut' => $e->statut, 'empreinte' => $e->document_sha256]], Response::HTTP_CREATED, self::SANS_CACHE);
    }

    public function attestation(Request $request): HttpResponse|JsonResponse
    {
        $dossier = $this->dossier($request);
        $document = $dossier === null ? null : $this->memoires->attestationValide($dossier);
        if ($document === null) {
            return response()->json(['message' => 'Aucune attestation d\'autorisation de dépôt délivrée.', 'code' => 'document_indisponible'], Response::HTTP_NOT_FOUND);
        }
        try {
            $pdf = $this->documents->contenu($document);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'document_indisponible'], Response::HTTP_CONFLICT, self::SANS_CACHE);
        }

        return response($pdf, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.mb_strtolower($document->numero).'.pdf"',
        ] + self::SANS_CACHE);
    }

    private function dossier(Request $request): ?DossierMemoire
    {
        $auditeur = $this->dossiers->pour($request->user())?->auditeur;

        return $auditeur === null ? null : DossierMemoire::query()->where('auditeur_id', $auditeur->id)->orderByDesc('id')->first();
    }

    /** Un agent qui consulte l'espace (jeton de consultation) ne dépose rien. */
    private function lectureSeule(Request $request): bool
    {
        return $request->user()->currentAccessToken()?->name === ConsultationEspaceCandidat::NOM_JETON || $request->user()->tokenCan('consultation');
    }

    private function absent(): JsonResponse
    {
        return response()->json(['message' => 'Aucun mémoire ouvert pour ce compte.', 'code' => 'memoire_missing'], Response::HTTP_NOT_FOUND);
    }
}
