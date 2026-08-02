<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applique la locale demandée par le client à l'application (ADR-0006).
 *
 * Sans ce middleware, les champs traduisibles (spatie/laravel-translatable)
 * étaient systématiquement rendus en français, quelle que soit la langue du
 * visiteur : le portail anglais affichait par exemple le nom de campagne
 * « Année académique 2026-2027 » en plein milieu d'une page anglaise.
 *
 * Le repli est toujours le français, langue source de l'institution.
 */
final class SetLocaleFromRequest
{
    /** Locales servies par l'API. Toute autre valeur retombe sur le français. */
    private const SUPPORTED = ['fr', 'en'];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->resolve($request);

        app()->setLocale($locale);

        $response = $next($request);

        // Permet aux caches intermédiaires de ne pas servir une réponse
        // française à un client anglophone, et inversement.
        $response->headers->set('Content-Language', $locale);
        $response->headers->set('Vary', trim($response->headers->get('Vary', '').', Accept-Language', ', '));

        return $response;
    }

    private function resolve(Request $request): string
    {
        // `?locale=` explicite : utile pour tester et pour les liens partagés.
        $explicit = $request->query('locale');
        if (is_string($explicit) && in_array($explicit, self::SUPPORTED, true)) {
            return $explicit;
        }

        // Aucune préférence exprimée : on sert le français, langue source de
        // l'institution. Le cas est traité explicitement plutôt que laissé au
        // défaut de Symfony, qui injecte `en-us,en;q=0.5` quand l'en-tête est
        // absent et ferait basculer un client neutre en anglais.
        $header = $request->headers->get('Accept-Language');
        if (! is_string($header) || trim($header) === '') {
            return 'fr';
        }

        // Sinon la négociation HTTP standard, qui gère les facteurs de qualité
        // (q=0.9) et les variantes régionales (en-GB, en-US…).
        $preferred = $request->getPreferredLanguage(self::SUPPORTED);

        return in_array($preferred, self::SUPPORTED, true) ? $preferred : 'fr';
    }
}
