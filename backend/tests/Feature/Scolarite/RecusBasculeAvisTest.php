<?php

declare(strict_types=1);

use App\Filament\Resources\AuditeurResource\Pages\ViewAuditeur;
use App\Filament\Resources\AuditeurResource\RelationManagers\VersementsRelationManager;
use App\Filament\Resources\RecuVersementResource;
use App\Mail\NotificationCandidatMail;
use App\Models\BaremeScolarite;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\CandidatureRelance;
use App\Models\RecuVersement;
use App\Models\User;
use App\Models\Versement;
use App\Services\Ecampus\ComptesEcampus;
use App\Services\ExportsRetenus\AttributionMatricules;
use App\Services\Scolarite\AvisRecus;
use App\Services\Scolarite\RecusVersements;
use App\Services\Scolarite\RegistreVersements;
use App\Services\Sms\SmsServiceInterface;
use App\Support\AppSettings;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

/*
 * Reçus des versements : bascule ACMA et avis à l'auditeur (spec lot 4 §5, décisions du 15/11/2026).
 */

uses()->group('scolarite');

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    config()->set('pssfp.candidature_app_url', 'https://apply.pssfp.org');
    Mail::fake();

    // Chaque SMS garde le niveau de transaction au moment de l'envoi : 1 = la seule transaction du test,
    // c'est-à-dire après la validation de celle de l'émission.
    $this->sms = [];
    $this->panneSms = false;
    $this->mock(SmsServiceInterface::class, function ($mock): void {
        $mock->shouldReceive('send')->andReturnUsing(function (string $tel, string $texte): void {
            if ($this->panneSms) {
                throw new RuntimeException('Passerelle SMS indisponible');
            }
            $this->sms[] = ['tel' => $tel, 'texte' => $texte, 'niveau' => DB::transactionLevel(), 'recus' => RecuVersement::query()->count()];
        });
    });

    $compte = fn (string $role): User => tap(User::factory()->create(['email' => $role.'-'.Str::random(6).'@pssfp.local', 'name' => 'Agent '.$role]), fn (User $u) => $u->assignRole($role));
    $this->agent = $compte('scolarite_agent');
    $this->responsable = $compte('scolarite_responsable');
    $this->financier = $compte('service_financier');
    $this->travelTo(now('Africa/Douala')->setDate(2026, 11, 10)->setTime(9, 0));
    AppSettings::set(RecusVersements::CLE_DEPUIS, now()->subDay()->toIso8601String());

    $campagne = CampagneCandidature::factory()->closed()->create(['nom' => ['fr' => 'Année académique 2026-2027'], 'promotion_numero' => 14]);
    BaremeScolarite::factory()->create(['annee_academique' => '2026-2027', 'niveau' => 'M1']);
    $this->awa = Candidature::factory()->forCampagne($campagne)->submitted()->create([
        'nom' => 'Abena', 'prenom' => 'Essai', 'statut' => Candidature::STATUT_ACCEPTE, 'phone_e164' => '+237699004001', 'email' => 'awa-'.Str::random(6).'@example.org',
        'ecampus_statut' => ComptesEcampus::CREE,
        'user_id' => User::factory()->create(['phone_e164' => '+237699004001', 'email' => Str::random(8).'@example.org'])->id,
    ]);
    app(AttributionMatricules::class)->attribuer($campagne, '26', $compte('admin'));
    $this->awa->refresh();
    $this->inscription = $this->awa->auditeur->inscriptionCourante;
    $this->service = fn (): RecusVersements => app(RecusVersements::class);
    $this->verser = fn (int $montant, string $date = '2026-11-02'): Versement => app(RegistreVersements::class)->enregistrer($this->inscription,
        ['montant' => $montant, 'date_versement' => $date, 'mode' => 'cremincam_agence', 'reference' => 'BOR-'.Str::random(6), 'payeur' => 'auditeur'], $this->agent)->versement;
    $this->a = fn (int $jour, int $heure, int $minute = 0) => $this->travelTo(now('Africa/Douala')->setDate(2026, 11, $jour)->setTime($heure, $minute));

    // Avis des reçus seulement : un versement peut aussi annoncer les accès FOAD.
    $this->smsRecus = fn (): array => array_values(array_filter($this->sms, fn (array $s): bool => str_starts_with($s['texte'], 'PSSFP : votre recu ')));
    $this->mailsRecus = fn (): Collection => Mail::sent(NotificationCandidatMail::class, fn (NotificationCandidatMail $m): bool => str_starts_with($m->sujetMessage, 'PSSFP — Votre reçu '));
});

