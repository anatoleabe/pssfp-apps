<?php

declare(strict_types=1);

use App\Filament\Resources\CampagneCandidatureResource\Pages\ListCampagneCandidatures;
use App\Filament\Resources\CandidatureResource\ComptesEcampusActions;
use App\Filament\Resources\CandidatureResource\Pages\ListCandidatures;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\User;
use App\Services\Ecampus\ComptesEcampus;
use App\Services\ExportsRetenus\AttributionMatricules;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses()->group('filament', 'exports-retenus', 'ecampus');

const JETON_ESSAI = 'jeton-de-test-ecampus';
const COHORTE_ESSAI = 'PROMO14_TC_2026_2027';

/**
 * Faux Moodle : comptes déjà présents, avertissements de cohorte à renvoyer.
 * Note chaque appel dans $this->appels ; une création ajoute le compte, comme
 * le ferait le vrai serveur.
 */
function fauxMoodle(object $test, array $comptes = [], array $avertissements = []): void
{
    $test->appels = [];
    Http::fake(['ecampus.test/*' => function (Request $requete) use ($test, &$comptes, &$avertissements) {
        $donnees = $requete->data();
        $test->appels[] = $donnees;

        return match ($donnees['wsfunction'] ?? '') {
            'core_user_get_users_by_field' => Http::response(array_values(array_filter(
                $comptes,
                fn (array $c): bool => in_array($c[$donnees['field']] ?? null, $donnees['values'], true),
            ))),
            'core_user_create_users' => (function () use (&$comptes, $donnees) {
                $u = $donnees['users'][0];
                $comptes[] = ['id' => 900, 'username' => $u['username'], 'email' => $u['email'], 'idnumber' => $u['idnumber']];

                return Http::response([['id' => 900, 'username' => $u['username']]]);
            })(),
            'core_cohort_add_cohort_members' => Http::response(['warnings' => array_splice($avertissements, 0)]),
            default => Http::response(['exception' => 'moodle_exception', 'errorcode' => 'inconnu', 'message' => 'x']),
        };
    }]);
}

function fonctionsAppelees(object $test): array
{
    return array_column($test->appels, 'wsfunction');
}

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    config()->set('pssfp.foad_url', 'https://ecampus.test');
    config()->set('pssfp.ecampus.jeton', JETON_ESSAI);
    config()->set('pssfp.ecampus.budget_secondes', 20);
    $this->seed([PaysSeeder::class, RegionsCamerounSeeder::class, DepartementsCamerounSeeder::class, RolePermissionSeeder::class]);

    $this->agent = fn (string $role): User => tap(
        User::factory()->create(['email' => $role.'-'.Str::random(6).'@pssfp.local']),
        fn (User $u) => $u->assignRole($role),
    );
    $this->admin = ($this->agent)('admin');

    $this->campagne = CampagneCandidature::factory()->create([
        'nom' => ['fr' => 'Année académique 2026-2027'],
        'promotion_numero' => 14,
    ]);
    $this->retenu = fn (array $champs = []): Candidature => Candidature::factory()->forCampagne($this->campagne)->submitted()->create($champs + [
        'nom' => 'Mengue', 'prenom' => 'Alice', 'statut' => Candidature::STATUT_ACCEPTE,
        'email' => 'alice-'.Str::random(6).'@example.org', 'ville_residence' => 'Yaoundé',
    ]);
    $this->numerote = function (array $champs = []): Candidature {
        $c = ($this->retenu)($champs);
        app(AttributionMatricules::class)->attribuer($this->campagne, '26', $this->admin, [$c->id]);

        return $c->fresh();
    };
});

