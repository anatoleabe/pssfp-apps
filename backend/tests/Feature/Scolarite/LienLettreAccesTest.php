<?php

declare(strict_types=1);

use App\Filament\Resources\AuditeurResource\Pages\ViewAuditeur;
use App\Http\Middleware\IpVisiteurDuPortail;
use App\Mail\NotificationCandidatMail;
use App\Models\BaremeScolarite;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\LienLettreAcces;
use App\Models\User;
use App\Models\Versement;
use App\Services\Ecampus\ComptesEcampus;
use App\Services\ExportsRetenus\AttributionMatricules;
use App\Services\ExportsRetenus\IdentifiantsMoodle;
use App\Services\Scolarite\LiensLettreAcces;
use App\Services\Scolarite\NotificationAcces;
use App\Services\Sms\SmsServiceInterface;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

uses()->group('scolarite', 'lien-lettre-acces');

/*
 * Lien court vers la lettre d'accès FOAD, à la place des codes en clair dans
 * le SMS (décision du 13/11/2026) : lien aléatoire sur apply.pssfp.org,
 * date de naissance exigée, 7 jours, 5 téléchargements, verrou après 5 dates
 * fausses, réservé aux auditeurs dont les droits sont payés.
 */

const MOTIF_LIEN = '#https://apply\.pssfp\.org/l/([A-Za-z0-9]{16})#';

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    config()->set('pssfp.candidature_app_url', 'https://apply.pssfp.org');
    config()->set('pssfp.portail_secret', 'secret-de-test-du-portail');
    $this->seed([PaysSeeder::class, RegionsCamerounSeeder::class, DepartementsCamerounSeeder::class, RolePermissionSeeder::class]);
    $this->admin = tap(User::factory()->create(['email' => 'adm-'.Str::random(5).'@pssfp.local']), fn (User $u) => $u->assignRole('admin'));
    $this->campagne = CampagneCandidature::factory()->closed()->create([
        'nom' => ['fr' => 'Année académique 2026-2027'], 'promotion_numero' => 14,
        'parametres_lettres' => ['date_lettres' => '2026-11-04'],
    ]);
    BaremeScolarite::factory()->create(['annee_academique' => '2026-2027', 'niveau' => 'M1']);
    $retenu = fn (string $nom, string $tel): Candidature => Candidature::factory()->forCampagne($this->campagne)->submitted()->create([
        'nom' => $nom, 'prenom' => 'Test', 'statut' => Candidature::STATUT_ACCEPTE, 'phone_e164' => $tel,
        'email' => Str::random(8).'@example.org', 'date_naissance' => '1990-04-12', 'ecampus_statut' => ComptesEcampus::CREE,
    ]);
    $this->awa = $retenu('Abena', '+237699004001');
    $this->bruno = $retenu('Bekono', '+237699004002');
    app(AttributionMatricules::class)->attribuer($this->campagne, '26', $this->admin);
    $this->awa->refresh();
    $this->bruno->refresh();
    Versement::factory()->create(['inscription_annuelle_id' => $this->awa->auditeur->inscriptionCourante->id, 'montant' => 185000, 'reference' => 'BOR-LIEN1']);

    $this->sms = [];
    $this->smsEnPanne = false;
    $this->mock(SmsServiceInterface::class, function ($mock): void {
        $mock->shouldReceive('send')->andReturnUsing(function (string $tel, string $texte): void {
            if ($this->smsEnPanne) {
                throw new RuntimeException('passerelle');
            }
            $this->sms[] = [$tel, $texte];
        });
    });
    Mail::fake();

    $this->jetonDe = function (string $texte): string {
        expect(preg_match(MOTIF_LIEN, $texte, $m))->toBe(1);

        return $m[1];
    };
    $this->lien = fn (): string => ($this->jetonDe)(app(LiensLettreAcces::class)->creer($this->awa->fresh(), $this->admin));
    $this->ouvrir = fn (string $jeton, string $date = '1990-04-12', string $ip = '41.202.1.10') => $this
        ->withHeaders([IpVisiteurDuPortail::ENTETE_IP => $ip, IpVisiteurDuPortail::ENTETE_SECRET => 'secret-de-test-du-portail'])
        ->post('/v1/lettre-acces/'.$jeton, ['date_naissance' => $date], ['Accept' => 'application/json']);
});

