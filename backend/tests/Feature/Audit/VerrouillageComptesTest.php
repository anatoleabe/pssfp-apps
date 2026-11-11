<?php

declare(strict_types=1);

use App\Filament\Pages\Auth\Connexion;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\AnomalieSysteme;
use App\Models\DemandeAutorisation;
use App\Models\Message;
use App\Models\User;
use App\Services\Audit\VerrouillageComptes;
use App\Services\Messagerie\Demandes;
use App\Services\Messagerie\TypesDemande;
use App\Services\Supervision\Controles\SecConnexions;
use App\Services\Supervision\Supervision;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Filament\Notifications\Auth\ResetPassword;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses()->group('audit', 'filament');

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    Notification::fake();
    $this->seed(RolePermissionSeeder::class);
    $this->compte = fn (string $role): User => tap(
        User::factory()->create(['email' => $role.'-'.Str::random(6).'@pssfp.local', 'name' => 'Agent '.$role.' '.Str::random(3), 'password' => 'mot-de-passe-juste']),
        fn (User $u) => $u->assignRole($role),
    );
    $this->agent = ($this->compte)('editor');
    $this->admin = ($this->compte)('admin');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

function tenter(User $u, string $motDePasse): bool
{
    $ok = Auth::guard('web')->attempt(['email' => $u->email, 'password' => $motDePasse]);
    Auth::guard('web')->logout();

    return $ok;
}

function rafale(User $u): void
{
    foreach (range(1, VerrouillageComptes::SEUIL_DEFAUT) as $i) {
        tenter($u, 'faux');
    }
}

it('compte les échecs du personnel, remet à zéro à la connexion, et ignore les candidats', function (): void {
    foreach (range(1, 3) as $i) {
        tenter($this->agent, 'faux');
    }
    expect($this->agent->refresh()->echecs_connexion)->toBe(3);
    tenter($this->agent, 'mot-de-passe-juste');
    expect($this->agent->refresh()->echecs_connexion)->toBe(0);

    $candidat = ($this->compte)('candidat');
    foreach (range(1, 6) as $i) {
        tenter($candidat, 'faux');
    }
    expect($candidat->refresh()->echecs_connexion)->toBe(0)->and($candidat->verrouille_le)->toBeNull();
});

it('verrouille 15 min, puis 1 h, puis jusqu\'à levée manuelle, et ne compte plus pendant un verrou', function (): void {
    rafale($this->agent);
    $this->agent->refresh();
    expect(VerrouillageComptes::estVerrouille($this->agent))->toBeTrue()
        ->and($this->agent->verrouille_jusqua->diffInMinutes(now(), true))->toEqualWithDelta(15, 0.1)
        ->and($this->agent->canAccessPanel(Filament::getPanel('admin')))->toBeFalse()
        ->and(Message::query()->count())->toBe(0);

    tenter($this->agent, 'faux');
    expect($this->agent->refresh()->echecs_connexion)->toBe(VerrouillageComptes::SEUIL_DEFAUT);

    $this->travel(16)->minutes();
    expect(VerrouillageComptes::estVerrouille($this->agent->refresh()))->toBeFalse();
    rafale($this->agent);
    expect($this->agent->refresh()->verrouille_jusqua->diffInMinutes(now(), true))->toEqualWithDelta(60, 0.1);

    $this->travel(61)->minutes();
    rafale($this->agent);
    $this->agent->refresh();
    expect(VerrouillageComptes::estVerrouille($this->agent))->toBeTrue()->and($this->agent->verrouille_jusqua)->toBeNull()
        ->and(Message::query()->where('categorie', Message::NOTIFICATION)->sole()->destinataires()->pluck('user_id')->all())->toContain($this->admin->id);
});

it('refuse un compte verrouillé à la connexion sans vérifier le mot de passe', function (): void {
    Livewire::test(Connexion::class)->fillForm(['email' => $this->admin->email, 'password' => 'mot-de-passe-juste'])
        ->call('authenticate')->assertHasNoFormErrors();
    expect(Auth::guard('web')->id())->toBe($this->admin->id);
    Auth::guard('web')->logout();

    app(VerrouillageComptes::class)->verrouiller($this->agent, 'essai');
    Hash::spy();

    Livewire::test(Connexion::class)->fillForm(['email' => $this->agent->email, 'password' => 'mot-de-passe-juste'])
        ->call('authenticate')->assertHasFormErrors(['email']);
    Hash::shouldNotHaveReceived('check');
    expect(Auth::guard('web')->check())->toBeFalse()
        ->and(Activity::query()->where('event', 'connexion_refusee_verrouille')->exists())->toBeTrue()
        ->and(Activity::query()->where('event', 'deconnexion')->where('subject_id', $this->agent->id)->exists())->toBeFalse();
    $this->actingAs($this->agent)->get('/admin')->assertForbidden();

});

