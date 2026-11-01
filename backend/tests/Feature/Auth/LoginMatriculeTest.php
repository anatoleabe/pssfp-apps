<?php

declare(strict_types=1);

use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\User;
use App\Services\EspaceCandidat\NouveauCodeCandidat;
use App\Services\ExportsRetenus\AttributionMatricules;
use App\Services\Sms\SmsServiceInterface;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;

uses()->group('auth', 'candidat');

/*
 * Les auditeurs ouvrent leur espace avec leurs identifiants FOAD (matricule et
 * mot de passe Moodle), en plus du téléphone et du code à 6 chiffres.
 */

beforeEach(function (): void {
    $this->seed([PaysSeeder::class, RegionsCamerounSeeder::class, DepartementsCamerounSeeder::class, RolePermissionSeeder::class]);
    $campagne = CampagneCandidature::factory()->create(['status' => 'closed', 'closes_at' => now()->subMonth()]);
    $this->compte = User::factory()->candidat('847291')->create(['phone_e164' => '+237699000051']);
    $this->candidature = Candidature::factory()->forCampagne($campagne)->submitted()->create([
        'user_id' => $this->compte->id, 'statut' => Candidature::STATUT_ACCEPTE, 'phone_e164' => '+237699000051',
    ]);
    $admin = tap(User::factory()->create(['email' => 'admin-'.Str::random(6).'@pssfp.local']), fn (User $u) => $u->assignRole('admin'));
    app(AttributionMatricules::class)->attribuer($campagne, '26', $admin);
    $this->candidature = $this->candidature->fresh();
});

function connexionMatricule(string $identifiant, string $motDePasse): TestResponse
{
    return test()->postJson('/v1/auth/candidat/login-matricule', ['identifiant' => $identifiant, 'mot_de_passe' => $motDePasse]);
}

it('ouvre l\'espace avec le matricule, en majuscules ou en minuscules, et le mot de passe FOAD', function (string $casse): void {
    $identifiant = $casse === 'min' ? mb_strtolower($this->candidature->matricule) : $this->candidature->matricule;

    $jeton = connexionMatricule($identifiant, $this->candidature->moodle_password)
        ->assertOk()
        ->assertJsonPath('user.id', $this->compte->id)
        ->json('token');

    $this->withToken($jeton)->getJson('/v1/applications/me')
        ->assertOk()
        ->assertJsonPath('data.numero_dossier', $this->candidature->numero_dossier);
})->with(['min', 'maj']);

it('donne la même réponse pour un mauvais mot de passe et un matricule inconnu, et journalise sans le mot de passe', function (): void {
    connexionMatricule($this->candidature->matricule, 'faux-mot-de-passe')
        ->assertUnauthorized()->assertJsonPath('message', 'Matricule ou mot de passe incorrect.');
    connexionMatricule('26EFP999', $this->candidature->moodle_password)
        ->assertUnauthorized()->assertJsonPath('message', 'Matricule ou mot de passe incorrect.');

    $raisons = Activity::query()->where('event', 'login_failed')->pluck('properties');
    expect($raisons->pluck('reason')->all())->toBe(['wrong_password', 'unknown_matricule'])
        ->and(json_encode($raisons))->not->toContain($this->candidature->moodle_password)->not->toContain('faux-mot-de-passe');
});

it('refuse un dossier retiré, non retenu ou rattaché à un compte du personnel', function (string $cas): void {
    match ($cas) {
        'retiré' => $this->candidature->update(['withdrawn_at' => now()]),
        'refusé' => $this->candidature->update(['statut' => Candidature::STATUT_REFUSE]),
        'personnel' => $this->compte->assignRole('editor'),
    };

    connexionMatricule($this->candidature->matricule, $this->candidature->moodle_password)->assertUnauthorized();
})->with(['retiré', 'refusé', 'personnel']);

it('bloque le matricule après dix échecs, même avec le bon mot de passe', function (): void {
    // IP variées : on teste le verrou par matricule, pas le frein par visiteur.
    foreach (range(1, 10) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => "41.202.1.{$i}"]);
        connexionMatricule($this->candidature->matricule, 'faux-'.$i)->assertUnauthorized();
    }

    $this->withServerVariables(['REMOTE_ADDR' => '41.202.1.99']);
    connexionMatricule($this->candidature->matricule, $this->candidature->moodle_password)->assertStatus(423);
});

it('valide le format de l\'identifiant', function (): void {
    connexionMatricule('pas un matricule', 'x')->assertUnprocessable()->assertJsonValidationErrors('identifiant');
});

it('ouvre une session en lecture seule et journalise la connexion', function (): void {
    $jeton = connexionMatricule($this->candidature->matricule, $this->candidature->moodle_password)
        ->assertOk()
        ->assertJsonPath('abilities', ['profile:read', 'application:read'])
        ->json('token');

    $this->withToken($jeton)->putJson('/v1/applications/me', [])->assertForbidden();
    $this->withToken($jeton)->postJson('/v1/applications/me/submit')->assertForbidden();

    $journal = Activity::query()->where('log_name', 'auth.candidat')->where('event', 'login')->sole();
    expect($journal->causer_id)->toBe($this->compte->id)
        ->and($journal->properties['methode'])->toBe('matricule')
        ->and(json_encode($journal->properties))->not->toContain($this->candidature->moodle_password);
});

it('traite un mot de passe illisible comme un échec, sans erreur serveur', function (): void {
    // Valeur chiffrée avec une autre clé : déchiffrement impossible.
    DB::table('candidatures')->where('id', $this->candidature->id)->update(['moodle_password' => 'valeur-corrompue']);

    connexionMatricule($this->candidature->matricule, 'nimporte')->assertUnauthorized();
});

it('lève le verrou du matricule quand l\'administration donne un nouveau code', function (): void {
    foreach (range(1, 10) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => "41.202.2.{$i}"]);
        connexionMatricule($this->candidature->matricule, 'faux-'.$i);
    }
    $admin = tap(User::factory()->create(['email' => 'adm-'.Str::random(6).'@pssfp.local']), fn (User $u) => $u->assignRole('admin'));
    $this->mock(SmsServiceInterface::class)->shouldReceive('send')->andReturnNull();
    app(NouveauCodeCandidat::class)->generer($this->candidature->fresh(), 'aucun', $admin);

    $this->withServerVariables(['REMOTE_ADDR' => '41.202.2.99']);
    connexionMatricule($this->candidature->matricule, $this->candidature->moodle_password)->assertOk();
});
