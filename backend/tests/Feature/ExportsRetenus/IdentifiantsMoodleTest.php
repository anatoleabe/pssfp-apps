<?php

declare(strict_types=1);

use App\Filament\Resources\CandidatureResource\Pages\ListCandidatures;
use App\Filament\Resources\CandidatureResource\Pages\ViewCandidature;
use App\Mail\NotificationCandidatMail;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\CandidatureRelance;
use App\Models\User;
use App\Services\ExportsRetenus\AttributionMatricules;
use App\Services\ExportsRetenus\IdentifiantsMoodle;
use App\Services\Sms\SmsServiceInterface;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

uses()->group('filament', 'exports-retenus');

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    config()->set('pssfp.foad_url', 'https://foad.pssfp.net');
    $this->seed([PaysSeeder::class, RegionsCamerounSeeder::class, DepartementsCamerounSeeder::class, RolePermissionSeeder::class]);

    $campagne = CampagneCandidature::factory()->create([
        'nom' => ['fr' => 'Année académique 2026-2027'],
        'promotion_numero' => 14,
    ]);
    $this->auditeur = Candidature::factory()->forCampagne($campagne)->submitted()->create([
        'nom' => 'Mengue', 'prenom' => 'Alice', 'statut' => Candidature::STATUT_ACCEPTE,
        'phone_e164' => '+237699000020', 'email' => 'alice@example.org',
    ]);
    $this->agent = fn (string $role): User => tap(
        User::factory()->create(['email' => $role.'-'.Str::random(6).'@pssfp.local']),
        fn (User $u) => $u->assignRole($role),
    );
    app(AttributionMatricules::class)->attribuer($campagne, '26', ($this->agent)('admin'));
    $this->auditeur = $this->auditeur->fresh();
    $this->motDePasse = $this->auditeur->moodle_password;

    $this->sms = [];
    $this->mock(SmsServiceInterface::class, function ($mock): void {
        $mock->shouldReceive('send')->andReturnUsing(function (string $tel, string $texte): void {
            $this->sms[] = [$tel, $texte];
        });
    });
});

it('montre identifiant et mot de passe à l\'administration, et journalise la consultation sans le mot de passe', function (): void {
    $this->actingAs(($this->agent)('admin'));

    $this->livewire(ViewCandidature::class, ['record' => $this->auditeur->getRouteKey()])
        ->mountAction('identifiantsMoodle')
        ->assertSee('26efp001')
        ->assertSee($this->motDePasse);

    $journal = Activity::query()->where('event', 'moodle_identifiants_consultes')->sole();
    expect($journal->subject_id)->toBe($this->auditeur->id)
        ->and(json_encode($journal->properties))->not->toContain($this->motDePasse);
});

it('cache les identifiants au comité d\'admission et à l\'accueil', function (string $role): void {
    $this->actingAs(($this->agent)($role));

    $this->livewire(ListCandidatures::class)
        ->assertTableActionHidden('identifiantsMoodle', $this->auditeur)
        ->assertTableActionHidden('envoyerIdentifiantsMoodle', $this->auditeur);
})->with(['admission_committee', 'receptionniste']);

it('ne change jamais le mot de passe : la fiche et l\'export montrent toujours le même', function (): void {
    app(AttributionMatricules::class)->attribuer($this->auditeur->campagne, '26', ($this->agent)('admin'));

    expect($this->auditeur->fresh()->moodle_password)->toBe($this->motDePasse);
});

it('envoie les identifiants par SMS et e-mail, sans les écrire dans les journaux', function (): void {
    Mail::fake();
    $this->actingAs(($this->agent)('admin'));

    $this->livewire(ListCandidatures::class)
        ->callTableAction('envoyerIdentifiantsMoodle', $this->auditeur, data: ['canal' => IdentifiantsMoodle::CANAL_LES_DEUX])
        ->assertHasNoTableActionErrors();

    expect($this->sms)->toHaveCount(1)
        ->and($this->sms[0][0])->toBe('+237699000020')
        ->and($this->sms[0][1])->toContain('26efp001')->toContain($this->motDePasse)
        ->and(mb_strlen($this->sms[0][1]))->toBeLessThanOrEqual(160)
        ->and(preg_match('/[^\x20-\x7E]/', $this->sms[0][1]))->toBe(0);
    Mail::assertSent(NotificationCandidatMail::class, fn (NotificationCandidatMail $m): bool => $m->hasTo('alice@example.org')
        && str_contains($m->corpsMessage, $this->motDePasse));

    $journal = Activity::query()->where('event', 'moodle_identifiants_envoyes')->sole();
    expect(json_encode($journal->properties))->not->toContain($this->motDePasse)
        ->and(CandidatureRelance::query()->count())->toBe(0);
});

it('n\'envoie que par le canal choisi', function (): void {
    Mail::fake();
    $this->actingAs(($this->agent)('admin'));

    $this->livewire(ListCandidatures::class)
        ->callTableAction('envoyerIdentifiantsMoodle', $this->auditeur, data: ['canal' => IdentifiantsMoodle::CANAL_EMAIL]);

    expect($this->sms)->toBe([]);
    Mail::assertSent(NotificationCandidatMail::class, 1);
});

it('envoie en groupe aux seuls retenus dotés d\'un matricule', function (): void {
    Mail::fake();
    $sansMatricule = Candidature::factory()->forCampagne($this->auditeur->campagne)->submitted()->create();
    $this->actingAs(($this->agent)('admin'));

    $this->livewire(ListCandidatures::class)
        ->callTableBulkAction('envoyerIdentifiantsMoodleBulk', [$this->auditeur, $sansMatricule], data: ['canal' => IdentifiantsMoodle::CANAL_SMS])
        ->assertHasNoTableBulkActionErrors();

    expect($this->sms)->toHaveCount(1);
    Mail::assertNothingSent();
});
