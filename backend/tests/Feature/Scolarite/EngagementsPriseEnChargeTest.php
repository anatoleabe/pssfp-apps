<?php

declare(strict_types=1);

use App\Jobs\SendThrottledMail;
use App\Mail\RelanceOrganismeMail;
use App\Models\Auditeur;
use App\Models\BaremeScolarite;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\DeblocageAcces;
use App\Models\EngagementPriseEnCharge;
use App\Models\User;
use App\Models\Versement;
use App\Services\Ecampus\ComptesEcampus;
use App\Services\ExportsRetenus\AttributionMatricules;
use App\Services\Scolarite\AccesEcampus;
use App\Services\Scolarite\DeblocagesAcces;
use App\Services\Scolarite\EngagementsPriseEnCharge;
use App\Services\Scolarite\RegistreVersements;
use App\Services\Scolarite\SuiviEngagement;
use App\Services\Sms\SmsServiceInterface;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses()->group('scolarite', 'prises-en-charge');

/*
 * Engagements de prise en charge (spec module 4, lot A2 §9) : la lettre d'un organisme
 * ouvre l'accès des auditeurs qu'on y rattache, sans compter un franc comme payé ; la
 * relance de l'organisme ne part que sur clic.
 */

const LETTRE_ENGAGEMENT = 'scolarite/justificatifs/01JLETTRE0000000000000000A.pdf';

const MIGRATION_ENGAGEMENTS = '2026_12_03_100000_create_engagements_prise_en_charge_tables.php';

beforeEach(function (): void {
    config()->set('pssfp.candidature_app_url', 'https://apply.pssfp.org');
    $this->seed([PaysSeeder::class, RegionsCamerounSeeder::class, DepartementsCamerounSeeder::class, RolePermissionSeeder::class]);
    $this->travelTo(now('Africa/Douala')->setDate(2026, 11, 16)->setTime(9, 0));
    $this->compte = fn (string $role): User => tap(User::factory()->create(['email' => $role.'-'.Str::random(6).'@pssfp.local']), fn (User $u) => $u->assignRole($role));
    $this->agent = ($this->compte)('scolarite_agent');
    $this->responsable = ($this->compte)('scolarite_responsable');
    $campagne = CampagneCandidature::factory()->closed()->create([
        'nom' => ['fr' => 'Année académique 2026-2027'], 'promotion_numero' => 14,
        'parametres_lettres' => ['date_lettres' => '2026-11-04'],
    ]);
    BaremeScolarite::factory()->create(['annee_academique' => '2026-2027', 'niveau' => 'M1']);
    $retenu = fn (string $nom, string $tel): Candidature => Candidature::factory()->forCampagne($campagne)->submitted()->create([
        'nom' => $nom, 'prenom' => 'Essai', 'statut' => Candidature::STATUT_ACCEPTE, 'phone_e164' => $tel,
        'email' => Str::lower($nom).'@example.org', 'date_naissance' => '1990-04-12', 'ecampus_statut' => ComptesEcampus::CREE,
    ]);
    $this->awa = $retenu('Abena', '+237699007001');
    $this->bruno = $retenu('Bekono', '+237699007002');
    $this->chantal = $retenu('Chimi', '+237699007003');
    app(AttributionMatricules::class)->attribuer($campagne, '26', ($this->compte)('admin'));
    foreach (['awa', 'bruno', 'chantal'] as $d) {
        $this->{$d}->refresh();
    }

    $this->sms = [];
    $this->mock(SmsServiceInterface::class, function ($mock): void {
        $mock->shouldReceive('send')->andReturnUsing(function (string $tel, string $texte): void {
            $this->sms[] = $tel;
        });
    });
    Mail::fake();

    $this->service = fn (): EngagementsPriseEnCharge => app(EngagementsPriseEnCharge::class);
    $this->engagement = fn (array $saisie = [], ?User $par = null): EngagementPriseEnCharge => ($this->service)()->creer($saisie + [
        'organisme' => 'Ministère de test', 'lettre_reference' => 'lt-2026-001', 'lettre_date' => '2026-11-10',
        'lettre_path' => LETTRE_ENGAGEMENT, 'contact_nom' => 'Service des ressources humaines', 'contact_email' => 'rh@organisme.example',
        'contact_telephone' => '+237222000000', 'portee' => EngagementPriseEnCharge::DROITS_INSCRIPTION,
        'annee_academique' => '2026-2027', 'echeance' => '2027-01-31',
    ], $par ?? $this->agent);
    $this->rattacher = fn (EngagementPriseEnCharge $e, array $dossiers, ?User $par = null) => ($this->service)()
        ->rattacher($e, array_map(fn (Candidature $c): Auditeur => $c->fresh()->auditeur, $dossiers), $par ?? $this->agent);
    $this->etat = fn (Candidature $c): string => AccesEcampus::etat(Candidature::query()->findOrFail($c->id));
    $this->verser = fn (Candidature $c, int $montant = 185000) => app(RegistreVersements::class)->enregistrer($c->fresh()->auditeur->inscriptionCourante, [
        'montant' => $montant, 'date_versement' => '2026-11-16', 'mode' => 'virement', 'reference' => 'VIR-'.Str::random(8), 'payeur' => 'auditeur',
    ], $this->agent);
});