it('envoie au solde un SMS avec le lien de la lettre, sans code en clair', function (): void {
    expect(app(NotificationAcces::class)->siDisponible($this->awa))->toBeTrue();

    $texte = $this->sms[0][1];
    expect($texte)->toMatch('#^PSSFP : telechargez votre lettre d\'acces FOAD sur https://apply\.pssfp\.org/l/[A-Za-z0-9]{16} \(lien valable 7 jours, date de naissance demandee\)$#')
        ->and(strlen($texte))->toBeLessThanOrEqual(160)
        ->and($texte)->not->toContain((string) $this->awa->moodle_password);

    $lien = LienLettreAcces::query()->sole();
    expect($lien->empreinte)->not->toBe(($this->jetonDe)($texte))
        ->and($lien->empreinte)->toBe(hash('sha256', ($this->jetonDe)($texte)))
        ->and($lien->expire_le->between(now()->addDays(7)->subMinute(), now()->addDays(7)->addMinute()))->toBeTrue();
});

it('garde le SMS vers l\'espace, sans point final, quand le mot de passe n\'est pas connu du portail', function (): void {
    $this->awa->forceFill(['moodle_password' => null, 'ecampus_statut' => ComptesEcampus::EXISTANT])->save();

    expect(app(NotificationAcces::class)->siDisponible($this->awa->fresh()))->toBeTrue()
        ->and($this->sms[0][1])->toBe('PSSFP : vos acces FOAD sont disponibles dans votre espace apply.pssfp.org')
        ->and(LienLettreAcces::query()->count())->toBe(0);
});

it('garde le SMS vers l\'espace tant que la lettre de la promotion n\'est pas prête', function (): void {
    $this->campagne->forceFill(['parametres_lettres' => null])->save();

    expect(app(NotificationAcces::class)->siDisponible($this->awa->fresh()))->toBeTrue()
        ->and($this->sms[0][1])->toBe('PSSFP : vos acces FOAD sont disponibles dans votre espace apply.pssfp.org')
        ->and(LienLettreAcces::query()->count())->toBe(0);
});

it('envoie le lien au lieu des codes avec « Envoyer les identifiants Moodle »', function (): void {
    $resultat = app(IdentifiantsMoodle::class)->envoyer($this->awa, IdentifiantsMoodle::CANAL_SMS, $this->admin);

    expect($resultat['sms'])->toBeTrue()
        ->and($this->sms[0][1])->toMatch(MOTIF_LIEN)
        ->and($this->sms[0][1])->not->toContain((string) $this->awa->moodle_password);
});

it('reconnaît un lien valide sans rien consommer, et ignore un lien inconnu', function (): void {
    $jeton = ($this->lien)();

    $this->getJson('/v1/lettre-acces/'.$jeton)->assertOk()->assertJsonStructure(['expire_le']);
    $this->getJson('/v1/lettre-acces/'.Str::random(16))->assertStatus(410);
    expect(LienLettreAcces::query()->sole()->telechargements)->toBe(0);
});

