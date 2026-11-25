<?php

declare(strict_types=1);

use App\Mail\NotificationCandidatMail;
use App\Models\AnomalieSysteme;
use App\Models\Auditeur;
use App\Models\BaremeScolarite;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\CandidatureRelance;
use App\Models\DeblocageAcces;
use App\Models\Role;
use App\Models\User;
use App\Models\Versement;
use App\Services\DocumentsAuditeurs\DocumentsAuditeurs;
use App\Services\DocumentsAuditeurs\FraisInscription;
use App\Services\Ecampus\ComptesEcampus;
use App\Services\ExportsRetenus\AttributionMatricules;
use App\Services\ExportsRetenus\IdentifiantsMoodle;
use App\Services\Scolarite\AccesEcampus;
use App\Services\Scolarite\DeblocagesAcces;
use App\Services\Scolarite\LibelleSituation;
use App\Services\Scolarite\LiensLettreAcces;
use App\Services\Scolarite\RegistreVersements;
use App\Services\Scolarite\RelancesDeblocages;
use App\Services\Sms\SmsServiceInterface;
use App\Services\Supervision\Controle;
use App\Services\Supervision\Supervision;
use App\Support\ModulePermissionMap;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses()->group('scolarite', 'acces-anticipe');

/*
 * Accès anticipé (spec module 4, lot A1) : l'accès à eCampus s'ouvre avant le
 * paiement des droits d'inscription, sur un virement annoncé ou un moratoire,
 * avec une échéance, des relances et une régularisation automatique.
 */

const PREUVE_VIREMENT = 'scolarite/justificatifs/01JABCDEFGHJKMNPQRSTVWXYZ0.pdf';

beforeEach(function (): void {
    config()->set('pssfp.candidature_app_url', 'https://apply.pssfp.org');
    $this->seed([PaysSeeder::class, RegionsCamerounSeeder::class, DepartementsCamerounSeeder::class, RolePermissionSeeder::class]);
    $this->travelTo(now('Africa/Douala')->setDate(2026, 11, 16)->setTime(9, 0));
    $this->compte = fn (string $role): User => tap(User::factory()->create(['email' => $role.'-'.Str::random(6).'@pssfp.local']), fn (User $u) => $u->assignRole($role));
    $this->administrateur = ($this->compte)('admin');
    $this->agent = ($this->compte)('scolarite_agent');
    $this->encaisseur = ($this->compte)('service_financier');
    $this->responsable = ($this->compte)('scolarite_responsable');
    $this->campagne = CampagneCandidature::factory()->closed()->create([
        'nom' => ['fr' => 'Année académique 2026-2027'], 'promotion_numero' => 14,
        'parametres_lettres' => ['date_lettres' => '2026-11-04'],
    ]);
    BaremeScolarite::factory()->create(['annee_academique' => '2026-2027', 'niveau' => 'M1']);
    $this->dossier = Candidature::factory()->forCampagne($this->campagne)->submitted()->create([
        'statut' => Candidature::STATUT_ACCEPTE, 'phone_e164' => '+237699005001', 'email' => 'auditeur@example.org',
        'date_naissance' => '1990-04-12', 'ecampus_statut' => ComptesEcampus::CREE,
    ]);
    app(AttributionMatricules::class)->attribuer($this->campagne, '26', ($this->compte)('admin'));
    $this->dossier->refresh();
    $this->inscription = $this->dossier->auditeur->inscriptionCourante;

    $this->sms = [];
    $this->mock(SmsServiceInterface::class, function ($mock): void {
        $mock->shouldReceive('send')->andReturnUsing(function (string $tel, string $texte): void {
            $this->sms[] = $texte;
        });
    });
    Mail::fake();

    $this->service = fn (): DeblocagesAcces => app(DeblocagesAcces::class);
    $this->virement = fn (array $saisie = [], ?User $par = null) => ($this->service)()->accorder($this->inscription->fresh(), $saisie + [
        'type' => DeblocageAcces::VIREMENT_ANNONCE, 'motif' => 'Virement annoncé, ordre de virement fourni',
        'echeance' => '2026-11-30', 'justificatif_path' => PREUVE_VIREMENT,
    ], $par ?? $this->agent);
    $this->moratoire = fn (array $saisie = [], ?User $par = null) => ($this->service)()->accorder($this->inscription->fresh(), $saisie + [
        'type' => DeblocageAcces::MORATOIRE, 'motif' => 'Délai accordé sur demande écrite', 'echeance' => '2027-01-15',
    ], $par ?? $this->responsable);
    $this->etat = fn (): string => AccesEcampus::etat(Candidature::query()->findOrFail($this->dossier->id));
    $this->verser = fn (int $montant = 185000) => verserConfirme($this->inscription->fresh(), [
        'montant' => $montant, 'date_versement' => '2026-11-16', 'mode' => 'virement', 'reference' => 'VIR-'.Str::random(8), 'payeur' => 'auditeur',
    ], $this->encaisseur);
    $this->relancer = fn () => $this->artisan('scolarite:relancer-deblocages')->assertSuccessful();
});