describe('création', function (): void {
    it('crée un engagement avec sa lettre, journalisé, sans auditeur', function (): void {
        $e = ($this->engagement)();

        expect($e->fresh())
            ->organisme->toBe('Ministère de test')
            ->lettre_reference->toBe('LT-2026-001')
            ->lettre_path->toBe(LETTRE_ENGAGEMENT)
            ->portee->toBe(EngagementPriseEnCharge::DROITS_INSCRIPTION)
            ->annee_academique->toBe('2026-2027')
            ->cree_par->toBe($this->agent->id)
            ->and($e->echeance->toDateString())->toBe('2027-01-31')
            ->and(SuiviEngagement::de($e->fresh())->etat())->toBe(EngagementPriseEnCharge::SANS_AUDITEUR)
            ->and(Activity::query()->where('event', 'engagement_cree')->sole()->subject_id)->toBe($e->id);
    });

    it('exige la lettre scannée, propre à cet engagement', function (?string $lettre): void {
        expect(fn () => ($this->engagement)(['lettre_path' => $lettre]))->toThrow(InvalidArgumentException::class, 'lettre');
    })->with([
        'absente' => [null],
        'hors du dossier des justificatifs' => ['candidatures/autre/piece.pdf'],
    ]);

    it('refuse une lettre déjà jointe à un versement', function (): void {
        Versement::factory()->create(['inscription_annuelle_id' => $this->awa->auditeur->inscriptionCourante->id, 'montant' => 1000, 'justificatif_path' => LETTRE_ENGAGEMENT]);

        expect(fn () => ($this->engagement)())->toThrow(InvalidArgumentException::class, 'déjà jointe');
    });

    it('valide l\'organisme, la portée, l\'année, la date de la lettre, l\'échéance et l\'e-mail du contact', function (array $saisie, string $message): void {
        expect(fn () => ($this->engagement)($saisie))->toThrow(InvalidArgumentException::class, $message);
    })->with([
        'organisme vide' => [['organisme' => '  '], 'organisme'],
        'portée inconnue' => [['portee' => 'tout'], 'portée'],
        'année sans barème' => [['annee_academique' => '2031-2032'], 'année'],
        'lettre future' => [['lettre_date' => '2026-11-20'], 'date de la lettre'],
        'échéance passée' => [['echeance' => '2026-11-16'], 'postérieure à aujourd\'hui'],
        'échéance trop lointaine' => [['echeance' => '2027-06-01'], '180 jours'],
        'e-mail invalide' => [['contact_email' => 'pas-une-adresse'], 'e-mail'],
    ]);

    it('refuse de saisir deux fois la même lettre', function (): void {
        ($this->engagement)();

        expect(fn () => ($this->engagement)(['organisme' => '  ministère de test ', 'lettre_path' => 'scolarite/justificatifs/01JLETTRE0000000000000000B.pdf']))
            ->toThrow(InvalidArgumentException::class, 'déjà enregistré');
    });

    it('réserve la création aux droits de déblocage', function (): void {
        expect(fn () => ($this->engagement)([], ($this->compte)('service_financier')))->toThrow(HttpException::class);
    });

    it('modifie le contact et les notes seulement, au journal', function (): void {
        $e = ($this->engagement)();

        ($this->service)()->modifierContact($e, ['contact_nom' => 'Direction financière', 'contact_email' => 'df@organisme.example',
            'contact_telephone' => null, 'notes' => 'Paiement par bon de caisse', 'organisme' => 'Autre'], $this->agent);

        $log = Activity::query()->where('event', 'engagement_modifie')->sole();
        expect($e->fresh())->contact_nom->toBe('Direction financière')->contact_email->toBe('df@organisme.example')
            ->contact_telephone->toBeNull()->notes->toBe('Paiement par bon de caisse')->organisme->toBe('Ministère de test')
            ->and($log->properties['avant']['contact_email'])->toBe('rh@organisme.example')
            ->and($log->properties['apres']['contact_email'])->toBe('df@organisme.example');
        expect(fn () => ($this->service)()->modifierContact($e, ['contact_email' => 'faux'], $this->agent))->toThrow(InvalidArgumentException::class, 'e-mail');
    });
});

