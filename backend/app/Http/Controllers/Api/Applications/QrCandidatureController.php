<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Applications;

use App\Http\Controllers\Controller;
use App\Models\Candidature;
use App\Support\PhoneMasker;
use Illuminate\Contracts\View\View;

/**
 * GET /v1/c/{uuid}/qr — page publique scannée par le QR du PDF récépissé.
 *
 * Cf. ajout 1 PR C : permet aux agents PSSFP au comptoir de vérifier rapidement
 * un candidat sans authentification, sans dépendre du frontend Next.js (PR F).
 *
 * Lecture seule, pas de PII complète exposée :
 * - Numéro de dossier, prénom + nom (déjà imprimés sur le PDF)
 * - Téléphone masqué `+237***4567`
 * - Statut, frais payés, hash SHA256 du récépissé
 *
 * Rendu HTML server-side via Blade (resources/views/c/qr-public.blade.php).
 */
final class QrCandidatureController extends Controller
{
    public function __invoke(string $uuid): View
    {
        $candidature = Candidature::query()
            ->where('uuid', $uuid)
            ->firstOrFail();

        return view('c.qr-public', [
            'candidature' => $candidature,
            'maskedPhone' => PhoneMasker::mask($candidature->phone_e164),
        ]);
    }
}