describe('liste d\'attente et bascule ACMA', function (): void {
    it('garde la liste d\'avant tant que la date de bascule n\'est pas posée', function (): void {
        $v = ($this->verser)(185000);

        expect(($this->service)()->bascule())->toBeNull()
            ->and(($this->service)()->debutAttente()?->equalTo(($this->service)()->miseEnService()))->toBeTrue()
            ->and(($this->service)()->enAttente($this->financier)->pluck('id')->all())->toBe([$v->id]);
    });

    it('ne compte que les versements enregistrés depuis la bascule, sans fermer l\'émission à l\'unité', function (): void {
        ($this->a)(11, 10);
        $ancien = ($this->verser)(185000);
        ($this->a)(12, 9, 30);
        $veille = ($this->verser)(100000, '2026-11-12');
        ($this->a)(12, 11);
        // Heure de Yaoundé, alors que l'application tourne ici en UTC : 9 h 30 précède 10 h.
        $this->artisan('scolarite:bascule-recus', ['--date' => '2026-11-12 10:00'])->assertSuccessful();
        $nouveau = ($this->verser)(300000, '2026-11-12');

        expect(($this->service)()->enAttente($this->financier)->pluck('id')->all())->toBe([$nouveau->id])
            ->and(($this->service)()->raisonRefus($ancien->refresh(), $this->financier))->toBeNull()
            ->and(($this->service)()->raisonRefus($veille->refresh(), $this->financier))->toBeNull();

        // Reçu à l'unité, sur demande de l'auditeur : il reste possible pour un versement d'avant la bascule.
        expect(($this->service)()->emettre($ancien, $this->financier)->numero)->toBe('RCPT-2026-000001');
    });

    it('part de la mise en service quand la bascule est antérieure', function (): void {
        $v = ($this->verser)(185000);
        AppSettings::set(RecusVersements::CLE_BASCULE, now()->subMonth()->toIso8601String());

        expect(($this->service)()->debutAttente()?->equalTo(($this->service)()->miseEnService()))->toBeTrue()
            ->and(($this->service)()->enAttente($this->financier)->pluck('id')->all())->toBe([$v->id]);
    });
});

