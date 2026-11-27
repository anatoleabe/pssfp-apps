<?php

declare(strict_types=1);

use App\Filament\Resources\AuditeurResource\Pages\ViewAuditeur;
use App\Filament\Resources\AuditeurResource\RelationManagers\VersementsRelationManager;
use App\Filament\Resources\DemandeAutorisationResource\Pages\ViewDemande;
use App\Filament\Resources\VersementResource\Pages\ListVersements;
use App\Models\AnnulationRecu;
use App\Models\BaremeScolarite;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\ConfirmationVersement;
use App\Models\CorrectionVersement;
use App\Models\DemandeAutorisation;
use App\Models\ImputationVersement;
use App\Models\Role;
use App\Models\User;
use App\Models\Versement;
use App\Services\Ecampus\ComptesEcampus;
use App\Services\ExportsRetenus\AttributionMatricules;
use App\Services\Messagerie\Demandes;
use App\Services\Messagerie\TypesDemande;
use App\Services\Scolarite\AccesEcampus;
use App\Services\Scolarite\ConfirmationsVersements;
use App\Services\Scolarite\CorrectionsVersements;
use App\Services\Scolarite\RecusVersements;
use App\Services\Scolarite\RegistreVersements;
use App\Services\Sms\SmsServiceInterface;
use App\Support\AppSettings;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
 * Correction d'un versement par contre-passation liée, sous-lot F4a (ADR-0015 ; spec module-4-scolarite-espace-financier
 * §7 et §8 ; décisions du 16/11/2026) : demande du service financier, décision d'un titulaire de l'annulation, une
 * transaction, pièce COR, reçus liés, confirmation reportée, jamais d'accès coupé à montant égal.
 */

uses()->group('scolarite');

const MIGRATION_CORRIGER = '2026_12_08_100300_grant_scolarite_versement_corriger.php';

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    config()->set('pssfp.candidature_app_url', 'https://apply.pssfp.org');
    Mail::fake();
    $this->sms = [];
    $this->mock(SmsServiceInterface::class, function ($mock): void {
        $mock->shouldReceive('send')->andReturnUsing(function (string $tel, string $texte): void {
            $this->sms[] = $texte;
        });
    });

    $this->compte = fn (string $role): User => tap(User::factory()->create(['email' => $role.'-'.Str::random(6).'@pssfp.local', 'name' => 'Agent '.$role]), fn (User $u) => $u->assignRole($role));

    $this->administrateur = ($this->compte)('admin');
    $this->financier = ($this->compte)('service_financier');
    $this->responsable = ($this->compte)('scolarite_responsable');
    $this->travelTo(now('Africa/Douala')->setDate(2026, 11, 10)->setTime(9, 0));
    AppSettings::set(RecusVersements::CLE_DEPUIS, now()->subDay()->toIso8601String());

    $campagne = CampagneCandidature::factory()->closed()->create(['nom' => ['fr' => 'Année académique 2026-2027'], 'promotion_numero' => 14]);
    BaremeScolarite::factory()->create(['annee_academique' => '2026-2027', 'niveau' => 'M1']);
    $dossier = fn (string $nom): Candidature => Candidature::factory()->forCampagne($campagne)->submitted()->create([
        'nom' => $nom, 'prenom' => 'Essai', 'statut' => Candidature::STATUT_ACCEPTE, 'phone_e164' => '+2376990'.random_int(10000, 99999),
        'email' => mb_strtolower($nom).'-'.Str::random(6).'@example.org', 'ecampus_statut' => ComptesEcampus::CREE,
    ]);
    $this->a = $dossier('Alpha');
    $this->b = $dossier('Beta');
    app(AttributionMatricules::class)->attribuer($campagne, '26', ($this->compte)('admin'));
    $this->a->refresh();
    $this->b->refresh();
    $this->droits = (int) $this->a->auditeur->inscriptionCourante->montant_inscription;

    $this->encaisser = fn (Candidature $c, array $champs = []): Versement => app(RegistreVersements::class)->encaisser($c->auditeur->inscriptionCourante, $champs + [
        'montant' => 100000, 'date_versement' => '2026-11-09', 'mode' => 'cremincam_agence', 'reference' => 'BOR-'.Str::random(6), 'payeur' => 'auditeur',
    ], $this->financier)->versement;
    $this->demander = fn (Versement $v, array $parametres, string $motif = 'Erreur de saisie au guichet du service financier'): DemandeAutorisation => app(Demandes::class)
        ->soumettre($this->financier, TypesDemande::CORRIGER_VERSEMENT, $v->id, $motif, null, $parametres);
    $this->decider = fn (DemandeAutorisation $d, array $options = [], ?User $decideur = null): string => app(Demandes::class)
        ->decider($decideur ?? $this->administrateur, $d, true, null, null, $options);
    $this->imputations = fn (Candidature $c): array => ImputationVersement::query()->where('auditeur_id', $c->auditeur->id)->whereNull('annule_at')
        ->get()->groupBy('inscription_annuelle_id')->map(fn ($l): int => (int) $l->sum('montant'))->all();
});