describe('règle d\'accès', function (): void {
    it('reste bloqué sans paiement ni déblocage', function (): void {
        expect(($this->etat)())->toBe(AccesEcampus::BLOQUE)
            ->and(AccesEcampus::accesOuvert($this->dossier->fresh()))->toBeFalse();
    });

    it('ouvre l\'accès pendant un déblocage en cours : codes remettables, lien et lettre PDF, situation inchangée', function (): void {
        ($this->virement)();
        $c = Candidature::query()->findOrFail($this->dossier->id);

        expect(($this->etat)())->toBe(AccesEcampus::DISPONIBLE)
            ->and(FraisInscription::payes($c))->toBeTrue()
            ->and(IdentifiantsMoodle::remettables($c))->toBeTrue()
            ->and(LiensLettreAcces::possible($c))->toBeTrue()
            ->and(app(DocumentsAuditeurs::class)->lettreAccesPdf($c, $this->agent)['mime'])->toBe('application/pdf')
            // Un auditeur débloqué reste « non soldé » tant qu'il n'a pas payé.
            ->and(LibelleSituation::cle($this->inscription->fresh()))->toBe(LibelleSituation::NON_SOLDEE);
    });

    it('referme l\'accès à la révocation quand les droits ne sont pas soldés', function (): void {
        $d = ($this->virement)()->deblocage;

        ($this->service)()->revoquer($d, 'Virement rejeté par la banque', $this->responsable);

        expect(($this->etat)())->toBe(AccesEcampus::BLOQUE)
            ->and($d->fresh())->statut->toBe(DeblocageAcces::REVOQUE)
            ->clos_par->toBe($this->responsable->id)
            ->motif_cloture->toBe('Virement rejeté par la banque')
            ->and(Activity::query()->where('event', 'deblocage_revoque')->count())->toBe(1);
    });

    it('régularise le déblocage dès que les droits sont soldés, par un versement', function (): void {
        $d = ($this->virement)()->deblocage;

        ($this->verser)(100000);
        expect($d->fresh()->statut)->toBe(DeblocageAcces::EN_COURS);

        ($this->verser)(85000);
        expect($d->fresh())->statut->toBe(DeblocageAcces::REGULARISE)->clos_le->not->toBeNull()
            ->and(($this->etat)())->toBe(AccesEcampus::DISPONIBLE)
            ->and(Activity::query()->where('event', 'deblocage_regularise')->count())->toBe(1);
    });

    it('régularise aussi sur une exonération totale', function (): void {
        $d = ($this->moratoire)()->deblocage;

        app(RegistreVersements::class)->accorderRemise($this->inscription->fresh(), 1185000, 'Exonération', $this->responsable);

        expect($d->fresh()->statut)->toBe(DeblocageAcces::REGULARISE);
    });

    it('referme l\'accès d\'un auditeur exclu ou en abandon après son déblocage', function (string $statut): void {
        ($this->virement)();
        $this->dossier->auditeur->forceFill(['statut' => $statut])->save();

        expect(($this->etat)())->toBe(AccesEcampus::BLOQUE)
            ->and(IdentifiantsMoodle::remettables(Candidature::query()->findOrFail($this->dossier->id)))->toBeFalse();
    })->with([Auditeur::STATUT_EXCLU, Auditeur::STATUT_ABANDON]);

    it('ne rouvre pas un déblocage clos : on en accorde un nouveau', function (): void {
        $d = ($this->virement)()->deblocage;
        $v = ($this->verser)()->versement;
        app(RegistreVersements::class)->annuler($v, 'Erreur de saisie', $this->administrateur);

        expect($d->fresh()->statut)->toBe(DeblocageAcces::REGULARISE)
            ->and(($this->etat)())->toBe(AccesEcampus::BLOQUE);

        ($this->moratoire)();
        expect(($this->etat)())->toBe(AccesEcampus::DISPONIBLE)
            ->and(DeblocageAcces::query()->count())->toBe(2);
    });
});