describe('création du compte', function (): void {
    it('crée le compte avec l\'identifiant et le mot de passe existants, puis l\'ajoute à la cohorte', function (): void {
        $c = ($this->numerote)(['nom' => 'Mengue', 'prenom' => 'Alice', 'phone_e164' => '+237699000020']);
        $motDePasse = $c->moodle_password;
        fauxMoodle($this);

        $statut = app(ComptesEcampus::class)->traiter($c, $this->admin);

        expect($statut)->toBe(ComptesEcampus::CREE)
            ->and(fonctionsAppelees($this))->toBe([
                'core_user_get_users_by_field',
                'core_user_get_users_by_field',
                'core_user_create_users',
                'core_cohort_add_cohort_members',
            ]);

        $compte = $this->appels[2]['users'][0];
        expect($compte['username'])->toBe(mb_strtolower((string) $c->matricule))
            ->and($compte['password'])->toBe($motDePasse)
            ->and($compte['lastname'])->toBe('MENGUE')
            ->and($compte['firstname'])->toBe('Alice')
            ->and($compte['email'])->toBe($c->email)
            ->and($compte['phone1'])->toBe('237699000020')
            ->and($compte['city'])->toBe('Yaoundé')
            ->and($compte['idnumber'])->toBe($c->matricule)
            ->and($compte)->not->toHaveKey('createpassword');

        $membre = $this->appels[3]['members'][0];
        expect($membre['cohorttype'])->toBe(['type' => 'idnumber', 'value' => COHORTE_ESSAI])
            ->and($membre['usertype'])->toBe(['type' => 'username', 'value' => mb_strtolower((string) $c->matricule)]);

        $c->refresh();
        expect($c->ecampus_statut)->toBe(ComptesEcampus::CREE)
            ->and($c->ecampus_detail)->toBeNull()
            ->and($c->ecampus_synchronise_at)->not->toBeNull()
            ->and($c->moodle_password)->toBe($motDePasse);
    });

    it('n\'envoie le jeton et le mot de passe que dans le corps d\'une requête HTTPS', function (): void {
        $c = ($this->numerote)();
        fauxMoodle($this);

        app(ComptesEcampus::class)->traiter($c, $this->admin);

        Http::assertSent(fn (Request $r): bool => str_starts_with($r->url(), 'https://ecampus.test/webservice/rest/server.php')
            && ! str_contains($r->url(), JETON_ESSAI)
            && ! str_contains($r->url(), (string) $c->moodle_password)
            && $r->method() === 'POST'
            && $r['wstoken'] === JETON_ESSAI
            && $r['moodlewsrestformat'] === 'json');
    });

    it('n\'écrit le mot de passe ni dans les journaux, ni dans le dossier, ni dans la file', function (): void {
        Queue::fake();
        $messages = [];
        Log::listen(function ($evenement) use (&$messages): void {
            $messages[] = $evenement->message.json_encode($evenement->context);
        });
        $c = ($this->numerote)();
        $motDePasse = (string) $c->moodle_password;
        fauxMoodle($this, [], [['warningcode' => '1', 'message' => 'cohort idnumber=X not exists']]);

        app(ComptesEcampus::class)->traiter($c, $this->admin);

        Queue::assertNothingPushed();
        expect(Activity::query()->where('event', 'ecampus_compte')->count())->toBe(1);
        Activity::query()->get()->each(function (Activity $a) use ($motDePasse): void {
            expect((string) json_encode($a->properties))->not->toContain($motDePasse);
        });
        expect(implode(' ', $messages))->not->toContain($motDePasse)
            ->and(implode(' ', $messages))->not->toContain(JETON_ESSAI)
            ->and((string) $c->fresh()->ecampus_detail)->not->toContain($motDePasse);
    });

    it('ne journalise ni le mot de passe ni le jeton quand eCampus est en panne', function (): void {
        $messages = [];
        Log::listen(function ($evenement) use (&$messages): void {
            $messages[] = $evenement->message.json_encode($evenement->context);
        });
        $c = ($this->numerote)();
        Http::fake(['ecampus.test/*' => fn () => throw new ConnectionException('cURL error 28 '.JETON_ESSAI)]);

        app(ComptesEcampus::class)->traiter($c, $this->admin);

        expect($messages)->not->toBeEmpty()
            ->and(implode(' ', $messages))->not->toContain(JETON_ESSAI)
            ->and(implode(' ', $messages))->not->toContain((string) $c->moodle_password);
    });
});