describe('permissions', function (): void {
    it('donne la demande au service financier seul, la décision aux titulaires de l\'annulation', function (): void {
        expect(CorrectionsVersements::peutDemander($this->financier))->toBeTrue()
            ->and(CorrectionsVersements::peutDemander($this->responsable))->toBeFalse()
            ->and(CorrectionsVersements::peutDecider($this->financier))->toBeFalse();
        // Règle du 25/11/2026 : l'administrateur seul décide ; le responsable de scolarité ne décide plus.
        expect(CorrectionsVersements::peutDecider($this->responsable))->toBeFalse();
        foreach (['admin', 'super_admin'] as $role) {
            expect(CorrectionsVersements::peutDecider(($this->compte)($role)))->toBeTrue($role);
        }
        expect(CorrectionsVersements::agitDirectement(($this->compte)('super_admin')))->toBeTrue()
            ->and(CorrectionsVersements::agitDirectement($this->financier))->toBeFalse();
    });

    it('accorde par migration additive, rejouable, et se défait', function (): void {
        Permission::query()->where('name', CorrectionsVersements::PERMISSION)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $migration = require database_path('migrations/'.MIGRATION_CORRIGER);
        $migration->up();
        $migration->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        expect(Role::findByName('service_financier', 'web')->hasPermissionTo(CorrectionsVersements::PERMISSION))->toBeTrue()
            ->and(Role::findByName('scolarite_responsable', 'web')->hasPermissionTo(CorrectionsVersements::PERMISSION))->toBeFalse();

        $migration->down();
        expect(Permission::query()->where('name', CorrectionsVersements::PERMISSION)->exists())->toBeFalse();
    });
});