describe('droits', function (): void {
    it('réserve le moratoire au responsable de scolarité', function (): void {
        expect(fn () => ($this->moratoire)([], $this->agent))->toThrow(HttpException::class);

        ($this->moratoire)();
        expect(DeblocageAcces::query()->sole()->type)->toBe(DeblocageAcces::MORATOIRE)
            // La prise en charge passe par un engagement (lot A2), ouvert à l'agent comme au responsable.
            ->and(DeblocagesAcces::typesPermis($this->agent))->toBe([DeblocageAcces::VIREMENT_ANNONCE => 'Virement annoncé', DeblocageAcces::PRISE_EN_CHARGE => 'Prise en charge'])
            ->and(array_keys(DeblocagesAcces::typesPermis($this->responsable)))->toBe([DeblocageAcces::VIREMENT_ANNONCE, DeblocageAcces::PRISE_EN_CHARGE, DeblocageAcces::MORATOIRE]);
    });

    it('refuse tout déblocage à qui n\'a aucun des deux droits', function (string $role): void {
        expect(fn () => ($this->virement)([], ($this->compte)($role)))->toThrow(HttpException::class);
    })->with(['service_financier', 'editor', 'receptionniste']);

    it('laisse l\'agent prolonger un virement annoncé une seule fois, de 30 jours au plus', function (): void {
        $d = ($this->virement)()->deblocage;
        $service = ($this->service)();

        expect(fn () => $service->prolonger($d, '2027-01-01', 'Banque en retard sur le virement', $this->agent))
            ->toThrow(InvalidArgumentException::class, '30 jours');

        $service->prolonger($d, '2026-12-30', 'Banque en retard sur le virement', $this->agent);
        expect($d->fresh())->echeance->toDateString()->toBe('2026-12-30')->prolongations->toBe(1)
            ->and(DeblocagesAcces::peutProlonger($d->fresh(), $this->agent))->toBeFalse()
            ->and(fn () => $service->prolonger($d->fresh(), '2027-01-05', 'Encore un peu de patience', $this->agent))
            ->toThrow(InvalidArgumentException::class, 'une seule fois');

        // Le responsable prolonge tout.
        $service->prolonger($d->fresh(), '2027-02-15', 'Accord du responsable de scolarité', $this->responsable);
        expect($d->fresh()->echeance->toDateString())->toBe('2027-02-15')
            ->and(Activity::query()->where('event', 'deblocage_prolonge')->count())->toBe(2);
    });

    it('ne laisse pas l\'agent prolonger un moratoire, ni révoquer', function (): void {
        $d = ($this->moratoire)()->deblocage;

        expect(DeblocagesAcces::peutProlonger($d, $this->agent))->toBeFalse()
            ->and(fn () => ($this->service)()->prolonger($d, '2027-01-20', 'Prolongation demandée', $this->agent))->toThrow(HttpException::class)
            ->and(fn () => ($this->service)()->revoquer($d, 'Révocation par l\'agent', $this->agent))->toThrow(HttpException::class);
    });

    it('laisse au seul responsable un nouveau déblocage après une révocation', function (): void {
        $d = ($this->virement)()->deblocage;
        ($this->service)()->revoquer($d, 'Virement rejeté par la banque', $this->responsable);

        $nouvellePreuve = ['justificatif_path' => 'scolarite/justificatifs/01JBBBBBBBBBBBBBBBBBBBBBBB.pdf'];
        expect(fn () => ($this->virement)($nouvellePreuve))->toThrow(InvalidArgumentException::class, 'révoqué');

        ($this->virement)($nouvellePreuve, $this->responsable);
        expect(DeblocageAcces::query()->enCours()->count())->toBe(1);
    });

    it('refuse une preuve déjà jointe à un versement ou à un autre déblocage', function (): void {
        Versement::factory()->create(['inscription_annuelle_id' => $this->inscription->id, 'montant' => 1000, 'justificatif_path' => PREUVE_VIREMENT]);

        expect(fn () => ($this->virement)())->toThrow(InvalidArgumentException::class, 'déjà jointe');
    });

    it('refuse une prolongation qui n\'allonge pas l\'échéance', function (): void {
        $d = ($this->virement)()->deblocage;

        expect(fn () => ($this->service)()->prolonger($d, '2026-11-25', 'Raccourcir le délai accordé', $this->responsable))
            ->toThrow(InvalidArgumentException::class, 'après l\'échéance actuelle');
    });
});