describe('compte déjà présent', function (): void {
    it('ne touche pas à un compte existant, surtout pas à son mot de passe', function (): void {
        $c = ($this->numerote)(['nom' => 'Mengue', 'prenom' => 'Alice']);
        fauxMoodle($this, [[
            'id' => 12, 'username' => mb_strtolower((string) $c->matricule), 'email' => $c->email,
            'firstname' => 'Alice', 'lastname' => 'MENGUE',
        ]]);

        $statut = app(ComptesEcampus::class)->traiter($c, $this->admin);

        expect($statut)->toBe(ComptesEcampus::EXISTANT)
            ->and(fonctionsAppelees($this))->toBe(['core_user_get_users_by_field'])
            ->and($c->fresh()->ecampus_detail)->toBeNull();
    });

    it('signale un écart de nom ou d\'e-mail sans rien corriger', function (): void {
        $c = ($this->numerote)(['nom' => 'Mengue', 'prenom' => 'Alice']);
        fauxMoodle($this, [[
            'id' => 12, 'username' => mb_strtolower((string) $c->matricule), 'email' => 'autre@example.org',
            'firstname' => 'Alice', 'lastname' => 'NGONO',
        ]]);

        app(ComptesEcampus::class)->traiter($c, $this->admin);

        expect($c->fresh()->ecampus_statut)->toBe(ComptesEcampus::EXISTANT)
            ->and($c->fresh()->ecampus_detail)->toContain('e-mail')->toContain('nom')
            ->and(fonctionsAppelees($this))->toBe(['core_user_get_users_by_field']);
    });

    it('termine un compte que le portail a créé si la réponse s\'est perdue, sans le recréer', function (): void {
        $c = ($this->numerote)();
        fauxMoodle($this, [[
            'id' => 12, 'username' => mb_strtolower((string) $c->matricule), 'email' => $c->email, 'idnumber' => $c->matricule,
        ]]);

        $statut = app(ComptesEcampus::class)->traiter($c, $this->admin);

        expect($statut)->toBe(ComptesEcampus::CREE)
            ->and(fonctionsAppelees($this))->toBe(['core_user_get_users_by_field', 'core_cohort_add_cohort_members']);
    });

    it('refuse de créer un compte avec un e-mail déjà pris par un autre compte', function (): void {
        $c = ($this->numerote)();
        fauxMoodle($this, [['id' => 12, 'username' => '25efp001', 'email' => $c->email]]);

        $statut = app(ComptesEcampus::class)->traiter($c, $this->admin);

        expect($statut)->toBe(ComptesEcampus::ECHEC)
            ->and($c->fresh()->ecampus_detail)->toContain('25efp001')
            ->and(fonctionsAppelees($this))->not->toContain('core_user_create_users');
    });

    it('ne rappelle pas eCampus pour un dossier déjà traité', function (): void {
        $c = ($this->numerote)();
        $c->forceFill(['ecampus_statut' => ComptesEcampus::CREE])->save();
        fauxMoodle($this);

        expect(app(ComptesEcampus::class)->traiter($c, $this->admin))->toBe(ComptesEcampus::CREE);
        Http::assertNothingSent();
    });
});

describe('cohorte', function (): void {
    it('accepte un auditeur déjà membre de la cohorte', function (): void {
        $c = ($this->numerote)();
        fauxMoodle($this, [], [['warningcode' => '3', 'message' => 'record already exists: cohort(idnumber=X) user(username=y)']]);

        expect(app(ComptesEcampus::class)->traiter($c, $this->admin))->toBe(ComptesEcampus::CREE);
    });

    it('garde le compte si la cohorte échoue, et ne termine que la cohorte en réessayant', function (): void {
        $c = ($this->numerote)();
        fauxMoodle($this, [], [['warningcode' => '1', 'message' => 'cohort idnumber=X not exists']]);
        $service = app(ComptesEcampus::class);

        expect($service->traiter($c, $this->admin))->toBe(ComptesEcampus::COHORTE_A_FINIR)
            ->and($c->fresh()->ecampus_detail)->toContain(COHORTE_ESSAI);

        $nouvelEssai = count($this->appels);
        expect($service->traiter($c->fresh(), $this->admin))->toBe(ComptesEcampus::CREE)
            ->and(array_slice(fonctionsAppelees($this), $nouvelEssai))
            ->toBe(['core_user_get_users_by_field', 'core_cohort_add_cohort_members']);
    });
});

