<?php

declare(strict_types=1);

use App\Models\Document;
use App\Models\SearchLog;
use App\Services\Library\Search\DatabaseDocumentSearch;
use App\Services\Library\Search\DocumentSearch;
use App\Services\Library\Search\FallbackDocumentSearch;
use App\Services\Library\Search\SearchResult;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Log;
use Meilisearch\Exceptions\ApiException;
use Meilisearch\Exceptions\CommunicationException;

uses()->group('module-3', 'library');

it('cherche dans les titres publiés, renvoie les facettes et journalise', function (): void {
    Document::factory()->published()->create(['title' => ['fr' => 'Décentralisation fiscale au Cameroun'], 'type' => 'memoire']);
    Document::factory()->published()->paid()->create(['title' => ['fr' => 'Fiscalité locale et décentralisation'], 'type' => 'ouvrage_numerique']);
    Document::factory()->create(['title' => ['fr' => 'Décentralisation brouillon']]);

    $this->getJson('/v1/library/search?q=decentralisation')
        ->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('meta.facets.type.memoire', 1)
        ->assertJsonPath('meta.facets.access_mode.payant', 1);

    expect(SearchLog::query()->where('query', 'decentralisation')->value('results_count'))->toBe(2);
});

it('applique les filtres du catalogue', function (): void {
    Document::factory()->published()->create(['title' => ['fr' => 'Audit interne'], 'type' => 'these']);
    Document::factory()->published()->create(['title' => ['fr' => 'Audit externe'], 'type' => 'memoire']);

    $this->getJson('/v1/library/search?q=audit&type=these')->assertJsonPath('meta.total', 1);
});

it('filtre le catalogue sur le type rapport', function (): void {
    Document::factory()->published()->create(['title' => ['fr' => 'Audit des comptes'], 'type' => 'rapport']);
    Document::factory()->published()->create(['title' => ['fr' => 'Audit interne'], 'type' => 'these']);

    $this->getJson('/v1/library/search?q=audit&type=rapport')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.type', 'rapport')
        ->assertJsonPath('data.0.type_label', 'Rapport et publication');
});

it('exige un terme de recherche', function (): void {
    $this->getJson('/v1/library/search')->assertUnprocessable();
});

it('se replie sur la base si Meilisearch est injoignable', function (): void {
    Document::factory()->published()->create(['title' => ['fr' => 'Marchés publics']]);
    $enPanne = new class implements DocumentSearch
    {
        public function search(string $q, array $filters, int $page, int $perPage): SearchResult
        {
            throw new CommunicationException('Meilisearch injoignable');
        }
    };
    app()->instance(DocumentSearch::class, new FallbackDocumentSearch($enPanne, new DatabaseDocumentSearch));

    $this->getJson('/v1/library/search?q=marches')->assertOk()->assertJsonPath('meta.total', 1);
});

it('ne masque pas une erreur applicative derrière le repli', function (): void {
    $buggy = new class implements DocumentSearch
    {
        public function search(string $q, array $filters, int $page, int $perPage): SearchResult
        {
            throw new RuntimeException('bug applicatif');
        }
    };
    app()->instance(DocumentSearch::class, new FallbackDocumentSearch($buggy, new DatabaseDocumentSearch));

    $this->getJson('/v1/library/search?q=marches')->assertStatus(500);
});

it('laisse remonter une erreur 4xx de Meilisearch mais se replie sur une 5xx', function (int $status, int $expected): void {
    Document::factory()->published()->create(['title' => ['fr' => 'Marchés publics']]);
    $api = new class($status) implements DocumentSearch
    {
        public function __construct(private int $status) {}

        public function search(string $q, array $filters, int $page, int $perPage): SearchResult
        {
            throw new ApiException(new Response($this->status), ['message' => 'erreur', 'code' => 'x', 'type' => 'x', 'link' => 'x']);
        }
    };
    app()->instance(DocumentSearch::class, new FallbackDocumentSearch($api, new DatabaseDocumentSearch));

    $this->getJson('/v1/library/search?q=marches')->assertStatus($expected);
})->with([[400, 500], [503, 200]]);

it('se replie sur la base si l\'index Meilisearch est absent, et le journalise en erreur', function (string $code, int $status, int $expected): void {
    Document::factory()->published()->create(['title' => ['fr' => 'Marchés publics']]);
    Log::spy();
    $api = new class($code, $status) implements DocumentSearch
    {
        public function __construct(private string $code, private int $status) {}

        public function search(string $q, array $filters, int $page, int $perPage): SearchResult
        {
            throw new ApiException(new Response($this->status), ['message' => 'Index `documents` not found.', 'code' => $this->code, 'type' => 'invalid_request', 'link' => 'x']);
        }
    };
    app()->instance(DocumentSearch::class, new FallbackDocumentSearch($api, new DatabaseDocumentSearch));

    $response = $this->getJson('/v1/library/search?q=marches')->assertStatus($expected);

    if ($expected === 200) {
        $response->assertJsonPath('meta.total', 1);
        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context): bool => ($context['code'] ?? null) === 'index_not_found');
    }
})->with([
    'index absent' => ['index_not_found', 404, 200],
    'autre 404' => ['document_not_found', 404, 500],
    '400' => ['invalid_search_filter', 400, 500],
]);
