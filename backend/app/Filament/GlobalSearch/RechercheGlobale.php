<?php

declare(strict_types=1);

namespace App\Filament\GlobalSearch;

use App\Http\Middleware\ExigerDoubleAuthentification;
use Filament\GlobalSearch\DefaultGlobalSearchProvider;
use Filament\GlobalSearch\GlobalSearchResults;

/**
 * Recherche globale du panneau. Lot 14d-4 : muette pour un compte qui doit encore activer sa double authentification
 * (son composant est rendu dans la barre du haut de « Ma sécurité », la seule page qui lui est ouverte).
 */
final class RechercheGlobale extends DefaultGlobalSearchProvider
{
    public function getResults(string $query): ?GlobalSearchResults
    {
        if (ExigerDoubleAuthentification::doitActiver(auth()->user())) {
            return GlobalSearchResults::make();
        }

        return parent::getResults($query);
    }
}