describe('panne et refus', function (): void {
    it('note l\'échec sans toucher au matricule ni au mot de passe', function (): void {
        $c = ($this->numerote)();
        $matricule = $c->matricule;
        $motDePasse = $c->moodle_password;
        Http::fake(['ecampus.test/*' => fn () => throw new ConnectionException('délai dépassé')]);

        $statut = app(ComptesEcampus::class)->traiter($c, $this->admin);

        $c->refresh();
        expect($statut)->toBe(ComptesEcampus::ECHEC)
            ->and($c->ecampus_detail)->toBe(ComptesEcampus::DETAIL_INJOIGNABLE)
            ->and($c->matricule)->toBe($matricule)
            ->and($c->moodle_password)->toBe($motDePasse);
    });

    it('traite un refus de Moodle comme un échec lisible', function (): void {
        $c = ($this->numerote)();
        Http::fake(['ecampus.test/*' => Http::response([
            'exception' => 'webservice_access_exception', 'errorcode' => 'accessexception', 'message' => 'Accès refusé',
        ])]);

        expect(app(ComptesEcampus::class)->traiter($c, $this->admin))->toBe(ComptesEcampus::ECHEC)
            ->and($c->fresh()->ecampus_detail)->toContain('accessexception');
    });

    it('ne suit pas une redirection, qui ferait repartir le mot de passe ailleurs', function (): void {
        $c = ($this->numerote)();
        Http::fake(['ecampus.test/*' => Http::response('', 302, ['Location' => 'https://ailleurs.test/'])]);

        expect(app(ComptesEcampus::class)->traiter($c, $this->admin))->toBe(ComptesEcampus::ECHEC);
        Http::assertSentCount(1);
    });

    it('refuse d\'envoyer quoi que ce soit hors HTTPS', function (): void {
        config()->set('pssfp.foad_url', 'http://ecampus.test');
        $c = ($this->numerote)();
        Http::fake();

        expect(app(ComptesEcampus::class)->traiter($c, $this->admin))->toBe(ComptesEcampus::ECHEC);
        Http::assertNothingSent();
    });

    it('ne note jamais « Créé » sur une réponse illisible', function (string $corps): void {
        $c = ($this->numerote)();
        $d = ($this->numerote)();
        Http::fake(['ecampus.test/*' => Http::response($corps, 200)]);

        expect(app(ComptesEcampus::class)->traiterPlusieurs(collect([$c, $d]), $this->admin))
            ->toBe(['crees' => 0, 'existants' => 0, 'echecs' => 1, 'restants' => 1])
            ->and($c->fresh()->ecampus_statut)->toBe(ComptesEcampus::ECHEC);
        Http::assertSentCount(1);
    })->with(['page HTML' => '<html>proxy</html>', 'null' => 'null', 'vide' => '']);

    it('ne note pas « Créé » si Moodle ne confirme pas la création', function (): void {
        $c = ($this->numerote)();
        Http::fake(['ecampus.test/*' => fn (Request $r) => Http::response($r['wsfunction'] === 'core_user_create_users'
            ? [['id' => 900, 'username' => 'un-autre-compte']]
            : [])]);

        expect(app(ComptesEcampus::class)->traiter($c, $this->admin))->toBe(ComptesEcampus::ECHEC);
        Http::assertNotSent(fn (Request $r): bool => $r['wsfunction'] === 'core_cohort_add_cohort_members');
    });

    it('arrête le lot sur un refus général de Moodle', function (string $exception, string $code): void {
        $a = ($this->numerote)();
        $b = ($this->numerote)();
        Http::fake(['ecampus.test/*' => Http::response(['exception' => $exception, 'errorcode' => $code, 'message' => 'non'])]);

        expect(app(ComptesEcampus::class)->traiterPlusieurs(collect([$a, $b]), $this->admin))
            ->toBe(['crees' => 0, 'existants' => 0, 'echecs' => 1, 'restants' => 1]);
        Http::assertSentCount(1);
    })->with([
        'jeton' => ['moodle_exception', 'invalidtoken'],
        'adresse IP' => ['webservice_access_exception', 'accessexception'],
        'maintenance' => ['moodle_exception', 'sitemaintenance'],
        'droit retiré' => ['required_capability_exception', 'nopermissions'],
    ]);

    it('continue le lot sur un refus propre à un dossier', function (): void {
        $a = ($this->numerote)();
        $b = ($this->numerote)();
        Http::fake(['ecampus.test/*' => Http::response(['exception' => 'invalid_parameter_exception', 'errorcode' => 'invalidparameter', 'message' => 'non'])]);

        expect(app(ComptesEcampus::class)->traiterPlusieurs(collect([$a, $b]), $this->admin))
            ->toBe(['crees' => 0, 'existants' => 0, 'echecs' => 2, 'restants' => 0]);
    });

    it('garde « Cohorte à finir » si la reprise tombe sur une panne', function (): void {
        $c = ($this->numerote)();
        $c->forceFill(['ecampus_statut' => ComptesEcampus::COHORTE_A_FINIR])->save();
        Http::fake(['ecampus.test/*' => fn () => throw new ConnectionException('panne')]);

        expect(app(ComptesEcampus::class)->traiter($c, $this->admin))->toBe(ComptesEcampus::COHORTE_A_FINIR);
    });

    it('termine la cohorte d\'un compte du portail même si son idnumber a été retouché', function (string $idnumber): void {
        $c = ($this->numerote)();
        $c->forceFill(['ecampus_statut' => ComptesEcampus::COHORTE_A_FINIR])->save();
        fauxMoodle($this, [['id' => 12, 'username' => mb_strtolower((string) $c->matricule), 'email' => $c->email, 'idnumber' => $idnumber]]);

        expect(app(ComptesEcampus::class)->traiter($c, $this->admin))->toBe(ComptesEcampus::CREE)
            ->and(fonctionsAppelees($this))->toContain('core_cohort_add_cohort_members');
    })->with(['vide' => '', 'autre valeur' => 'X']);

    it('reconnaît la marque du portail quelle que soit la casse', function (): void {
        $c = ($this->numerote)();
        fauxMoodle($this, [['id' => 12, 'username' => mb_strtolower((string) $c->matricule), 'email' => $c->email, 'idnumber' => mb_strtolower((string) $c->matricule)]]);

        expect(app(ComptesEcampus::class)->traiter($c, $this->admin))->toBe(ComptesEcampus::CREE);
    });

    it('rend un bilan juste si un incident interne coupe le lot', function (): void {
        $a = ($this->numerote)();
        $b = ($this->numerote)();
        fauxMoodle($this);
        $verrou = Mockery::mock(Lock::class);
        $verrou->shouldReceive('get')->andThrow(new RuntimeException('Redis coupé'));
        Cache::shouldReceive('lock')->andReturn($verrou);
        Log::spy();

        expect(app(ComptesEcampus::class)->traiterPlusieurs(collect([$a, $b]), $this->admin))
            ->toBe(['crees' => 0, 'existants' => 0, 'echecs' => 0, 'restants' => 2]);
        Log::shouldHaveReceived('error')->once();
    });

    it('n\'appelle pas eCampus quand le jeton n\'est pas configuré', function (): void {
        config()->set('pssfp.ecampus.jeton', '');
        $c = ($this->numerote)();
        Http::fake();

        expect(ComptesEcampus::actif())->toBeFalse()
            ->and(app(ComptesEcampus::class)->traiterPlusieurs(collect([$c]), $this->admin))->toBeNull();
        Http::assertNothingSent();
        expect($c->fresh()->ecampus_statut)->toBeNull();
    });

    it('note un e-mail manquant sans tenter de création', function (): void {
        $c = ($this->numerote)(['email' => null]);
        fauxMoodle($this);

        expect(app(ComptesEcampus::class)->traiter($c, $this->admin))->toBe(ComptesEcampus::ECHEC)
            ->and($c->fresh()->ecampus_detail)->toContain('E-mail')
            ->and(fonctionsAppelees($this))->toBe(['core_user_get_users_by_field']);
    });
});

