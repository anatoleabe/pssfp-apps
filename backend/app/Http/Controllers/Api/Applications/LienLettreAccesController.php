<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Applications;

use App\Http\Controllers\Controller;
use App\Http\Requests\Scolarite\LienLettreAccesRequest;
use App\Models\Candidature;
use App\Services\DocumentsAuditeurs\DocumentsIncomplets;
use App\Services\DocumentsAuditeurs\LettreAccesFoadPdf;
use App\Services\Scolarite\LiensLettreAcces;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response as HttpResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lien court de la lettre d'accès FOAD (SMS) : public, sans compte, mais
 * date de naissance exigée. Un lien inconnu, expiré, épuisé ou révoqué reçoit
 * la même réponse.
 */
final class LienLettreAccesController extends Controller
{
    private const SANS_CACHE = ['Cache-Control' => 'no-store, private', 'Pragma' => 'no-cache', 'X-Robots-Tag' => 'noindex, nofollow'];

    /** GET /v1/lettre-acces/{jeton} : le lien est-il encore valable ? Ne consomme rien (aperçus de liens). */
    public function show(string $jeton, LiensLettreAcces $liens): JsonResponse
    {
        $lien = $liens->trouver($jeton);

        return $lien === null
            ? $this->invalide()
            : response()->json(['expire_le' => $lien->expire_le->toIso8601String()], Response::HTTP_OK, self::SANS_CACHE);
    }

    /** POST /v1/lettre-acces/{jeton} : la bonne date de naissance donne le PDF. */
    public function telecharger(LienLettreAccesRequest $request, string $jeton, LiensLettreAcces $liens, LettreAccesFoadPdf $lettre): HttpResponse|JsonResponse
    {
        $lien = $liens->trouver($jeton);
        if ($lien === null) {
            return $this->invalide();
        }

        $resultat = $liens->verifier($lien, (string) $request->validated('date_naissance'), (string) $request->ip());

        return match ($resultat['statut']) {
            LiensLettreAcces::OK => $this->pdf($lien->candidature, $lettre),
            LiensLettreAcces::DATE_INCORRECTE => response()->json([
                'message' => 'Date de naissance incorrecte.',
                'code' => 'date_incorrecte',
                'restantes' => $resultat['restantes'],
            ], Response::HTTP_UNPROCESSABLE_ENTITY, self::SANS_CACHE),
            LiensLettreAcces::VERROUILLE => response()->json([
                'message' => 'Trop d\'essais : ce lien est désormais fermé. Demandez un nouveau lien à la scolarité ou connectez-vous à votre espace.',
                'code' => 'lien_verrouille',
            ], Response::HTTP_LOCKED, self::SANS_CACHE),
            default => $this->invalide(),
        };
    }

    private function pdf(Candidature $c, LettreAccesFoadPdf $lettre): HttpResponse|JsonResponse
    {
        try {
            $pdf = $lettre->generer($c);
        } catch (DocumentsIncomplets) {
            return response()->json([
                'message' => 'Votre lettre d\'accès n\'est pas encore prête : réessayez plus tard ou rapprochez-vous de la scolarité.',
                'code' => 'lettre_non_prete',
            ], Response::HTTP_CONFLICT, self::SANS_CACHE);
        }

        return response($pdf, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="acces-foad-'.mb_strtolower((string) $c->matricule).'.pdf"',
        ] + self::SANS_CACHE);
    }

    private function invalide(): JsonResponse
    {
        return response()->json([
            'message' => 'Ce lien n\'est plus valable. Connectez-vous à votre espace ou demandez un nouveau lien à la scolarité.',
            'code' => 'lien_invalide',
        ], Response::HTTP_GONE, self::SANS_CACHE);
    }
}
