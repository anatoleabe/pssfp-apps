<?php

declare(strict_types=1);

use App\Exceptions\NotConfiguredException;
use App\Services\Sms\AfricasTalkingProvider;
use Illuminate\Support\Facades\Http;

uses()->group('sms');

function atSuccessPayload(): array
{
    return [
        'SMSMessageData' => [
            'Message' => 'Sent to 1/1 Total Cost: XAF 15.0000',
            'Recipients' => [
                ['number' => '+237691111222', 'status' => 'Success', 'cost' => 'XAF 15.0000'],
            ],
        ],
    ];
}

it('throws NotConfiguredException when credentials are missing', function (): void {
    config()->set('services.africas_talking.username', null);
    config()->set('services.africas_talking.api_key', null);

    expect(fn () => app(AfricasTalkingProvider::class)->send('+237691111222', 'test'))
        ->toThrow(NotConfiguredException::class);
});

it('sends the SMS with apiKey header, sender id and E.164 recipient', function (): void {
    config()->set('services.africas_talking.username', 'pssfp');
    config()->set('services.africas_talking.api_key', 'atsk_test');
    config()->set('services.africas_talking.sender_id', 'PSSFP');
    Http::fake(['api.africastalking.com/*' => Http::response(atSuccessPayload())]);

    app(AfricasTalkingProvider::class)->send('+237691111222', 'PSSFP : code 123456');

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://api.africastalking.com/version1/messaging'
            && $request->hasHeader('apiKey', 'atsk_test')
            && $request['username'] === 'pssfp'
            && $request['to'] === '+237691111222'
            && $request['from'] === 'PSSFP';
    });
});

it('throws when the HTTP call fails', function (): void {
    config()->set('services.africas_talking.username', 'pssfp');
    config()->set('services.africas_talking.api_key', 'atsk_test');
    Http::fake(['api.africastalking.com/*' => Http::response('server error', 500)]);

    expect(fn () => app(AfricasTalkingProvider::class)->send('+237691111222', 'test'))
        ->toThrow(RuntimeException::class);
});

it('throws when Africa\'s Talking rejects the recipient', function (): void {
    config()->set('services.africas_talking.username', 'pssfp');
    config()->set('services.africas_talking.api_key', 'atsk_test');
    Http::fake([
        'api.africastalking.com/*' => Http::response([
            'SMSMessageData' => [
                'Recipients' => [
                    ['number' => '+237691111222', 'status' => 'InvalidSenderId'],
                ],
            ],
        ]),
    ]);

    expect(fn () => app(AfricasTalkingProvider::class)->send('+237691111222', 'test'))
        ->toThrow(RuntimeException::class, 'InvalidSenderId');
});
