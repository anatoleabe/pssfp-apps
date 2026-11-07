<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Applications;

use App\Http\Controllers\Controller;
use App\Models\Auditeur;
use App\Models\Requete;
use App\Models\RequeteEvenement;
use App\Services\EspaceCandidat\ConsultationEspaceCandidat;
use App\Services\Lmd\Requetes;
use App\Services\Scolarite\DossierAuditeurConnecte;
use App\Support\CandidatureObjectStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * « Mes requêtes » (spec module 4, lot 8). Propriété stricte : l'auditeur est celui du
 * jeton ; il voit ses étapes, jamais le détail interne ni l'agent. Les ECUE se désignent
 * par leur code.
 */
final class RequetesController extends Controller
{
    private const SANS_CACHE = ['Cache-Control' => 'no-store, private', 'Pragma' => 'no-cache'];

    public function __construct(private readonly DossierAuditeurConnecte $dossiers, private readonly Requetes $requetes) {}

    public function index(Request $request): JsonResponse
    {
        $auditeur = $this->auditeur($request);
        if ($auditeur === null) {
            return $this->absent();
        }

        return response()->json(['data' => [
            'requetes' => Requete::query()->where('auditeur_id', $auditeur->id)->with(['ecue', 'evenements'])->latest('id')->get()
                ->map(fn (Requete $r): array => [
                    'id' => $r->uuid,
                    'numero' => $r->numero,
                    'type' => $r->type,
                    'objet' => $r->objet(),
                    'statut' => $r->statut,
                    'statut_libelle' => Requete::STATUTS[$r->statut] ?? $r->statut,
                    'deposee_le' => $r->created_at?->timezone('Africa/Douala')->toIso8601String(),
                    'empreinte' => $r->sha256,
                    'annulable' => in_array($r->statut, ['deposee', 'cotee'], true),
                    'mention' => $r->statut === 'appliquee' || $r->statut === 'cloturee' ? $r->mention : null,
                    'note_retenue' => $r->statut === 'appliquee' && $r->note_retenue !== null ? (float) $r->note_retenue : null,
                    'motif_decision' => in_array($r->statut, ['irrecevable', 'rejetee', 'cloturee', 'appliquee'], true) ? $r->decision_motif : null,
                    'document_disponible' => $r->statut === 'document_disponible',
                    'etapes' => $r->evenements->where('visible_auditeur', true)->map(fn (RequeteEvenement $e): array => [
                        'statut' => $e->statut, 'libelle' => $e->libelle, 'le' => $e->created_at?->timezone('Africa/Douala')->toIso8601String(),
                    ])->values()->all(),
                ])->values()->all(),
            'cibles' => array_map(fn (array $c): array => [
                'ecue' => $c['ecue']->code, 'intitule' => $c['ecue']->intitule, 'session' => $c['session'], 'notes' => $c['notes'],
            ], $this->requetes->cibles($auditeur)),
            'motifs' => Requete::MOTIFS,
            'documents' => Requete::DOCUMENTS,
        ]], Response::HTTP_OK, self::SANS_CACHE);
    }

    public function store(Request $request): JsonResponse
    {
        if ($this->lectureSeule($request)) {
            return response()->json(['message' => 'Consultation en lecture seule.'], Response::HTTP_FORBIDDEN);
        }
        $auditeur = $this->auditeur($request);
        if ($auditeur === null) {
            return $this->absent();
        }
        $data = $request->validate([
            'type' => ['required', 'in:note,document'],
            'ecue' => ['nullable', 'string', 'max:20'],
            'session' => ['nullable', 'in:normale,rattrapage'],
            'note_visee' => ['nullable', 'in:cc,assiduite,examen'],
            'motif' => ['nullable', 'string', 'max:20'],
            'type_document' => ['nullable', 'string', 'max:30'],
            'description' => ['required', 'string', 'min:10', 'max:2000'],
            'pieces' => ['nullable', 'array', 'max:5'],
            'pieces.*' => ['file', 'max:5120', 'mimetypes:application/pdf,image/jpeg,image/png'],
        ]);
        $ecue = collect($this->requetes->cibles($auditeur))->first(fn (array $c): bool => $c['ecue']->code === ($data['ecue'] ?? null))['ecue'] ?? null;

        try {
            $r = $this->requetes->deposer($auditeur, [...$data, 'maquette_ecue_id' => $ecue?->id],
                array_values(array_filter((array) $request->file('pieces', []), fn ($f): bool => $f instanceof UploadedFile)));
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'requete_refusee'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json(['data' => [
            'id' => $r->uuid, 'numero' => $r->numero, 'empreinte' => $r->sha256,
            'deposee_le' => $r->created_at?->timezone('Africa/Douala')->toIso8601String(),
        ]], Response::HTTP_CREATED, self::SANS_CACHE);
    }

    public function annuler(Request $request, string $uuid): JsonResponse
    {
        if ($this->lectureSeule($request)) {
            return response()->json(['message' => 'Consultation en lecture seule.'], Response::HTTP_FORBIDDEN);
        }
        $auditeur = $this->auditeur($request);
        $requete = $auditeur === null ? null : Requete::query()->where('uuid', $uuid)->where('auditeur_id', $auditeur->id)->first();
        if ($requete === null) {
            return $this->absent();
        }
        try {
            $this->requetes->annuler($requete, $auditeur);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'annulation_refusee'], Response::HTTP_CONFLICT);
        }

        return response()->json(['data' => ['statut' => 'annulee']], Response::HTTP_OK, self::SANS_CACHE);
    }

    public function document(Request $request, string $uuid): HttpResponse|JsonResponse
    {
        $auditeur = $this->auditeur($request);
        $requete = $auditeur === null ? null : Requete::query()->where('uuid', $uuid)->where('auditeur_id', $auditeur->id)
            ->where('statut', 'document_disponible')->first();
        $pdf = $requete?->document_path === null ? null : CandidatureObjectStorage::read($requete->document_path);
        if ($pdf === null) {
            return $this->absent();
        }

        return response($pdf, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.mb_strtolower($requete->numero).'.pdf"',
        ] + self::SANS_CACHE);
    }

    /** Un agent qui consulte l'espace (jeton de consultation) ne dépose ni n'annule rien. */
    private function lectureSeule(Request $request): bool
    {
        return $request->user()->currentAccessToken()?->name === ConsultationEspaceCandidat::NOM_JETON || $request->user()->tokenCan('consultation');
    }

    private function auditeur(Request $request): ?Auditeur
    {
        return $this->dossiers->pour($request->user())?->auditeur;
    }

    private function absent(): JsonResponse
    {
        return response()->json(['message' => 'Introuvable.', 'code' => 'introuvable'], Response::HTTP_NOT_FOUND);
    }
}
