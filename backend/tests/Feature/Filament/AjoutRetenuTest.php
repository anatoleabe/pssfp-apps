<?php

declare(strict_types=1);

use App\Events\CandidatureAccepted;
use App\Events\CandidatureCreated;
use App\Events\CandidatureSubmitted;
use App\Filament\Resources\CandidatureResource;
use App\Filament\Resources\CandidatureResource\Pages\ListCandidatures;
use App\Filament\Widgets\AvancementCampagneWidget;
use App\Filament\Widgets\QuotasRegionauxWidget;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\User;
use App\Services\AjoutRetenu\AjoutRetenu;
use App\Services\DocumentsAuditeurs\DocumentsAuditeurs;
use App\Services\DocumentsAuditeurs\ParametresLettres;
use App\Services\ExportsRetenus\AttributionMatricules;
use App\Services\ExportsRetenus\ExportsRetenus;
use App\Services\ExportsRetenus\ExportsRetenusExcel;
use App\Services\ResultatsSelection\ResultatsSelectionService;
use App\Services\ResultatsSelection\SeanceJury;
use App\Services\Sms\SmsServiceInterface;
use App\Support\PhoneMasker;
use Carbon\CarbonImmutable;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses()->group('filament', 'candidature');

/*
 * Ajout d'un retenu depuis l'admin, sans passer par le portail de candidature.
 * Le dossier naît retenu, sans soumission en ligne : il suit ensuite le parcours
 * ordinaire (matricule, lettres, exports) mais reste hors du PV du jury.
 */

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    $this->seed([PaysSeeder::class, RegionsCamerounSeeder::class, DepartementsCamerounSeeder::class, RolePermissionSeeder::class]);

    $this->campagne = CampagneCandidature::factory()->create([
        'status' => 'closed',
        'opens_at' => now()->subMonths(3),
        'closes_at' => now()->subMonth(),
        'prefix_numero' => 'P14026-',
        'promotion_numero' => 14,
        'parametres_lettres' => [
            'date_lettres' => '2026-11-04', 'rentree_date' => '2026-11-10', 'rentree_date_fin' => '2026-11-11',
            'rentree_heure' => '08:00 heures précises',
            'rentree_lieu' => 'au Concord International Hotel, Rue Daniel Essomba, Yaoundé',
            'frais_tranche1_date' => '2026-11-09', 'frais_tranche2_date' => '2027-01-29',
        ],
    ]);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
    $this->comite = User::factory()->create();
    $this->comite->assignRole('admission_committee');
    $this->donnees = [
        'campagne_id' => $this->campagne->id,
        'civilite' => 'Mme',
        'genre' => 'F',
        'nom' => 'Fictif',
        'prenom' => 'Amina Test',
        'date_naissance' => '1990-05-12',
        'lieu_naissance' => 'Yaoundé',
        'nationalite' => 'CM',
        'phone_e164' => '+237699000071',
        'phone_e164_confirmation' => '+237699000071',
        'email' => 'amina.fictif@example.test',
        'specialite' => collect((array) config('specialites'))->first(),
        'type_etude' => 'presentiel',
        'motif' => 'Décision du jury, note fictive n° 1',
    ];
});

function ajouterRetenu(array $changements = []): Candidature
{
    return app(AjoutRetenu::class)->ajouter(array_merge(test()->donnees, $changements), test()->admin);
}