describe('refus', function (): void {
    it('refuse un dossier retiré', function (): void {
        $this->dossier->forceFill(['withdrawn_at' => now()])->save();

        expect(fn () => ($this->virement)())->toThrow(InvalidArgumentException::class, 'retiré');
    });

    it('refuse un auditeur exclu ou en abandon', function (string $statut): void {
        $this->dossier->auditeur->forceFill(['statut' => $statut])->save();

        expect(fn () => ($this->virement)())->toThrow(InvalidArgumentException::class, mb_strtolower(Auditeur::STATUTS[$statut]));
    })->with([Auditeur::STATUT_EXCLU, Auditeur::STATUT_ABANDON]);

    it('refuse une inscription déjà soldée', function (): void {
        ($this->verser)();

        expect(fn () => ($this->virement)())->toThrow(InvalidArgumentException::class, 'déjà soldés');
    });

    it('refuse un second déblocage en cours sur la même inscription', function (): void {
        ($this->virement)();

        expect(fn () => ($this->moratoire)())->toThrow(InvalidArgumentException::class, 'déjà en cours');
    });

    it('valide le motif, la preuve du virement et l\'échéance', function (array $saisie, string $message): void {
        expect(fn () => ($this->virement)($saisie, $this->responsable))->toThrow(InvalidArgumentException::class, $message);
    })->with([
        'motif trop court' => [['motif' => 'Virement'], 'au moins 10 caractères'],
        'sans preuve' => [['justificatif_path' => null], 'preuve du virement'],
        'chemin forgé' => [['justificatif_path' => 'signatures/president.png'], 'preuve du virement'],
        'échéance passée' => [['echeance' => '2026-11-16'], 'postérieure à aujourd\'hui'],
        'virement à plus de 60 jours' => [['echeance' => '2027-01-16'], '60 jours'],
        'cas inconnu' => [['type' => 'autre'], 'cas de la liste'],
    ]);

    it('exige l\'échéance d\'un moratoire et la borne à 180 jours', function (): void {
        expect(fn () => ($this->moratoire)(['echeance' => null]))->toThrow(InvalidArgumentException::class, 'échéance')
            ->and(fn () => ($this->moratoire)(['echeance' => '2027-06-01']))->toThrow(InvalidArgumentException::class, '180 jours');
    });

    it('propose 15 jours par défaut pour un virement annoncé', function (): void {
        $d = ($this->virement)(['echeance' => null])->deblocage;

        expect($d->echeance->toDateString())->toBe('2026-12-01')
            ->and(DeblocagesAcces::echeanceParDefaut(DeblocageAcces::VIREMENT_ANNONCE)?->toDateString())->toBe('2026-12-01');
    });

    it('n\'accorde pas de prise en charge hors engagement : elle passe par le rattachement (lot A2)', function (): void {
        expect(fn () => ($this->moratoire)(['type' => DeblocageAcces::PRISE_EN_CHARGE]))->toThrow(InvalidArgumentException::class, 'engagement');
    });

    it('garantit en base un seul déblocage en cours par inscription, et la preuve d\'un virement', function (): void {
        DeblocageAcces::factory()->create(['inscription_annuelle_id' => $this->inscription->id]);

        expect(fn () => DB::transaction(fn () => DeblocageAcces::factory()->moratoire()->create(['inscription_annuelle_id' => $this->inscription->id])))
            ->toThrow(UniqueConstraintViolationException::class)
            ->and(fn () => DB::transaction(fn () => DeblocageAcces::factory()->create(['justificatif_path' => null, 'statut' => DeblocageAcces::REVOQUE, 'inscription_annuelle_id' => $this->inscription->id])))
            ->toThrow(QueryException::class);

        // Un déblocage clos n'empêche pas d'en accorder un autre.
        DeblocageAcces::factory()->moratoire()->create(['inscription_annuelle_id' => $this->inscription->id, 'statut' => DeblocageAcces::REVOQUE]);
        expect(DeblocageAcces::query()->count())->toBe(2);
    });
});