describe('commande scolarite:bascule-recus', function (): void {
    it('pose la date une fois et l\'écrit au journal scolarite', function (): void {
        $this->artisan('scolarite:bascule-recus')
            ->expectsOutputToContain('Bascule des reçus posée au 10/11/2026 09:00')
            ->assertSuccessful();

        $journal = Activity::query()->where('event', 'recus_bascule_posee')->sole();
        expect(($this->service)()->bascule()?->equalTo(now()))->toBeTrue()
            ->and($journal->log_name)->toBe('scolarite')
            ->and($journal->properties['date'])->toBe(($this->service)()->bascule()->toIso8601String());
    });

    it('ne change rien quand on la rejoue, sans date ou avec la même', function (): void {
        $this->artisan('scolarite:bascule-recus', ['--date' => '2026-11-10 08:00'])->assertSuccessful();
        $posee = AppSettings::get(RecusVersements::CLE_BASCULE);
        ($this->a)(10, 15);

        $this->artisan('scolarite:bascule-recus')->expectsOutputToContain('déjà posée au 10/11/2026 08:00')->assertSuccessful();
        $this->artisan('scolarite:bascule-recus', ['--date' => '2026-11-10 08:00'])->assertSuccessful();

        expect(AppSettings::get(RecusVersements::CLE_BASCULE))->toBe($posee)
            ->and(Activity::query()->where('event', 'like', 'recus_bascule%')->count())->toBe(1);
    });

    it('refuse de déplacer une date posée sans --force, et --force sans motif', function (): void {
        $this->artisan('scolarite:bascule-recus', ['--date' => '2026-11-10 08:00'])->assertSuccessful();
        $posee = AppSettings::get(RecusVersements::CLE_BASCULE);

        $this->artisan('scolarite:bascule-recus', ['--date' => '2026-11-09 18:00'])
            ->expectsOutputToContain('déjà posée au 10/11/2026 08:00')
            ->assertFailed();
        $this->artisan('scolarite:bascule-recus', ['--date' => '2026-11-09 18:00', '--force' => true])->assertFailed();
        $this->artisan('scolarite:bascule-recus', ['--date' => '2026-11-09 18:00', '--force' => true, '--motif' => 'ok'])->assertFailed();

        expect(AppSettings::get(RecusVersements::CLE_BASCULE))->toBe($posee)
            ->and(Activity::query()->where('event', 'recus_bascule_deplacee')->exists())->toBeFalse();
    });

    it('déplace la date avec --force et un motif, et garde l\'ancienne au journal', function (): void {
        $this->artisan('scolarite:bascule-recus', ['--date' => '2026-11-10 08:00'])->assertSuccessful();
        $ancienne = ($this->service)()->bascule();

        $this->artisan('scolarite:bascule-recus', ['--date' => '2026-11-09 18:00', '--force' => true, '--motif' => 'ACMA arrêté la veille au soir'])
            ->expectsOutputToContain('Bascule des reçus déplacée au 09/11/2026 18:00')
            ->assertSuccessful();

        $journal = Activity::query()->where('event', 'recus_bascule_deplacee')->sole();
        expect(($this->service)()->bascule()?->equalTo(now('Africa/Douala')->setDate(2026, 11, 9)->setTime(18, 0)))->toBeTrue()
            ->and($journal->log_name)->toBe('scolarite')
            ->and($journal->properties['ancienne'])->toBe($ancienne->toIso8601String())
            ->and($journal->properties['motif'])->toBe('ACMA arrêté la veille au soir');
    });

    it('refuse une date illisible ou à venir', function (): void {
        $this->artisan('scolarite:bascule-recus', ['--date' => 'pas une date'])->assertFailed();
        $this->artisan('scolarite:bascule-recus', ['--date' => '2026-11-11 08:00'])->assertFailed();

        expect(AppSettings::get(RecusVersements::CLE_BASCULE))->toBeNull()
            ->and(Activity::query()->where('event', 'like', 'recus_bascule%')->exists())->toBeFalse();
    });
});

