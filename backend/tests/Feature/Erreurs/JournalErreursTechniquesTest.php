<?php

declare(strict_types=1);

use App\Filament\Resources\ErreurTechniqueResource;
use App\Filament\Resources\ErreurTechniqueResource\Pages\ListErreursTechniques;
use App\Http\Middleware\IpVisiteurDuPortail;
use App\Models\ErreurTechnique;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses()->group('socle', 'erreurs');

/*
 * Journal des erreurs techniques, visible dans le back-office. Le 13/11/2026,
 * un auditeur n'a pas pu se connecter depuis son téléphone (10 échecs en
 * 500) sans qu'aucune trace n'apparaisse dans l'administration : l'erreur
 * mourait dans le portail, avant l'API.
 */

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    config()->set('pssfp.filament.require_2fa', false);
    config()->set('pssfp.portail_secret', 'secret-de-test-du-portail');
    Route::middleware('api')->get('v1/essai-panne', fn () => throw new RuntimeException('Panne de test'));
});

/** @return array<string, string> */
function entetesPortail(string $ip = '41.202.1.10', string $secret = 'secret-de-test-du-portail'): array
{
    return [IpVisiteurDuPortail::ENTETE_IP => $ip, IpVisiteurDuPortail::ENTETE_SECRET => $secret];
}

/** @return array<string, string> */
function signalementPortail(array $surcharge = []): array
{
    return array_merge([
        'page' => 'https://apply.pssfp.org./login',
        'message' => 'An unexpected response was received from the server.',
        'digest' => '268796391',
        'navigateur' => 'Mozilla/5.0 (Linux; Android 10; K) Chrome/151.0.0.0 Mobile Safari/537.36',
    ], $surcharge);
}

it('enregistre une panne de l\'API avec la requête, sans sa chaîne de paramètres', function (): void {
    $this->getJson('/v1/essai-panne?code=CODE-SECRET')->assertStatus(500);

    $erreur = ErreurTechnique::query()->sole();
    expect($erreur->source)->toBe(ErreurTechnique::SOURCE_API)
        ->and($erreur->type)->toBe('RuntimeException')
        ->and($erreur->message)->toBe('Panne de test')
        ->and($erreur->methode)->toBe('GET')
        ->and($erreur->page)->toBe('/v1/essai-panne')
        ->and($erreur->emplacement)->toContain('.php:')
        ->and($erreur->requete)->not->toBeEmpty()
        ->and($erreur->toJson())->not->toContain('CODE-SECRET');
});

it('enregistre le modèle de la route, jamais un jeton ou un identifiant qu\'elle porte', function (): void {
    Route::middleware('api')->post('v1/essai-jeton/{jeton}', fn () => throw new RuntimeException('Panne'));

    $this->postJson('/v1/essai-jeton/Xk7pQ2mZ9vRt4bNw')->assertStatus(500);

    expect(ErreurTechnique::query()->sole()->page)->toBe('/v1/essai-jeton/{jeton}');
});

it('masque le jeton d\'un lien de lettre d\'accès signalé par le portail', function (): void {
    $this->withHeaders(entetesPortail())
        ->postJson('/v1/erreurs/portail', signalementPortail(['page' => 'https://apply.pssfp.org/en/l/Xk7pQ2mZ9vRt4bNw']))
        ->assertNoContent();

    expect(ErreurTechnique::query()->sole()->page)->toBe('https://apply.pssfp.org/en/l/{jeton}');
});

it('n\'enregistre ni une page introuvable ni une saisie refusée', function (): void {
    $this->getJson('/v1/page-qui-n-existe-pas')->assertNotFound();
    $this->postJson('/v1/auth/candidat/login', [])->assertUnprocessable();

    expect(ErreurTechnique::query()->count())->toBe(0);
});

it('classe une panne hors requête HTTP (file, tâche planifiée) en tâche de fond', function (): void {
    report(new RuntimeException('Envoi SMS impossible'));

    expect(ErreurTechnique::query()->sole()->source)->toBe(ErreurTechnique::SOURCE_TACHES);
});

it('retire les valeurs d\'une erreur SQL : ni requête ni donnée personnelle', function (): void {
    report(new QueryException(
        'pgsql',
        'insert into users (email) values (?)',
        ['candidat.secret@exemple.cm'],
        new PDOException("SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint \"users_email_unique\"\nDETAIL:  Key (email)=(candidat.secret@exemple.cm) already exists."),
    ));

    $message = ErreurTechnique::query()->sole()->message;
    expect($message)->toContain('users_email_unique')
        ->and($message)->not->toContain('candidat.secret@exemple.cm')
        ->and($message)->not->toContain('insert into');
});

it('ne fait jamais échouer le signalement quand la table est inaccessible', function (): void {
    Schema::drop('erreurs_techniques');

    expect(fn () => report(new RuntimeException('Panne pendant une panne')))->not->toThrow(Throwable::class);
});

it('reçoit l\'erreur signalée par le portail, avec l\'adresse réelle du visiteur', function (): void {
    $this->withHeaders(entetesPortail('41.202.1.10'))
        ->postJson('/v1/erreurs/portail', signalementPortail())
        ->assertNoContent();

    $erreur = ErreurTechnique::query()->sole();
    expect($erreur->source)->toBe(ErreurTechnique::SOURCE_APPLY)
        ->and($erreur->page)->toBe('https://apply.pssfp.org./login')
        ->and($erreur->digest)->toBe('268796391')
        ->and($erreur->navigateur)->toContain('Android 10')
        ->and($erreur->ip)->toBe('41.202.1.10')
        ->and($erreur->user_id)->toBeNull();
});