describe('droits', function (): void {
    it('réserve la création à l\'administration', function (string $role): void {
        $c = ($this->numerote)();
        fauxMoodle($this);

        app(ComptesEcampus::class)->traiter($c, ($this->agent)($role));
    })->with(['admission_committee', 'receptionniste', 'librarian'])->throws(HttpException::class);

    it('refuse un dossier sans matricule ou retiré', function (array $champs): void {
        $c = ($this->numerote)();
        $c->forceFill($champs)->save();
        fauxMoodle($this);

        expect(fn () => app(ComptesEcampus::class)->traiter($c->fresh(), $this->admin))->toThrow(InvalidArgumentException::class);
        Http::assertNothingSent();
    })->with([
        'retiré' => [['withdrawn_at' => now()]],
        'refusé' => [['statut' => Candidature::STATUT_REFUSE]],
    ]);

    it('ne laisse pas l\'édition du dossier modifier ces champs', function (): void {
        $c = ($this->numerote)();

        expect($c->isFillable('ecampus_statut'))->toBeFalse()
            ->and($c->isFillable('ecampus_detail'))->toBeFalse()
            ->and($c->isFillable('ecampus_synchronise_at'))->toBeFalse();
    });
});

describe('plusieurs dossiers', function (): void {
    it('compte les résultats de chaque dossier', function (): void {
        $a = ($this->numerote)();
        $b = ($this->numerote)();
        fauxMoodle($this, [['id' => 12, 'username' => mb_strtolower((string) $b->matricule), 'email' => $b->email]]);

        expect(app(ComptesEcampus::class)->traiterPlusieurs(collect([$a, $b]), $this->admin))
            ->toBe(['crees' => 1, 'existants' => 1, 'echecs' => 0, 'restants' => 0]);
    });

    it('s\'arrête à la première panne au lieu d\'attendre chaque délai', function (): void {
        $a = ($this->numerote)();
        $b = ($this->numerote)();
        $tentatives = 0;
        Http::fake(['ecampus.test/*' => function () use (&$tentatives): never {
            $tentatives++;

            throw new ConnectionException('panne');
        }]);

        expect(app(ComptesEcampus::class)->traiterPlusieurs(collect([$a, $b]), $this->admin))
            ->toBe(['crees' => 0, 'existants' => 0, 'echecs' => 1, 'restants' => 1])
            ->and($tentatives)->toBe(1);
        expect($b->fresh()->ecampus_statut)->toBeNull();
    });

    it('laisse à reprendre un dossier déjà en cours de traitement ailleurs', function (): void {
        $c = ($this->numerote)();
        fauxMoodle($this);
        $verrou = Cache::lock('ecampus-compte:'.$c->id, 60);
        $verrou->get();

        expect(app(ComptesEcampus::class)->traiterPlusieurs(collect([$c]), $this->admin))
            ->toBe(['crees' => 0, 'existants' => 0, 'echecs' => 0, 'restants' => 1]);
        Http::assertNothingSent();

        $verrou->release();
    });

    it('s\'arrête quand le temps alloué à la requête est écoulé', function (): void {
        config()->set('pssfp.ecampus.budget_secondes', 0);
        $a = ($this->numerote)();
        fauxMoodle($this);

        expect(app(ComptesEcampus::class)->traiterPlusieurs(collect([$a]), $this->admin))
            ->toBe(['crees' => 0, 'existants' => 0, 'echecs' => 0, 'restants' => 1]);
        Http::assertNothingSent();
    });
});

