<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Applications;

use App\Http\Controllers\Controller;
use App\Http\Resources\ScolariteResource;
use App\Models\Candidature;
use App\Models\RecuVersement;
use App\Services\DocumentsAuditeurs\DocumentsIncomplets;
use App\Services\DocumentsAuditeurs\LettreAccesFoadPdf;
use App\Services\EspaceCandidat\ConsultationEspaceCandidat;
use App\Services\ExportsRetenus\IdentifiantsMoodle;
use App\Services\Scolarite\AccesEcampus;
use App\Services\Scolarite\DossierAuditeurConnecte;
use App\Services\Scolarite\RecusVersements;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Scolarité de l'auditeur connecté : situation, relevé, mot de passe FOAD et lettre d'accès.
 *
 * Propriété stricte : le dossier est toujours un dossier du compte du jeton,
 * jamais un identifiant reçu.
 */
final class ScolariteController extends Controller
{
    private const SANS_CACHE = ['Cache-Control' => 'no-store, private', 'Pragma' => 'no-cache'];

    public function show(Request $request): JsonResponse
    {
        $dossier = $this->dossier($request);
        if ($dossier === null) {
            return $this->absent();
        }

        return ScolariteResource::make($dossier)->response()->withHeaders(self::SANS_CACHE);
    }

    /** Le mot de passe ne sort qu'ici (et dans la lettre d'accès), à la demande, état revérifié à chaque fois. */
    public function reveler(Request $request): JsonResponse
    {
        $dossier = $this->dossierRemettable($request);
        if ($dossier instanceof JsonResponse) {
            return $dossier;
        }

        activity('auth.candidat')->causedBy($request->user())->performedOn($dossier)
            ->event('acces_mot_de_passe_affiche')
            ->withProperties(['ip' => $request->ip(), 'connexion' => $request->user()->currentAccessToken()?->name])
            ->log('Mot de passe FOAD affiché dans l\'espace');

        return response()->json(['data' => [
            'identifiant' => IdentifiantsMoodle::identifiant($dossier),
            'mot_de_passe' => (string) $dossier->moodle_password,
            'foad_url' => (string) config('pssfp.foad_url'),
        ]], Response::HTTP_OK, self::SANS_CACHE);
    }

    /** Lettre d'accès FOAD en PDF : mêmes conditions que l'affichage du mot de passe, qu'elle contient. */
    public function lettreAcces(Request $request, LettreAccesFoadPdf $lettre): HttpResponse|JsonResponse
    {
        $dossier = $this->dossierRemettable($request);
        if ($dossier instanceof JsonResponse) {
            return $dossier;
        }

        try {
            $pdf = $lettre->generer($dossier);
        } catch (DocumentsIncomplets) {
            // Date ou référence des lettres pas encore saisie sur la promotion.
            return response()->json([
                'message' => 'Votre lettre d\'accès n\'est pas encore prête : réessayez plus tard ou rapprochez-vous de la scolarité.',
                'code' => 'lettre_non_prete',
            ], Response::HTTP_CONFLICT, self::SANS_CACHE);
        }

        activity('auth.candidat')->causedBy($request->user())->performedOn($dossier)
            ->event('acces_foad_telecharge')
            ->withProperties(['ip' => $request->ip(), 'connexion' => $request->user()->currentAccessToken()?->name])
            ->log('Lettre d\'accès FOAD (PDF) téléchargée dans l\'espace');

        return response($pdf, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="acces-foad-'.mb_strtolower((string) $dossier->matricule).'.pdf"',
        ] + self::SANS_CACHE);
    }

    public function releve(Request $request): HttpResponse|JsonResponse
    {
        $dossier = $this->dossier($request);
        if ($dossier === null) {
            return $this->absent();
        }

        $situation = (new ScolariteResource($dossier))->toArray($request);
        $pdf = Pdf::loadView('pdf.releve-scolarite', [
            'dossier' => $dossier,
            'situation' => $situation,
            'edite_le' => now('Africa/Douala'),
        ])->setPaper('a4', 'portrait');

        return response($pdf->output(), Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="releve-scolarite.pdf"',
        ] + self::SANS_CACHE);
    }

    /** Reçu d'un versement de l'auditeur connecté (Scolarité lot 4) ; 404 pour tout reçu d'un autre. */
    public function recu(Request $request, string $uuid, RecusVersements $recus): HttpResponse|JsonResponse
    {
        $dossier = $this->dossier($request);
        $auditeurId = $dossier?->auditeur?->id;
        $recu = $auditeurId === null ? null : RecuVersement::query()->where('uuid', $uuid)
            ->whereHas('inscription', fn ($q) => $q->where('auditeur_id', $auditeurId))->first();
        if ($recu === null) {
            return response()->json(['message' => 'Reçu introuvable.', 'code' => 'recu_missing'], Response::HTTP_NOT_FOUND);
        }

        activity('scolarite')->causedBy($request->user())->performedOn($recu)
            ->event('recu_telecharge')
            ->withProperties(['numero' => $recu->numero, 'ip' => $request->ip(), 'connexion' => $request->user()->currentAccessToken()?->name])
            ->log('Reçu téléchargé dans l\'espace');

        return response($recus->pdf($recu), Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$recu->nomFichier().'"',
        ] + self::SANS_CACHE);
    }

    private function dossier(Request $request): ?Candidature
    {
        return app(DossierAuditeurConnecte::class)->pour($request->user());
    }

    /**
     * Dossier dont l'auditeur peut recevoir le mot de passe, ou la réponse de refus :
     * jamais pour un agent qui consulte l'espace, ni avant l'inscription soldée.
     */
    private function dossierRemettable(Request $request): Candidature|JsonResponse
    {
        $jeton = $request->user()->currentAccessToken();
        if ($jeton?->name === ConsultationEspaceCandidat::NOM_JETON || $request->user()->tokenCan('consultation')) {
            return response()->json(['message' => 'Consultation en lecture seule : le mot de passe n\'est pas affiché.'], Response::HTTP_FORBIDDEN);
        }

        $dossier = $this->dossier($request);
        if ($dossier === null) {
            return $this->absent();
        }
        if (AccesEcampus::etat($dossier) !== AccesEcampus::DISPONIBLE) {
            return response()->json([
                'message' => 'Vos accès ne sont pas encore disponibles.',
                'code' => 'acces_indisponible',
            ], Response::HTTP_FORBIDDEN, self::SANS_CACHE);
        }
        if (! AccesEcampus::motDePasseConnu($dossier)) {
            return response()->json([
                'message' => 'Votre mot de passe eCampus n\'est pas connu du portail : utilisez celui que vous avez déjà.',
                'code' => 'mot_de_passe_inconnu',
            ], Response::HTTP_NOT_FOUND, self::SANS_CACHE);
        }

        return $dossier;
    }

    private function absent(): JsonResponse
    {
        return response()->json([
            'message' => 'Aucune scolarité ouverte pour ce compte.',
            'code' => 'auditeur_missing',
        ], Response::HTTP_NOT_FOUND);
    }
}
