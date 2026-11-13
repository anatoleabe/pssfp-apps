<?php

declare(strict_types=1);

use App\Http\Middleware\IpVisiteurDuPortail;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Spatie\Activitylog\Models\Activity;

uses()->group('auth', 'candidat');

/*
 * Le portail appelle l'API depuis son serveur : sans l'adresse réelle du
 * visiteur, trois échecs de connexion de n'importe qui bloquaient la
 * connexion de tous les candidats pendant dix minutes.
 */

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    config()->set('pssfp.portail_secret', 'secret-de-test-du-portail');
    User::factory()->candidat('847291')->create(['phone_e164' => '+237699000041']);
});

/** @return array<string, string> */
function depuisLePortail(string $ip, string $secret = 'secret-de-test-du-portail'): array
{
    return [IpVisiteurDuPortail::ENTETE_IP => $ip, IpVisiteurDuPortail::ENTETE_SECRET => $secret];
}

function echecConnexion(array $entetes): void
{
    test()->withHeaders($entetes)
        ->postJson('/v1/auth/candidat/login', ['phone_e164' => '+237699000041', 'pin' => '000001'])
        ->assertUnauthorized();
}

it('limite les échecs par visiteur et non plus pour tout le portail', function (): void {
    foreach (range(1, 3) as $_) {
        echecConnexion(depuisLePortail('41.202.1.10'));
    }

    // Le visiteur fautif est freiné…
    $this->withHeaders(depuisLePortail('41.202.1.10'))
        ->postJson('/v1/auth/candidat/login', ['phone_e164' => '+237699000041', 'pin' => '847291'])
        ->assertStatus(429);
    // … un autre candidat, derrière le même serveur de portail, se connecte.
    $this->withHeaders(depuisLePortail('102.244.5.7'))
        ->postJson('/v1/auth/candidat/login', ['phone_e164' => '+237699000041', 'pin' => '847291'])
        ->assertOk();
});

it('journalise l\'adresse réelle du visiteur', function (): void {
    echecConnexion(depuisLePortail('2c0f:f5c0:1::25'));

    expect(Activity::query()->where('event', 'login_failed')->sole()->properties['ip'])->toBe('2c0f:f5c0:1::25');
});

it('ignore l\'adresse fournie sans le bon secret, ou invalide', function (array $entetes): void {
    echecConnexion($entetes);

    expect(Activity::query()->where('event', 'login_failed')->sole()->properties['ip'])->toBe('127.0.0.1');
})->with([
    'sans secret' => [[IpVisiteurDuPortail::ENTETE_IP => '41.202.1.10']],
    'mauvais secret' => [depuisLePortail('41.202.1.10', 'devine')],
    'adresse invalide' => [depuisLePortail('pas-une-ip')],
]);

it('ignore l\'en-tête tant qu\'aucun secret n\'est configuré', function (): void {
    config()->set('pssfp.portail_secret', '');

    echecConnexion(depuisLePortail('41.202.1.10', ''));

    expect(Activity::query()->where('event', 'login_failed')->sole()->properties['ip'])->toBe('127.0.0.1');
});

it('applique les limites de débit des routes au visiteur, pas au serveur du portail', function (): void {
    // Laravel range la limite de débit avant les middlewares sans priorité :
    // sans ip.portail déclaré prioritaire, les 20 inscriptions par minute (et
    // les 30 connexions par matricule) valaient pour tous les visiteurs à la fois.
    foreach (range(1, 20) as $_) {
        $this->withHeaders(depuisLePortail('41.202.1.10'))->postJson('/v1/auth/candidat/register', [])->assertUnprocessable();
    }
    $this->withHeaders(depuisLePortail('41.202.1.10'))->postJson('/v1/auth/candidat/register', [])->assertStatus(429);

    $this->withHeaders(depuisLePortail('102.244.5.7'))->postJson('/v1/auth/candidat/register', [])->assertUnprocessable();
});
