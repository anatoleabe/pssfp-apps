<?php

declare(strict_types=1);

use App\Events\CandidatureSubmitted;
use App\Listeners\SendCandidatureSubmittedEmail;
use App\Mail\CandidatureSubmittedAdminMail;
use App\Mail\CandidatureSubmittedMail;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Support\AppSettings;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Illuminate\Support\Facades\Mail;

uses()->group('applications', 'mail', 'settings');

beforeEach(function (): void {
    Mail::fake();

    $this->seed([
        PaysSeeder::class,
        RegionsCamerounSeeder::class,
        DepartementsCamerounSeeder::class,
    ]);

    $this->campagne = CampagneCandidature::factory()->create([
        'slug' => 'p14-2026',
        'status' => 'open',
        'opens_at' => now()->subMonth(),
        'closes_at' => now()->addMonth(),
    ]);

    $this->candidature = Candidature::factory()->create([
        'campagne_id' => $this->campagne->id,
        'email' => 'candidat@example.com',
        'statut' => Candidature::STATUT_CANDIDAT,
        'submitted_at' => now(),
    ]);
});

function declencherNotification(Candidature $candidature): void
{
    (new SendCandidatureSubmittedEmail)->handle(new CandidatureSubmitted($candidature));
}

it('adds the configured recipients in blind copy of the admin notification', function (): void {
    AppSettings::set(AppSettings::KEY_CANDIDATURE_NOTIFICATION_BCC, [
        'direction@pssfp.net',
        'scolarite@pssfp.net',
    ]);

    declencherNotification($this->candidature);

    Mail::assertQueued(
        CandidatureSubmittedAdminMail::class,
        fn (CandidatureSubmittedAdminMail $mail): bool => $mail->hasBcc('direction@pssfp.net')
            && $mail->hasBcc('scolarite@pssfp.net')
            && $mail->hasTo(config('mail.admissions_recipient')),
    );
});

it('never puts the copies in visible Cc', function (): void {
    AppSettings::set(AppSettings::KEY_CANDIDATURE_NOTIFICATION_BCC, ['direction@pssfp.net']);

    declencherNotification($this->candidature);

    Mail::assertQueued(
        CandidatureSubmittedAdminMail::class,
        fn (CandidatureSubmittedAdminMail $mail): bool => ! $mail->hasCc('direction@pssfp.net'),
    );
});

it('never puts the copies on the candidate confirmation', function (): void {
    AppSettings::set(AppSettings::KEY_CANDIDATURE_NOTIFICATION_BCC, ['direction@pssfp.net']);

    declencherNotification($this->candidature);

    Mail::assertQueued(
        CandidatureSubmittedMail::class,
        fn (CandidatureSubmittedMail $mail): bool => ! $mail->hasBcc('direction@pssfp.net'),
    );
});

it('still notifies the admissions inbox when no copy is configured', function (): void {
    declencherNotification($this->candidature);

    Mail::assertQueued(
        CandidatureSubmittedAdminMail::class,
        fn (CandidatureSubmittedAdminMail $mail): bool => $mail->hasTo(config('mail.admissions_recipient')),
    );
});
