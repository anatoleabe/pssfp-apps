<?php

declare(strict_types=1);

use App\Filament\Resources\CandidatureResource\Pages\ListCandidatures;
use App\Filament\Resources\CandidatureResource\Pages\ViewCandidature;
use App\Mail\NotificationCandidatMail;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\CandidatureDocument;
use App\Models\User;
use App\Services\EspaceCandidat\ConsultationEspaceCandidat;
use App\Services\EspaceCandidat\NouveauCodeCandidat;
use App\Services\Sms\SmsServiceInterface;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses()->group('filament', 'espace-candidat');

/*
 * Accès de l'administration à l'espace d'un candidat : nouveau code de
 * connexion (le PIN n'est stocké que haché) et consultation en lecture seule.
 */

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    config()->set('pssfp.candidature_app_url', 'https://apply.pssfp.org');
    $this->seed([PaysSeeder::class, RegionsCamerounSeeder::class, DepartementsCamerounSeeder::class, RolePermissionSeeder::class]);

    $campagne = CampagneCandidature::factory()->create(['status' => 'closed', 'closes_at' => now()->subMonth()]);
    $this->compte = User::factory()->candidat('847291')->create(['phone_e164' => '+237699000031']);
    $this->candidature = Candidature::factory()->forCampagne($campagne)->submitted()->create([
        'user_id' => $this->compte->id,
        'nom' => 'Mengue', 'prenom' => 'Alice',
        'statut' => Candidature::STATUT_ACCEPTE,
        'phone_e164' => '+237699000031', 'email' => 'alice@example.org',
    ]);
    $this->agent = fn (string $role): User => tap(
        User::factory()->create(['email' => $role.'-'.Str::random(6).'@pssfp.local']),
        fn (User $u) => $u->assignRole($role),
    );

    $this->sms = [];
    $this->mock(SmsServiceInterface::class, function ($mock): void {
        $mock->shouldReceive('send')->andReturnUsing(function (string $tel, string $texte): void {
            $this->sms[] = [$tel, $texte];
        });
    });
});

/** Code envoyé dans le dernier SMS. */
function codeDuSms(array $sms): string
{
    expect(preg_match('/est (\d{6})\./', end($sms)[1], $m))->toBe(1);

    return $m[1];
}

it('donne un nouveau code au candidat par SMS, coupe ses sessions et ne journalise pas le code', function (): void {
    $this->compte->createToken('candidat', ['application:read']);
    $this->actingAs(($this->agent)('admin'));

    $this->livewire(ListCandidatures::class)
        ->callTableAction('nouveauCode', $this->candidature, data: ['canal' => NouveauCodeCandidat::CANAL_SMS])
        ->assertHasNoTableActionErrors()
        ->assertNotified();

    expect($this->sms)->toHaveCount(1)
        ->and($this->sms[0][0])->toBe('+237699000031')
        ->and(strlen($this->sms[0][1]))->toBeLessThanOrEqual(160)
        ->and(mb_check_encoding($this->sms[0][1], 'ASCII'))->toBeTrue();
    $code = codeDuSms($this->sms);
    $compte = $this->compte->fresh();
    expect(Hash::check($code, $compte->password))->toBeTrue()
        ->and(Hash::check('847291', $compte->password))->toBeFalse()
        ->and($compte->tokens()->count())->toBe(0);

    $journal = Activity::query()->where('event', 'nouveau_code_connexion')->sole();
    expect($journal->subject_id)->toBe($this->candidature->id)
        ->and(json_encode($journal->properties))->not->toContain($code);
});

it('permet ensuite au candidat de se connecter avec le nouveau code', function (): void {
    $resultat = app(NouveauCodeCandidat::class)->generer($this->candidature, NouveauCodeCandidat::CANAL_AUCUN, ($this->agent)('admin'));

    expect($this->sms)->toBe([])
        ->and($resultat['code'])->toMatch('/^\d{6}$/');
    $this->postJson('/v1/auth/candidat/login', ['phone_e164' => '+237699000031', 'pin' => $resultat['code']])
        ->assertOk()
        ->assertJsonStructure(['token']);
});