describe('notification', function (): void {
    it('prévient l\'auditeur par SMS et par e-mail avec le lien vers sa lettre, sans prétendre l\'inscription soldée', function (): void {
        $resultat = ($this->virement)();

        expect($resultat->notification)->toBeTrue()
            ->and($this->sms)->toHaveCount(1)
            ->and($this->sms[0])->toContain('https://apply.pssfp.org/l/')
            ->and(Activity::query()->where('event', 'deblocage_accorde')->sole()->properties['type'])->toBe(DeblocageAcces::VIREMENT_ANNONCE);
        Mail::assertSent(NotificationCandidatMail::class, fn (NotificationCandidatMail $m): bool => $m->hasTo('auditeur@example.org')
            && str_contains($m->corpsMessage, '/l/')
            && str_contains($m->corpsMessage, 'avant le 30/11/2026')
            && ! str_contains($m->corpsMessage, 'est soldée'));
    });
});

describe('relances', function (): void {
    it('relance à l\'échéance dépassée, puis tous les 7 jours, jamais avant', function (): void {
        $d = ($this->virement)(['echeance' => '2026-11-20'])->deblocage;
        $this->sms = [];
        $a = function (int $jour): void {
            $this->travelTo(now('Africa/Douala')->setDate(2026, 11, $jour)->setTime(8, 0));
            ($this->relancer)();
        };

        $a(20);
        expect($this->sms)->toBe([]);

        $a(21);
        expect($this->sms)->toBe([RelancesDeblocages::texteSms(DeblocageAcces::VIREMENT_ANNONCE)])
            ->and($d->fresh())->relances->toBe(1)->derniere_relance_le->not->toBeNull();

        ($this->relancer)();
        $a(27);
        expect($this->sms)->toHaveCount(1);

        $a(28);
        expect($this->sms)->toHaveCount(2)
            ->and($d->fresh()->relances)->toBe(2)
            ->and(Activity::query()->where('event', 'deblocage_relance')->count())->toBe(2);
    });

    it('trace la relance dans l\'espace de l\'auditeur, SMS et e-mail', function (): void {
        ($this->virement)(['echeance' => '2026-11-20']);
        $this->travelTo(now('Africa/Douala')->setDate(2026, 11, 21)->setTime(8, 0));
        ($this->relancer)();

        $traces = CandidatureRelance::query()->where('cause', CandidatureRelance::CAUSE_DEBLOCAGE)->get();
        expect($traces->pluck('canal')->sort()->values()->all())->toBe(['email', 'sms'])
            ->and($traces->pluck('envoye_par')->unique()->all())->toBe([null])
            ->and($this->dossier->fresh()->messagesRecus->pluck('cause')->unique()->all())->toBe([CandidatureRelance::CAUSE_DEBLOCAGE]);
    });

    it('adapte le texte au cas du déblocage', function (): void {
        ($this->moratoire)(['echeance' => '2026-11-20']);
        $this->sms = [];
        $this->travelTo(now('Africa/Douala')->setDate(2026, 11, 21)->setTime(8, 0));
        ($this->relancer)();

        expect($this->sms)->toBe(['PSSFP : le delai accorde pour vos droits d\'inscription est depasse. Merci de regulariser aupres de la scolarite.']);
    });

    it('régularise en rattrapage un auditeur soldé au lieu de le relancer', function (): void {
        $d = ($this->virement)(['echeance' => '2026-11-20'])->deblocage;
        $this->sms = [];
        // Solde arrivé sans passer par le registre (reprise) : seule la tâche quotidienne le voit.
        Versement::factory()->create(['inscription_annuelle_id' => $this->inscription->id, 'montant' => 185000]);
        $this->travelTo(now('Africa/Douala')->setDate(2026, 11, 21)->setTime(8, 0));
        ($this->relancer)();

        expect($d->fresh()->statut)->toBe(DeblocageAcces::REGULARISE)->and($this->sms)->toBe([]);
    });

    it('régularise chaque jour tout déblocage soldé, même avant son échéance', function (): void {
        $d = ($this->virement)(['echeance' => '2026-12-15'])->deblocage;
        Versement::factory()->create(['inscription_annuelle_id' => $this->inscription->id, 'montant' => 185000]);
        ($this->relancer)();

        expect($d->fresh()->statut)->toBe(DeblocageAcces::REGULARISE);
    });

    it('ne relance ni un dossier retiré ni un auditeur exclu', function (): void {
        ($this->virement)(['echeance' => '2026-11-20']);
        $this->sms = [];
        $this->dossier->auditeur->forceFill(['statut' => Auditeur::STATUT_EXCLU])->save();
        $this->travelTo(now('Africa/Douala')->setDate(2026, 11, 21)->setTime(8, 0));
        ($this->relancer)();
        expect($this->sms)->toBe([]);

        $this->dossier->auditeur->forceFill(['statut' => Auditeur::STATUT_ACTIF])->save();
        $this->dossier->forceFill(['withdrawn_at' => now()])->save();
        ($this->relancer)();
        expect($this->sms)->toBe([])
            ->and(DeblocageAcces::query()->sole()->relances)->toBe(0);
    });

    it('planifie les relances chaque jour à 8 h, heure de Yaoundé', function (): void {
        $event = collect(app(Schedule::class)->events())->first(fn ($e): bool => str_contains((string) $e->command, 'scolarite:relancer-deblocages'));

        expect($event)->not->toBeNull()
            ->and($event->expression)->toBe('0 8 * * *')
            ->and($event->timezone)->toBe('Africa/Douala');
    });
});

