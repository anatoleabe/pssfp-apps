<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\DocumentDelivre;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Vérification publique d'un document officiel (RG-059, ENF-12) : type, numéro, date,
 * titulaire et statut, rien d'autre (ni note, ni matricule, ni coordonnée).
 *
 * Avec `?empreinte=` (SHA-256 du PDF présenté, calculé par le navigateur du vérificateur), la
 * réponse dit en plus si ce fichier est bien celui qui a été délivré (`conforme`). L'empreinte
 * conservée n'est jamais renvoyée, et le fichier ne transite pas par le serveur.
 */
final class VerificationDocumentController extends Controller
{
    public function __invoke(Request $request, string $code): JsonResponse
    {
        $document = preg_match('/^[A-Z0-9]{12}$/', $code) === 1
            ? DocumentDelivre::query()->where('code_verification', $code)->first()
            : null;
        if ($document === null) {
            return response()->json(['message' => 'Aucun document ne correspond à ce code.', 'code' => 'document_inconnu'], Response::HTTP_NOT_FOUND);
        }

        $empreinte = $request->query('empreinte');
        $conforme = is_string($empreinte) && preg_match('/^[a-f0-9]{64}$/', $empreinte) === 1
            ? ['conforme' => hash_equals((string) $document->sha256, $empreinte)]
            : [];

        return response()->json(['data' => [
            ...$conforme,
            'type' => $document->type,
            // Lot 17a : un relevé de semestre se distingue d'un relevé de l'année.
            'libelle' => DocumentDelivre::TYPES[$document->type].($document->type === DocumentDelivre::RELEVE && $document->deliberation?->estSemestrielle()
                ? ' du '.mb_strtolower((string) $document->deliberation->libelleSemestre()) : ''),
            'numero' => $document->numero,
            'delivre_le' => $document->created_at?->timezone('Africa/Douala')->toDateString(),
            'titulaire' => $document->titulaire,
            'statut' => $document->estRevoque() ? 'revoque' : 'authentique',
            'revoque_le' => $document->revoque_at?->timezone('Africa/Douala')->toDateString(),
        ]], Response::HTTP_OK, ['Cache-Control' => 'no-store']);
    }
}
