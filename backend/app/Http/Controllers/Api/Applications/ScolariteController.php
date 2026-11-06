<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Applications;

use App\Http\Controllers\Controller;
use App\Http\Resources\ScolariteResource;
use App\Models\Candidature;
use App\Services\EspaceCandidat\ConsultationEspaceCandidat;
use App\Services\ExportsRetenus\IdentifiantsMoodle;
use App\Services\Scolarite\AccesEcampus;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Scolarité de l'auditeur connecté : situation, relevé, mot de passe FOAD.
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

    /** Le mot de passe ne sort qu'ici, à la demande, état revérifié à chaque fois. */
    public function reveler(Request $request): JsonResponse
    {
        // Un agent qui consulte l'espace voit la situation, jamais le mot de passe.
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

        activity('auth.candidat')->causedBy($request->user())->performedOn($dossier)
            ->event('acces_mot_de_passe_affiche')
            ->withProperties(['ip' => $request->ip(), 'connexion' => $jeton?->name])
            ->log('Mot de passe FOAD affiché dans l\'espace');

        return response()->json(['data' => [
            'identifiant' => IdentifiantsMoodle::identifiant($dossier),
            'mot_de_passe' => (string) $dossier->moodle_password,
            'foad_url' => (string) config('pssfp.foad_url'),
        ]], Response::HTTP_OK, self::SANS_CACHE);
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

    /**
     * Dernier dossier retenu, non retiré et doté d'une fiche auditeur du compte :
     * un brouillon dans une campagne suivante ne masque pas la scolarité en cours.
     */
    private function dossier(Request $request): ?Candidature
    {
        return Candidature::query()
            ->where('user_id', $request->user()->id)
            ->where('statut', Candidature::STATUT_ACCEPTE)
            ->whereNull('withdrawn_at')
            ->whereHas('auditeur')
            ->with(['campagne', 'auditeur.inscriptionCourante.bareme'])
            ->orderByDesc('matricule_attribue_at')
            ->orderByDesc('id')
            ->first();
    }

    private function absent(): JsonResponse
    {
        return response()->json([
            'message' => 'Aucune scolarité ouverte pour ce compte.',
            'code' => 'auditeur_missing',
        ], Response::HTTP_NOT_FOUND);
    }
}