describe('avis à l\'auditeur', function (): void {
    it('prévient l\'auditeur une fois, par SMS et par e-mail, après la validation de l\'émission', function (): void {
        $v = ($this->verser)(500000);

        $r = ($this->service)()->emettre($v, $this->financier);

        $sms = ($this->smsRecus)();
        expect($sms)->toHaveCount(1)
            ->and($sms[0]['texte'])->toBe('PSSFP : votre recu RCPT-2026-000001 de 500000 FCFA est disponible dans votre espace https://apply.pssfp.org')
            ->and($sms[0]['tel'])->toBe('+237699004001')
            ->and($sms[0]['niveau'])->toBe(1)
            ->and($sms[0]['recus'])->toBe(1);

        $mails = ($this->mailsRecus)();
        expect($mails)->toHaveCount(1);
        $mail = $mails->first();
        expect($mail->hasTo($this->awa->email))->toBeTrue()
            ->and($mail->sujetMessage)->toBe('PSSFP — Votre reçu RCPT-2026-000001')
            ->and($mail->corpsMessage)->toBe(AvisRecus::texteEmail('RCPT-2026-000001', 500000, '2026-2027'))
            ->and($mail->corpsMessage)->toContain('RCPT-2026-000001')->toContain('500 000 FCFA')->toContain('2026-2027')->toEndWith('https://apply.pssfp.org');

        // Tracé comme les autres envois aux auditeurs : journal des envois, espace de l'auditeur, journal scolarite.
        $traces = CandidatureRelance::query()->where('cause', CandidatureRelance::CAUSE_RECU)->get();
        expect($traces->pluck('canal')->sort()->values()->all())->toBe(['email', 'sms'])
            ->and($traces->pluck('statut')->unique()->all())->toBe([CandidatureRelance::STATUT_ENVOYE])
            ->and($traces->pluck('envoye_par')->unique()->all())->toBe([$this->financier->id])
            ->and($this->awa->fresh()->messagesRecus->where('cause', CandidatureRelance::CAUSE_RECU))->toHaveCount(2);
        $journal = Activity::query()->where('event', 'recu_avis')->sole();
        expect($journal->log_name)->toBe('scolarite')
            ->and($journal->subject_id)->toBe($r->id)
            ->and($journal->properties['sms_envoyes'])->toBe(1)
            ->and($journal->properties['emails_envoyes'])->toBe(1);
    });

    it('n\'envoie rien pour une émission défaite avec sa transaction', function (): void {
        $v = ($this->verser)(500000);

        expect(fn () => DB::transaction(function () use ($v): void {
            ($this->service)()->emettre($v, $this->financier);

            throw new RuntimeException('Incident après l\'émission');
        }))->toThrow(RuntimeException::class, 'Incident après l\'émission');

        expect(RecuVersement::query()->count())->toBe(0)
            ->and(($this->smsRecus)())->toBe([])
            ->and(($this->mailsRecus)())->toHaveCount(0)
            ->and(CandidatureRelance::query()->where('cause', CandidatureRelance::CAUSE_RECU)->exists())->toBeFalse();
    });

    it('écrit un SMS ASCII d\'un segment, sans téléphone, qui finit sur l\'adresse', function (): void {
        config()->set('pssfp.candidature_app_url', 'https://apply.pssfp.org/');
        $texte = AvisRecus::texteSms('RCPT-2026-000012', 500000);

        expect($texte)->toBe('PSSFP : votre recu RCPT-2026-000012 de 500000 FCFA est disponible dans votre espace https://apply.pssfp.org')
            ->and(preg_match('/[^\x20-\x7E]/', $texte))->toBe(0)
            ->and(mb_strlen(AvisRecus::texteSms('RCPT-2026-999999', 9_999_999)))->toBeLessThanOrEqual(160)
            ->and(preg_match('/\d{3} ?\d{2} ?\d{2} ?\d{2}/', $texte))->toBe(0)
            ->and($texte)->toEndWith('https://apply.pssfp.org');
    });

    it('garde le reçu quand la passerelle SMS échoue, et trace l\'échec au journal des envois', function (): void {
        $v = ($this->verser)(500000);
        $this->panneSms = true;

        $this->actingAs($this->financier);
        $this->livewire(VersementsRelationManager::class, ['ownerRecord' => $this->awa->auditeur, 'pageClass' => ViewAuditeur::class])->callTableAction('emettreRecu', $v)->assertHasNoTableActionErrors()->assertNotified('Reçu RCPT-2026-000001 émis');

        $sms = CandidatureRelance::query()->where('cause', CandidatureRelance::CAUSE_RECU)->where('canal', CandidatureRelance::CANAL_SMS)->sole();
        expect(RecuVersement::query()->where('versement_id', $v->id)->value('numero'))->toBe('RCPT-2026-000001')
            ->and($sms->statut)->toBe(CandidatureRelance::STATUT_ECHEC)
            ->and($sms->erreur)->toContain('Passerelle SMS indisponible')
            ->and(($this->mailsRecus)())->toHaveCount(1)
            ->and(Activity::query()->where('event', 'recu_avis')->sole()->properties['sms_echecs'])->toBe(1);
    });

    it('garde le reçu même quand tout le circuit d\'envoi lève, et le dit au journal applicatif', function (): void {
        $v = ($this->verser)(500000);
        // Le journal des envois refuse toute écriture : le circuit lève jusqu'à l'avis.
        CandidatureRelance::creating(fn () => throw new RuntimeException('Journal des envois indisponible'));
        $journaux = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$journaux): void {
            $journaux[] = $e->message;
        });

        $r = ($this->service)()->emettre($v, $this->financier);

        expect(RecuVersement::query()->whereKey($r->id)->value('numero'))->toBe('RCPT-2026-000001')
            ->and(Activity::query()->where('event', 'recu_emis')->count())->toBe(1)
            ->and(Activity::query()->where('event', 'recu_avis')->exists())->toBeFalse()
            ->and($journaux)->toContain('Avis du reçu à l\'auditeur interrompu.');
    });

    it('n\'envoie aucun avis à l\'annulation d\'un reçu ni de son versement, mais annonce le nouveau reçu', function (): void {
        $v = ($this->verser)(500000);
        $r = ($this->service)()->emettre($v, $this->financier);
        $avant = CandidatureRelance::query()->where('cause', CandidatureRelance::CAUSE_RECU)->count();

        ($this->service)()->annuler($r, 'Erreur sur le payeur', $this->financier);
        expect(($this->smsRecus)())->toHaveCount(1)
            ->and(($this->mailsRecus)())->toHaveCount(1)
            ->and(CandidatureRelance::query()->where('cause', CandidatureRelance::CAUSE_RECU)->count())->toBe($avant);

        $nouveau = ($this->service)()->emettre($v->refresh(), $this->financier);
        expect(($this->smsRecus)())->toHaveCount(2)
            ->and(($this->smsRecus)()[1]['texte'])->toContain($nouveau->numero);

        app(RegistreVersements::class)->annuler($v->refresh(), 'Bordereau rejeté par la banque', $this->responsable);
        expect($nouveau->fresh()->annulation)->not->toBeNull()
            ->and(($this->smsRecus)())->toHaveCount(2)
            ->and(($this->mailsRecus)())->toHaveCount(2)
            ->and(Activity::query()->where('event', 'recu_avis')->count())->toBe(2);
    });
});

