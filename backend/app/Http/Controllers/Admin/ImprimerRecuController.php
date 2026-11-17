<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Auditeur;
use App\Models\RecuVersement;
use App\Models\User;
use App\Services\Scolarite\EspaceFinances;
use App\Services\Scolarite\RecusVersements;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * GET /admin/recus/{uuid}/imprimer : le reçu A4 (deux exemplaires) affiché dans le navigateur, pour l'aperçu et
 * l'impression immédiate après l'encaissement (17/11/2026). Le téléchargement reste dans les listes.
 *
 * Route du panneau Filament : authentification et 2FA de l'administration. Mêmes lecteurs que les listes :
 * la fiche auditeur (scolarité) ou l'espace Finances.
 */
final class ImprimerRecuController extends Controller
{
    public static function peutImprimer(?User $user): bool
    {
        return $user !== null && ($user->can('viewAny', Auditeur::class) || EspaceFinances::peutConsulter($user));
    }

    public function __invoke(Request $request, RecuVersement $recu, RecusVersements $recus): Response
    {
        abort_unless(self::peutImprimer($request->user()), 403);

        $pdf = $recus->pdf($recu);
        activity('scolarite')->causedBy($request->user())->performedOn($recu)->event('recu_imprime')
            ->withProperties(['numero' => $recu->numero])->log('Reçu ouvert pour impression dans l\'administration');

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$recu->nomFichier().'"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
