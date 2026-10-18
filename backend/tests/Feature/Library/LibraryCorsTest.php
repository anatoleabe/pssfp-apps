<?php

declare(strict_types=1);

uses()->group('module-3', 'library');

/*
 * La recherche et la balise de consultation partent du navigateur de
 * library.pssfp.org : l'API doit accepter cette origine (prévol CORS).
 */
it('accepte les requêtes CORS de la bibliothèque', function (string $origin): void {
    $this->withHeaders([
        'Origin' => $origin,
        'Access-Control-Request-Method' => 'GET',
    ])->options('/v1/library/search?q=budget')
        ->assertHeader('Access-Control-Allow-Origin', $origin);
})->with(['https://library.pssfp.org', 'https://bibliotheque.pssfp.org']);

it('refuse une origine inconnue', function (): void {
    $response = $this->withHeaders([
        'Origin' => 'https://evil.example',
        'Access-Control-Request-Method' => 'GET',
    ])->options('/v1/library/search?q=budget');

    expect($response->headers->get('Access-Control-Allow-Origin'))->not->toBe('https://evil.example');
});
