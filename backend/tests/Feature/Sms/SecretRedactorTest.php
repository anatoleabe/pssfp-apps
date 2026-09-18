<?php

declare(strict_types=1);

use App\Support\SecretRedactor;

uses()->group('sms', 'securite');

it('efface une clé passée en paramètre de requête', function (): void {
    $message = 'cURL error 28: Operation timed out for '
        .'https://account.echosms.io/api/sent/compose?api_key=9|HIfmqBKTYuYCS8nq';

    $redige = SecretRedactor::redact($message);

    expect($redige)->not->toContain('HIfmqBKTYuYCS8nq')
        ->and($redige)->toContain('[secret masqué]')
        ->and($redige)->toContain('cURL error 28');
});

it('efface un jeton porteur', function (): void {
    expect(SecretRedactor::redact('Authorization: Bearer 1680|F2S1mQs6FJn0NMUp4FD'))
        ->not->toContain('F2S1mQs6FJn0NMUp4FD');
});

it('laisse intact un message sans secret', function (): void {
    expect(SecretRedactor::redact('Solde SMS insuffisant'))->toBe('Solde SMS insuffisant')
        ->and(SecretRedactor::redact(null))->toBeNull();
});
