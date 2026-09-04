<?php

declare(strict_types=1);

use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\CandidatureRelance;
use App\Models\User;
use App\Services\NotificationCandidatsService;
use App\Services\Sms\EchoSmsProvider;
use App\Services\Sms\SmsServiceInterface;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Http;

uses()->group('applications', 'tracabilite');

beforeEach(function (): void {
    $this->seed([
        PaysSeeder::class, RegionsCamerounSeeder::class,
        DepartementsCamerounSeeder::class, RolePermissionSeeder::class,
    ]);

    config()->set('services.sms.provider', 'echosms');
    config()->set('services.echosms.base_url', 'https://account.echosms.io/api');
    config()->set('services.echosms.api_key', 'cle-de-test');
    config()->set('services.echosms.from_type', 'sender_id');
    config()->set('services.echosms.sender_id', 'PSSFP');

    $this->app->bind(SmsServiceInterface::class, EchoSmsProvider::class);

    $this->campagne = CampagneCandidature::factory()->create([
        'status' => 'open',
        'opens_at' => now()->subMonth(),
        'closes_at' => now()->addMonth(),
    ]);

    $this->candidature = Candidature::factory()->forCampagne($this->campagne)->create([
        'nom' => 'Ndongo',
        'prenom' => 'Paul',
        'phone_e164' => '+237691234567',
        'indicatif1' => '+237',
        'telephone1' => '691234567',
        'specialite' => array_values((array) config('specialites'))[0],
    ]);

    $this->agent = User::factory()->create();
    $this->agent->assignRole('admin');
});

it('enregistre le Sender ID et le code fournisseur sur un envoi réussi', function (): void {
    Http::fake(['*/sent/compose*' => Http::response(['response' => '1016'], 200)]);

    app(NotificationCandidatsService::class)->envoyer(
        Candidature::whereKey($this->candidature->id)->get(),
        'sms',
        'Bonjour {prenom}, votre dossier est complet.',
        null,
        $this->agent,
    );

    $ligne = CandidatureRelance::latest('id')->first();

    expect($ligne->statut)->toBe(CandidatureRelance::STATUT_ENVOYE)
        ->and($ligne->expediteur)->toBe('PSSFP')
        ->and($ligne->code_fournisseur)->toBe('1016');
});

it('distingue une livraison partielle par son code', function (): void {
    Http::fake(['*/sent/compose*' => Http::response(['response' => '1015'], 200)]);

    app(NotificationCandidatsService::class)->envoyer(
        Candidature::whereKey($this->candidature->id)->get(),
        'sms',
        'Message',
        null,
        $this->agent,
    );

    expect(CandidatureRelance::latest('id')->first()->code_fournisseur)->toBe('1015');
});

it('conserve le Sender ID même quand la passerelle refuse l’envoi', function (): void {
    // Solde épuisé : sans l'expéditeur en base, impossible de distinguer
    // ce cas d'un masking expiré depuis l'admin.
    Http::fake(['*/sent/compose*' => Http::response(['response' => '1007'], 200)]);

    app(NotificationCandidatsService::class)->envoyer(
        Candidature::whereKey($this->candidature->id)->get(),
        'sms',
        'Message',
        null,
        $this->agent,
    );

    $ligne = CandidatureRelance::latest('id')->first();

    expect($ligne->statut)->toBe(CandidatureRelance::STATUT_ECHEC)
        ->and($ligne->expediteur)->toBe('PSSFP')
        ->and($ligne->erreur)->toContain('1007')
        ->and($ligne->erreur)->toContain('Solde insuffisant');
});

it('n’écrit jamais la clé API dans le journal', function (): void {
    Http::fake(['*/sent/compose*' => Http::response(['response' => '1002'], 200)]);

    app(NotificationCandidatsService::class)->envoyer(
        Candidature::whereKey($this->candidature->id)->get(),
        'sms',
        'Message',
        null,
        $this->agent,
    );

    $ligne = CandidatureRelance::latest('id')->first();

    expect($ligne->erreur)->not->toContain('cle-de-test')
        ->and($ligne->erreur)->not->toContain('api_key');
});

it('trace l’adresse d’expédition pour un e-mail', function (): void {
    config()->set('mail.from.address', 'contact@pssfp.net');

    $this->candidature->update(['email' => 'paul.ndongo@example.com']);

    app(NotificationCandidatsService::class)->envoyer(
        Candidature::whereKey($this->candidature->id)->get(),
        'email',
        'Bonjour {prenom}',
        'Votre dossier',
        $this->agent,
    );

    $ligne = CandidatureRelance::latest('id')->first();

    expect($ligne->canal)->toBe(CandidatureRelance::CANAL_EMAIL)
        ->and($ligne->expediteur)->toBe('contact@pssfp.net');
});
