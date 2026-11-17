<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Auditeur;
use App\Services\Scolarite\ExtraitCompte;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * GET /admin/auditeurs/{uuid}/extrait-compte : extrait de compte de l'auditeur (PDF), affiché pour l'aperçu et
 * l'impression ; `?telecharger=1` le livre en pièce jointe. Route du panneau (session et 2FA de l'administration),
 * mêmes lecteurs que les reçus.
 */
final class ExtraitCompteController extends Controller
{
    public function __invoke(Request $request, Auditeur $auditeur, ExtraitCompte $extrait): Response
    {
        abort_unless(ImprimerRecuController::peutImprimer($request->user()), 403);

        $pdf = $extrait->pdf($auditeur, $request->user());
        $disposition = $request->boolean('telecharger') ? 'attachment' : 'inline';

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition.'; filename="'.$extrait->nomFichier($auditeur).'"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