describe('attribution du matricule depuis l\'admin', function (): void {
    it('crée le compte à l\'attribution d\'un matricule', function (): void {
        $c = ($this->retenu)();
        fauxMoodle($this);
        $this->actingAs($this->admin);

        $this->livewire(ListCandidatures::class)
            ->callTableAction('attribuerMatricule', $c, data: ['annee' => '26'])
            ->assertHasNoTableActionErrors()
            ->assertNotified();

        $c->refresh();
        expect($c->matricule)->not->toBeNull()
            ->and($c->ecampus_statut)->toBe(ComptesEcampus::CREE);
    });

    it('crée les comptes à l\'attribution de toute une promotion', function (): void {
        $a = ($this->retenu)();
        $b = ($this->retenu)();
        fauxMoodle($this);
        $this->actingAs($this->admin);

        $this->livewire(ListCampagneCandidatures::class)
            ->callTableAction('attribuerMatricules', $this->campagne, data: ['annee' => '26'])
            ->assertHasNoTableActionErrors();

        expect($a->fresh()->ecampus_statut)->toBe(ComptesEcampus::CREE)
            ->and($b->fresh()->ecampus_statut)->toBe(ComptesEcampus::CREE);
    });

    it('ne prend pas les dossiers déjà numérotés quand une nouvelle attribution a lieu', function (): void {
        $ancien = ($this->numerote)();
        $nouveau = ($this->retenu)();
        fauxMoodle($this);
        $this->actingAs($this->admin);

        $this->livewire(ListCampagneCandidatures::class)
            ->callTableAction('attribuerMatricules', $this->campagne, data: ['annee' => '26']);

        expect($ancien->fresh()->ecampus_statut)->toBeNull()
            ->and($nouveau->fresh()->ecampus_statut)->toBe(ComptesEcampus::CREE);
    });

    it('garde le matricule attribué si eCampus est en panne', function (): void {
        $c = ($this->retenu)();
        Http::fake(['ecampus.test/*' => fn () => throw new ConnectionException('panne')]);
        $this->actingAs($this->admin);

        $this->livewire(ListCandidatures::class)
            ->callTableAction('attribuerMatricule', $c, data: ['annee' => '26'])
            ->assertHasNoTableActionErrors();

        $c->refresh();
        expect($c->matricule)->not->toBeNull()
            ->and($c->moodle_password)->not->toBeNull()
            ->and($c->ecampus_statut)->toBe(ComptesEcampus::ECHEC);
    });

    it('attribue comme avant quand le jeton n\'est pas configuré', function (): void {
        config()->set('pssfp.ecampus.jeton', '');
        $c = ($this->retenu)();
        Http::fake();
        $this->actingAs($this->admin);

        $this->livewire(ListCandidatures::class)
            ->callTableAction('attribuerMatricule', $c, data: ['annee' => '26'])
            ->assertHasNoTableActionErrors();

        expect($c->fresh()->matricule)->not->toBeNull();
        Http::assertNothingSent();
    });
});

