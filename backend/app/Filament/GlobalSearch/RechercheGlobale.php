<?php

declare(strict_types=1);

namespace App\Filament\GlobalSearch;

use App\Filament\Resources\AuditeurResource;
use App\Filament\Resources\CandidatureResource;
use App\Filament\Resources\DemandeAutorisationResource;
use App\Filament\Resources\UserResource;
use App\Http\Middleware\ExigerDoubleAuthentification;
use Filament\Facades\Filament;
use Filament\GlobalSearch\DefaultGlobalSearchProvider;
use Filament\GlobalSearch\GlobalSearchResults;

/**
 * Recherche globale du panneau. Lot 14d-4 : muette pour un compte qui doit encore activer sa double authentification
 * (son composant est rendu dans la barre du haut de « Ma sécurité », la seule page qui lui est ouverte).
 *
 * Lot 32 (25/11/2026) : la recherche unique du panneau. Les auditeurs d'abord, puis les dossiers de candidature, les
 * comptes et les demandes ; les autres écrans ensuite, dans l'ordre du panneau.
 */
final class RechercheGlobale extends DefaultGlobalSearchProvider
{
    /** @var list<class-string> */
    public const PRIORITE = [AuditeurResource::class, CandidatureResource::class, UserResource::class, DemandeAutorisationResource::class];

    public function getResults(string $query): ?GlobalSearchResults
    {
        $resultats = GlobalSearchResults::make();
        if (ExigerDoubleAuthentification::doitActiver(auth()->user())) {
            return $resultats;
        }
        $ressources = collect(array_values(Filament::getResources()))
            ->sortBy(fn (string $r, int $rang): int => ($p = array_search($r, self::PRIORITE, true)) === false ? count(self::PRIORITE) + $rang : $p);
        foreach ($ressources as $ressource) {
            if (! $ressource::canGloballySearch()) {
                continue;
            }
            $trouves = $ressource::getGlobalSearchResults($query);
            if ($trouves->count() > 0) {
                $resultats->category($ressource::getPluralModelLabel(), $trouves);
            }
        }

        return $resultats;
    }
}
