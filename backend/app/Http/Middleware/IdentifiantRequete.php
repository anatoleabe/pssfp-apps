<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Donne à chaque requête un identifiant, repris dans toutes les lignes du
 * journal technique (Context) et renvoyé dans l'en-tête X-Request-Id : un
 * agent qui signale une erreur peut le citer, et l'on retrouve toutes les
 * lignes de sa requête (chantier « Socle », étape 6).
 *
 * L'identifiant fourni par un proxy (nginx) est repris s'il est sûr ; sinon
 * on en crée un.
 */
final class IdentifiantRequete
{
    public const ENTETE = 'X-Request-Id';

    /** Lettres, chiffres, tirets et points, 8 à 64 caractères : rien d'injectable dans un journal. */
    private const FORMAT = '/\A[A-Za-z0-9.\-]{8,64}\z/';

    public function handle(Request $request, Closure $next): Response
    {
        $fourni = (string) $request->headers->get(self::ENTETE, '');
        $identifiant = preg_match(self::FORMAT, $fourni) === 1 ? $fourni : (string) Str::uuid();

        Context::add('request_id', $identifiant);

        $response = $next($request);
        $response->headers->set(self::ENTETE, $identifiant);

        return $response;
    }
}
