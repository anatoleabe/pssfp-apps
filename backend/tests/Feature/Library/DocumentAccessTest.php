<?php

declare(strict_types=1);

use App\Models\Document;
use App\Services\Library\AccessDecision;
use App\Services\Library\DocumentAccess;

uses()->group('module-3', 'library');

it('décide l\'accès selon l\'ordre de la spec §4', function (Closure $make, bool $allowed, string $reason): void {
    $decision = app(DocumentAccess::class)->decide(null, $make());

    expect($decision->allowed)->toBe($allowed)->and($decision->reason)->toBe($reason);
})->with([
    'brouillon' => [fn () => Document::factory()->make(), false, AccessDecision::UNPUBLISHED],
    'lien externe' => [fn () => Document::factory()->published()->external()->make(), true, AccessDecision::EXTERNAL],
    'payant' => [fn () => Document::factory()->published()->withFile()->paid()->make(), false, AccessDecision::PAID],
    'payant et réservé' => [fn () => Document::factory()->published()->withFile()->paid()->reserved()->make(), false, AccessDecision::PAID],
    'réservé' => [fn () => Document::factory()->published()->withFile()->reserved()->make(), false, AccessDecision::RESERVED],
    'public' => [fn () => Document::factory()->published()->withFile()->make(), true, AccessDecision::PUBLIC],
]);

it('sérialise la décision pour l\'API', function (): void {
    $decision = app(DocumentAccess::class)->decide(null, Document::factory()->published()->withFile()->make());

    expect($decision->toArray())->toBe(['allowed' => true, 'reason' => 'public']);
});