describe('remplacement à montant égal', function (): void {
    it('annule, remplace, relie les reçus et numérote la pièce, sans rien changer au compte', function (): void {
        $v = ($this->encaisser)($this->a, ['reference' => 'BOR-FAUX']);
        $avant = ($this->imputations)($this->a);
        $ancienRecu = $v->recuEnVigueur();

        $d = ($this->demander)($v, ['nature' => 'remplacement', 'categorie' => 'reference', 'reference' => 'BOR-JUSTE']);
        expect($d->statut)->toBe(DemandeAutorisation::SOUMISE);
        $resultat = ($this->decider)($d);

        $c = CorrectionVersement::query()->sole();
        $nouveau = $c->versementRemplacant;
        $v->refresh();
        expect($resultat)->toStartWith('Correction COR-2026-00001 exécutée : reçu RCPT-2026-000002 émis')
            ->and($c->numero)->toBe('COR-2026-00001')
            ->and($c->demande_id)->toBe($d->id)
            ->and($c->demandee_par)->toBe($this->financier->id)
            ->and($c->executee_par)->toBe($this->administrateur->id)
            ->and($c->champs)->toEqual(['reference' => ['avant' => 'BOR-FAUX', 'apres' => 'BOR-JUSTE']])
            ->and($v->statut())->toBe(Versement::STATUT_REMPLACE)
            ->and($v->annule_par)->toBe($this->administrateur->id)
            ->and($nouveau->reference)->toBe('BOR-JUSTE')
            ->and($nouveau->montant)->toBe($v->montant)
            ->and($nouveau->saisi_par)->toBe($this->financier->id)
            ->and($nouveau->inscription_annuelle_id)->toBe($v->inscription_annuelle_id)
            ->and(AnnulationRecu::query()->sole()->numero)->toBe($ancienRecu->numero.'-A')
            ->and($c->recuRemplacant->remplace_recu_id)->toBe($ancienRecu->id)
            ->and($c->recuRemplacant->emis_par)->toBe($this->financier->id)
            ->and(($this->imputations)($this->a))->toBe($avant)
            ->and($d->refresh()->statut)->toBe(DemandeAutorisation::EXECUTEE)
            ->and(Activity::query()->where('event', 'versement_corrige')->sole()->properties['correction'])->toBe('COR-2026-00001');

        $this->artisan('scolarite:verifier-comptes')->assertSuccessful();
    });

    it('reporte d\'office la confirmation : l\'accès de l\'auditeur ne change jamais (équivalence)', function (): void {
        $v = ($this->encaisser)($this->a, ['montant' => $this->droits]);
        confirmerVersement($v);
        $this->a->refresh();
        $etatAvant = AccesEcampus::etat($this->a);
        $origine = ConfirmationVersement::query()->where('versement_id', $v->id)->sole();
        expect($etatAvant)->toBe(AccesEcampus::etat($this->a))
            ->and(AccesEcampus::accesOuvert($this->a))->toBeTrue();

        foreach (['reference' => 'BOR-NEUF', 'date_versement' => '2026-11-08', 'mode' => 'virement', 'payeur' => 'autre'] as $champ => $valeur) {
            $courant = Versement::query()->where('auditeur_id', $this->a->auditeur->id)->whereNull('annule_at')->sole();
            $prevision = app(CorrectionsVersements::class)->prevoir($courant, CorrectionsVersements::parametres($courant, ['nature' => 'remplacement', 'categorie' => 'autre', $champ => $valeur]));
            expect($prevision[0]['acces_avant'])->toBeTrue()->and($prevision[0]['acces_apres'])->toBeTrue();

            ($this->decider)(($this->demander)($courant, ['nature' => 'remplacement', 'categorie' => 'autre', $champ => $valeur]));

            expect(AccesEcampus::etat(Candidature::query()->findOrFail($this->a->id)))->toBe($etatAvant, "accès après la correction de {$champ}");
        }

        $remplacant = Versement::query()->where('auditeur_id', $this->a->auditeur->id)->whereNull('annule_at')->sole();
        $report = $remplacant->confirmation;
        expect($report->correction_id)->not->toBeNull()
            ->and($report->confirme_par)->toBe($origine->confirme_par)
            ->and($report->justificatif_path)->toBeNull()
            ->and($report->scan())->toBe($origine->justificatif_path)
            ->and(CorrectionVersement::query()->pluck('confirmation_reportee')->unique()->all())->toBe([CorrectionVersement::REPORT_OFFICE])
            ->and(CorrectionVersement::query()->count())->toBe(4);
    });
});

