<?php

declare(strict_types=1);

use App\Http\Middleware\IdentifiantRequete;
use App\Logging\AjouterUtilisateur;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;

uses()->group('socle');

it('donne un identifiant à chaque requête et le renvoie', function (): void {
    $reponse = $this->getJson('/v1/health');

    expect($reponse->headers->get(IdentifiantRequete::ENTETE))
        ->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/');
});

it('reprend l\'identifiant fourni par le proxy s\'il est sûr, le remplace sinon', function (string $fourni, bool $repris): void {
    $renvoye = $this->getJson('/v1/health', [IdentifiantRequete::ENTETE => $fourni])->headers->get(IdentifiantRequete::ENTETE);

    expect($renvoye === $fourni)->toBe($repris);
})->with([
    'identifiant nginx' => ['4f1c2b7e9a0d3c55', true],
    'trop court' => ['abc', false],
    'saut de ligne' => ["abcdefgh\nfaux", false],
    'caractères interdits' => ['<script>alert(1)</script>', false],
]);

it('ajoute l\'utilisateur connecté à chaque ligne du journal technique', function (): void {
    $journal = Log::build(['driver' => 'monolog', 'handler' => TestHandler::class]);
    (new AjouterUtilisateur)($journal);
    /** @var TestHandler $lignes */
    $lignes = $journal->getLogger()->getHandlers()[0];

    $journal->warning('Avant connexion');
    $this->actingAs($agent = User::factory()->create());
    $journal->warning('Après connexion');

    expect($lignes->getRecords()[0]->extra['user_id'])->toBeNull()
        ->and($lignes->getRecords()[1]->extra['user_id'])->toBe($agent->id);
});

it('écrit le journal technique par jour, sur 30 jours, lisible par PHP-FPM et les commandes', function (): void {
    $quotidien = config('logging.channels.daily');

    expect(config('logging.channels.stack.channels'))->toContain('daily')
        ->and($quotidien['days'])->toBe(30)
        ->and($quotidien['permission'])->toBe(0664)
        ->and($quotidien['tap'])->toContain(AjouterUtilisateur::class);
})->skip(fn (): bool => env('LOG_STACK') !== null, 'LOG_STACK imposé par l\'environnement');