it('déverrouille depuis l\'écran des comptes et impose un nouveau mot de passe', function (): void {
    app(VerrouillageComptes::class)->verrouiller($this->agent, 'essai');
    $service = app(VerrouillageComptes::class);

    expect(fn () => $service->deverrouiller($this->agent->refresh(), ($this->compte)('editor'), 'Motif suffisant'))->toThrow(HttpException::class)
        ->and(fn () => $service->deverrouiller($this->agent, $this->admin, 'court'))->toThrow(InvalidArgumentException::class, 'motif');

    $this->actingAs($this->admin);
    Livewire::test(ListUsers::class)->callTableAction('deverrouiller', $this->agent, ['motif' => 'Erreur de mot de passe confirmée par téléphone']);
    $this->agent->refresh();
    expect($this->agent->verrouille_le)->toBeNull()->and($this->agent->echecs_connexion)->toBe(0)
        ->and(Hash::check('mot-de-passe-juste', $this->agent->password))->toBeFalse()
        ->and(Activity::query()->where('event', 'compte_deverrouille')->sole()->properties['lien_reinitialisation'])->toBeTrue();
    Notification::assertSentTo($this->agent, ResetPassword::class);
});

it('déverrouille en secours depuis la console', function (): void {
    app(VerrouillageComptes::class)->verrouiller($this->agent, 'essai');

    $this->artisan('comptes:deverrouiller', ['email' => $this->agent->email, '--motif' => 'court'])->assertFailed();
    $this->artisan('comptes:deverrouiller', ['email' => $this->agent->email, '--motif' => 'Tous les administrateurs étaient bloqués'])->assertSuccessful();
    expect(VerrouillageComptes::estVerrouille($this->agent->refresh()))->toBeFalse();
    Notification::assertSentTo($this->agent, ResetPassword::class);
});

it('déverrouille par une demande d\'autorisation soumise par un collègue', function (): void {
    app(VerrouillageComptes::class)->verrouiller($this->agent, 'essai');
    $collegue = ($this->compte)('editor');
    $demandes = app(Demandes::class);

    expect(app(TypesDemande::class)->objets(TypesDemande::DEVERROUILLER_COMPTE))->toHaveKey($this->agent->id);
    $d = $demandes->soumettre($collegue, TypesDemande::DEVERROUILLER_COMPTE, $this->agent->id, 'Il s\'est trompé de mot de passe ce matin');
    tenter($this->agent, 'faux');
    expect($demandes->decider($this->admin, $d, true))->toBe('Compte déverrouillé')
        ->and($d->refresh()->statut)->toBe(DemandeAutorisation::EXECUTEE)
        ->and($this->agent->refresh()->verrouille_le)->toBeNull();
});

it('signale les comptes verrouillés et les rafales d\'échecs dans SEC_CONNEXIONS, avec un plafond', function (): void {
    app(VerrouillageComptes::class)->verrouiller($this->agent, 'essai');
    foreach (range(1, SecConnexions::ECHECS_SUSPECTS) as $i) {
        Auth::guard('web')->attempt(['email' => 'inconnu@exemple.org', 'password' => 'x']);
    }

    app(Supervision::class)->executer(['SEC_CONNEXIONS']);
    $cles = AnomalieSysteme::query()->where('controle_code', 'SEC_CONNEXIONS')->pluck('cle')->all();
    expect($cles)->toContain('verrouille:'.$this->agent->id)->toContain('identifiant:inconnu@exemple.org');
});

it('ferme les routes HTTP de Fortify et garde la redirection de connexion', function (): void {
    $this->post('/register', ['name' => 'X', 'email' => 'x@exemple.org', 'password' => 'secret123', 'password_confirmation' => 'secret123'])->assertNotFound();
    $this->post('/login', ['email' => $this->agent->email, 'password' => 'mot-de-passe-juste'])->assertStatus(302)->assertRedirect('/admin/login');
    expect(Auth::guard('web')->check())->toBeFalse();
    $this->get('/')->assertOk();
});