describe('montant ou auditeur changé sur un versement confirmé', function (): void {
    it('exige l\'attestation du décideur, sinon la décision est refusée et la demande attend', function (): void {
        $v = ($this->encaisser)($this->a, ['montant' => 50000]);
        confirmerVersement($v);
        $d = ($this->demander)($v, ['nature' => 'remplacement', 'categorie' => 'montant', 'montant' => 60000]);

        expect(fn () => ($this->decider)($d))->toThrow(InvalidArgumentException::class, 'J\'atteste');
        expect($d->refresh()->statut)->toBe(DemandeAutorisation::SOUMISE)
            ->and($d->decisions()->count())->toBe(0)
            ->and(CorrectionVersement::query()->count())->toBe(0);

        ($this->decider)($d, [CorrectionsVersements::OPTION_ATTESTATION => true]);
        $c = CorrectionVersement::query()->sole();
        expect($c->confirmation_reportee)->toBe(CorrectionVersement::REPORT_ATTESTE)
            ->and($c->versementRemplacant->montant)->toBe(60000)
            ->and($c->versementRemplacant->confirmation->confirme_par)->toBe($this->administrateur->id);
    });

    it('annonce et fait accepter la fermeture d\'un accès par une baisse de montant', function (): void {
        $v = ($this->encaisser)($this->a, ['montant' => $this->droits]);
        confirmerVersement($v);
        expect(AccesEcampus::accesOuvert($this->a->refresh()))->toBeTrue();
        $d = ($this->demander)($v, ['nature' => 'remplacement', 'categorie' => 'montant', 'montant' => $this->droits - 1000]);

        expect(app(CorrectionsVersements::class)->exigences($v, $d->parametres))->toBe([CorrectionsVersements::OPTION_ATTESTATION => true, CorrectionsVersements::OPTION_FERMETURE => true])
            ->and(fn () => ($this->decider)($d, [CorrectionsVersements::OPTION_ATTESTATION => true]))->toThrow(InvalidArgumentException::class, 'ferme l\'accès');

        ($this->decider)($d, [CorrectionsVersements::OPTION_ATTESTATION => true, CorrectionsVersements::OPTION_FERMETURE => true]);
        expect(AccesEcampus::accesOuvert(Candidature::query()->findOrFail($this->a->id)))->toBeFalse();
    });

    it('déplace le versement vers le bon auditeur, reçu au bon nom, comptes cohérents', function (): void {
        $v = ($this->encaisser)($this->a, ['montant' => 70000]);
        $d = ($this->demander)($v, ['nature' => 'remplacement', 'categorie' => 'auditeur', 'auditeur_id' => $this->b->auditeur->id]);
        ($this->decider)($d);

        $c = CorrectionVersement::query()->sole();
        expect($c->versementRemplacant->auditeur_id)->toBe($this->b->auditeur->id)
            ->and($c->versementRemplacant->inscription_annuelle_id)->toBe($this->b->auditeur->inscriptionCourante->id)
            ->and($c->recuRemplacant->matricule)->toBe($this->b->matricule)
            ->and(($this->imputations)($this->a))->toBe([])
            ->and(array_sum(($this->imputations)($this->b)))->toBe(70000);
        $this->artisan('scolarite:verifier-comptes')->assertSuccessful();
    });
});

describe('annulation sans remplacement', function (): void {
    it('passe par une demande et laisse une pièce d\'annulation', function (): void {
        $v = ($this->encaisser)($this->a);
        ($this->decider)(($this->demander)($v, ['nature' => 'annulation', 'categorie' => 'doublon']));

        $c = CorrectionVersement::query()->sole();
        expect($c->nature)->toBe(CorrectionVersement::ANNULATION)
            ->and($c->versement_remplacant_id)->toBeNull()
            ->and($c->annulation_recu_id)->not->toBeNull()
            ->and($v->refresh()->statut())->toBe(Versement::STATUT_ANNULE)
            ->and(($this->imputations)($this->a))->toBe([]);
    });

    it('reste un geste direct du responsable, avec sa catégorie et sa pièce', function (): void {
        $v = ($this->encaisser)($this->a);
        $c = app(CorrectionsVersements::class)->annulerDirectement($v, 'doublon', 'Doublon constaté au guichet de la scolarité', $this->administrateur);

        expect($c->demande_id)->toBeNull()
            ->and($c->demandee_par)->toBe($this->administrateur->id)
            ->and($c->categorie)->toBe('doublon')
            ->and($v->refresh()->estAnnule())->toBeTrue()
            ->and(fn () => app(CorrectionsVersements::class)->annulerDirectement(($this->encaisser)($this->b), 'doublon', 'Doublon constaté au guichet', $this->financier))
            ->toThrow(HttpException::class);
    });
});

