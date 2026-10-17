<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Library;

use App\Http\Controllers\Controller;
use App\Http\Resources\Library\DocumentResource;
use App\Models\Document;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/** Liste filtrable et fiche des documents publiés (spec V1 §5.3). */
final class DocumentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'type' => ['nullable', Rule::in(array_keys(Document::TYPES))],
            'specialite' => ['nullable', 'string', 'max:120'],
            'promotion' => ['nullable', 'integer', 'min:1', 'max:99'],
            'annee_min' => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'annee_max' => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'langue' => ['nullable', Rule::in(array_keys(Document::LANGUAGES))],
            'acces' => ['nullable', Rule::in(['libre', 'reserve', 'payant'])],
            'mot_cle' => ['nullable', 'string', 'max:120'],
            'tri' => ['nullable', Rule::in(array_keys((array) config('library.sorts')))],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ]);

        [$column, $direction] = config('library.sorts.'.($filters['tri'] ?? 'recent'));

        $query = Document::query()->published()
            ->with(['authors', 'motsCles'])
            ->tap(fn (Builder $q) => self::applyFilters($q, $filters))
            ->tap(fn (Builder $q) => self::orderNullsLast($q, $column, $direction))
            ->orderBy('id');

        $perPage = min((int) ($filters['per_page'] ?? config('library.per_page')), (int) config('library.max_per_page'));

        return DocumentResource::collection($query->paginate($perPage))->response();
    }

    public function show(string $slug): JsonResponse
    {
        $document = Document::query()->published()->with(['authors', 'motsCles'])->where('slug', $slug)->first();

        if ($document === null) {
            return response()->json(['message' => 'Document introuvable.'], Response::HTTP_NOT_FOUND);
        }

        // Compteur de consultations sans toucher updated_at ni réindexer.
        DB::table('documents')->where('id', $document->id)->increment('views_count');
        $document->views_count++;

        return DocumentResource::make($document)->detailed()
            ->additional(['related' => DocumentResource::collection($this->related($document))])
            ->response();
    }

    /** @param array<string, mixed> $filters */
    public static function applyFilters(Builder $query, array $filters): void
    {
        $query
            ->when($filters['type'] ?? null, fn (Builder $q, $v) => $q->where('type', $v))
            ->when($filters['specialite'] ?? null, fn (Builder $q, $v) => $q->where('specialite', $v))
            ->when($filters['promotion'] ?? null, fn (Builder $q, $v) => $q->where('promotion', $v))
            ->when($filters['annee_min'] ?? null, fn (Builder $q, $v) => $q->where('year', '>=', $v))
            ->when($filters['annee_max'] ?? null, fn (Builder $q, $v) => $q->where('year', '<=', $v))
            ->when($filters['langue'] ?? null, fn (Builder $q, $v) => $q->where('language', $v))
            ->when($filters['mot_cle'] ?? null, fn (Builder $q, $v) => $q->whereHas('motsCles', fn (Builder $m) => $m->where('slug', $v)))
            ->when($filters['acces'] ?? null, fn (Builder $q, $v) => match ($v) {
                'payant' => $q->whereNotNull('price_xaf'),
                'reserve' => $q->whereNull('price_xaf')->where('access_level', '!=', 'public'),
                default => $q->whereNull('price_xaf')->where('access_level', 'public'),
            });
    }

    /**
     * Tri sans valeur en dernier (PostgreSQL les place en tête en DESC).
     *
     * Seul fragment SQL écrit à la main : le constructeur de requêtes n'expose
     * pas NULLS LAST. Colonne et sens viennent de config('library.sorts'),
     * jamais de la requête HTTP.
     */
    private static function orderNullsLast(Builder $query, string $column, string $direction): void
    {
        $query->orderByRaw($query->getQuery()->getGrammar()->wrap($column).($direction === 'desc' ? ' DESC' : ' ASC').' NULLS LAST');
    }

    /**
     * Même type, mots-clés communs en priorité, 4 au plus.
     *
     * @return Collection<int, Document>
     */
    private function related(Document $document): Collection
    {
        $motCleIds = $document->motsCles->pluck('id');

        return Document::query()->published()
            ->with(['authors', 'motsCles'])
            ->where('id', '!=', $document->id)
            ->where('type', $document->type)
            ->when($motCleIds->isNotEmpty(), fn (Builder $q) => $q
                ->withExists(['motsCles as shares_mot_cle' => fn (Builder $m) => $m->whereIn('mots_cles.id', $motCleIds)])
                ->orderByDesc('shares_mot_cle'))
            ->orderByDesc('views_count')
            ->limit(4)
            ->get();
    }
}
