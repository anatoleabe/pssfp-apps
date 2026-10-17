<?php

declare(strict_types=1);

use App\Models\LibraryRedirect;

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
]);

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