describe('rattachement', function (): void {
    it('ouvre l\'accès par un déblocage lié à l\'engagement, à son échéance, et prévient l\'auditeur', function (): void {
        $e = ($this->engagement)();
        expect(($this->etat)($this->awa))->toBe(AccesEcampus::BLOQUE);

        $resultat = ($this->rattacher)($e, [$this->awa]);

        $d = DeblocageAcces::query()->sole();
        expect($resultat->rattaches)->toBe([$this->awa->matricule])
            ->and($resultat->refus)->toBe([])
            ->and($d)->type->toBe(DeblocageAcces::PRISE_EN_CHARGE)->engagement_id->toBe($e->id)->justificatif_path->toBeNull()
            ->statut->toBe(DeblocageAcces::EN_COURS)->accorde_par->toBe($this->agent->id)
            ->and($d->echeance->toDateString())->toBe('2027-01-31')
            ->and($d->motif)->toBe('Prise en charge : Ministère de test, lettre LT-2026-001 du 10/11/2026')
            ->and(($this->etat)($this->awa))->toBe(AccesEcampus::DISPONIBLE)
            ->and($this->sms)->toBe(['+237699007001'])
            ->and(Activity::query()->where('event', 'deblocage_accorde')->sole()->properties['engagement'])->toBe($e->uuid);
    });

    it('traite chaque auditeur séparément et nomme les refus par matricule', function (): void {
        $e = ($this->engagement)();
        ($this->verser)($this->bruno);

        $resultat = ($this->rattacher)($e, [$this->awa, $this->bruno, $this->chantal]);

        expect($resultat->rattaches)->toBe([$this->awa->matricule, $this->chantal->matricule])
            ->and(array_keys($resultat->refus))->toBe([$this->bruno->matricule])
            ->and($resultat->refus[$this->bruno->matricule])->toContain('déjà soldés')
            ->and(DeblocageAcces::query()->where('engagement_id', $e->id)->count())->toBe(2);
    });

    it('applique les refus de A1', function (Closure $situation, string $message): void {
        $e = ($this->engagement)();
        $situation->call($this);

        $resultat = ($this->rattacher)($e, [$this->awa]);

        expect($resultat->rattaches)->toBe([])
            ->and($resultat->refus[$this->awa->matricule])->toContain($message)
            ->and(DeblocageAcces::query()->where('engagement_id', $e->id)->count())->toBe(0);
    })->with([
        'dossier retiré' => [fn () => $this->awa->forceFill(['withdrawn_at' => now()])->save(), 'retiré'],
        'exclu' => [fn () => $this->awa->auditeur->forceFill(['statut' => Auditeur::STATUT_EXCLU])->save(), 'exclu'],
        'abandon' => [fn () => $this->awa->auditeur->forceFill(['statut' => Auditeur::STATUT_ABANDON])->save(), 'abandon'],
        'droits soldés' => [fn () => ($this->verser)($this->awa), 'déjà soldés'],
        'déblocage en cours' => [fn () => DeblocageAcces::factory()->create(['inscription_annuelle_id' => $this->awa->auditeur->inscriptionCourante->id]), 'déjà en cours'],
    ]);

    it('refuse une inscription d\'une autre année, déjà couverte par un autre engagement, ou une échéance dépassée', function (): void {
        BaremeScolarite::factory()->create(['annee_academique' => '2027-2028', 'niveau' => 'M1']);
        $autreAnnee = ($this->engagement)(['annee_academique' => '2027-2028', 'lettre_reference' => 'LT-2']);
        $premier = ($this->engagement)(['lettre_reference' => 'LT-3', 'lettre_path' => 'scolarite/justificatifs/01JLETTRE0000000000000000C.pdf']);
        $second = ($this->engagement)(['organisme' => 'Autre organisme', 'lettre_path' => 'scolarite/justificatifs/01JLETTRE0000000000000000D.pdf']);
        ($this->rattacher)($premier, [$this->bruno]);

        expect(($this->rattacher)($autreAnnee, [$this->awa])->refus[$this->awa->matricule])->toContain('2027-2028');

        // Bruno est couvert par le premier engagement : régularisé, il reste couvert et ne passe pas au second.
        DeblocageAcces::query()->where('engagement_id', $premier->id)->update(['statut' => DeblocageAcces::REGULARISE, 'clos_le' => now()]);
        expect(($this->rattacher)($second, [$this->bruno])->refus[$this->bruno->matricule])->toContain('autre engagement');

        $this->travelTo(now('Africa/Douala')->setDate(2027, 2, 1)->setTime(9, 0));
        expect(($this->rattacher)($second, [$this->chantal])->refus[$this->chantal->matricule])->toContain('échéance');
    });

    it('après une révocation, laisse au seul responsable un nouveau rattachement', function (): void {
        $e = ($this->engagement)();
        ($this->rattacher)($e, [$this->awa]);
        app(DeblocagesAcces::class)->revoquer(DeblocageAcces::query()->sole(), 'Lettre de l\'employeur retirée', $this->responsable);

        expect(($this->rattacher)($e, [$this->awa])->refus[$this->awa->matricule])->toContain('révoqué')
            ->and(($this->rattacher)($e, [$this->awa], $this->responsable)->rattaches)->toBe([$this->awa->matricule]);
    });

    it('réserve le rattachement aux droits de déblocage', function (): void {
        $e = ($this->engagement)();

        expect(fn () => ($this->rattacher)($e, [$this->awa], ($this->compte)('service_financier')))->toThrow(HttpException::class);
    });

    it('propose la prise en charge à l\'agent, et la refuse hors engagement', function (): void {
        expect(DeblocagesAcces::typesPermis($this->agent))->toHaveKey(DeblocageAcces::PRISE_EN_CHARGE)
            ->and(DeblocagesAcces::typesPermis(($this->compte)('service_financier')))->toBe([]);

        expect(fn () => app(DeblocagesAcces::class)->accorder($this->awa->auditeur->inscriptionCourante, [
            'type' => DeblocageAcces::PRISE_EN_CHARGE, 'motif' => 'Lettre de l\'employeur reçue', 'echeance' => '2027-01-15',
        ], $this->responsable))->toThrow(InvalidArgumentException::class, 'engagement');
    });

    it('garantit en base qu\'une prise en charge, et elle seule, porte un engagement', function (): void {
        $e = ($this->engagement)();
        $inscription = $this->awa->auditeur->inscriptionCourante;
        $tenter = fn (array $attributs): Closure => fn () => DB::transaction(fn () => DeblocageAcces::factory()->create($attributs + ['inscription_annuelle_id' => $inscription->id]));

        expect($tenter(['type' => DeblocageAcces::PRISE_EN_CHARGE, 'justificatif_path' => null]))->toThrow(QueryException::class)
            ->and($tenter(['engagement_id' => $e->id]))->toThrow(QueryException::class)
            ->and(DeblocageAcces::query()->count())->toBe(0);
    });
});

