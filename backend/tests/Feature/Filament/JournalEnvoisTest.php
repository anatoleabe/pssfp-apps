<?php

declare(strict_types=1);

use App\Filament\Resources\CandidatureRelanceResource;
use App\Filament\Resources\CandidatureRelanceResource\Pages\ListCandidatureRelances;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\CandidatureRelance;
use App\Models\User;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;

uses()->group('filament', 'journal-envois');

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    $this->seed([
        PaysSeeder::class, RegionsCamerounSeeder::class,
        DepartementsCamerounSeeder::class, RolePermissionSeeder::class,
    ]);

    $this->campagne = CampagneCandidature::factory()->create([
        'status' => 'open',
        'opens_at' => now()->subMonth(),
        'closes_at' => now()->addMonth(),
    ]);

    $this->candidature = Candidature::factory()->forCampagne($this->campagne)->create([
        'nom' => 'Ndongo',
        'prenom' => 'Paul',
        'specialite' => array_values((array) config('specialites'))[0],
    ]);
});

function relance(array $overrides = []): CandidatureRelance
{
    return CandidatureRelance::create(array_merge([
        'candidature_id' => test()->candidature->id,
        'cause' => 'ready',
        'canal' => CandidatureRelance::CANAL_SMS,
        'statut' => CandidatureRelance::STATUT_ENVOYE,
        'expediteur' => 'PSSFP',
        'code_fournisseur' => '1016',
        'sent_at' => now(),
    ], $overrides));
}

it('est visible pour un admin', function (): void {
    $u = User::factory()->create();
    $u->assignRole('admin');
    $this->actingAs($u);

    expect(CandidatureRelanceResource::canViewAny())->toBeTrue();
});

it('est visible pour un super_admin', function (): void {
    $u = User::factory()->create();
    $u->assignRole('super_admin');
    $this->actingAs($u);

    expect(CandidatureRelanceResource::canViewAny())->toBeTrue();
});

it('reste fermé au comité d’admission et à l’éditeur', function (string $role): void {
    $u = User::factory()->create();
    $u->assignRole($role);
    $this->actingAs($u);

    expect(CandidatureRelanceResource::canViewAny())->toBeFalse();
})->with(['admission_committee', 'editor']);

it('n’autorise ni création, ni édition, ni suppression', function (): void {
    $u = User::factory()->create();
    $u->assignRole('super_admin');
    $this->actingAs($u);

    $ligne = relance();

    expect(CandidatureRelanceResource::canCreate())->toBeFalse()
        ->and(CandidatureRelanceResource::canEdit($ligne))->toBeFalse()
        ->and(CandidatureRelanceResource::canDelete($ligne))->toBeFalse()
        ->and(CandidatureRelanceResource::canDeleteAny())->toBeFalse();
});

it('affiche les envois avec leur expéditeur', function (): void {
    $u = User::factory()->create();
    $u->assignRole('admin');
    $this->actingAs($u);

    $envoye = relance();
    $echec = relance([
        'statut' => CandidatureRelance::STATUT_ECHEC,
        'erreur' => 'Echo SMS a refusé l\'envoi (code 1007 : Solde insuffisant).',
        'code_fournisseur' => null,
    ]);

    $this->livewire(ListCandidatureRelances::class)
        ->assertCanSeeTableRecords([$envoye, $echec])
        ->assertTableColumnStateSet('expediteur', 'PSSFP', $envoye);
});

it('filtre sur les échecs seuls', function (): void {
    $u = User::factory()->create();
    $u->assignRole('admin');
    $this->actingAs($u);

    $envoye = relance();
    $echec = relance(['statut' => CandidatureRelance::STATUT_ECHEC]);

    $this->livewire(ListCandidatureRelances::class)
        ->filterTable('statut', CandidatureRelance::STATUT_ECHEC)
        ->assertCanSeeTableRecords([$echec])
        ->assertCanNotSeeTableRecords([$envoye]);
});

it('filtre sur le canal', function (): void {
    $u = User::factory()->create();
    $u->assignRole('admin');
    $this->actingAs($u);

    $sms = relance();
    $email = relance(['canal' => CandidatureRelance::CANAL_EMAIL, 'expediteur' => 'contact@pssfp.net']);

    $this->livewire(ListCandidatureRelances::class)
        ->filterTable('canal', CandidatureRelance::CANAL_EMAIL)
        ->assertCanSeeTableRecords([$email])
        ->assertCanNotSeeTableRecords([$sms]);
});

it('filtre sur l’expéditeur, options lues en base', function (): void {
    $u = User::factory()->create();
    $u->assignRole('admin');
    $this->actingAs($u);

    $pssfp = relance();
    $autre = relance(['expediteur' => 'ANCIEN_ID']);

    $this->livewire(ListCandidatureRelances::class)
        ->filterTable('expediteur', 'ANCIEN_ID')
        ->assertCanSeeTableRecords([$autre])
        ->assertCanNotSeeTableRecords([$pssfp]);
});

it('filtre sur une période', function (): void {
    $u = User::factory()->create();
    $u->assignRole('admin');
    $this->actingAs($u);

    $ancien = relance(['sent_at' => now()->subDays(10)]);
    $recent = relance(['sent_at' => now()]);

    $this->livewire(ListCandidatureRelances::class)
        ->filterTable('periode', ['du' => now()->subDay()->toDateString()])
        ->assertCanSeeTableRecords([$recent])
        ->assertCanNotSeeTableRecords([$ancien]);
});

it('compte les échecs dans le badge de navigation', function (): void {
    $u = User::factory()->create();
    $u->assignRole('admin');
    $this->actingAs($u);

    relance();
    relance(['statut' => CandidatureRelance::STATUT_ECHEC]);
    relance(['statut' => CandidatureRelance::STATUT_ECHEC]);

    expect(CandidatureRelanceResource::getNavigationBadge())->toBe('2');
});

it('n’affiche aucun badge quand tout est parti', function (): void {
    $u = User::factory()->create();
    $u->assignRole('admin');
    $this->actingAs($u);

    relance();

    expect(CandidatureRelanceResource::getNavigationBadge())->toBeNull();
});
