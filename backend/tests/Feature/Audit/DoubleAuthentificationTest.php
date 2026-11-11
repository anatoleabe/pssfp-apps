<?php

declare(strict_types=1);

use App\Filament\GlobalSearch\RechercheGlobale;
use App\Filament\Pages\Auth\Connexion;
use App\Filament\Pages\MaSecurite;
use App\Http\Middleware\ExigerDoubleAuthentification;
use App\Mail\AlerteSecuriteMail;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Livewire\Livewire;
use Livewire\Mechanisms\PersistentMiddleware\PersistentMiddleware;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Activitylog\Models\Activity;

uses()->group('audit', 'filament');

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    Mail::fake();
    $this->seed(RolePermissionSeeder::class);
    $this->agent = tap(User::factory()->create(['email' => 'agent-'.Str::random(6).'@pssfp.local', 'password' => 'mot-de-passe-juste']),
        fn (User $u) => $u->assignRole('editor'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

function codeCourant(User $u): string
{
    return (new Google2FA)->getCurrentOtp(decrypt($u->refresh()->two_factor_secret));
}

function activer2fa(User $u): void
{
    app(EnableTwoFactorAuthentication::class)($u, true);
    app(ConfirmTwoFactorAuthentication::class)($u->refresh(), codeCourant($u));
}

function identifiants(User $u, string $motDePasse = 'mot-de-passe-juste')
{
    return Livewire::test(Connexion::class)->fillForm(['email' => $u->email, 'password' => $motDePasse])->call('authenticate');
}

it('active, confirme, renouvelle les codes et désactive depuis « Ma sécurité »', function (): void {
    $this->actingAs($this->agent);

    Livewire::test(MaSecurite::class)->callAction('activer', ['mot_de_passe' => 'faux']);
    expect($this->agent->refresh()->two_factor_secret)->toBeNull();

    $page = Livewire::test(MaSecurite::class)->callAction('activer', ['mot_de_passe' => 'mot-de-passe-juste']);
    expect($this->agent->refresh()->two_factor_secret)->not->toBeNull()->and($this->agent->two_factor_confirmed_at)->toBeNull();
    $page->callAction('confirmer', ['code' => '000000']);
    expect($this->agent->refresh()->two_factor_confirmed_at)->toBeNull();
    $page->callAction('confirmer', ['code' => codeCourant($this->agent)]);
    expect($this->agent->refresh()->hasEnabledTwoFactorAuthentication())->toBeTrue()->and($this->agent->recoveryCodes())->toHaveCount(8);
    $page->assertSee($this->agent->recoveryCodes()[0]);
    Mail::assertSent(AlerteSecuriteMail::class, fn (AlerteSecuriteMail $m): bool => $m->hasTo($this->agent->email) && str_contains($m->evenement, 'activée'));
    $page->call('$refresh')->assertDontSee($this->agent->recoveryCodes()[0]);
    $anciens = $this->agent->recoveryCodes();

    $page->callAction('nouveauxCodes', ['mot_de_passe' => 'mot-de-passe-juste']);
    expect($this->agent->refresh()->recoveryCodes())->not->toBe($anciens);

    config()->set('pssfp.filament.require_2fa', true);
    Livewire::test(MaSecurite::class)->assertActionHidden('desactiver');
    config()->set('pssfp.filament.require_2fa', false);
    Livewire::test(MaSecurite::class)->callAction('desactiver', ['mot_de_passe' => 'mot-de-passe-juste']);
    expect($this->agent->refresh()->two_factor_secret)->toBeNull()
        ->and(Activity::query()->where('log_name', 'comptes')->pluck('event')->all())
        ->toContain('double_authentification_activee', 'codes_secours_renouveles', 'double_authentification_desactivee');
});

it('ouvre la session en une étape sans double authentification', function (): void {
    identifiants($this->agent)->assertHasNoFormErrors();
    expect(Auth::guard('web')->id())->toBe($this->agent->id);
});

it('exige le code avant d\'ouvrir la session, compte un code faux comme un échec', function (): void {
    activer2fa($this->agent);
    $this->travel(31)->seconds();

    $page = identifiants($this->agent)->assertHasNoFormErrors()->assertSet('etape', 'code');
    expect(Auth::guard('web')->check())->toBeFalse();

    $page->fillForm(['code' => '123456'])->call('authenticate')->assertHasFormErrors(['code']);
    expect(Auth::guard('web')->check())->toBeFalse()->and($this->agent->refresh()->echecs_connexion)->toBe(1);

    // Fortify refuse le rejeu d'un code (celui de l'activation) ; l'horloge du générateur ne suit pas travel().
    Cache::flush();
    $page->fillForm(['code' => codeCourant($this->agent)])->call('authenticate')->assertHasNoFormErrors();
    expect(Auth::guard('web')->id())->toBe($this->agent->id)->and($this->agent->refresh()->echecs_connexion)->toBe(0);
});

it('accepte un code de secours une seule fois', function (): void {
    activer2fa($this->agent);
    $secours = $this->agent->refresh()->recoveryCodes()[0];

    identifiants($this->agent)->fillForm(['code' => $secours])->call('authenticate')->assertHasNoFormErrors();
    expect(Auth::guard('web')->id())->toBe($this->agent->id)->and($this->agent->refresh()->recoveryCodes())->not->toContain($secours);
    Auth::guard('web')->logout();

    identifiants($this->agent)->fillForm(['code' => $secours])->call('authenticate')->assertHasFormErrors(['code']);
    expect(Auth::guard('web')->check())->toBeFalse();
});

it('fait recommencer quand l\'étape du code a expiré', function (): void {
    activer2fa($this->agent);
    $page = identifiants($this->agent);
    $this->travel(Connexion::DUREE_ETAPE_CODE_SECONDES + 1)->seconds();

    $page->fillForm(['code' => codeCourant($this->agent)])->call('authenticate')->assertHasFormErrors(['email'])->assertSet('etape', 'identifiants');
    expect(Auth::guard('web')->check())->toBeFalse();
});

it('conduit sur « Ma sécurité » quand la double authentification est obligatoire et absente', function (): void {
    config()->set('pssfp.filament.require_2fa', true);
    $this->actingAs($this->agent);

    $this->get('/admin')->assertRedirect(MaSecurite::getUrl());
    $this->get(MaSecurite::getUrl())->assertOk()->assertSee('obligatoire');

    activer2fa($this->agent);
    $this->get('/admin')->assertOk();
});

it('rend la recherche globale muette et ne propose pas « se souvenir de moi » tant que la double authentification manque', function (): void {
    config()->set('pssfp.filament.require_2fa', true);
    Livewire::test(Connexion::class)->assertFormFieldDoesNotExist('remember');
    $this->actingAs($this->agent);

    expect(app(RechercheGlobale::class)->getResults('agent')?->getCategories())->toBeEmpty();
});

it('alerte le titulaire et change son jeton de rappel à l\'usage d\'un code de secours', function (): void {
    activer2fa($this->agent);
    $jeton = $this->agent->refresh()->remember_token;

    identifiants($this->agent)->fillForm(['code' => $this->agent->recoveryCodes()[0]])->call('authenticate')->assertHasNoFormErrors();
    expect($this->agent->refresh()->remember_token)->not->toBe($jeton);
    Mail::assertSent(AlerteSecuriteMail::class, fn (AlerteSecuriteMail $m): bool => str_contains($m->evenement, 'code de secours'));
});

it('rejoue l\'obligation sur les échanges Livewire (middleware persistant)', function (): void {
    expect(app(PersistentMiddleware::class)->getPersistentMiddleware())
        ->toContain(ExigerDoubleAuthentification::class);
});