it('envoie le code par e-mail immédiatement, sans passer par la file', function (): void {
    Mail::fake();

    $resultat = app(NouveauCodeCandidat::class)->generer($this->candidature, NouveauCodeCandidat::CANAL_EMAIL, ($this->agent)('admin'));

    expect($resultat['email'])->toBeTrue()->and($this->sms)->toBe([]);
    Mail::assertSent(NotificationCandidatMail::class, fn (NotificationCandidatMail $m): bool => $m->hasTo('alice@example.org')
        && str_contains($m->corpsMessage, $resultat['code']));
    Mail::assertNothingQueued();
});

it('réserve les deux boutons à l\'administration', function (): void {
    $this->actingAs(($this->agent)('admission_committee'));
    $this->livewire(ListCandidatures::class)
        ->assertTableActionHidden('nouveauCode', $this->candidature)
        ->assertTableActionHidden('voirEspace', $this->candidature);

    $this->actingAs(($this->agent)('admin'));
    $this->livewire(ViewCandidature::class, ['record' => $this->candidature->getRouteKey()])
        ->assertActionVisible('nouveauCode')
        ->assertActionVisible('voirEspace');

    expect(fn () => app(NouveauCodeCandidat::class)->generer($this->candidature, NouveauCodeCandidat::CANAL_AUCUN, ($this->agent)('admission_committee')))
        ->toThrow(HttpException::class);
});

it('refuse un compte qui porte aussi un rôle du personnel', function (): void {
    $this->compte->assignRole('editor');
    $this->actingAs(($this->agent)('admin'));

    $this->livewire(ListCandidatures::class)
        ->assertTableActionHidden('nouveauCode', $this->candidature)
        ->assertTableActionHidden('voirEspace', $this->candidature);
    $this->get(route('filament.admin.candidatures.espace', $this->candidature))->assertNotFound();
    expect(fn () => app(NouveauCodeCandidat::class)->generer($this->candidature->fresh(), NouveauCodeCandidat::CANAL_AUCUN, ($this->agent)('admin')))
        ->toThrow(InvalidArgumentException::class)
        ->and(Hash::check('847291', $this->compte->fresh()->password))->toBeTrue();
});

it('journalise le dossier réellement affiché quand le compte en a plusieurs', function (): void {
    $ancienne = Candidature::factory()->forCampagne(CampagneCandidature::factory()->create(['status' => 'closed', 'closes_at' => now()->subYear()]))
        ->create(['user_id' => $this->compte->id, 'statut' => Candidature::STATUT_REFUSE, 'created_at' => now()->subYear()]);
    $code = Str::after(app(ConsultationEspaceCandidat::class)->ouvrir($ancienne, ($this->agent)('admin')), 'code=');

    $this->postJson('/v1/auth/candidat/consultation', ['code' => $code])->assertOk();

    $journal = Activity::query()->where('event', 'espace_consulte')->sole();
    expect($journal->subject_id)->toBe($this->candidature->id)
        ->and($journal->properties['dossier_demande'])->toBe($ancienne->numero_dossier);
});

it('ne journalise jamais le code quand l\'envoi échoue', function (): void {
    $this->mock(SmsServiceInterface::class, fn ($mock) => $mock->shouldReceive('send')->andThrow(new RuntimeException('fournisseur indisponible')));
    $contextes = [];
    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('error')->andReturnUsing(function (string $message, array $contexte) use (&$contextes): void {
        $contextes[] = $message.json_encode($contexte);
    });

    $resultat = app(NouveauCodeCandidat::class)->generer($this->candidature, NouveauCodeCandidat::CANAL_SMS, ($this->agent)('admin'));

    expect($resultat['sms'])->toBeFalse()
        ->and($contextes)->toHaveCount(1)
        ->and($contextes[0])->not->toContain($resultat['code']);
});

