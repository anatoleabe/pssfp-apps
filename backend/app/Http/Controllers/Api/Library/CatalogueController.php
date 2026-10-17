<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Library;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\LibraryRedirect;
use App\Models\MotCle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Données transverses de la bibliothèque : compteurs, thésaurus, anciennes URL. */
final class CatalogueController extends Controller
{
    public function stats(): JsonResponse
    {
        $byType = Document::query()->published()
            ->selectRaw('type, COUNT(*) AS total')
            ->groupBy('type')
            ->pluck('total', 'type')
            ->map(fn ($n): int => (int) $n);

        return response()->json(['data' => [
            'total' => $byType->sum(),
            'by_type' => $byType,
            'paid_count' => Document::query()->published()->whereNotNull('price_xaf')->count(),
        ]]);
    }

    public function motsCles(): JsonResponse
    {
        $items = MotCle::query()->with('parent')->orderBy('slug')->get()->map(fn (MotCle $m): array => [
            'slug' => $m->slug,
            'label' => $m->getTranslation('label', 'fr'),
            'parent_slug' => $m->parent?->slug,
        ]);

        return response()->json(['data' => $items]);
    }

    public function redirect(Request $request): JsonResponse
    {
        $validated = $request->validate(['path' => ['required', 'string', 'max:500']]);
        $path = '/'.ltrim($validated['path'], '/');
        $redirect = LibraryRedirect::query()->where('from_path', $path)->first();

        if ($redirect === null) {
            return response()->json(['message' => 'Aucune redirection.'], Response::HTTP_NOT_FOUND);
        }

        $redirect->increment('hits');

        return response()->json(['data' => [
            'to_path' => $redirect->to_path,
            'status_code' => $redirect->status_code,
        ]]);
    }
}