describe('écran des reçus', function (): void {
    it('indique au service financier la date qui borne la liste d\'attente', function (): void {
        ($this->verser)(185000);

        $this->actingAs($this->financier);
        $this->get(RecuVersementResource::getUrl('index'))->assertOk()
            ->assertSee('Versements enregistrés depuis le 09/11/2026 : 1 en attente de reçu.')
            ->assertDontSee('Un versement plus ancien reçoit son reçu');
        expect(RecuVersementResource::getNavigationBadgeTooltip())->toBe('Versements sans reçu (enregistrés depuis le 09/11/2026)');

        ($this->a)(12, 10);
        $this->artisan('scolarite:bascule-recus')->assertSuccessful();

        $this->get(RecuVersementResource::getUrl('index'))->assertOk()
            ->assertSee('Versements enregistrés depuis le 12/11/2026 : aucun en attente de reçu.')
            ->assertSee('Un versement plus ancien reçoit son reçu à la demande de l\'auditeur, depuis sa fiche.');
        expect(RecuVersementResource::getNavigationBadgeTooltip())->toBe('Versements sans reçu (enregistrés depuis le 12/11/2026)')
            ->and(RecuVersementResource::getNavigationBadge())->toBeNull();

        $this->actingAs($this->agent);
        $this->get(RecuVersementResource::getUrl('index'))->assertOk()->assertDontSee('en attente de reçu');
    });
});
