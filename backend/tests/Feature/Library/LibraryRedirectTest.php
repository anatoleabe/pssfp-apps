<?php

declare(strict_types=1);

use App\Models\LibraryRedirect;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

uses()->group('module-3', 'library');

it('accepte un chemin interne comme cible de redirection', function (): void {
    $redirect = LibraryRedirect::create(['from_path' => '/index.php/textes', 'to_path' => '/collections/texte_loi?acces=libre']);

    expect($redirect->fresh()->to_path)->toBe('/collections/texte_loi?acces=libre');
});

it('refuse une cible de redirection hors du site ou piégée', function (string $toPath): void {
    expect(fn () => LibraryRedirect::create(['from_path' => '/index.php/piege', 'to_path' => $toPath]))
        ->toThrow(InvalidArgumentException::class);

    expect(LibraryRedirect::query()->where('from_path', '/index.php/piege')->exists())->toBeFalse();
})->with([
    'protocole relatif' => '//evil.com',
    'barre oblique inverse' => '/\\evil.com',
    'tabulation' => "/\t/evil.com",
    'retour chariot' => "/ok\r\nLocation: https://evil.com",
    'caractère nul' => "/ok\0",
    'DEL' => "/ok\x7F",
    'URL absolue' => 'https://evil.com/x',
    'chemin relatif' => 'collections/memoire',
    'vide' => '',
    'segment .. puis //' => '/a/..//evil.com',
    'segment %2e%2e puis //' => '/%2e%2e//evil.com',
    'segment . puis barre inverse' => '/./\\evil.com',
    'segment .. puis barre inverse' => '/x/../\\evil.com',
    'segment %2e' => '/%2e/x',
    'segment %2E%2E majuscules' => '/a/%2E%2E/b',
    'segment . final' => '/a/.',
    'segment .. avant la query' => '/a/..?x=1',
    'barre inverse au milieu' => '/collections\\memoire',
]);

it('accepte les chemins internes usuels', function (string $toPath): void {
    expect(LibraryRedirect::isSafeTarget($toPath))->toBeTrue();
})->with([
    '/collections/texte_loi',
    '/documents/x?y=1',
    '/documents/rapport-v1.2',
    '/documents/a...b',
    '/collections/memoire?acces=libre&page=2',
]);

/** Insertion brute (comme un import en masse) : la contrainte CHECK de PostgreSQL doit refuser. */
function insertRawRedirect(string $from, string $to): void
{
    DB::table('library_redirects')->insert(['from_path' => $from, 'to_path' => $to, 'status_code' => 301, 'hits' => 0, 'created_at' => now(), 'updated_at' => now()]);
}

it('refuse en base une cible piégée insérée sans passer par le modèle', function (string $toPath): void {
    expect(fn () => insertRawRedirect('/index.php/brut', $toPath))->toThrow(QueryException::class, 'library_redirects_to_path_check');
})->with([
    '//evil.com',
    '/\\evil.com',
    "/\t/evil.com",
    'https://evil.com',
    '/a/..//evil.com',
    '/%2e%2e//evil.com',
    '/./\\evil.com',
    '/x/../\\evil.com',
    '/%2e/x',
    '/a/%2E%2E/b',
]);

it('accepte en base une cible interne et refuse une ancienne URL qui ne commence pas par /', function (): void {
    insertRawRedirect('/index.php?option=com_docman&view=list', '/documents/x?y=1');
    expect(DB::table('library_redirects')->where('from_path', '/index.php?option=com_docman&view=list')->value('to_path'))->toBe('/documents/x?y=1');

    expect(fn () => insertRawRedirect('index.php/sans-barre', '/collections/memoire'))->toThrow(QueryException::class, 'library_redirects_from_path_check');
});

it('migration des contraintes réversible', function (): void {
    $migration = require database_path('migrations/2026_10_18_100000_add_library_redirects_path_checks.php');

    $migration->down();
    insertRawRedirect('/index.php/avant', '//evil.com');
    DB::table('library_redirects')->where('from_path', '/index.php/avant')->delete();

    $migration->up();
    expect(fn () => insertRawRedirect('/index.php/apres', '//evil.com'))->toThrow(QueryException::class, 'library_redirects_to_path_check');
});

it('refuse aussi une cible piégée à la mise à jour', function (): void {
    $redirect = LibraryRedirect::create(['from_path' => '/index.php/a', 'to_path' => '/collections/memoire']);

    expect(fn () => $redirect->update(['to_path' => '//evil.com']))->toThrow(InvalidArgumentException::class);
    expect($redirect->fresh()->to_path)->toBe('/collections/memoire');
});

it('résout une ancienne URL /index.php stockée avec sa query string', function (): void {
    LibraryRedirect::create(['from_path' => '/index.php?option=com_docman&view=list&slug=memoires', 'to_path' => '/collections/memoire']);

    $this->getJson('/v1/library/redirects?path='.rawurlencode('/index.php?option=com_docman&view=list&slug=memoires'))
        ->assertOk()
        ->assertJsonPath('data.to_path', '/collections/memoire');

    $this->getJson('/v1/library/redirects?path='.rawurlencode('/index.php'))->assertNotFound();
});

it('rejette les caractères de contrôle C1 dans une cible', function (): void {
    expect(LibraryRedirect::isSafeTarget("/a\u{0085}b"))->toBeFalse()
        ->and(LibraryRedirect::isSafeTarget("/a\u{009F}"))->toBeFalse()
        ->and(LibraryRedirect::isSafeTarget('/documents/été'))->toBeTrue();
});