it('crée un dossier retenu et son compte candidat, sans soumission ni message', function (): void {
    Event::fake([CandidatureAccepted::class, CandidatureCreated::class, CandidatureSubmitted::class]);
    Mail::fake();
    $sms = Mockery::mock(SmsServiceInterface::class);
    $sms->shouldNotReceive('send');
    app()->instance(SmsServiceInterface::class, $sms);

    $c = ajouterRetenu();

    expect($c->statut)->toBe(Candidature::STATUT_ACCEPTE)
        ->and($c->numero_dossier)->toStartWith('P14026-')
        ->and($c->decided_at)->not->toBeNull()
        ->and($c->submitted_at)->toBeNull()
        ->and($c->frais_paye)->toBeFalse()
        ->and($c->matricule)->toBeNull()
        ->and($c->ajoute_par)->toBe($this->admin->id)
        ->and($c->phone_country)->toBe('CM')
        ->and($c->user->phone_e164)->toBe('+237699000071')
        ->and($c->user->roles->pluck('name')->all())->toBe(['candidat']);

    Event::assertNotDispatched(CandidatureAccepted::class);
    Event::assertNotDispatched(CandidatureCreated::class);
    Event::assertNotDispatched(CandidatureSubmitted::class);
    Mail::assertNothingQueued();
    Mail::assertNothingSent();

    $journal = Activity::query()->where('event', 'retenu_ajoute')->sole();
    expect($journal->causer_id)->toBe($this->admin->id)
        ->and($journal->subject_id)->toBe($c->id)
        ->and($journal->properties['motif'])->toBe('Décision du jury, note fictive n° 1');
    // Le motif est interne : il ne doit pas revenir au candidat.
    expect($c->decision_commentaire)->toBeNull()->and($c->decision_motif)->toBeNull();
});

it('réutilise le compte d\'un ancien candidat au même téléphone et à la même date de naissance', function (): void {
    $ancien = User::factory()->candidat()->create(['phone_e164' => '+237699000071', 'date_naissance' => '1990-05-12']);

    expect(ajouterRetenu()->user_id)->toBe($ancien->id)
        ->and(User::query()->where('phone_e164', '+237699000071')->count())->toBe(1);
});

it('refuse un téléphone déjà pris dans la campagne, même par un dossier supprimé', function (bool $supprime): void {
    $existant = Candidature::factory()->forCampagne($this->campagne)->create(['phone_e164' => '+237699000071']);
    if ($supprime) {
        $existant->delete();
    }

    expect(fn () => ajouterRetenu())->toThrow(InvalidArgumentException::class, $existant->numero_dossier);
    expect(Candidature::withTrashed()->count())->toBe(1);
})->with([false, true]);

it('refuse la même personne sous un autre téléphone, malgré les accents et l\'ordre des mots', function (): void {
    $existant = Candidature::factory()->forCampagne($this->campagne)->create([
        'nom' => 'AMINA-TEST', 'prenom' => 'Fictif', 'date_naissance' => '1990-05-12', 'phone_e164' => '+237699000072',
    ]);

    expect(fn () => ajouterRetenu(['nom' => 'Fictîf']))->toThrow(InvalidArgumentException::class, $existant->numero_dossier);
    expect(User::query()->where('phone_e164', '+237699000071')->exists())->toBeFalse();
});

it('accepte un homonyme né un autre jour', function (): void {
    Candidature::factory()->forCampagne($this->campagne)->create([
        'nom' => 'Fictif', 'prenom' => 'Amina Test', 'date_naissance' => '1991-05-12', 'phone_e164' => '+237699000072',
    ]);

    expect(ajouterRetenu()->exists)->toBeTrue();
});

it('refuse le téléphone d\'un compte du personnel, sans le rattacher', function (): void {
    User::factory()->create(['phone_e164' => '+237699000071'])->assignRole('admin');

    expect(fn () => ajouterRetenu())->toThrow(InvalidArgumentException::class, 'personnel');
    expect(Candidature::query()->count())->toBe(0);
});

it('refuse un compte candidat dont la date de naissance diffère : numéro probablement erroné', function (): void {
    User::factory()->candidat()->create(['phone_e164' => '+237699000071', 'date_naissance' => '1985-01-01']);

    expect(fn () => ajouterRetenu())->toThrow(InvalidArgumentException::class, 'date de naissance');
    expect(Candidature::query()->count())->toBe(0);
});

it('refuse un téléphone mal formé et normalise les espaces', function (): void {
    expect(fn () => ajouterRetenu(['phone_e164' => '699000071']))->toThrow(InvalidArgumentException::class, 'téléphone');
    expect(ajouterRetenu(['phone_e164' => '+237 699 00 00 71'])->phone_e164)->toBe('+237699000071');
});

it('déduit le pays du téléphone de son indicatif, pas de la nationalité', function (): void {
    expect(ajouterRetenu(['phone_e164' => '+24166000071', 'nationalite' => 'CM'])->phone_country)->toBe('GA');
});