it('rattache l\'erreur signalée au compte connecté', function (): void {
    $candidat = User::factory()->candidat()->create();
    $jeton = $candidat->createToken('candidat', ['application:read'])->plainTextToken;

    $this->withHeaders(entetesPortail())->withToken($jeton)
        ->postJson('/v1/erreurs/portail', signalementPortail())
        ->assertNoContent();

    expect(ErreurTechnique::query()->sole()->user_id)->toBe($candidat->id);
});

it('refuse un signalement qui ne vient pas du portail', function (array $entetes): void {
    $this->withHeaders($entetes)->postJson('/v1/erreurs/portail', signalementPortail())->assertForbidden();

    expect(ErreurTechnique::query()->count())->toBe(0);
})->with([
    'sans secret' => [[]],
    'mauvais secret' => [['X-PSSFP-Portail' => 'devine']],
]);

it('limite le débit des signalements par visiteur', function (): void {
    foreach (range(1, 20) as $_) {
        $this->withHeaders(entetesPortail('41.202.1.10'))->postJson('/v1/erreurs/portail', signalementPortail())->assertNoContent();
    }

    $this->withHeaders(entetesPortail('41.202.1.10'))->postJson('/v1/erreurs/portail', signalementPortail())->assertStatus(429);
    // Un autre visiteur, derrière le même serveur de portail, n'est pas freiné.
    $this->withHeaders(entetesPortail('102.244.5.7'))->postJson('/v1/erreurs/portail', signalementPortail())->assertNoContent();
});

it('borne la taille de chaque champ signalé', function (): void {
    $this->withHeaders(entetesPortail())
        ->postJson('/v1/erreurs/portail', signalementPortail(['message' => Str::repeat('x', 5000)]))
        ->assertUnprocessable();

    expect(ErreurTechnique::query()->count())->toBe(0);
});

it('purge les erreurs de plus de 90 jours', function (): void {
    $ancienne = ErreurTechnique::query()->create(['source' => ErreurTechnique::SOURCE_API, 'message' => 'ancienne', 'created_at' => now()->subDays(91)]);
    $recente = ErreurTechnique::query()->create(['source' => ErreurTechnique::SOURCE_API, 'message' => 'récente', 'created_at' => now()->subDays(89)]);

    $this->artisan('model:prune', ['--model' => [ErreurTechnique::class]])->assertSuccessful();

    expect(ErreurTechnique::query()->pluck('id')->all())->toBe([$recente->id])
        ->and(ErreurTechnique::query()->find($ancienne->id))->toBeNull();
});

it('réserve la page des erreurs à l\'administration', function (string $role, bool $autorise): void {
    $this->actingAs(User::factory()->create()->assignRole($role));

    expect(ErreurTechniqueResource::canAccess())->toBe($autorise);
    $this->get(ErreurTechniqueResource::getUrl('index'))->assertStatus($autorise ? 200 : 403);
})->with([
    ['super_admin', true],
    ['admin', true],
    ['admission_committee', false],
    ['receptionniste', false],
]);

it('compte dans le menu les erreurs des dernières 24 heures', function (): void {
    ErreurTechnique::query()->create(['source' => ErreurTechnique::SOURCE_API, 'message' => 'hier', 'created_at' => now()->subHours(25)]);
    ErreurTechnique::query()->create(['source' => ErreurTechnique::SOURCE_APPLY, 'message' => 'ce matin', 'created_at' => now()->subHours(2)]);
    ErreurTechnique::query()->create(['source' => ErreurTechnique::SOURCE_API, 'message' => 'maintenant', 'created_at' => now()]);

    expect(ErreurTechniqueResource::getNavigationBadge())->toBe('2');
});

it('affiche les erreurs dans le tableau, compte et appareil compris', function (): void {
    $candidat = User::factory()->candidat()->create(['name' => 'Candidat Essai']);
    $erreur = ErreurTechnique::query()->create([
        'source' => ErreurTechnique::SOURCE_APPLY,
        'message' => 'An unexpected response was received from the server.',
        'page' => 'https://apply.pssfp.org./login',
        'navigateur' => 'Mozilla/5.0 (Linux; Android 10; K) Chrome/151.0.0.0 Mobile Safari/537.36',
        'user_id' => $candidat->id,
    ]);
    $this->actingAs(User::factory()->create()->assignRole('admin'));

    $this->livewire(ListErreursTechniques::class)
        ->assertCanSeeTableRecords([$erreur])
        ->assertSee('Candidat Essai')
        ->assertSee('Android · Chrome 151')
        ->assertSee('Portail apply');
});

it('résume l\'appareil à partir de l\'en-tête du navigateur', function (?string $navigateur, string $attendu): void {
    expect(ErreurTechnique::appareil($navigateur))->toBe($attendu);
})->with([
    'android chrome' => ['Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Mobile Safari/537.36', 'Android · Chrome 151'],
    'iphone chrome' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 26_7_2 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/157.0.8103.37 Mobile/15E148 Safari/604.1', 'iPhone · Chrome 157'],
    'iphone safari' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1', 'iPhone · Safari 18'],
    'windows firefox' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:159.0) Gecko/20100101 Firefox/159.0', 'Windows · Firefox 159'],
    'windows edge' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36 Edg/156.0.0.0', 'Windows · Edge 156'],
    'absent' => [null, '—'],
]);