it('masque les boutons pour un dossier sans compte candidat', function (): void {
    $this->candidature->update(['user_id' => ($this->agent)('editor')->id]);
    $this->actingAs(($this->agent)('admin'));

    $this->livewire(ListCandidatures::class)
        ->assertTableActionHidden('nouveauCode', $this->candidature->fresh())
        ->assertTableActionHidden('voirEspace', $this->candidature->fresh());
});

it('ouvre l\'espace du candidat depuis l\'administration vers le portail', function (): void {
    $this->actingAs(($this->agent)('admin'));

    $reponse = $this->get(route('filament.admin.candidatures.espace', $this->candidature));

    $reponse->assertRedirect();
    expect($reponse->headers->get('Location'))->toMatch('#^https://apply\.pssfp\.org/auth/consultation\?code=[A-Za-z0-9]{64}$#')
        ->and($reponse->headers->get('Cache-Control'))->toContain('no-store');
});

it('refuse l\'ouverture de l\'espace hors administration', function (): void {
    $this->get(route('filament.admin.candidatures.espace', $this->candidature))->assertRedirect('/admin/login');

    $this->actingAs(($this->agent)('admission_committee'));
    $this->get(route('filament.admin.candidatures.espace', $this->candidature))->assertForbidden();
});

it('échange le lien contre une session en lecture seule, une seule fois, et le journalise', function (): void {
    $admin = ($this->agent)('admin');
    $url = app(ConsultationEspaceCandidat::class)->ouvrir($this->candidature, $admin);
    $code = Str::after($url, 'code=');

    $session = $this->postJson('/v1/auth/candidat/consultation', ['code' => $code])
        ->assertOk()
        ->assertJsonPath('abilities', ConsultationEspaceCandidat::ABILITIES)
        ->json();
    $this->postJson('/v1/auth/candidat/consultation', ['code' => $code])->assertStatus(410);

    $jeton = $this->compte->tokens()->sole();
    expect($jeton->name)->toBe(ConsultationEspaceCandidat::NOM_JETON)
        ->and($jeton->expires_at->between(now()->addMinutes(29), now()->addMinutes(31)))->toBeTrue();

    $this->withToken($session['token'])->getJson('/v1/applications/me')
        ->assertOk()
        ->assertJsonPath('data.numero_dossier', $this->candidature->numero_dossier);

    $journal = Activity::query()->where('event', 'espace_consulte')->sole();
    expect($journal->causer_id)->toBe($admin->id)
        ->and($journal->subject_id)->toBe($this->candidature->id);
});

it('interdit toute écriture avec une session de consultation', function (string $methode, string $route): void {
    $code = Str::after(app(ConsultationEspaceCandidat::class)->ouvrir($this->candidature, ($this->agent)('admin')), 'code=');
    $jeton = $this->postJson('/v1/auth/candidat/consultation', ['code' => $code])->json('token');

    // Pièce réelle du dossier : une route liée à un modèle inexistant répondrait 404 avant le contrôle des droits.
    $piece = $this->candidature->documents()->create([
        'type' => CandidatureDocument::TYPE_CV, 'original_filename' => 'piece.pdf', 'mime' => 'application/pdf', 'size' => 10, 'path' => 'tests/piece.pdf',
    ]);

    $this->withToken($jeton)->json($methode, str_replace('{piece}', $piece->uuid, $route), [])->assertForbidden();
})->with([
    'modifier le dossier' => ['PUT', '/v1/applications/me'],
    'soumettre' => ['POST', '/v1/applications/me/submit'],
    'se retirer' => ['POST', '/v1/applications/me/withdraw'],
    'déposer une photo' => ['POST', '/v1/applications/me/photo'],
    'supprimer la photo' => ['DELETE', '/v1/applications/me/photo'],
    'déposer une pièce' => ['POST', '/v1/applications/me/documents'],
    'supprimer une pièce' => ['DELETE', '/v1/applications/me/documents/{piece}'],
    'changer le code' => ['POST', '/v1/auth/candidat/reset-pin'],
]);

