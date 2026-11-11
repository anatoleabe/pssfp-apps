<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Filament\Pages\MaSecurite;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lot 14d-4 : quand la double authentification est obligatoire (FILAMENT_REQUIRE_2FA), un compte qui ne l'a pas activée
 * est conduit sur « Ma sécurité ». Avant, il était refusé en bloc, donc incapable de l'activer.
 *
 * Middleware persistant (revue de sécurité) : Livewire le rejoue sur ses échanges, avec la route de la page d'origine ;
 * seuls les composants de « Ma sécurité » passent donc. La recherche globale, rendue sur cette page, est rendue muette
 * (App\Filament\GlobalSearch\RechercheGlobale).
 */
final class ExigerDoubleAuthentification
{
    /** Routes ouvertes à un compte qui doit encore activer sa double authentification. */
    public const ROUTES_OUVERTES = [
        'filament.admin.pages.ma-securite',
        'filament.admin.auth.logout',
        // Sans elles, un compte à l'adresse non vérifiée tournerait en rond entre « Ma sécurité » et la vérification.
        'filament.admin.auth.email-verification.*',
    ];

    public static function doitActiver(mixed $user): bool
    {
        return MaSecurite::obligatoire() && $user instanceof User && ! $user->hasEnabledTwoFactorAuthentication();
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! self::doitActiver($request->user()) || $request->routeIs(...self::ROUTES_OUVERTES)) {
            return $next($request);
        }

        return redirect()->to(MaSecurite::getUrl());
    }
}
