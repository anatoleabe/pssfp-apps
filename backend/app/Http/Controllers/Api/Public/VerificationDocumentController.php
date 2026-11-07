<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\DocumentDelivre;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Vérification publique d'un document officiel (RG-059, ENF-12) : type, numéro, date,
 * titulaire et statut, rien d'autre (ni note, ni matricule, ni coordonnée).
 */
final class VerificationDocumentController extends Controller
{
    public function __invoke(string $code): JsonResponse
    {
        $document = preg_match('/^[A-Z0-9]{12}$/', $code) === 1
            ? DocumentDelivre::query()->where('code_verification', $code)->first()
            : null;
        if ($document === null) {
            return response()->json(['message' => 'Aucun document ne correspond à ce code.', 'code' => 'document_inconnu'], Response::HTTP_NOT_FOUND);
        }

        return response()->json(['data' => [
            'type' => $document->type,
            'libelle' => DocumentDelivre::TYPES[$document->type],
            'numero' => $document->numero,
            'delivre_le' => $document->created_at?->timezone('Africa/Douala')->toDateString(),
            'titulaire' => $document->titulaire,
            'statut' => $document->estRevoque() ? 'revoque' : 'authentique',
            'revoque_le' => $document->revoque_at?->timezone('Africa/Douala')->toDateString(),
        ]], Response::HTTP_OK, ['Cache-Control' => 'no-store']);
    }
}
