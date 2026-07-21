<?php

declare(strict_types=1);

use App\Events\CandidatureAccepted;
use App\Events\CandidatureCreated;
use App\Events\CandidatureRefused;
use App\Events\CandidatureSubmitted;
use App\Listeners\SendCandidatureSmsNotifications;
use App\Models\Candidature;
use App\Services\Sms\SmsServiceInterface;

it('sends an SMS for every candidature lifecycle event', function (): void {
    $candidature = new Candidature([
        'phone_e164' => '+237691111222',
    ]);
    $candidature->setAttribute('numero_dossier', 'P14026-001');
    $candidature->setAttribute('uuid', 'f91fb74b-90fa-4d24-bf56-a54f0d219801');

    $messages = [];
    $sms = Mockery::mock(SmsServiceInterface::class);
    $sms->shouldReceive('send')
        ->times(4)
        ->withArgs(function (string $phone, string $message) use (&$messages): bool {
            $messages[] = $message;

            return $phone === '+237691111222' && str_contains($message, 'P14026-001');
        });

    $listener = new SendCandidatureSmsNotifications($sms);
    $listener->handleCreated(new CandidatureCreated($candidature));
    $listener->handleSubmitted(new CandidatureSubmitted($candidature));
    $listener->handleAccepted(new CandidatureAccepted($candidature));
    $listener->handleRefused(new CandidatureRefused($candidature, 'Motif de recette'));

    expect($messages)->toHaveCount(4)
        ->and($messages[0])->toContain('compte candidat est créé')
        ->and($messages[1])->toContain('soumise avec succès')
        ->and($messages[2])->toContain('décision est disponible')
        ->and($messages[3])->toContain('décision est disponible');
});