it('remet la lettre en PDF avec la bonne date de naissance, et le journalise', function (): void {
    $jeton = ($this->lien)();

    $reponse = ($this->ouvrir)($jeton)->assertOk();

    expect($reponse->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($reponse->headers->get('Content-Disposition'))->toContain('attachment')
        ->and($reponse->headers->get('Cache-Control'))->toContain('no-store')
        ->and(substr((string) $reponse->getContent(), 0, 4))->toBe('%PDF')
        ->and(LienLettreAcces::query()->sole()->telechargements)->toBe(1);

    $journal = Activity::query()->where('event', 'lettre_acces_telechargee_par_lien')->sole();
    expect($journal->subject_id)->toBe($this->awa->id)
        ->and($journal->properties['ip'])->toBe('41.202.1.10')
        ->and(json_encode($journal->toArray()))->not->toContain((string) $this->awa->moodle_password)
        ->and(json_encode($journal->toArray()))->not->toContain($jeton);
});

it('refuse une mauvaise date et verrouille le lien au cinquième échec', function (): void {
    $jeton = ($this->lien)();

    foreach ([4, 3, 2, 1] as $restantes) {
        ($this->ouvrir)($jeton, '1991-01-01')->assertStatus(422)->assertJson(['code' => 'date_incorrecte', 'restantes' => $restantes]);
    }
    ($this->ouvrir)($jeton, '1991-01-01')->assertStatus(423)->assertJson(['code' => 'lien_verrouille']);
    ($this->ouvrir)($jeton)->assertStatus(410);

    expect(Activity::query()->where('event', 'lettre_acces_lien_verrouille')->count())->toBe(1);
});

it('expire au bout de 7 jours', function (): void {
    $jeton = ($this->lien)();

    $this->travel(7)->days();
    $this->travel(1)->minutes();

    ($this->ouvrir)($jeton)->assertStatus(410)->assertJson(['code' => 'lien_invalide']);
});

it('cesse de fonctionner après 5 téléchargements', function (): void {
    $jeton = ($this->lien)();

    foreach (range(1, 5) as $_) {
        ($this->ouvrir)($jeton)->assertOk();
    }
    ($this->ouvrir)($jeton)->assertStatus(410);
});

it('remplace l\'ancien lien par le nouveau', function (): void {
    $ancien = ($this->lien)();
    $nouveau = ($this->lien)();

    ($this->ouvrir)($ancien)->assertStatus(410);
    ($this->ouvrir)($nouveau)->assertOk();
});

it('ferme le lien d\'un dossier qui n\'est plus remettable', function (): void {
    $jeton = ($this->lien)();
    $this->awa->forceFill(['withdrawn_at' => now()])->save();

    ($this->ouvrir)($jeton)->assertStatus(410);
});

it('ne crée aucun lien pour un dossier dont les droits ne sont pas payés', function (): void {
    expect(fn () => app(LiensLettreAcces::class)->creer($this->bruno, $this->admin))->toThrow(InvalidArgumentException::class)
        ->and(LienLettreAcces::query()->count())->toBe(0);
});

it('valide la date reçue avant toute comparaison', function (): void {
    $jeton = ($this->lien)();

    ($this->ouvrir)($jeton, '12/04/1990')->assertStatus(422)->assertJsonValidationErrors('date_naissance');
    expect(LienLettreAcces::query()->sole()->echecs)->toBe(0);
});

it('envoie un nouveau lien depuis la fiche de l\'auditeur', function (): void {
    $this->actingAs($this->admin);

    $this->livewire(ViewAuditeur::class, ['record' => ficheDe($this->awa)->getRouteKey()])
        ->assertActionVisible('envoyerLienLettre')
        ->callAction('envoyerLienLettre')
        ->assertHasNoActionErrors();

    expect($this->sms)->toHaveCount(1)
        ->and($this->sms[0][1])->toMatch(MOTIF_LIEN)
        ->and(Activity::query()->where('event', 'lettre_acces_lien_envoye')->count())->toBe(1);
});

it('révoque les liens ouverts quand le numéro de téléphone change', function (): void {
    $jeton = ($this->lien)();

    $this->awa->fresh()->update(['phone_e164' => '+237699004099']);

    ($this->ouvrir)($jeton)->assertStatus(410);
});

it('ne garde jamais deux liens ouverts pour un même dossier', function (): void {
    ($this->lien)();
    ($this->lien)();
    ($this->lien)();

    expect(LienLettreAcces::query()->whereNull('revoque_le')->count())->toBe(1);
});

it('envoie le même lien par SMS et par e-mail au solde', function (): void {
    expect(app(NotificationAcces::class)->siDisponible($this->awa))->toBeTrue();

    $jeton = ($this->jetonDe)($this->sms[0][1]);
    Mail::assertSent(NotificationCandidatMail::class, fn (NotificationCandidatMail $m): bool => $m->hasTo($this->awa->email)
        && str_contains($m->corpsMessage, 'https://apply.pssfp.org/l/'.$jeton)
        && str_contains($m->corpsMessage, 'date de naissance')
        && ! str_contains($m->corpsMessage, (string) $this->awa->moodle_password));
    expect(LienLettreAcces::query()->count())->toBe(1);
});

it('prévient par e-mail quand le SMS échoue, sans proposer de renvoi', function (): void {
    $this->smsEnPanne = true;

    expect(app(NotificationAcces::class)->siDisponible($this->awa))->toBeTrue();

    Mail::assertSent(NotificationCandidatMail::class, 1);
    expect($this->awa->auditeur->inscriptionCourante->fresh()->acces_notifie_at)->not->toBeNull()
        ->and(Activity::query()->where('event', 'acces_sms_envoye')->sole()->properties->only(['sms', 'email'])->all())
        ->toBe(['sms' => false, 'email' => true]);
});

it('laisse le renvoi ouvert quand ni le SMS ni l\'e-mail ne partent', function (): void {
    $this->smsEnPanne = true;
    $this->awa->forceFill(['email' => null])->save();

    expect(app(NotificationAcces::class)->siDisponible($this->awa->fresh()))->toBeFalse()
        ->and($this->awa->auditeur->inscriptionCourante->fresh()->acces_notifie_at)->toBeNull();
    Mail::assertNothingSent();
});

it('envoie aussi le nouveau lien de la fiche par e-mail', function (): void {
    $this->smsEnPanne = true;

    expect(app(LiensLettreAcces::class)->envoyer($this->awa, $this->admin))->toBeTrue();
    Mail::assertSent(NotificationCandidatMail::class, fn (NotificationCandidatMail $m): bool => str_contains($m->corpsMessage, '/l/'));
});
