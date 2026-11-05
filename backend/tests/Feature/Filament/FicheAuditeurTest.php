<?php

declare(strict_types=1);

use App\Filament\Resources\AuditeurResource\Pages\ListAuditeurs;
use App\Filament\Resources\AuditeurResource\Pages\ViewAuditeur;
use App\Models\Auditeur;
use App\Models\BaremeScolarite;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\User;
use App\Services\Ecampus\ComptesEcampus;
use App\Services\ExportsRetenus\AttributionMatricules;
use App\Services\Sms\SmsServiceInterface;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

uses()->group('filament', 'scolarite');

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    $this->seed([PaysSeeder::class, RegionsCamerounSeeder::class, DepartementsCamerounSeeder::class, RolePermissionSeeder::class]);
    $this->compte = fn (string $role): User => tap(User::factory()->create(['email' => $role.'-'.Str::random(6).'@pssfp.local']), fn (User $u) => $u->assignRole($role));
    $this->campagne = CampagneCandidature::factory()->create(['nom' => ['fr' => 'Année académique 2026-2027'], 'promotion_numero' => 14]);
    BaremeScolarite::factory()->create(['annee_academique' => '2026-2027', 'niveau' => 'M1']);
    $this->dossier = Candidature::factory()->forCampagne($this->campagne)->submitted()->create([
        'statut' => Candidature::STATUT_ACCEPTE, 'phone_e164' => '+237699003001', 'email' => 'awa@example.org', 'ecampus_statut' => ComptesEcampus::CREE,
    ]);
    app(AttributionMatricules::class)->attribuer($this->campagne, '26', ($this->compte)('admin'));
    $this->fiche = $this->dossier->fresh()->auditeur;
    $this->sms = [];
    $this->mock(SmsServiceInterface::class, function ($mock): void {
        $mock->shouldReceive('send')->andReturnUsing(function (string $tel): void {
            $this->sms[] = $tel;
        });
    });
    Mail::fake();
});

it('consulte les identifiants FOAD du dossier depuis la fiche, en le journalisant sur la candidature', function (): void {
    $this->actingAs(($this->compte)('admin'));

    $this->livewire(ViewAuditeur::class, ['record' => $this->fiche->getRouteKey()])
        ->mountAction('identifiantsMoodle')
        ->assertSee((string) $this->dossier->fresh()->moodle_password);

    expect(Activity::query()->where('event', 'moodle_identifiants_consultes')->where('subject_id', $this->dossier->id)->exists())->toBeTrue();
});

it('cache les accès et les documents à la Scolarité, qui n\'en a pas le droit', function (): void {
    $this->actingAs(($this->compte)('scolarite_responsable'));

    $this->livewire(ViewAuditeur::class, ['record' => $this->fiche->getRouteKey()])
        ->assertActionHidden('identifiantsMoodle')
        ->assertActionHidden('envoyerIdentifiantsMoodle')
        ->assertActionHidden('lettreAdmission')
        ->assertActionVisible('changerStatut');
});

it('change le statut depuis la fiche, et filtre la liste par statut', function (): void {
    $this->actingAs(($this->compte)('scolarite_responsable'));

    $this->livewire(ViewAuditeur::class, ['record' => $this->fiche->getRouteKey()])
        ->callAction('changerStatut', data: ['statut' => Auditeur::STATUT_SUSPENDU, 'depuis' => '2026-11-02', 'motif' => 'Mission longue'])
        ->assertHasNoActionErrors()
        ->assertSee('Mission longue');

    expect($this->fiche->fresh()->statut)->toBe(Auditeur::STATUT_SUSPENDU);
    $this->livewire(ListAuditeurs::class)
        ->filterTable('statut', Auditeur::STATUT_SUSPENDU)->assertCanSeeTableRecords([$this->fiche])
        ->filterTable('statut', Auditeur::STATUT_ACTIF)->assertCanNotSeeTableRecords([$this->fiche]);
});

it('réserve le statut au responsable', function (): void {
    $this->actingAs(($this->compte)('scolarite_agent'));

    $this->livewire(ViewAuditeur::class, ['record' => $this->fiche->getRouteKey()])->assertActionHidden('changerStatut');
});

it('montre l\'historique de la fiche sans les propriétés du journal', function (): void {
    activity('scolarite')->performedOn($this->fiche)->withProperties(['secret' => 'NE-PAS-AFFICHER'])->log('Événement de test');
    $this->actingAs(($this->compte)('scolarite_agent'));

    $this->livewire(ViewAuditeur::class, ['record' => $this->fiche->getRouteKey()])
        ->assertSee('Événement de test')
        ->assertDontSee('NE-PAS-AFFICHER');
});

it('n\'affiche dans l\'historique que les entrées de cet auditeur', function (): void {
    $autre = Auditeur::factory()->create();
    activity('scolarite')->performedOn($autre)->log('Entrée d\'un autre auditeur');

    expect($this->fiche->historique()->pluck('description'))->not->toContain('Entrée d\'un autre auditeur');
});
