<?php

declare(strict_types=1);

use App\Filament\Resources\CandidatureResource\Pages\ListCandidatures;
use App\Filament\Resources\JournalActiviteResource;
use App\Filament\Resources\JournalActiviteResource\Pages\ListJournalActivite;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\User;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

uses()->group('filament', 'journal');

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    $this->seed([PaysSeeder::class, RegionsCamerounSeeder::class, DepartementsCamerounSeeder::class, RolePermissionSeeder::class]);
    $this->campagne = CampagneCandidature::factory()->create(['promotion_numero' => 14]);
    $this->agent = fn (string $role): User => tap(
        User::factory()->create(['email' => $role.'-'.Str::random(6).'@pssfp.local', 'password' => bcrypt('Bon-Mot-de-passe-1')]),
        fn (User $u) => $u->assignRole($role),
    );
});

it('affiche les seuls retenus avec l\'onglet « Retenus »', function (): void {
    $retenu = Candidature::factory()->forCampagne($this->campagne)->submitted()->create(['statut' => Candidature::STATUT_ACCEPTE]);
    $retire = Candidature::factory()->forCampagne($this->campagne)->submitted()->create(['statut' => Candidature::STATUT_ACCEPTE, 'withdrawn_at' => now()]);
    $enExamen = Candidature::factory()->forCampagne($this->campagne)->submitted()->create();
    $this->actingAs(($this->agent)('admission_committee'));

    $this->livewire(ListCandidatures::class)
        ->set('activeTab', 'retenus')
        ->assertCanSeeTableRecords([$retenu])
        ->assertCanNotSeeTableRecords([$retire, $enExamen])
        ->set('activeTab', 'en_examen')
        ->assertCanSeeTableRecords([$enExamen])
        ->assertCanNotSeeTableRecords([$retenu]);
});

it('réserve le journal à l\'administration', function (string $role, bool $autorise): void {
    $this->actingAs(($this->agent)($role));

    expect(JournalActiviteResource::canAccess())->toBe($autorise);
    $this->get(JournalActiviteResource::getUrl('index'))->assertStatus($autorise ? 200 : 403);
})->with([
    ['super_admin', true],
    ['admin', true],
    ['admission_committee', false],
    ['receptionniste', false],
]);

it('journalise connexions, déconnexions et échecs du personnel, sans mot de passe', function (): void {
    $agent = ($this->agent)('admission_committee');

    Auth::guard('web')->attempt(['email' => $agent->email, 'password' => 'Mauvais-Secret-42']);
    Auth::guard('web')->attempt(['email' => $agent->email, 'password' => 'Bon-Mot-de-passe-1']);
    Auth::guard('web')->logout();

    $evenements = Activity::query()->where('log_name', 'connexions')->orderBy('id')->pluck('event')->all();
    expect($evenements)->toBe(['connexion_echouee', 'connexion', 'deconnexion'])
        ->and(Activity::query()->where('log_name', 'connexions')->get()->toJson())->not->toContain('Mauvais-Secret-42')
        ->and(Activity::query()->where('event', 'connexion')->sole()->causer_id)->toBe($agent->id);
});

it('journalise une modification faite dans le back-office : quels champs, jamais leurs valeurs', function (): void {
    $admin = ($this->agent)('admin');
    $dossier = Candidature::factory()->forCampagne($this->campagne)->submitted()->create();
    $this->actingAs($admin);

    $dossier->update(['adresse' => 'Rue tres confidentielle 12']);

    $ligne = Activity::query()->where('log_name', 'admin')->where('event', 'updated')->sole();
    expect($ligne->causer_id)->toBe($admin->id)
        ->and($ligne->subject_id)->toBe($dossier->id)
        ->and($ligne->properties->get('champs'))->toBe(['adresse'])
        ->and($ligne->properties->get('element'))->toBe($dossier->numero_dossier)
        ->and($ligne->properties->toJson())->not->toContain('confidentielle');
});

it('ne journalise pas dans ce volet ce que fait un candidat ou un traitement sans session back-office', function (): void {
    $dossier = Candidature::factory()->forCampagne($this->campagne)->submitted()->create();

    $dossier->update(['adresse' => 'Nouvelle adresse']);

    expect(Activity::query()->where('log_name', 'admin')->count())->toBe(0);
});

it('liste les entrées du journal pour un admin, avec leur auteur', function (): void {
    $admin = ($this->agent)('admin');
    $this->actingAs($admin);
    $this->campagne->update(['max_voeux' => 2]);
    $ligne = Activity::query()->where('log_name', 'admin')->latest('id')->first();

    $this->livewire(ListJournalActivite::class)
        ->assertCanSeeTableRecords([$ligne])
        ->assertSee($admin->name)
        ->filterTable('log_name', ['admin'])
        ->assertCanSeeTableRecords([$ligne]);
});

it('filtre le journal par auteur et par période', function (): void {
    $admin = ($this->agent)('admin');
    $autre = ($this->agent)('admin');
    $this->actingAs($autre);
    $this->campagne->update(['max_voeux' => 3]);
    $deAutre = Activity::query()->where('log_name', 'admin')->latest('id')->first();
    $this->actingAs($admin);
    $this->campagne->update(['max_voeux' => 2]);
    $deAdmin = Activity::query()->where('log_name', 'admin')->latest('id')->first();
    $ancienne = activity('candidatures')->causedByAnonymous()->event('ancien')->log('Ancienne action');
    $ancienne->forceFill(['created_at' => now()->subDays(10)])->save();

    $this->livewire(ListJournalActivite::class)
        ->filterTable('auteur', ['causer_id' => $admin->id])
        ->assertCanSeeTableRecords([$deAdmin])
        ->assertCanNotSeeTableRecords([$deAutre, $ancienne])
        ->resetTableFilters()
        ->filterTable('periode', ['du' => now()->subDay()->toDateString(), 'au' => now()->toDateString()])
        ->assertCanSeeTableRecords([$deAdmin, $deAutre])
        ->assertCanNotSeeTableRecords([$ancienne]);
});
