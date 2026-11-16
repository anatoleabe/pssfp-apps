<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\RecuVersement;
use App\Services\Scolarite\VerificationRecus;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Page publique de vérification d'un reçu, ouverte par le QR code (reçu A4, R1). Limitée en débit, non indexée, sans cache.
 * Un identifiant inconnu ou mal formé donne la même réponse : rien ne permet de deviner un reçu.
 */
final class VerificationRecuController extends Controller
{
    private const ENTETES = ['X-Robots-Tag' => 'noindex, nofollow', 'Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer'];

    public function __invoke(string $uuid): Response
    {
        $recu = Str::isUuid($uuid) ? RecuVersement::query()->where('uuid', $uuid)->first() : null;
        if ($recu === null) {
            return response()->view('recus.verification', ['fiche' => null], 404, self::ENTETES);
        }

        return response()->view('recus.verification', ['fiche' => VerificationRecus::fiche($recu)], 200, self::ENTETES);
    }
}
