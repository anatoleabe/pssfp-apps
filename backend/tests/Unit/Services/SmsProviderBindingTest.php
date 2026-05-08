<?php

declare(strict_types=1);

use App\Exceptions\NotConfiguredException;
use App\Services\Sms\AfricasTalkingProvider;
use App\Services\Sms\FakeSmsProvider;
use App\Services\Sms\SmsServiceInterface;
use Illuminate\Support\Facades\Log;

uses()->group('unit', 'auth', 'sms');

it('binds FakeSmsProvider when SMS_PROVIDER is fake', function (): void {
    config()->set('services.sms.provider', 'fake');

    $resolved = app(SmsServiceInterface::class);

    expect($resolved)->toBeInstanceOf(FakeSmsProvider::class);
});

it('binds AfricasTalkingProvider when SMS_PROVIDER is africas_talking', function (): void {
    config()->set('services.sms.provider', 'africas_talking');

    $resolved = app(SmsServiceInterface::class);

    expect($resolved)->toBeInstanceOf(AfricasTalkingProvider::class);
});

it('AfricasTalkingProvider throws NotConfiguredException without env vars', function (): void {
    config()->set('services.africas_talking.username', null);
    config()->set('services.africas_talking.api_key', null);

    $provider = new AfricasTalkingProvider;

    expect(fn () => $provider->send('+237691234567', 'test'))
        ->toThrow(NotConfiguredException::class);
});

it('FakeSmsProvider does not perform any network call', function (): void {
    Log::shouldReceive('channel')
        ->with('sms')
        ->andReturnSelf();
    Log::shouldReceive('info')
        ->once()
        ->withArgs(fn ($msg, $context) => str_contains($msg, 'fake-sms'));

    $provider = new FakeSmsProvider;
    $provider->send('+237691234567', 'Hello');
});
