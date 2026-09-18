<?php

declare(strict_types=1);

use App\Filament\Pages\Parametres;
use App\Mail\EnvoiTestMail;
use App\Models\User;
use App\Services\EnvoiTestService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Spatie\Activitylog\Models\Activity;

uses()->group('admin', 'envois');

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    config()->set('pssfp.filament.require_2fa', false);
    config()->set('services.sms.provider', 'techsoft');
    config()->set('services.techsoft.base_url', 'https://app.techsoft-sms.com/api/v3');
    config()->set('services.techsoft.api_token', 'jeton-de-test-secret');
    config()->set('services.techsoft.sender_id', 'PSSFP');
});

function auteurDiagnostic(): User
{
    $user = User::factory()->create();
    $user->assignRole('super_admin');

    return $user;
}

function reponseEnvoiOk(): void
{
    Http::fake(['*/sms/send' => Http::response([
        'status' => 'success',
        'data' => [['uid' => 'u1', 'from' => 'PSSFP', 'status' => 'Delivered', 'cost' => '12']],
    ], 200)]);
}

it('normalise un numéro local camerounais avant d\'appeler la passerelle', function (): void {
    reponseEnvoiOk();

    app(EnvoiTestService::class)->envoyerSms('691234567', auteurDiagnostic());

    Http::assertSent(fn ($request): bool => $request['recipient'] === '+237691234567');
});

it('refuse un numéro hors indicatifs desservis sans appeler la passerelle', function (): void {
    Http::fake();

    expect(fn () => app(EnvoiTestService::class)->envoyerSms('+15551234567', auteurDiagnostic()))
        ->toThrow(RuntimeException::class);

    Http::assertNothingSent();
});

it('remonte intact le message d\'erreur de la passerelle', function (): void {
    Http::fake(['*/sms/send' => Http::response(['status' => 'error', 'code' => 104], 403)]);

    expect(fn () => app(EnvoiTestService::class)->envoyerSms('691234567', auteurDiagnostic()))
        ->toThrow(RuntimeException::class, 'Solde SMS insuffisant');
});

it('envoie un e-mail de test', function (): void {
    Mail::fake();

    app(EnvoiTestService::class)->envoyerEmail('destinataire@example.test', auteurDiagnostic());

    Mail::assertSent(EnvoiTestMail::class);
});

it('trace chaque test dans l\'Activity Log avec le numéro masqué', function (): void {
    reponseEnvoiOk();

    $auteur = auteurDiagnostic();
    app(EnvoiTestService::class)->envoyerSms('691234567', $auteur);

    $log = Activity::where('event', 'envoi_test')->latest()->first();

    expect($log)->not->toBeNull()
        ->and($log->causer_id)->toBe($auteur->id)
        ->and(json_encode($log->properties))->not->toContain('691234567')
        ->and(json_encode($log->properties))->not->toContain('jeton-de-test-secret');
});

it('bloque le sixième envoi de test dans la minute', function (): void {
    reponseEnvoiOk();

    $auteur = auteurDiagnostic();
    $service = app(EnvoiTestService::class);

    for ($i = 0; $i < 5; $i++) {
        $service->envoyerSms('691234567', $auteur);
    }

    expect(fn () => $service->envoyerSms('691234567', $auteur))
        ->toThrow(RuntimeException::class, 'Trop d\'envois de test');
});

it('affiche le fournisseur actif et son expéditeur', function (): void {
    $this->actingAs(auteurDiagnostic());

    $this->get('/admin/parametres')
        ->assertOk()
        ->assertSee('TechSoft Bulk SMS')
        ->assertSee('PSSFP')
        // Le jeton ne doit apparaître nulle part dans la page rendue.
        ->assertDontSee('jeton-de-test-secret');
});

it('cache les actions d\'envoi à qui n\'a pas candidature.notify', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo('settings.manage');
    $this->actingAs($user);

    $this->livewire(Parametres::class)
        ->assertActionHidden('tester_sms')
        ->assertActionHidden('tester_email')
        ->assertActionHidden('verifier_connexion');
});

it('expose les actions d\'envoi à un super admin', function (): void {
    $this->actingAs(auteurDiagnostic());

    $this->livewire(Parametres::class)
        ->assertActionVisible('tester_sms')
        ->assertActionVisible('tester_email')
        ->assertActionVisible('verifier_connexion');
});