describe('suivi', function (): void {
    it('calcule couvert, réglé, reste et état selon la portée', function (): void {
        $droits = ($this->engagement)();
        $annee = ($this->engagement)(['portee' => EngagementPriseEnCharge::SCOLARITE_ANNEE, 'lettre_reference' => 'LT-ANNEE',
            'lettre_path' => 'scolarite/justificatifs/01JLETTRE0000000000000000E.pdf']);
        ($this->rattacher)($droits, [$this->awa, $this->bruno]);
        ($this->rattacher)($annee, [$this->chantal]);
        ($this->verser)($this->awa, 100000);

        $suiviDroits = SuiviEngagement::de($droits->fresh());
        $suiviAnnee = SuiviEngagement::de($annee->fresh());

        expect($suiviDroits->nombre())->toBe(2)
            ->and($suiviDroits->couvert)->toBe(370000)
            ->and($suiviDroits->regle)->toBe(0)
            ->and($suiviDroits->reste)->toBe(270000)
            ->and($suiviDroits->etat())->toBe(EngagementPriseEnCharge::EN_COURS)
            ->and($suiviAnnee->couvert)->toBe(1185000)
            ->and($suiviAnnee->reste)->toBe(1185000);

        $this->travelTo(now('Africa/Douala')->setDate(2027, 2, 1)->setTime(9, 0));
        expect(SuiviEngagement::de($droits->fresh())->etat())->toBe(EngagementPriseEnCharge::ECHU);
    });
});