describe('boutons de l\'admin', function (): void {
    it('réessaie depuis la ligne du dossier', function (): void {
        $c = ($this->numerote)();
        $c->forceFill(['ecampus_statut' => ComptesEcampus::ECHEC, 'ecampus_detail' => ComptesEcampus::DETAIL_INJOIGNABLE])->save();
        fauxMoodle($this);
        $this->actingAs($this->admin);

        $this->livewire(ListCandidatures::class)
            ->assertTableActionVisible('creerCompteEcampus', $c)
            ->callTableAction('creerCompteEcampus', $c)
            ->assertHasNoTableActionErrors();

        $c->refresh();
        expect($c->ecampus_statut)->toBe(ComptesEcampus::CREE)
            ->and($c->ecampus_detail)->toBeNull();
    });

    it('traite une sélection', function (): void {
        $a = ($this->numerote)();
        $b = ($this->numerote)();
        fauxMoodle($this);
        $this->actingAs($this->admin);

        $this->livewire(ListCandidatures::class)
            ->callTableBulkAction('creerComptesEcampus', [$a, $b])
            ->assertHasNoTableBulkActionErrors();

        expect($a->fresh()->ecampus_statut)->toBe(ComptesEcampus::CREE)
            ->and($b->fresh()->ecampus_statut)->toBe(ComptesEcampus::CREE);
    });

    it('masque le bouton une fois le compte traité, sans jeton, ou sans droit', function (): void {
        $c = ($this->numerote)();
        $termine = ($this->numerote)();
        $termine->forceFill(['ecampus_statut' => ComptesEcampus::EXISTANT])->save();

        $this->actingAs($this->admin);
        $this->livewire(ListCandidatures::class)->assertTableActionHidden('creerCompteEcampus', $termine);

        config()->set('pssfp.ecampus.jeton', '');
        $this->livewire(ListCandidatures::class)->assertTableActionHidden('creerCompteEcampus', $c);

        config()->set('pssfp.ecampus.jeton', JETON_ESSAI);
        $this->actingAs(($this->agent)('admission_committee'));
        $this->livewire(ListCandidatures::class)->assertTableActionHidden('creerCompteEcampus', $c);
    });

    it('masque colonne et filtre eCampus sans jeton', function (): void {
        config()->set('pssfp.ecampus.jeton', '');
        ($this->numerote)();
        $this->actingAs($this->admin);

        $this->livewire(ListCandidatures::class)
            ->assertTableColumnHidden('ecampus_statut')
            ->assertTableFilterHidden('ecampus');
    });

    it('n\'affiche plus d\'état à reprendre sur un dossier retiré', function (): void {
        $c = ($this->numerote)();
        $c->forceFill(['ecampus_statut' => ComptesEcampus::ECHEC, 'withdrawn_at' => now()])->save();
        $cree = ($this->numerote)();
        $cree->forceFill(['ecampus_statut' => ComptesEcampus::CREE, 'withdrawn_at' => now()])->save();

        expect(ComptesEcampusActions::libelle($c->fresh()))->toBeNull()
            ->and(ComptesEcampusActions::libelle($cree->fresh()))->toBe('Créé');
    });

    it('filtre les comptes à créer ou en échec', function (): void {
        $aCreer = ($this->numerote)();
        $fait = ($this->numerote)();
        $fait->forceFill(['ecampus_statut' => ComptesEcampus::CREE])->save();
        $this->actingAs($this->admin);

        $this->livewire(ListCandidatures::class)
            ->filterTable('ecampus', 'a_traiter')
            ->assertCanSeeTableRecords([$aCreer])
            ->assertCanNotSeeTableRecords([$fait]);
    });
});

