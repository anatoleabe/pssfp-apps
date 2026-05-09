<?php

declare(strict_types=1);

use App\Mail\ContactAutoReplyMailable;
use App\Mail\ContactMessageMailable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

uses()->group('module-1', 'contact');

beforeEach(function (): void {
    RateLimiter::clear('contact:127.0.0.1');
    Mail::fake();
});

it('returns 422 when nom is missing', function (): void {
    $response = $this->postJson('/v1/contact', [
        'email' => 'demo@example.com',
        'message' => 'Bonjour, je voudrais des informations.',
        'cgu' => true,
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['nom']);
});

it('returns 422 when email is invalid', function (): void {
    $response = $this->postJson('/v1/contact', [
        'nom' => 'Test',
        'email' => 'pas-un-email',
        'message' => 'Bonjour, je voudrais des informations.',
        'cgu' => true,
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['email']);
});

it('returns 422 when cgu is not accepted', function (): void {
    $response = $this->postJson('/v1/contact', [
        'nom' => 'Test',
        'email' => 'demo@example.com',
        'message' => 'Bonjour, je voudrais des informations.',
        'cgu' => false,
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['cgu']);
});

it('returns 422 when message is too short', function (): void {
    $response = $this->postJson('/v1/contact', [
        'nom' => 'Test',
        'email' => 'demo@example.com',
        'message' => 'court',
        'cgu' => true,
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['message']);
});

it('returns 201 and dispatches both mailables on happy path', function (): void {
    $response = $this->postJson('/v1/contact', [
        'nom' => 'Marie Demo',
        'email' => 'marie@example.com',
        'phone' => '+237691234567',
        'organisation' => 'MINFI',
        'subject' => 'Question sur la formation continue',
        'message' => 'Je voudrais des informations sur le module FC-03 cette annee.',
        'cgu' => true,
    ]);

    $response->assertStatus(201);
    Mail::assertSent(ContactMessageMailable::class, 1);
    Mail::assertSent(ContactAutoReplyMailable::class, 1);
});

it('rate-limits at 5 messages per IP per hour', function (): void {
    $payload = [
        'nom' => 'Test',
        'email' => 'demo@example.com',
        'message' => 'Bonjour, je voudrais des informations.',
        'cgu' => true,
    ];

    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/v1/contact', $payload)->assertStatus(201);
    }

    $this->postJson('/v1/contact', $payload)->assertStatus(429);
});

it('writes the IP into the message payload (not exposed in response)', function (): void {
    $this->postJson('/v1/contact', [
        'nom' => 'Test',
        'email' => 'demo@example.com',
        'message' => 'Bonjour, je voudrais des informations.',
        'cgu' => true,
    ])->assertStatus(201);

    Mail::assertSent(ContactMessageMailable::class, function ($mail): bool {
        return isset($mail->payload['ip']) && $mail->payload['ip'] !== '';
    });
});
