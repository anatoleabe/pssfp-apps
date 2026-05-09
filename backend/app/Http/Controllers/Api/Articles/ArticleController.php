<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Articles;

use App\Http\Controllers\Controller;
use App\Http\Resources\ArticleResource;
use App\Models\Article;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Endpoints publics /v1/articles/* (cf. spec module 1 PR N).
 *
 * - index : liste publiée paginée 9 articles/page, filtres ?category=, ?featured=
 * - show  : détail d'un article par slug — 404 si non publié.
 */
final class ArticleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Article::query()->published()->orderByDesc('published_at');

        if ($request->filled('category')) {
            $query->where('category', $request->string('category')->toString());
        }

        if ($request->boolean('featured')) {
            $query->where('is_pinned', true);
        }

        $perPage = min((int) $request->input('per_page', 9), 30);
        $page = $query->paginate($perPage);

        return ArticleResource::collection($page)->response();
    }

    public function show(string $slug): JsonResponse
    {
        $article = Article::query()->published()->where('slug', $slug)->first();
        if ($article === null) {
            return response()->json(['message' => 'Article introuvable.'], Response::HTTP_NOT_FOUND);
        }

        return ArticleResource::make($article)->response();
    }
}
