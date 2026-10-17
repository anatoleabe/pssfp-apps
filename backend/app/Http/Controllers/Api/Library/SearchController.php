<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Library;

use App\Http\Controllers\Controller;
use App\Http\Resources\Library\DocumentResource;
use App\Models\Document;
use App\Models\SearchLog;
use App\Services\Library\Search\DocumentSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

/** Recherche plein texte publique (spec V1 §5.3). */
final class SearchController extends Controller
{
    public function __invoke(Request $request, DocumentSearch $search): JsonResponse
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:200'],
            'type' => ['nullable', Rule::in(array_keys(Document::TYPES))],
            'specialite' => ['nullable', 'string', 'max:120'],
            'promotion' => ['nullable', 'integer', 'min:1', 'max:99'],
            'annee_min' => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'annee_max' => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'langue' => ['nullable', Rule::in(array_keys(Document::LANGUAGES))],
            'acces' => ['nullable', Rule::in(['libre', 'reserve', 'payant'])],
            'mot_cle' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $filters = Arr::except($data, ['q', 'page']);
        $page = (int) ($data['page'] ?? 1);
        $perPage = (int) config('library.per_page');

        $result = $search->search($data['q'], $filters, $page, $perPage);

        SearchLog::create([
            'query' => mb_substr($data['q'], 0, 255),
            'filters' => $filters !== [] ? $filters : null,
            'results_count' => $result->total,
            'ip' => $request->ip(),
            'searched_at' => now(),
        ]);

        return response()->json([
            'data' => DocumentResource::collection($result->documents)->resolve($request),
            'highlights' => (object) $result->highlights,
            'meta' => [
                'total' => $result->total,
                'page' => $page,
                'per_page' => $perPage,
                'last_page' => max(1, (int) ceil($result->total / $perPage)),
                'facets' => $result->facets,
            ],
        ]);
    }
}
