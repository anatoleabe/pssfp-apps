<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Reference;

use App\Http\Controllers\Controller;
use App\Http\Resources\DepartementCamerounResource;
use App\Http\Resources\PaysResource;
use App\Http\Resources\RegionCamerounResource;
use App\Models\DepartementCameroun;
use App\Models\Pays;
use App\Models\RegionCameroun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Cache;

/**
 * Référentiels publics pour les sélecteurs cascading frontend.
 *
 * Cache 24h Redis par endpoint. Tag `reference` pour purge globale future
 * (commande artisan `reference:flush` à coder ultérieurement).
 *
 * Endpoints :
 * - GET /v1/reference/pays
 * - GET /v1/reference/regions-cameroun
 * - GET /v1/reference/departements-cameroun?region={code}
 * - GET /v1/reference/specialites (V1 : config statique, V2 : table CMS)
 */
final class ReferenceController extends Controller
{
    private const CACHE_TTL = 86400; // 24h

    public function pays(): AnonymousResourceCollection
    {
        $pays = Cache::remember('reference.pays', self::CACHE_TTL, function () {
            return Pays::query()->orderBy('nom')->get();
        });

        return PaysResource::collection($pays);
    }

    public function regions(): AnonymousResourceCollection
    {
        $regions = Cache::remember('reference.regions_cameroun', self::CACHE_TTL, function () {
            return RegionCameroun::query()->orderBy('order')->get();
        });

        return RegionCamerounResource::collection($regions);
    }

    public function departements(Request $request): AnonymousResourceCollection
    {
        $regionCode = (string) $request->query('region', '');

        $cacheKey = $regionCode === ''
            ? 'reference.departements_cameroun:all'
            : 'reference.departements_cameroun:'.$regionCode;

        $deps = Cache::remember($cacheKey, self::CACHE_TTL, function () use ($regionCode) {
            $q = DepartementCameroun::query()->orderBy('nom');
            if ($regionCode !== '') {
                $q->where('region_code', $regionCode);
            }

            return $q->get();
        });

        return DepartementCamerounResource::collection($deps);
    }

    public function specialites(): JsonResponse
    {
        $specs = (array) config('specialites', []);

        $items = [];
        foreach ($specs as $slug => $label) {
            $items[] = ['slug' => $slug, 'label' => $label];
        }

        return response()->json(['data' => $items]);
    }
}