describe('contrôle interne', function (): void {
    it('signale dans DEB_ECHUS les déblocages échus et non régularisés, et les ferme à la régularisation', function (): void {
        $d = ($this->virement)(['echeance' => '2026-11-20'])->deblocage;
        $supervision = app(Supervision::class);
        $controle = $supervision->controles()['DEB_ECHUS'];

        expect($controle->criticite())->toBe(Controle::MAJEUR)
            ->and($controle->delaiJours())->toBe(7)
            ->and($controle->responsable())->toBe('scolarite_agent')
            ->and(array_key_last($supervision->controles()))->toBe('SYS_CONTROLES');

        $supervision->executer(['DEB_ECHUS']);
        expect(AnomalieSysteme::query()->where('controle_code', 'DEB_ECHUS')->count())->toBe(0);

        $this->travelTo(now('Africa/Douala')->setDate(2026, 11, 21)->setTime(6, 0));
        $supervision->executer(['DEB_ECHUS']);
        $anomalie = AnomalieSysteme::query()->where('controle_code', 'DEB_ECHUS')->sole();
        expect($anomalie->cle)->toBe((string) $d->id)
            ->and($anomalie->detail['resume'])->toContain((string) $this->dossier->matricule)->toContain('Virement annoncé')->toContain('20/11/2026')
            ->and($anomalie->detail['resume'])->not->toContain((string) $this->dossier->nom);

        ($this->verser)();
        $supervision->executer(['DEB_ECHUS']);
        expect($anomalie->fresh()->statut)->toBe(AnomalieSysteme::CORRIGEE);
    });
});

