<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Services\EspaceCandidat\ConsultationEspaceCandidat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /v1/auth/candidat/consultation : le portail candidature échange le code
 * d'ouverture créé depuis l'administration contre un jeton en lecture seule.
 */
final class ConsultationEspaceController extends Controller
{
    public function __invoke(Request $request, ConsultationEspaceCandidat $consultation): JsonResponse
    {
        $donnees = $request->validate([
            'code' => ['required', 'string', 'regex:/^[A-Za-z0-9]{64}$/'],
        ]);

        $session = $consultation->echanger($donnees['code']);
        if ($session === null) {
            return response()->json([
                'message' => 'Lien de consultation expiré ou déjà utilisé. Relancez « Voir son espace » depuis l\'administration.',
            ], 410);
        }

        return response()->json($session + ['abilities' => ConsultationEspaceCandidat::ABILITIES]);
    }
}
