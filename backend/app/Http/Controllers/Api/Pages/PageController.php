<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Pages;

use App\Http\Controllers\Controller;
use App\Http\Resources\PageResource;
use App\Models\Page;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Endpoints publics /v1/pages/* (cf. spec module 1 PR K).
 *
 * - index : liste des pages publiées, filtrable par parent_slug et in_menu.
 * - show  : détail d'une page par slug — 404 si non publiée ou inexistante.
 * - menu  : structure de navigation principale (pages is_in_menu=true).
 *
 * Toutes les réponses sont en français (V1 mono-locale FR).
 * Cache HTTP 5 min — invalidé via Eloquent observer (à câbler en PR de suivi).
 */
final class PageController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Page::query()->published();

        if ($request->filled('parent_slug')) {
            $query->where('parent_slug', $request->string('parent_slug')->toString());
        }

        if ($request->boolean('in_menu')) {
            $query->inMenu();
        } else {
            $query->orderBy('order');
        }

        $pages = $query->limit(100)->get();

        return PageResource::collection($pages)->response();
    }

    public function show(string $slug): JsonResponse
    {
        // Le slug peut contenir des slashs (ex: pssfp/presentation) — on
        // décode l'URL pour reconstruire le slug original.
        $cleanSlug = trim(rawurldecode($slug), '/');

        $page = Page::query()->published()->where('slug', $cleanSlug)->first();

        if ($page === null) {
            return response()->json([
                'message' => 'Page introuvable.',
            ], Response::HTTP_NOT_FOUND);
        }

        return PageResource::make($page)->response();
    }

    /**
     * Structure du menu principal.
     *
     * Retourne un tableau [{slug, label, order, children?}] trié par ordre
     * croissant. La hiérarchie 1 niveau (parent_slug) est nestée côté backend
     * pour éviter au frontend de devoir reconstruire la structure.
     */
    public function menu(): JsonResponse
    {
        $pages = Page::query()
            ->published()
            ->inMenu()
            ->get(['slug', 'parent_slug', 'order', 'menu_label', 'title']);

        $tree = [];
        $childrenByParent = [];

        foreach ($pages as $page) {
            // menu_label peut être stocké en `{"fr": null}` quand non défini
            // (cf. Spatie translatable). On fallback sur title si label vide.
            $rawLabel = $page->getTranslation('menu_label', 'fr');
            $label = $rawLabel !== null && $rawLabel !== ''
                ? $rawLabel
                : $page->getTranslation('title', 'fr');

            $node = [
                'slug' => $page->slug,
                'label' => $label,
                'order' => (int) $page->order,
            ];

            if ($page->parent_slug === null) {
                $tree[$page->slug] = $node;
            } else {
                $childrenByParent[$page->parent_slug][] = $node;
            }
        }

        foreach ($childrenByParent as $parentSlug => $children) {
            usort($children, static fn ($a, $b) => $a['order'] <=> $b['order']);
            if (isset($tree[$parentSlug])) {
                $tree[$parentSlug]['children'] = $children;
            } else {
                // Parent absent du menu (ou inexistant) — on remonte les enfants
                // au niveau racine pour ne pas les perdre.
                foreach ($children as $child) {
                    $tree[$child['slug']] = $child;
                }
            }
        }

        $sorted = array_values($tree);
        usort($sorted, static fn ($a, $b) => $a['order'] <=> $b['order']);

        return response()->json(['data' => $sorted]);
    }
}
