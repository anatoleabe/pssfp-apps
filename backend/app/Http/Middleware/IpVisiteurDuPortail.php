<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adresse IP réelle du visiteur pour l'authentification candidat.
 *
 * Le portail candidature appelle l'API depuis son serveur : sans ce
 * middleware, l'API ne voit que l'adresse du serveur (127.0.0.1), et les
 * limites « par IP » de connexion, d'inscription et de « PIN oublié »
 * s'appliquent à tous les candidats à la fois (constaté en production :
 * trois échecs en dix minutes bloquaient toutes les connexions).
 *
 * Le portail transmet l'adresse reçue de nginx avec un secret partagé ;
 * sans secret valide, l'en-tête est ignoré (un visiteur ne peut pas
 * choisir son adresse).
 */
final class IpVisiteurDuPortail
{
    public const ENTETE_IP = 'X-PSSFP-Client-IP';

    public const ENTETE_SECRET = 'X-PSSFP-Portail';

    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('pssfp.portail_secret', '');
        $fourni = (string) $request->header(self::ENTETE_SECRET, '');
        $ip = (string) $request->header(self::ENTETE_IP, '');

        if ($secret !== '' && $fourni !== '' && hash_equals($secret, $fourni)
            && filter_var($ip, FILTER_VALIDATE_IP) !== false) {
            $request->server->set('REMOTE_ADDR', $ip);
        }

        // Jamais transmis plus loin (journaux, exceptions).
        $request->headers->remove(self::ENTETE_SECRET);

        return $next($request);
    }
}