describe('refus et garde-fous', function (): void {
    it('refuse un remplacement sans changement, un versement annulé, une seconde demande en cours', function (): void {
        $v = ($this->encaisser)($this->a, ['reference' => 'BOR-UN']);

        expect(fn () => ($this->demander)($v, ['nature' => 'remplacement', 'categorie' => 'reference', 'reference' => 'bor-un']))
            ->toThrow(InvalidArgumentException::class, 'ne change rien');

        ($this->demander)($v, ['nature' => 'remplacement', 'categorie' => 'reference', 'reference' => 'BOR-DEUX']);
        expect(fn () => ($this->demander)($v, ['nature' => 'annulation', 'categorie' => 'doublon']))->toThrow(InvalidArgumentException::class, 'déjà en cours');

        $annule = ($this->encaisser)($this->b);
        app(RegistreVersements::class)->annuler($annule, 'Doublon de saisie', $this->administrateur);
        expect(fn () => ($this->demander)($annule->refresh(), ['nature' => 'annulation', 'categorie' => 'doublon']))->toThrow(InvalidArgumentException::class);
    });

    it('ne laisse jamais le demandeur décider, et refuse la demande à qui n\'encaisse pas', function (): void {
        $v = ($this->encaisser)($this->a);
        $d = ($this->demander)($v, ['nature' => 'annulation', 'categorie' => 'doublon']);

        expect(fn () => ($this->decider)($d, [], $this->financier))->toThrow(InvalidArgumentException::class)
            ->and(TypesDemande::peutViser($this->responsable, TypesDemande::CORRIGER_VERSEMENT))->toBeFalse()
            ->and(TypesDemande::peutViser(($this->compte)('scolarite_agent'), TypesDemande::CORRIGER_VERSEMENT))->toBeFalse();
    });

    it('suspend la confirmation et la relance tant qu\'une correction est demandée', function (): void {
        $v = ($this->encaisser)($this->a);
        $d = ($this->demander)($v, ['nature' => 'remplacement', 'categorie' => 'reference', 'reference' => 'BOR-AUTRE']);
        $agent = ($this->compte)('scolarite_agent');

        expect(ConfirmationsVersements::raisonRefus($v, $agent))->toContain($d->numero)
            ->and(Versement::query()->sansCorrectionEnCours()->pluck('id')->all())->toBe([]);
    });

    it('fait échouer l\'exécution si le versement a changé depuis la demande', function (): void {
        $v = ($this->encaisser)($this->a);
        $d = ($this->demander)($v, ['nature' => 'remplacement', 'categorie' => 'reference', 'reference' => 'BOR-AUTRE']);
        app(RecusVersements::class)->annuler($v->recuEnVigueur(), 'Reçu annulé entre-temps', $this->administrateur);

        expect(($this->decider)($d))->toContain('Échec de l\'exécution')
            ->and($d->refresh()->statut)->toBe(DemandeAutorisation::ECHEC)
            ->and(CorrectionVersement::query()->count())->toBe(0)
            ->and($v->refresh()->estAnnule())->toBeFalse();
    });

    it('fait échouer l\'exécution si le demandeur a perdu le droit d\'émettre le reçu', function (): void {
        $v = ($this->encaisser)($this->a);
        $d = ($this->demander)($v, ['nature' => 'remplacement', 'categorie' => 'montant', 'montant' => 120000]);
        Role::findByName('service_financier', 'web')->revokePermissionTo(RecusVersements::PERMISSION_EMETTRE);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        expect(($this->decider)($d))->toContain('Échec de l\'exécution')
            ->and($v->refresh()->estAnnule())->toBeFalse();
    });
});

describe('base de données', function (): void {
    it('rend la pièce immuable et numérote sans trou après une transaction annulée', function (): void {
        $v = ($this->encaisser)($this->a);
        DB::beginTransaction();
        app(CorrectionsVersements::class)->annulerDirectement($v, 'doublon', 'Doublon annulé puis défait par la transaction', $this->administrateur);
        DB::rollBack();
        $c = app(CorrectionsVersements::class)->annulerDirectement($v->refresh(), 'doublon', 'Doublon constaté au guichet de la scolarité', $this->administrateur);

        expect($c->numero)->toBe('COR-2026-00001');
        foreach (['UPDATE corrections_versements SET motif = \'Modification interdite\'', 'DELETE FROM corrections_versements'] as $sql) {
            DB::beginTransaction();
            try {
                expect(fn () => DB::statement($sql))->toThrow(QueryException::class, 'immuable');
            } finally {
                DB::rollBack();
            }
        }
    });

    it('se défait tant qu\'aucune correction n\'existe, et refuse ensuite', function (): void {
        $migration = require database_path('migrations/2026_12_08_100200_create_corrections_versements_tables.php');
        $migration->down();
        expect(Schema::hasTable('corrections_versements'))->toBeFalse()
            ->and(Schema::hasColumn('recus_versements', 'remplace_recu_id'))->toBeFalse();
        $migration->up();

        app(CorrectionsVersements::class)->annulerDirectement(($this->encaisser)($this->a), 'doublon', 'Doublon constaté au guichet de la scolarité', $this->administrateur);
        expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'pièces');
    });

    it('ne prend aucun scan propre pour une confirmation reportée', function (): void {
        DB::beginTransaction();
        try {
            $v = ($this->encaisser)($this->a);
            expect(fn () => DB::table('confirmations_versements')->insert(['versement_id' => $v->id, 'confirme_par' => $this->responsable->id,
                'confirme_at' => now(), 'attestation' => false, 'reprise' => false, 'created_at' => now()]))->toThrow(QueryException::class);
        } finally {
            DB::rollBack();
        }
    });
});