it('laisse lire le récépissé et la photo avec une session de consultation', function (string $route): void {
    $code = Str::after(app(ConsultationEspaceCandidat::class)->ouvrir($this->candidature, ($this->agent)('admin')), 'code=');
    $jeton = $this->postJson('/v1/auth/candidat/consultation', ['code' => $code])->json('token');

    expect($this->withToken($jeton)->get($route)->status())->not->toBeIn([401, 403]);
})->with(['/v1/applications/me/recipisse', '/v1/applications/me/photo']);

it('refuse un lien expiré, inconnu ou dont l\'agent a perdu le droit', function (): void {
    $admin = ($this->agent)('admin');
    $consultation = app(ConsultationEspaceCandidat::class);

    $expire = Str::after($consultation->ouvrir($this->candidature, $admin), 'code=');
    $this->travel(61)->seconds();
    $this->postJson('/v1/auth/candidat/consultation', ['code' => $expire])->assertStatus(410);

    $this->postJson('/v1/auth/candidat/consultation', ['code' => Str::random(64)])->assertStatus(410);
    $this->postJson('/v1/auth/candidat/consultation', ['code' => 'court'])->assertUnprocessable();

    $retire = Str::after($consultation->ouvrir($this->candidature, $admin), 'code=');
    $admin->syncRoles(['editor']);
    $this->postJson('/v1/auth/candidat/consultation', ['code' => $retire])->assertStatus(410);

    expect($this->compte->tokens()->count())->toBe(0);
});

it('plafonne les nouveaux codes par candidat et prévient l\'agent', function (): void {
    $admin = ($this->agent)('admin');
    $service = app(NouveauCodeCandidat::class);
    foreach (range(1, NouveauCodeCandidat::MAX_PAR_COMPTE) as $_) {
        $service->generer($this->candidature, NouveauCodeCandidat::CANAL_AUCUN, $admin);
    }
    $avant = $this->compte->fresh()->password;

    $this->actingAs($admin);
    $this->livewire(ListCandidatures::class)
        ->callTableAction('nouveauCode', $this->candidature, data: ['canal' => NouveauCodeCandidat::CANAL_SMS])
        ->assertNotified('Aucun nouveau code');

    expect($this->compte->fresh()->password)->toBe($avant)
        ->and($this->sms)->toBe([]);
});

it('n\'ouvre qu\'une consultation à la fois et refuse un compte devenu inéligible', function (): void {
    $admin = ($this->agent)('admin');
    $consultation = app(ConsultationEspaceCandidat::class);
    $echanger = fn (): ?string => $this->postJson('/v1/auth/candidat/consultation', [
        'code' => Str::after($consultation->ouvrir($this->candidature, $admin), 'code='),
    ])->json('token');

    $premier = $echanger();
    $second = $echanger();
    expect($premier)->not->toBe($second)
        ->and($this->compte->tokens()->where('name', ConsultationEspaceCandidat::NOM_JETON)->count())->toBe(1);

    $code = Str::after($consultation->ouvrir($this->candidature, $admin), 'code=');
    $this->compte->assignRole('editor');
    $this->postJson('/v1/auth/candidat/consultation', ['code' => $code])->assertStatus(410);
});

it('ne partage pas son compteur de débit avec « PIN oublié »', function (): void {
    foreach (range(1, 15) as $_) {
        $this->postJson('/v1/auth/candidat/consultation', ['code' => Str::random(64)])->assertStatus(410);
    }

    $this->postJson('/v1/auth/candidat/forgot-pin', ['phone_e164' => '+237699000031'])->assertStatus(202);
});