it('interdit l\'ajout sans la permission, quel que soit l\'appelant', function (): void {
    expect(fn () => app(AjoutRetenu::class)->ajouter($this->donnees, $this->comite))
        ->toThrow(HttpException::class);
    expect(Candidature::query()->count())->toBe(0);
});

it('suit le parcours ordinaire : matricule au rang suivant, lettres et exports', function (): void {
    $premier = Candidature::factory()->forCampagne($this->campagne)->submitted()->create(['statut' => Candidature::STATUT_ACCEPTE, 'nom' => 'Zzz']);
    app(AttributionMatricules::class)->attribuer($this->campagne, '26', $this->admin);

    $c = ajouterRetenu();
    app(AttributionMatricules::class)->attribuer($this->campagne, '26', $this->admin, [$c->id]);
    $c = $c->fresh();

    expect($c->matricule)->toBe('26EFP002')
        ->and($premier->fresh()->matricule)->toBe('26EFP001')
        ->and($c->moodle_password)->not->toBeEmpty();

    foreach ([ExportsRetenusExcel::FULL_DATA, ExportsRetenusExcel::ACMA, ExportsRetenusExcel::MOODLE] as $type) {
        expect(app(ExportsRetenus::class)->generer($this->campagne, $type, $this->admin, 'P14')['lignes'])->toBe(2);
    }
    foreach ([ParametresLettres::DOCUMENT_ACCES, ParametresLettres::DOCUMENT_ADMISSION] as $document) {
        expect(app(DocumentsAuditeurs::class)->generer($this->campagne, $document, $this->admin, [$c->id])['pages'])->toBe(1);
    }
});

it('ouvre l\'espace du retenu par matricule et lui montre sa décision, sans récépissé', function (): void {
    $c = ajouterRetenu();
    app(AttributionMatricules::class)->attribuer($this->campagne, '26', $this->admin);
    $c = $c->fresh();

    $jeton = $this->postJson('/v1/auth/candidat/login-matricule', ['identifiant' => $c->matricule, 'mot_de_passe' => $c->moodle_password])
        ->assertOk()->json('token');

    $this->withToken($jeton)->getJson('/v1/applications/me')
        ->assertOk()
        ->assertJsonPath('data.numero_dossier', $c->numero_dossier)
        ->assertJsonPath('data.statut', Candidature::STATUT_ACCEPTE)
        ->assertJsonPath('data.submitted_at', null)
        ->assertJsonPath('data.recipisse_available', false);
});

it('reste hors du PV du jury, qui reflète la délibération', function (): void {
    $delibere = Candidature::factory()->forCampagne($this->campagne)->submitted()->create([
        'statut' => Candidature::STATUT_ACCEPTE, 'depot_physique_at' => now(),
    ]);
    ajouterRetenu();

    $seance = new SeanceJury(dateDeliberation: CarbonImmutable::parse('2026-10-20'));
    [, $dossiers] = app(ResultatsSelectionService::class)->construireAvecDossiers($this->campagne, $seance);

    expect($dossiers->pluck('id')->all())->toBe([$delibere->id]);
});

it('n\'ouvre pas la création générale des dossiers', function (): void {
    expect(CandidatureResource::canCreate())->toBeFalse();
});

it('propose le bouton aux administrateurs seulement et enregistre depuis le formulaire', function (): void {
    $this->actingAs($this->comite);
    $this->livewire(ListCandidatures::class)->assertActionHidden('ajouterRetenu');

    $this->actingAs($this->admin);
    $this->livewire(ListCandidatures::class)
        ->assertActionVisible('ajouterRetenu')
        ->callAction('ajouterRetenu', data: $this->donnees)
        ->assertHasNoActionErrors()
        ->assertNotified();

    expect(Candidature::query()->where('phone_e164', '+237699000071')->sole()->statut)->toBe(Candidature::STATUT_ACCEPTE);
});

it('exige les champs des lettres et le motif dans le formulaire', function (string $champ): void {
    $this->actingAs($this->admin);
    $this->livewire(ListCandidatures::class)
        ->callAction('ajouterRetenu', data: array_merge($this->donnees, [$champ => null]))
        ->assertHasActionErrors([$champ => 'required']);

    expect(Candidature::query()->count())->toBe(0);
})->with(['civilite', 'genre', 'nom', 'prenom', 'date_naissance', 'lieu_naissance', 'nationalite', 'phone_e164', 'phone_e164_confirmation', 'email', 'specialite', 'type_etude', 'motif']);