describe('correction directe du super administrateur', function (): void {
    it('corrige sans demande, en son nom des deux côtés', function (): void {
        $super = ($this->compte)('super_admin');
        $v = ($this->encaisser)($this->a, ['reference' => 'BOR-SA']);
        $c = app(CorrectionsVersements::class)->executer($v, CorrectionsVersements::parametres($v, ['nature' => 'remplacement', 'categorie' => 'reference', 'reference' => 'BOR-SA2']),
            'Correction directe par le super administrateur', $super, $super);

        expect($c->demande_id)->toBeNull()
            ->and($c->versementRemplacant->saisi_par)->toBe($super->id)
            ->and($c->recuRemplacant)->not->toBeNull();
    });
});

describe('écrans', function (): void {
    it('laisse le service financier demander la correction depuis la liste des versements', function (): void {
        $v = ($this->encaisser)($this->a, ['reference' => 'BOR-ECRAN']);

        $this->actingAs($this->financier);
        $this->livewire(ListVersements::class)
            ->assertTableActionVisible('corriger', $v)
            ->assertTableActionHidden('annuler', $v)
            ->callTableAction('corriger', $v, data: ['nature' => 'remplacement', 'categorie' => 'reference', 'reference' => 'BOR-ECRAN-2',
                'motif' => 'Référence mal recopiée depuis le bordereau'])
            ->assertHasNoTableActionErrors();

        $d = DemandeAutorisation::query()->sole();
        expect($d->type)->toBe(TypesDemande::CORRIGER_VERSEMENT)
            ->and($d->entite_id)->toBe($v->id)
            ->and($d->parametres['champs']['reference']['apres'])->toBe('BOR-ECRAN-2');

        // La demande en cours se vérifie à l'ouverture de l'action, pas dans la liste (pas de requête par ligne).
        $this->livewire(ListVersements::class)->mountTableAction('corriger', $v)->assertNotified('Correction impossible');
        $this->actingAs(($this->compte)('scolarite_agent'));
        $this->livewire(VersementsRelationManager::class, ['ownerRecord' => $this->a->auditeur, 'pageClass' => ViewAuditeur::class])
            ->assertTableActionHidden('corriger', $v);
    });

    it('fait approuver la demande dans l\'écran des demandes, avec l\'attestation exigée', function (): void {
        $v = ($this->encaisser)($this->a, ['montant' => 50000]);
        confirmerVersement($v);
        $d = ($this->demander)($v, ['nature' => 'remplacement', 'categorie' => 'montant', 'montant' => 55000]);

        $this->actingAs($this->administrateur);
        $this->livewire(ViewDemande::class, ['record' => $d->getRouteKey()])
            ->mountAction('approuver')
            ->assertSee('accès eCampus')
            ->setActionData([CorrectionsVersements::OPTION_ATTESTATION => false])
            ->callMountedAction()
            ->assertHasActionErrors([CorrectionsVersements::OPTION_ATTESTATION]);

        $this->livewire(ViewDemande::class, ['record' => $d->getRouteKey()])
            ->callAction('approuver', data: [CorrectionsVersements::OPTION_ATTESTATION => true])
            ->assertHasNoActionErrors();

        expect($d->refresh()->statut)->toBe(DemandeAutorisation::EXECUTEE)
            ->and(CorrectionVersement::query()->sole()->versementRemplacant->montant)->toBe(55000);
    });

    it('fait annuler directement par l\'administrateur depuis la fiche, avec une catégorie', function (): void {
        $v = ($this->encaisser)($this->a);

        $this->actingAs($this->administrateur);
        $this->livewire(VersementsRelationManager::class, ['ownerRecord' => $this->a->auditeur, 'pageClass' => ViewAuditeur::class])
            ->callTableAction('annuler', $v, data: ['categorie' => 'doublon', 'motif' => 'Doublon de saisie au guichet'])
            ->assertHasNoTableActionErrors()
            ->assertTableActionHidden('corriger', $v);

        expect(CorrectionVersement::query()->sole()->categorie)->toBe('doublon')
            ->and($v->refresh()->annule_motif)->toContain('Doublon de saisie au guichet');
    });
});