describe('droits par migration', function (): void {
    it('accorde les deux droits par migration additive, sans retirer les autres droits des rôles', function (): void {
        $roles = collect(['super_admin', 'admin', 'scolarite_agent', 'scolarite_responsable', 'service_financier'])
            ->mapWithKeys(fn (string $r): array => [$r => Role::findByName($r, 'web')]);
        Permission::query()->whereIn('name', [DeblocagesAcces::PERMISSION, DeblocagesAcces::PERMISSION_EXCEPTIONNEL])->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $avant = $roles->map(fn (Role $r): int => $r->fresh()->permissions->count());

        $migration = require database_path('migrations/2026_11_29_100100_grant_scolarite_deblocage_permissions.php');
        $migration->up();
        $migration->up();

        $droits = $roles->map(fn (Role $r) => $r->fresh()->permissions->pluck('name'));
        expect($droits['scolarite_agent'])->toContain(DeblocagesAcces::PERMISSION)->not->toContain(DeblocagesAcces::PERMISSION_EXCEPTIONNEL)
            ->and($droits['scolarite_responsable'])->toContain(DeblocagesAcces::PERMISSION)->toContain(DeblocagesAcces::PERMISSION_EXCEPTIONNEL)
            ->and($droits['admin'])->toContain(DeblocagesAcces::PERMISSION_EXCEPTIONNEL)
            ->and($droits['super_admin'])->toContain(DeblocagesAcces::PERMISSION_EXCEPTIONNEL)
            ->and($droits['service_financier'])->not->toContain(DeblocagesAcces::PERMISSION)
            ->and($roles->map(fn (Role $r): int => $r->fresh()->permissions->count())->all())->toBe([
                'super_admin' => $avant['super_admin'] + 2, 'admin' => $avant['admin'] + 2, 'scolarite_agent' => $avant['scolarite_agent'] + 1,
                'scolarite_responsable' => $avant['scolarite_responsable'] + 2, 'service_financier' => $avant['service_financier'],
            ]);
    });

    it('déclare les deux droits dans la carte des modules et le seeder, en actions sensibles', function (): void {
        expect(ModulePermissionMap::sensitiveActions())->toHaveKeys([DeblocagesAcces::PERMISSION, DeblocagesAcces::PERMISSION_EXCEPTIONNEL])
            ->and($this->agent->can(DeblocagesAcces::PERMISSION))->toBeTrue()
            ->and($this->agent->can(DeblocagesAcces::PERMISSION_EXCEPTIONNEL))->toBeFalse()
            ->and($this->responsable->can(DeblocagesAcces::PERMISSION_EXCEPTIONNEL))->toBeTrue();
    });
});