it('affiche un doublon comme une erreur, sans fermer ni créer', function (): void {
    $existant = Candidature::factory()->forCampagne($this->campagne)->create(['phone_e164' => '+237699000071']);

    $this->actingAs($this->admin);
    $this->livewire(ListCandidatures::class)
        ->callAction('ajouterRetenu', data: $this->donnees)
        ->assertNotified();

    expect(Candidature::query()->pluck('id')->all())->toBe([$existant->id]);
});

it('protège la colonne de traçabilité des écritures en masse et de l\'API', function (): void {
    $c = new Candidature;

    expect($c->isFillable('ajoute_par'))->toBeFalse();
});

it('filtre les dossiers ajoutés par l\'admin', function (): void {
    $ajoute = ajouterRetenu();
    $ordinaire = Candidature::factory()->forCampagne($this->campagne)->submitted()->create();

    $this->actingAs($this->admin);
    $this->livewire(ListCandidatures::class)
        ->filterTable('ajoute_admin', true)
        ->assertCanSeeTableRecords([$ajoute])
        ->assertCanNotSeeTableRecords([$ordinaire]);
});

it('refuse une confirmation de téléphone différente, sans rien créer', function (): void {
    $this->actingAs($this->admin);
    $this->livewire(ListCandidatures::class)
        ->callAction('ajouterRetenu', data: array_merge($this->donnees, ['phone_e164_confirmation' => '+237699000079']))
        ->assertHasActionErrors(['phone_e164_confirmation' => 'same'])
        ->assertSee('Les deux numéros ne sont pas identiques')
        ->assertDontSee('validation.same');

    expect(Candidature::query()->count())->toBe(0);
});

it('refuse une fonction trop longue dans le formulaire, avant la base', function (): void {
    $this->actingAs($this->admin);
    $this->livewire(ListCandidatures::class)
        ->callAction('ajouterRetenu', data: array_merge($this->donnees, ['fonction_actuelle' => str_repeat('a', 151)]))
        ->assertHasActionErrors(['fonction_actuelle' => 'max']);

    expect(Candidature::query()->count())->toBe(0);
});

it('ne présélectionne ni ne propose une campagne en préparation ou archivée', function (string $statut): void {
    $autre = CampagneCandidature::factory()->create(['status' => $statut, 'opens_at' => now()->addYear(), 'closes_at' => now()->addYears(2)]);

    $this->actingAs($this->admin);
    $this->livewire(ListCandidatures::class)
        ->mountAction('ajouterRetenu')
        ->assertActionDataSet(['campagne_id' => $this->campagne->id])
        ->assertFormFieldExists('campagne_id', 'mountedActionForm')
        ->callMountedAction();

    // Une valeur forgée ne passe pas non plus : le service tranche.
    expect(fn () => ajouterRetenu(['campagne_id' => $autre->id]))->toThrow(InvalidArgumentException::class, 'campagne');
    expect(Candidature::query()->where('campagne_id', $autre->id)->count())->toBe(0);
})->with(['draft', 'archived']);

it('refuse un compte candidat sans date de naissance : rien ne prouve que c\'est la même personne', function (): void {
    User::factory()->candidat()->create(['phone_e164' => '+237699000071', 'date_naissance' => null]);

    expect(fn () => ajouterRetenu())->toThrow(InvalidArgumentException::class, 'date de naissance');
    expect(Candidature::query()->count())->toBe(0);
});

it('permet de reprendre un ajout sur un mauvais numéro, une fois ce dossier retiré', function (): void {
    $errone = ajouterRetenu();
    expect(fn () => ajouterRetenu(['phone_e164' => '+237699000072']))->toThrow(InvalidArgumentException::class, $errone->numero_dossier);

    $errone->update(['withdrawn_at' => now()]);

    expect(ajouterRetenu(['phone_e164' => '+237699000072'])->phone_e164)->toBe('+237699000072');
});