describe('prolongation', function (): void {
    it('laisse le responsable prolonger l\'engagement et tous ses déblocages en cours', function (): void {
        $e = ($this->engagement)();
        ($this->rattacher)($e, [$this->awa, $this->bruno]);

        ($this->service)()->prolonger($e, '2027-03-31', 'Courrier du ministère : paiement au 1er trimestre', $this->responsable);

        expect($e->fresh()->echeance->toDateString())->toBe('2027-03-31')
            ->and($e->fresh()->prolongations)->toBe(1)
            ->and(DeblocageAcces::query()->pluck('echeance')->map->toDateString()->unique()->all())->toBe(['2027-03-31'])
            ->and(DeblocageAcces::query()->pluck('prolongations')->unique()->all())->toBe([1])
            ->and(Activity::query()->where('event', 'engagement_prolonge')->count())->toBe(1)
            ->and(Activity::query()->where('event', 'deblocage_prolonge')->count())->toBe(2);
    });

    it('refuse la prolongation à l\'agent, et une échéance qui n\'allonge pas ou trop lointaine', function (): void {
        $e = ($this->engagement)();

        expect(fn () => ($this->service)()->prolonger($e, '2027-03-31', 'Courrier du ministère reçu', $this->agent))->toThrow(HttpException::class)
            ->and(fn () => ($this->service)()->prolonger($e, '2027-01-15', 'Courrier du ministère reçu', $this->responsable))->toThrow(InvalidArgumentException::class, 'après l\'échéance actuelle')
            ->and(fn () => ($this->service)()->prolonger($e, '2027-07-01', 'Courrier du ministère reçu', $this->responsable))->toThrow(InvalidArgumentException::class, '180 jours');
    });

    it('refuse de prolonger seul un déblocage rattaché à un engagement', function (): void {
        $e = ($this->engagement)();
        ($this->rattacher)($e, [$this->awa]);
        $d = DeblocageAcces::query()->sole();

        expect(DeblocagesAcces::peutProlonger($d, $this->responsable))->toBeFalse()
            ->and(fn () => app(DeblocagesAcces::class)->prolonger($d, '2027-02-15', 'Demande de l\'auditeur reçue', $this->responsable))
            ->toThrow(InvalidArgumentException::class, 'prolongez l\'engagement');
    });
});