describe('rapprochement des comptes déjà créés', function (): void {
    it('note les comptes trouvés, sans aucune écriture sur eCampus', function (): void {
        $present = ($this->numerote)(['nom' => 'Mengue', 'prenom' => 'Alice']);
        $absent = ($this->numerote)();
        fauxMoodle($this, [[
            'id' => 12, 'username' => mb_strtolower((string) $present->matricule), 'email' => 'autre@example.org',
            'firstname' => 'Alice', 'lastname' => 'MENGUE',
        ]]);

        $this->artisan('ecampus:rapprocher', ['--campagne' => $this->campagne->id])
            ->expectsOutputToContain('1 compte(s) existant(s), dont 1 avec une différence ; 1 absent(s)')
            ->assertSuccessful();

        expect(array_unique(fonctionsAppelees($this)))->toBe(['core_user_get_users_by_field'])
            ->and($present->fresh()->ecampus_statut)->toBe(ComptesEcampus::EXISTANT)
            ->and($present->fresh()->ecampus_detail)->toContain('e-mail')
            ->and($absent->fresh()->ecampus_statut)->toBeNull();
    });

    it('laisse à terminer un compte du portail dont la création a été interrompue', function (): void {
        $c = ($this->numerote)();
        fauxMoodle($this, [['id' => 12, 'username' => mb_strtolower((string) $c->matricule), 'email' => $c->email, 'idnumber' => $c->matricule]]);

        $this->artisan('ecampus:rapprocher', ['--campagne' => $this->campagne->id])->assertSuccessful();

        expect($c->fresh()->ecampus_statut)->toBe(ComptesEcampus::COHORTE_A_FINIR);
    });

    it('échoue sur une réponse illisible au lieu de tout compter absent', function (): void {
        $c = ($this->numerote)();
        Http::fake(['ecampus.test/*' => Http::response('<html>proxy</html>', 200)]);

        $this->artisan('ecampus:rapprocher', ['--campagne' => $this->campagne->id])->assertFailed();

        expect($c->fresh()->ecampus_statut)->toBeNull();
    });

    it('s\'arrête sans rien noter si eCampus ne répond pas', function (): void {
        $c = ($this->numerote)();
        Http::fake(['ecampus.test/*' => fn () => throw new ConnectionException('panne')]);

        $this->artisan('ecampus:rapprocher', ['--campagne' => $this->campagne->id])->assertFailed();

        expect($c->fresh()->ecampus_statut)->toBeNull();
    });

    it('refuse de tourner sans jeton', function (): void {
        config()->set('pssfp.ecampus.jeton', '');
        Http::fake();

        $this->artisan('ecampus:rapprocher')->assertFailed();
        Http::assertNothingSent();
    });
});