it('écarte le retenu ajouté de la relance des frais de dossier', function (): void {
    $ajoute = ajouterRetenu();
    $impaye = Candidature::factory()->forCampagne($this->campagne)->submitted()->create(['frais_paye' => false]);

    $this->actingAs($this->admin);
    $this->livewire(ListCandidatures::class)
        ->filterTable('frais_paye', false)
        ->assertCanSeeTableRecords([$impaye])
        ->assertCanNotSeeTableRecords([$ajoute]);
});

it('ne propose pas « Marquer comme candidat » sur un retenu ajouté', function (): void {
    $ajoute = ajouterRetenu();
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super_admin');

    $this->actingAs($superAdmin);
    $this->livewire(ListCandidatures::class)->assertTableActionHidden('markAsCandidat', $ajoute);
});

it('ne compte pas le retenu ajouté dans les tableaux de réception d\'une campagne ouverte', function (): void {
    $this->campagne->update(['status' => 'open', 'opens_at' => now()->subMonth(), 'closes_at' => now()->addMonth()]);
    ajouterRetenu(['region' => 'CENTRE']);
    Candidature::factory()->forCampagne($this->campagne)->submitted()->create(['frais_paye' => true, 'region' => 'CENTRE']);

    $this->actingAs($this->admin);
    $this->livewire(AvancementCampagneWidget::class)->assertSee('100');
    $donnees = (new ReflectionMethod(QuotasRegionauxWidget::class, 'getViewData'))->invoke(new QuotasRegionauxWidget);

    expect($donnees['total'])->toBe(1);
});

function corrigerTelephone(Candidature $c, string $nouveau = '+237699000072'): Candidature
{
    return app(AjoutRetenu::class)->corrigerTelephone($c, $nouveau, 'Erreur de saisie, note fictive', test()->admin);
}

it('corrige le téléphone : le compte suit, le mauvais numéro perd code et sessions', function (): void {
    $c = ajouterRetenu();
    $compte = $c->user;
    $compte->forceFill(['password' => Hash::make('847291')])->save();
    $jeton = $compte->createToken('candidat', ['application:read'])->plainTextToken;
    app(AttributionMatricules::class)->attribuer($this->campagne, '26', $this->admin);
    $matricule = $c->fresh()->matricule;

    $corrige = corrigerTelephone($c->fresh());

    expect($corrige->phone_e164)->toBe('+237699000072')
        ->and($corrige->matricule)->toBe($matricule)
        ->and($corrige->user_id)->toBe($compte->id)
        ->and($compte->fresh()->phone_e164)->toBe('+237699000072')
        ->and(Hash::check('847291', $compte->fresh()->password))->toBeFalse()
        ->and($compte->tokens()->count())->toBe(0)
        ->and(User::query()->where('phone_e164', '+237699000071')->exists())->toBeFalse();

    Auth::forgetGuards();
    $this->withToken($jeton)->getJson('/v1/applications/me')->assertUnauthorized();
    $this->flushHeaders();
    $this->postJson('/v1/auth/candidat/login-matricule', ['identifiant' => $matricule, 'mot_de_passe' => $corrige->moodle_password])->assertOk();
});

it('détache le dossier d\'un compte qui en porte un autre, sans toucher ce compte', function (): void {
    $ancienne = CampagneCandidature::factory()->create(['status' => 'closed', 'prefix_numero' => 'P13025-']);
    $proprietaire = User::factory()->candidat('847291')->create(['phone_e164' => '+237699000071', 'date_naissance' => '1990-05-12']);
    Candidature::factory()->forCampagne($ancienne)->create(['user_id' => $proprietaire->id, 'phone_e164' => '+237699000071']);
    $c = ajouterRetenu();
    expect($c->user_id)->toBe($proprietaire->id);

    $corrige = corrigerTelephone($c);

    expect($corrige->user_id)->not->toBe($proprietaire->id)
        ->and($corrige->user->phone_e164)->toBe('+237699000072')
        ->and($proprietaire->fresh()->phone_e164)->toBe('+237699000071')
        ->and(Hash::check('847291', $proprietaire->fresh()->password))->toBeTrue();
});

it('rattache au compte du bon numéro et supprime le compte créé par erreur', function (): void {
    $bon = User::factory()->candidat()->create(['phone_e164' => '+237699000072', 'date_naissance' => '1990-05-12']);
    $c = ajouterRetenu();
    $errone = $c->user_id;

    expect(corrigerTelephone($c)->user_id)->toBe($bon->id)
        ->and(User::query()->whereKey($errone)->exists())->toBeFalse();
});

