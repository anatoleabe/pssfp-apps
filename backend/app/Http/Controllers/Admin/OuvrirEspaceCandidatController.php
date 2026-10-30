<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Candidature;
use App\Services\EspaceCandidat\ConsultationEspaceCandidat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * GET /admin/candidatures/{uuid}/espace : ouvre, dans un nouvel onglet,
 * l'espace du candidat en lecture seule sur le portail candidature.
 *
 * Route du panneau Filament : authentification et 2FA de l'administration.
 * Le code d'ouverture est créé au clic, jamais au rendu de la page.
 */
final class OuvrirEspaceCandidatController extends Controller
{
    public function __invoke(Request $request, Candidature $candidature, ConsultationEspaceCandidat $consultation): RedirectResponse
    {
        abort_unless($request->user()?->can(ConsultationEspaceCandidat::PERMISSION), 403);

        try {
            $url = $consultation->ouvrir($candidature, $request->user());
        } catch (\InvalidArgumentException) {
            abort(404, 'Ce dossier n\'est rattaché à aucun compte candidat.');
        }

        // Pas de cache : chaque clic doit produire un nouveau lien.
        return redirect()->away($url)->withHeaders(['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer']);
    }
}