describe('relance de l\'organisme', function (): void {
    beforeEach(function (): void {
        Bus::fake([SendThrottledMail::class]);
        $this->lire = fn (SendThrottledMail $job, string $propriete): mixed => (new ReflectionProperty($job, $propriete))->getValue($job);
    });

    it('envoie un e-mail au contact sur clic, avec les auditeurs qui ont un reste', function (): void {
        $e = ($this->engagement)();
        ($this->rattacher)($e, [$this->awa, $this->bruno]);
        ($this->verser)($this->bruno);

        ($this->service)()->relancer($e, $this->agent);

        $jobs = Bus::dispatched(SendThrottledMail::class)->values();
        expect($jobs)->toHaveCount(1)
            ->and(($this->lire)($jobs[0], 'destinataires'))->toBe(['rh@organisme.example']);
        $mail = ($this->lire)($jobs[0], 'mailable');
        expect($mail)->toBeInstanceOf(RelanceOrganismeMail::class);
        $html = $mail->render();
        expect($html)->toContain((string) $this->awa->matricule)->toContain('185 000')->toContain('LT-2026-001')
            ->not->toContain((string) $this->bruno->matricule)
            ->and($e->fresh())->relances->toBe(1)->derniere_relance_le->not->toBeNull()
            ->and(Activity::query()->where('event', 'engagement_relance')->sole()->properties['destinataire'])->toBe('rh@organisme.example');
    });

    it('refuse sans adresse du contact, ou quand rien ne reste à régler', function (): void {
        $sansAdresse = ($this->engagement)(['contact_email' => null]);
        ($this->rattacher)($sansAdresse, [$this->awa]);
        expect(fn () => ($this->service)()->relancer($sansAdresse, $this->agent))->toThrow(InvalidArgumentException::class, 'adresse');

        $solde = ($this->engagement)(['lettre_reference' => 'LT-SOLDE', 'lettre_path' => 'scolarite/justificatifs/01JLETTRE0000000000000000F.pdf']);
        ($this->rattacher)($solde, [$this->bruno]);
        ($this->verser)($this->bruno);
        expect(fn () => ($this->service)()->relancer($solde, $this->agent))->toThrow(InvalidArgumentException::class, 'Rien à relancer');

        Bus::assertNotDispatched(SendThrottledMail::class);
    });

    it('réserve la relance aux droits de déblocage', function (): void {
        $e = ($this->engagement)();
        ($this->rattacher)($e, [$this->awa]);

        expect(fn () => ($this->service)()->relancer($e, ($this->compte)('service_financier')))->toThrow(HttpException::class);
    });

    it('ne relance jamais l\'organisme d\'elle-même : la tâche quotidienne n\'écrit qu\'aux auditeurs', function (): void {
        $e = ($this->engagement)(['echeance' => '2026-11-20']);
        ($this->rattacher)($e, [$this->awa]);
        $this->travelTo(now('Africa/Douala')->setDate(2026, 11, 28)->setTime(8, 0));

        $this->artisan('scolarite:relancer-deblocages')->assertSuccessful();

        // L'auditeur, lui, est relancé (A1) ; rien ne part vers l'organisme.
        Bus::assertNotDispatched(SendThrottledMail::class, fn (SendThrottledMail $job): bool => ($this->lire)($job, 'mailable') instanceof RelanceOrganismeMail
            || in_array('rh@organisme.example', ($this->lire)($job, 'destinataires'), true));
        Mail::assertNotSent(RelanceOrganismeMail::class);
        expect($e->fresh()->relances)->toBe(0)
            ->and(DeblocageAcces::query()->sole()->relances)->toBe(1)
            ->and(collect(app(Schedule::class)->events())->filter(fn ($ev): bool => str_contains((string) $ev->command, 'organisme'))->count())->toBe(0);
    });
});

describe('migration', function (): void {
    it('se défait tant qu\'aucun engagement n\'existe, puis se refait', function (): void {
        $migration = require database_path('migrations/'.MIGRATION_ENGAGEMENTS);

        $migration->down();

        expect(Schema::hasTable('engagements_prise_en_charge'))->toBeFalse()
            ->and(Schema::hasTable('reglements_organismes'))->toBeFalse()
            ->and(Schema::hasColumn('versements', 'reglement_organisme_id'))->toBeFalse()
            ->and(Schema::hasColumn('deblocages_acces', 'engagement_id'))->toBeFalse();

        $migration->up();

        expect(Schema::hasTable('engagements_prise_en_charge'))->toBeTrue()
            ->and(Schema::hasColumn('versements', 'reglement_organisme_id'))->toBeTrue()
            ->and(Schema::hasColumn('deblocages_acces', 'engagement_id'))->toBeTrue();
    });

    it('refuse de se défaire dès qu\'un engagement existe', function (): void {
        ($this->engagement)();
        $migration = require database_path('migrations/'.MIGRATION_ENGAGEMENTS);

        expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'Des engagements de prise en charge sont enregistrés')
            ->and(Schema::hasTable('engagements_prise_en_charge'))->toBeTrue();
    });
});
