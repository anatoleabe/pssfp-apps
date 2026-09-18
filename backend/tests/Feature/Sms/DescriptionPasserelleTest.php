<?php

declare(strict_types=1);

use App\Services\Sms\AfricasTalkingProvider;
use App\Services\Sms\EchoSmsCodes;
use App\Services\Sms\EchoSmsProvider;
use App\Services\Sms\FakeSmsProvider;
use App\Services\Sms\TechSoftProvider;

uses()->group('sms');

it('décrit TechSoft avec son expéditeur et son jeton', function (): void {
    config()->set('services.techsoft.api_token', 'jeton-de-test-secret');
    config()->set('services.techsoft.sender_id', 'PSSFP');

    $description = app(TechSoftProvider::class)->decrire();

    expect($description->libelle)->toBe('TechSoft Bulk SMS')
        ->and($description->expediteur)->toBe('PSSFP')
        ->and($description->jetonConfigure)->toBeTrue()
        ->and($description->envoiReel)->toBeTrue();
});

it('signale un jeton absent', function (): void {
    config()->set('services.techsoft.api_token', '');
    config()->set('services.techsoft.sender_id', 'PSSFP');

    expect(app(TechSoftProvider::class)->decrire()->jetonConfigure)->toBeFalse();
});

it('n\'expose jamais la valeur du jeton dans la description', function (): void {
    config()->set('services.techsoft.api_token', 'valeur-ultra-secrete-du-jeton');
    config()->set('services.techsoft.sender_id', 'PSSFP');

    $description = app(TechSoftProvider::class)->decrire();

    expect($description->jetonConfigure)->toBeTrue()
        ->and(json_encode($description))->not->toContain('valeur-ultra-secrete-du-jeton');
});

it('déclare la simulation comme n\'envoyant rien', function (): void {
    $description = app(FakeSmsProvider::class)->decrire();

    expect($description->envoiReel)->toBeFalse()
        ->and($description->libelle)->toBe('Simulation (aucun envoi réel)');
});

it('décrit aussi Echo SMS et Africa\'s Talking', function (): void {
    expect(app(EchoSmsProvider::class)->decrire()->libelle)->toBe('Echo SMS')
        ->and(app(AfricasTalkingProvider::class)->decrire()->libelle)->toBe('Africa\'s Talking');
});

it('conserve la traduction des codes Echo SMS après extraction', function (): void {
    expect(EchoSmsCodes::libelle('1016'))->toBe('Message envoyé')
        ->and(EchoSmsCodes::libelle('1007'))->toBe('Solde insuffisant')
        ->and(EchoSmsCodes::libelle('1002'))->toBe('Sender ID / masking invalide')
        ->and(EchoSmsCodes::libelle('9999'))->toBeNull();
});