describe('suites de la relecture', function (): void {
    it('réserve l\'attestation à qui peut confirmer les versements, en erreur de décision', function (): void {
        $v = ($this->encaisser)($this->a, ['montant' => 50000]);
        confirmerVersement($v);
        $d = ($this->demander)($v, ['nature' => 'remplacement', 'categorie' => 'montant', 'montant' => 60000]);
        $sansConfirmer = ($this->compte)('caisse');
        $sansConfirmer->givePermissionTo(CorrectionsVersements::PERMISSION_DECIDER);

        expect(fn () => ($this->decider)($d, [CorrectionsVersements::OPTION_ATTESTATION => true], $sansConfirmer))
            ->toThrow(InvalidArgumentException::class, 'scolarite.versement_confirmer');
        expect($d->refresh()->statut)->toBe(DemandeAutorisation::SOUMISE)
            ->and($d->decisions()->count())->toBe(0)
            ->and(fn () => app(CorrectionsVersements::class)->executer($v, $d->parametres, $d->motif, $this->financier, $sansConfirmer,
                [CorrectionsVersements::OPTION_ATTESTATION => true], $d))->toThrow(InvalidArgumentException::class, 'scolarite.versement_confirmer');
    });

    it('fait passer le super administrateur par une demande dès qu\'une attestation est exigée', function (): void {
        $super = ($this->compte)('super_admin');
        $v = ($this->encaisser)($this->a, ['montant' => 50000]);
        confirmerVersement($v);
        $parametres = CorrectionsVersements::parametres($v, ['nature' => 'remplacement', 'categorie' => 'montant', 'montant' => 40000]);

        expect(fn () => app(CorrectionsVersements::class)->executer($v, $parametres, 'Montant mal saisi au guichet', $super, $super,
            [CorrectionsVersements::OPTION_ATTESTATION => true, CorrectionsVersements::OPTION_FERMETURE => true]))->toThrow(InvalidArgumentException::class, 'demande');

        $this->actingAs($super);
        $this->livewire(ListVersements::class)
            ->callTableAction('corriger', $v, data: ['nature' => 'remplacement', 'categorie' => 'montant', 'montant' => 40000, 'motif' => 'Montant mal saisi au guichet'])
            ->assertHasNoTableActionErrors();
        expect(DemandeAutorisation::query()->sole()->demandeur_id)->toBe($super->id)
            ->and(CorrectionVersement::query()->count())->toBe(0);
    });

    it('ne lance aucune requête de compte ni de demande par ligne de la liste', function (): void {
        foreach (range(1, 4) as $k) {
            ($this->encaisser)($k % 2 === 0 ? $this->a : $this->b);
        }
        $this->actingAs($this->financier);
        DB::enableQueryLog();
        $this->livewire(ListVersements::class)->assertSuccessful();
        $requetes = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        expect($requetes->filter(fn (string $q): bool => str_contains($q, 'demandes_autorisation') || str_contains($q, 'from "imputations_versements"'))->all())->toBe([]);
    });

    it('donne au décideur la raison d\'une correction devenue impossible', function (): void {
        $v = ($this->encaisser)($this->a);
        $d = ($this->demander)($v, ['nature' => 'remplacement', 'categorie' => 'auditeur', 'auditeur_id' => $this->b->auditeur->id]);
        $this->b->forceFill(['withdrawn_at' => now()])->save();

        expect(fn () => app(CorrectionsVersements::class)->prevoir($v, $d->parametres))->toThrow(InvalidArgumentException::class, 'retiré');
        $this->actingAs($this->administrateur);
        $this->livewire(ViewDemande::class, ['record' => $d->getRouteKey()])->mountAction('approuver')->assertSee('Correction impossible en l\'état');
    });

    it('cite sur le nouveau reçu un reçu déjà annulé auparavant', function (): void {
        $v = ($this->encaisser)($this->a, ['reference' => 'BOR-AVANT']);
        $ancien = $v->recuEnVigueur();
        app(RecusVersements::class)->annuler($ancien, 'Nom de l\'auditeur corrigé', $this->administrateur);
        ($this->decider)(($this->demander)($v->refresh(), ['nature' => 'remplacement', 'categorie' => 'reference', 'reference' => 'BOR-APRES']));

        expect(CorrectionVersement::query()->sole()->recuRemplacant->remplace_recu_id)->toBe($ancien->id);
    });

    it('joint au remplaçant le scan déposé avec la demande', function (): void {
        $v = ($this->encaisser)($this->a);
        $scan = Versement::DOSSIER_JUSTIFICATIFS.'/'.Str::ulid().'.pdf';
        ($this->decider)(($this->demander)($v, ['nature' => 'remplacement', 'categorie' => 'reference', 'reference' => 'BOR-SCAN', 'justificatif_path' => $scan]));

        expect(CorrectionVersement::query()->sole()->versementRemplacant->justificatif_path)->toBe($scan);
    });

    it('refuse le retour arrière dès qu\'un reçu en remplace un autre, même sans correction', function (): void {
        $v = ($this->encaisser)($this->a);
        app(RecusVersements::class)->annuler($v->recuEnVigueur(), 'Nom de l\'auditeur corrigé', $this->administrateur);
        $nouveau = app(RecusVersements::class)->emettre($v->refresh(), $this->financier);
        expect($nouveau->remplace_recu_id)->not->toBeNull();

        $migration = require database_path('migrations/2026_12_08_100200_create_corrections_versements_tables.php');
        expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'reçus remplaçants');
    });

    it('ne cite jamais sur un reçu le reçu d\'un autre auditeur', function (): void {
        $v = ($this->encaisser)($this->a);
        $ancien = $v->recuEnVigueur();
        ($this->decider)(($this->demander)($v, ['nature' => 'remplacement', 'categorie' => 'auditeur', 'auditeur_id' => $this->b->auditeur->id]));
        $c = CorrectionVersement::query()->sole();
        $recus = app(RecusVersements::class);

        $nouveau = view('pdf.recu-versement', $recus->donneesPdf($c->recuRemplacant))->render();
        $annule = view('pdf.recu-versement', $recus->donneesPdf($ancien->refresh()))->render();
        expect($nouveau)->toContain($c->numero)->not->toContain($ancien->numero)
            ->and($annule)->toContain($c->numero)->not->toContain($c->recuRemplacant->numero);
    });

    it('verrouille ensemble les deux auditeurs, par id croissant, avant toute écriture (corrections croisées)', function (): void {
        [$petit, $grand] = $this->a->auditeur->id < $this->b->auditeur->id ? [$this->a, $this->b] : [$this->b, $this->a];
        $deGrand = ($this->encaisser)($grand, ['montant' => 30000]);
        $dePetit = ($this->encaisser)($petit, ['montant' => 40000]);
        $dGrand = ($this->demander)($deGrand, ['nature' => 'remplacement', 'categorie' => 'auditeur', 'auditeur_id' => $petit->auditeur->id]);
        $dPetit = ($this->demander)($dePetit, ['nature' => 'remplacement', 'categorie' => 'auditeur', 'auditeur_id' => $grand->auditeur->id]);

        foreach ([$dGrand, $dPetit] as $d) {
            $requetes = [];
            DB::listen(function ($q) use (&$requetes): void {
                $requetes[] = ['sql' => $q->sql, 'liaisons' => $q->bindings];
            });
            ($this->decider)($d);
            $verrous = collect($requetes)->filter(fn (array $r): bool => str_contains($r['sql'], 'from "auditeurs"') && str_contains($r['sql'], 'for update') && str_contains($r['sql'], 'order by'));
            $premiere = collect($requetes)->search(fn (array $r): bool => str_starts_with(mb_strtolower($r['sql']), 'update "versements"'));
            $verrou = $verrous->keys()->first();

            expect($verrou)->not->toBeNull()
                ->and($verrou)->toBeLessThan($premiere)
                // Identifiants écrits dans la requête (whereKey), dans l'ordre croissant, triés par id.
                ->and($verrous->first()['sql'])->toContain('in ('.$petit->auditeur->id.', '.$grand->auditeur->id.')')->toContain('order by "id" asc');
        }
        $this->artisan('scolarite:verifier-comptes')->assertSuccessful();
    });
});