it('refuse la correction vers un numéro inutilisable, sans rien changer', function (Closure $preparer, string $message): void {
    $c = ajouterRetenu();
    $preparer();

    expect(fn () => corrigerTelephone($c))->toThrow(InvalidArgumentException::class, $message);
    expect($c->fresh()->phone_e164)->toBe('+237699000071')
        ->and($c->user->fresh()->phone_e164)->toBe('+237699000071');
})->with([
    'dossier de la campagne' => [fn () => Candidature::factory()->forCampagne(test()->campagne)->create(['phone_e164' => '+237699000072']), 'dossier'],
    'compte du personnel' => [fn () => User::factory()->create(['phone_e164' => '+237699000072'])->assignRole('admin'), 'personnel'],
    'autre date de naissance' => [fn () => User::factory()->candidat()->create(['phone_e164' => '+237699000072', 'date_naissance' => '1985-01-01']), 'date de naissance'],
]);

it('refuse la correction d\'un numéro identique ou mal formé', function (string $nouveau): void {
    expect(fn () => corrigerTelephone(ajouterRetenu(), $nouveau))->toThrow(InvalidArgumentException::class, 'téléphone');
})->with(['+237 699 00 00 71', '699000072']);

it('réserve la correction aux retenus ajoutés et à la permission', function (): void {
    $portail = Candidature::factory()->forCampagne($this->campagne)->submitted()->create();
    expect(fn () => corrigerTelephone($portail))->toThrow(InvalidArgumentException::class, 'portail');
    expect(fn () => app(AjoutRetenu::class)->corrigerTelephone(ajouterRetenu(), '+237699000072', 'Motif', $this->comite))
        ->toThrow(HttpException::class);
});

it('journalise la correction avec des numéros masqués', function (): void {
    $c = corrigerTelephone(ajouterRetenu());

    $journal = Activity::query()->where('event', 'retenu_telephone_corrige')->sole();
    expect($journal->subject_id)->toBe($c->id)
        ->and($journal->properties['motif'])->toBe('Erreur de saisie, note fictive')
        ->and($journal->properties['ancien'])->toBe(PhoneMasker::mask('+237699000071'))
        ->and($journal->properties['nouveau'])->toBe(PhoneMasker::mask('+237699000072'))
        ->and(json_encode($journal->properties))->not->toContain('699000071');
});

it('propose la correction depuis la liste, seulement sur un retenu ajouté, et demande confirmation', function (): void {
    $ajoute = ajouterRetenu();
    $portail = Candidature::factory()->forCampagne($this->campagne)->submitted()->create();
    $donnees = ['telephone' => '+237699000072', 'telephone_confirmation' => '+237699000072', 'motif' => 'Erreur de saisie'];

    $this->actingAs($this->admin);
    $page = $this->livewire(ListCandidatures::class)
        ->assertTableActionVisible('corrigerTelephone', $ajoute)
        ->assertTableActionHidden('corrigerTelephone', $portail);
    $page->callTableAction('corrigerTelephone', $ajoute, array_merge($donnees, ['telephone_confirmation' => '+237699000079']))
        ->assertHasTableActionErrors(['telephone_confirmation' => 'same']);
    expect($ajoute->fresh()->phone_e164)->toBe('+237699000071');

    $this->livewire(ListCandidatures::class)
        ->callTableAction('corrigerTelephone', $ajoute, $donnees)
        ->assertHasNoTableActionErrors()
        ->assertNotified();
    expect($ajoute->fresh()->phone_e164)->toBe('+237699000072');

    $this->actingAs($this->comite);
    $this->livewire(ListCandidatures::class)->assertTableActionHidden('corrigerTelephone', $ajoute);
});

it('signale des identifiants déjà envoyés, que la correction ne reprend pas', function (): void {
    $c = ajouterRetenu();
    expect(app(AjoutRetenu::class)->identifiantsDejaEnvoyes($c))->toBeFalse();

    activity('candidatures')->performedOn($c)->event('moodle_identifiants_envoyes')->log('Envoi fictif');

    expect(app(AjoutRetenu::class)->identifiantsDejaEnvoyes($c))->toBeTrue();
});
