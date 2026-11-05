<?php

declare(strict_types=1);

use App\Filament\Resources\CandidatureResource\Pages\ListCandidatures;
use App\Mail\NotificationCandidatMail;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\User;
use App\Services\DocumentsAuditeurs\DocumentImpossible;
use App\Services\DocumentsAuditeurs\DocumentsAuditeurs;
use App\Services\DocumentsAuditeurs\ParametresLettres;
use App\Services\ExportsRetenus\AttributionMatricules;
use App\Services\ExportsRetenus\IdentifiantsMoodle;
use App\Services\Sms\SmsServiceInterface;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

uses()->group('filament', 'documents-auditeurs');

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    $this->seed([PaysSeeder::class, RegionsCamerounSeeder::class, DepartementsCamerounSeeder::class, RolePermissionSeeder::class]);
    $this->admin = tap(User::factory()->create(['email' => 'admin-'.Str::random(6).'@pssfp.local']), fn (User $u) => $u->assignRole('admin'));
    $this->campagne = CampagneCandidature::factory()->create([
        'nom' => ['fr' => 'Année académique 2026-2027'], 'promotion_numero' => 14,
    ]);
    $retenu = fn (string $nom): Candidature => Candidature::factory()->forCampagne($this->campagne)->submitted()->create([
        'nom' => $nom, 'prenom' => 'Essai', 'statut' => Candidature::STATUT_ACCEPTE,
        'phone_e164' => '+2376990'.random_int(10000, 99999), 'email' => Str::random(8).'@example.org',
    ]);
    $this->paye = $retenu('Abega');
    $this->impaye = $retenu('Biya');
    app(AttributionMatricules::class)->attribuer($this->campagne, '26', $this->admin);
    $this->paye->refresh();
    $this->impaye->refresh();
    solderInscription($this->paye);

    $this->sms = [];
    $this->mock(SmsServiceInterface::class, function ($mock): void {
        $mock->shouldReceive('send')->andReturnUsing(function (string $tel): bool {
            $this->sms[] = $tel;

            return true;
        });
    });
    Mail::fake();
});

it('refuse d\'envoyer le code d\'accès tant que les droits ne sont pas pointés', function (): void {
    expect(fn () => app(IdentifiantsMoodle::class)->envoyer($this->impaye, IdentifiantsMoodle::CANAL_LES_DEUX, $this->admin))
        ->toThrow(InvalidArgumentException::class);

    expect($this->sms)->toBeEmpty();
    Mail::assertNothingSent();
});

it('envoie le code d\'accès une fois les droits pointés', function (): void {
    app(IdentifiantsMoodle::class)->envoyer($this->paye, IdentifiantsMoodle::CANAL_LES_DEUX, $this->admin);

    expect($this->sms)->toBe([$this->paye->phone_e164]);
    Mail::assertSent(NotificationCandidatMail::class);
});

it('masque l\'envoi sur la ligne d\'un dossier impayé, mais laisse la consultation à l\'admin', function (): void {
    $this->actingAs($this->admin);

    $this->livewire(ListCandidatures::class)
        ->assertTableActionHidden('envoyerIdentifiantsMoodle', $this->impaye)
        ->assertTableActionVisible('envoyerIdentifiantsMoodle', $this->paye)
        ->assertTableActionVisible('identifiantsMoodle', $this->impaye)
        ->assertTableActionHidden('lettreAccesFoad', $this->impaye)
        ->assertTableActionVisible('lettreAccesFoad', $this->paye);
});

it('n\'envoie un groupe qu\'aux dossiers pointés et signale les autres', function (): void {
    $this->actingAs($this->admin);

    $this->livewire(ListCandidatures::class)
        ->callTableBulkAction('envoyerIdentifiantsMoodleBulk', [$this->paye, $this->impaye], data: ['canal' => IdentifiantsMoodle::CANAL_SMS])
        ->assertHasNoTableBulkActionErrors()
        ->assertNotified();

    expect($this->sms)->toBe([$this->paye->phone_e164]);
});

it('exclut les impayés de la lettre d\'accès, sans toucher à la lettre d\'admission', function (): void {
    $service = new ReflectionMethod(DocumentsAuditeurs::class, 'auditeurs');
    $instance = app(DocumentsAuditeurs::class);

    $acces = $service->invoke($instance, $this->campagne, ParametresLettres::DOCUMENT_ACCES, null);
    $admission = $service->invoke($instance, $this->campagne, ParametresLettres::DOCUMENT_ADMISSION, null);

    expect($acces->pluck('id')->all())->toBe([$this->paye->id])
        ->and($admission->pluck('id')->sort()->values()->all())->toBe(collect([$this->paye->id, $this->impaye->id])->sort()->values()->all());
});

it('explique pourquoi aucune lettre d\'accès ne sort quand personne n\'a payé', function (): void {
    annulerVersements($this->paye);
    $this->campagne->forceFill(['parametres_lettres' => ['reference_acces' => 'REF-ESSAI', 'date_lettres' => '2026-11-05']])->save();

    expect(fn () => app(DocumentsAuditeurs::class)->generer($this->campagne->fresh(), ParametresLettres::DOCUMENT_ACCES, $this->admin))
        ->toThrow(DocumentImpossible::class, 'droits d\'inscription');
});
