<?php

declare(strict_types=1);

use App\Filament\Resources\AuditeurResource\Pages\ViewAuditeur;
use App\Filament\Resources\DemandeAutorisationResource\Pages\ViewDemande;
use App\Mail\NotificationCandidatMail;
use App\Models\Auditeur;
use App\Models\BaremeScolarite;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\DeblocageAcces;
use App\Models\DemandeAutorisation;
use App\Models\Message;
use App\Models\User;
use App\Services\Ecampus\ComptesEcampus;
use App\Services\ExportsRetenus\AttributionMatricules;
use App\Services\Messagerie\Demandes;
use App\Services\Messagerie\TypesDemande;
use App\Services\Scolarite\AccesEcampus;
use App\Services\Scolarite\RegistreVersements;
use App\Services\Sms\SmsServiceInterface;
use App\Support\CandidatureObjectStorage;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

uses()->group('scolarite', 'acces-anticipe', 'messagerie');

/*
 * Demande de moratoire (spec module 4, lot A3, §10) : l'agent de scolarité
 * demande un moratoire au responsable par le circuit des demandes du lot 14c ;
 * l'approbation crée le moratoire au nom du responsable.
 */

const PIECE_DEMANDE_MORATOIRE = 'scolarite/justificatifs/01JCCCCCCCCCCCCCCCCCCCCCCC.pdf';

beforeEach(function (): void {
    config()->set('pssfp.candidature_app_url', 'https://apply.pssfp.org');
    config()->set('pssfp.filament.require_2fa', false);
    Storage::fake(CandidatureObjectStorage::DISK);
    $this->seed([PaysSeeder::class, RegionsCamerounSeeder::class, DepartementsCamerounSeeder::class, RolePermissionSeeder::class]);
    $this->travelTo(now('Africa/Douala')->setDate(2026, 11, 16)->setTime(9, 0));
    $this->compte = fn (string $role): User => tap(
        User::factory()->create(['email' => $role.'-'.Str::random(6).'@pssfp.local', 'name' => 'Compte '.$role.' '.Str::random(3)]),
        fn (User $u) => $u->assignRole($role),
    );
    $this->agent = ($this->compte)('scolarite_agent');
    $this->encaisseur = ($this->compte)('service_financier');
    $this->responsable = ($this->compte)('scolarite_responsable');
    $this->campagne = CampagneCandidature::factory()->closed()->create([
        'nom' => ['fr' => 'Année académique 2026-2027'], 'promotion_numero' => 14,
        'parametres_lettres' => ['date_lettres' => '2026-11-04'],
    ]);
    BaremeScolarite::factory()->create(['annee_academique' => '2026-2027', 'niveau' => 'M1']);
    $this->dossier = Candidature::factory()->forCampagne($this->campagne)->submitted()->create([
        'nom' => 'Essai', 'prenom' => 'Fictif', 'statut' => Candidature::STATUT_ACCEPTE, 'phone_e164' => '+237699007001',
        'email' => 'auditeur-a3@example.org', 'date_naissance' => '1990-04-12', 'ecampus_statut' => ComptesEcampus::CREE,
    ]);
    app(AttributionMatricules::class)->attribuer($this->campagne, '26', ($this->compte)('admin'));
    $this->dossier->refresh();
    $this->auditeur = $this->dossier->auditeur;
    $this->inscription = $this->auditeur->inscriptionCourante;

    $this->sms = [];
    $this->mock(SmsServiceInterface::class, function ($mock): void {
        $mock->shouldReceive('send')->andReturnUsing(function (string $tel, string $texte): void {
            $this->sms[] = $texte;
        });
    });
    Mail::fake();

    $this->demandes = app(Demandes::class);
    $this->demander = fn (array $parametres = [], ?User $par = null) => $this->demandes->soumettre(
        $par ?? $this->agent, TypesDemande::ACCORDER_MORATOIRE, $this->inscription->id,
        'Demande écrite de l\'auditeur : salaire versé en fin de mois', null,
        $parametres + ['echeance' => '2027-01-15', 'justificatif_path' => PIECE_DEMANDE_MORATOIRE],
    );
    $this->etat = fn (): string => AccesEcampus::etat(Candidature::query()->findOrFail($this->dossier->id));
    $this->verser = fn () => app(RegistreVersements::class)->enregistrer($this->inscription->fresh(), [
        'montant' => 185000, 'date_versement' => '2026-11-16', 'mode' => 'virement', 'reference' => 'VIR-'.Str::random(8), 'payeur' => 'auditeur',
    ], $this->encaisseur);
    $this->page = fn () => $this->livewire(ViewAuditeur::class, ['record' => $this->auditeur->getRouteKey()]);
});

describe('soumission', function (): void {
    it('laisse l\'agent demander un moratoire au responsable, sans rien accorder', function (): void {
        $d = ($this->demander)();

        expect($d->fresh())
            ->type->toBe(TypesDemande::ACCORDER_MORATOIRE)
            ->entite_id->toBe($this->inscription->id)
            ->statut->toBe(DemandeAutorisation::SOUMISE)
            ->empreinte->toBeNull()
            ->parametres->toBe(['echeance' => '2027-01-15', 'justificatif_path' => PIECE_DEMANDE_MORATOIRE])
            ->and($d->objet)->toContain((string) $this->dossier->matricule)->toContain('2026-2027')
            ->and($d->echeance->diffInHours($d->soumise_le, true))->toEqual(48.0)
            ->and($this->demandes->decideurs($d))->toContain($this->responsable->id)->not->toContain($this->agent->id)
            ->and(DeblocageAcces::query()->count())->toBe(0)
            ->and(($this->etat)())->toBe(AccesEcampus::BLOQUE)
            ->and(Activity::query()->where('event', 'demande_soumise')->count())->toBe(1);
        $message = Message::query()->where('categorie', Message::DEMANDE)->sole();
        expect($message->priorite)->toBe(Message::HAUTE)
            ->and($message->corps)->toContain('Échéance proposée : 15/01/2027')->toContain('demande écrite de l\'auditeur jointe')
            ->and($message->destinataires()->pluck('user_id')->all())->toContain($this->responsable->id);
    });

    it('ferme la demande à qui accorde directement et à qui ne débloque pas', function (string $role, string $message): void {
        expect(fn () => ($this->demander)([], ($this->compte)($role)))->toThrow(InvalidArgumentException::class, $message)
            ->and(DemandeAutorisation::query()->count())->toBe(0);
    })->with([
        'responsable de scolarité' => ['scolarite_responsable', 'déjà ce droit'],
        'admin' => ['admin', 'déjà ce droit'],
        'service financier' => ['service_financier', 'pas ouvert'],
    ]);

    it('reste hors de l\'écran général des demandes : seule la fiche saisit l\'échéance', function (): void {
        expect(TypesDemande::peutViser($this->agent, TypesDemande::ACCORDER_MORATOIRE))->toBeTrue()
            ->and(TypesDemande::libellesPour($this->agent))->not->toHaveKey(TypesDemande::ACCORDER_MORATOIRE)
            ->and(TypesDemande::libellesPour($this->agent))->toHaveKey(TypesDemande::VALIDER_AUTRE)
            ->and(fn () => $this->demandes->soumettre($this->agent, TypesDemande::ACCORDER_MORATOIRE, $this->inscription->id, 'Demande sans échéance proposée'))
            ->toThrow(InvalidArgumentException::class, 'échéance');
    });

    it('valide l\'échéance proposée et la pièce', function (array $parametres, string $message): void {
        expect(fn () => ($this->demander)($parametres))->toThrow(InvalidArgumentException::class, $message)
            ->and(DemandeAutorisation::query()->count())->toBe(0);
    })->with([
        'au-delà de 180 jours' => [['echeance' => '2027-06-01'], '180 jours'],
        'passée' => [['echeance' => '2026-11-16'], 'postérieure à aujourd\'hui'],
        'absente' => [['echeance' => null], 'échéance'],
        'date illisible' => [['echeance' => 'demain matin'], 'Date invalide'],
        'chemin forgé' => [['justificatif_path' => 'signatures/president.png'], 'pièce jointe est invalide'],
    ]);

    it('refuse une seconde demande en attente sur la même inscription', function (): void {
        ($this->demander)();

        expect(fn () => ($this->demander)(['justificatif_path' => null], ($this->compte)('scolarite_agent')))
            ->toThrow(InvalidArgumentException::class, 'déjà en cours')
            ->and(DemandeAutorisation::query()->count())->toBe(1);
    });

    it('refuse la demande sur un dossier soldé, retiré, exclu ou déjà débloqué', function (Closure $situation, string $message): void {
        $situation->call($this);

        expect(fn () => ($this->demander)())->toThrow(InvalidArgumentException::class, $message)
            ->and(DemandeAutorisation::query()->count())->toBe(0);
    })->with([
        // Closures typées : Pest les passe telles quelles, le test les exécute après beforeEach.
        'soldé' => [fn () => ($this->verser)(), 'déjà soldés'],
        'retiré' => [fn () => $this->dossier->forceFill(['withdrawn_at' => now()])->save(), 'retiré'],
        'exclu' => [fn () => $this->auditeur->forceFill(['statut' => Auditeur::STATUT_EXCLU])->save(), 'exclu'],
        'déjà débloqué' => [fn () => DeblocageAcces::factory()->create(['inscription_annuelle_id' => $this->inscription->id]), 'déjà en cours'],
    ]);
});

describe('décision', function (): void {
    it('accorde le moratoire au nom du responsable qui approuve, ouvre l\'accès et prévient l\'auditeur', function (): void {
        $d = ($this->demander)();

        $resultat = $this->demandes->decider($this->responsable, $d, true, 'Accord du responsable');

        expect($resultat)->toBe('Moratoire accordé jusqu\'au 15/01/2027 ; auditeur prévenu par SMS et par e-mail')
            ->and($d->refresh())->statut->toBe(DemandeAutorisation::EXECUTEE)->resultat->toBe($resultat)
            ->and(DeblocageAcces::query()->sole())
            ->type->toBe(DeblocageAcces::MORATOIRE)
            ->statut->toBe(DeblocageAcces::EN_COURS)
            ->accorde_par->toBe($this->responsable->id)
            ->justificatif_path->toBe(PIECE_DEMANDE_MORATOIRE)
            ->motif->toBe('Demande '.$d->numero.' : Demande écrite de l\'auditeur : salaire versé en fin de mois')
            ->and(DeblocageAcces::query()->sole()->echeance->toDateString())->toBe('2027-01-15')
            ->and(($this->etat)())->toBe(AccesEcampus::DISPONIBLE)
            ->and($this->sms)->toHaveCount(1)
            ->and($this->sms[0])->toContain('https://apply.pssfp.org/l/')
            ->and(Activity::query()->where('event', 'deblocage_accorde')->sole()->causer_id)->toBe($this->responsable->id)
            ->and(Message::query()->where('categorie', Message::DECISION)->sole()->destinataires()->pluck('user_id')->all())->toBe([$this->agent->id]);
        Mail::assertSent(NotificationCandidatMail::class, fn (NotificationCandidatMail $m): bool => $m->hasTo('auditeur-a3@example.org')
            && str_contains($m->corpsMessage, 'avant le 15/01/2027'));
    });

    it('ne laisse décider que qui accorde un moratoire', function (string $role): void {
        $d = ($this->demander)();
        $decideur = ($this->compte)($role);

        expect($this->demandes->peutDecider($decideur, $d))->toBeFalse()
            ->and(fn () => $this->demandes->decider($decideur, $d, true))->toThrow(InvalidArgumentException::class, 'droit requis')
            ->and($d->refresh()->statut)->toBe(DemandeAutorisation::SOUMISE)
            ->and(DeblocageAcces::query()->count())->toBe(0);
    })->with(['scolarite_agent', 'service_financier', 'editor']);

    it('ne crée rien sur un rejet', function (): void {
        $d = ($this->demander)();

        expect($this->demandes->decider($this->responsable, $d, false, 'Aucune pièce de l\'employeur au dossier'))->toBe('Demande rejetée')
            ->and($d->refresh()->statut)->toBe(DemandeAutorisation::REJETEE)
            ->and(DeblocageAcces::query()->count())->toBe(0)
            ->and(($this->etat)())->toBe(AccesEcampus::BLOQUE)
            ->and($this->sms)->toBe([]);
    });

    it('refait les contrôles à l\'exécution et clôt la demande en échec si l\'état a changé', function (Closure $situation, string $message): void {
        $d = ($this->demander)();
        $situation->call($this);
        $avant = DeblocageAcces::query()->count();
        $this->sms = [];

        expect($this->demandes->decider($this->responsable, $d, true))->toStartWith('Échec de l\'exécution : ')->toContain($message)
            ->and($d->refresh())->statut->toBe(DemandeAutorisation::ECHEC)->resultat->toContain($message)
            ->and(DeblocageAcces::query()->count())->toBe($avant)
            ->and(DeblocageAcces::query()->where('accorde_par', $this->responsable->id)->exists())->toBeFalse()
            ->and($this->sms)->toBe([])
            ->and(Activity::query()->where('event', 'demande_echec')->count())->toBe(1)
            ->and(Message::query()->where('categorie', Message::DECISION)->sole()->destinataires()->pluck('user_id')->all())->toBe([$this->agent->id]);
    })->with([
        'soldé entre-temps' => [fn () => ($this->verser)(), 'déjà soldés'],
        'débloqué entre-temps' => [fn () => DeblocageAcces::factory()->create(['inscription_annuelle_id' => $this->inscription->id]), 'déjà en cours'],
        'retiré entre-temps' => [fn () => $this->dossier->forceFill(['withdrawn_at' => now()])->save(), 'retiré'],
        'exclu entre-temps' => [fn () => $this->auditeur->forceFill(['statut' => Auditeur::STATUT_ABANDON])->save(), 'abandon'],
        'échéance dépassée entre-temps' => [fn () => $this->travelTo(now('Africa/Douala')->setDate(2027, 1, 16)->setTime(9, 0)), 'postérieure à aujourd\'hui'],
    ]);
});

describe('migration', function (): void {
    beforeEach(function (): void {
        $this->inserer = fn (array $attributs) => DB::transaction(fn () => DB::table('demandes_autorisation')->insert($attributs + [
            'uuid' => (string) Str::uuid(), 'numero' => 'DM-2026-09999', 'annee' => 2026, 'sequence' => 9999,
            'demandeur_id' => $this->agent->id, 'entite_id' => $this->inscription->id, 'objet' => 'Inscription fictive',
            'motif' => 'Motif de la demande fictive', 'statut' => DemandeAutorisation::SOUMISE, 'soumise_le' => now(), 'echeance' => now()->addDay(),
        ]));
    });

    it('garantit en base l\'échéance d\'une demande de moratoire et fige ses paramètres', function (): void {
        $d = ($this->demander)();

        expect(fn () => DB::transaction(fn () => DB::table('demandes_autorisation')->where('id', $d->id)->update(['parametres' => json_encode(['echeance' => '2027-05-01'])])))
            ->toThrow(QueryException::class, 'figée')
            ->and(fn () => ($this->inserer)(['type' => TypesDemande::ACCORDER_MORATOIRE, 'entite_id' => null, 'parametres' => json_encode(['justificatif_path' => null])]))
            ->toThrow(QueryException::class, 'demandes_autorisation_parametres_check')
            ->and(fn () => ($this->inserer)(['type' => TypesDemande::VALIDER_AUTRE, 'entite_id' => null, 'parametres' => json_encode(['a', 'b'])]))
            ->toThrow(QueryException::class, 'demandes_autorisation_parametres_check')
            ->and(fn () => ($this->inserer)(['type' => 'accorder_tout']))
            ->toThrow(QueryException::class, 'demandes_autorisation_check');
    });

    it('se défait et se refait proprement', function (): void {
        $migration = require database_path('migrations/2026_12_02_110000_add_accorder_moratoire_to_demandes_autorisation.php');

        $migration->down();
        expect(Schema::hasColumn('demandes_autorisation', 'parametres'))->toBeFalse()
            ->and(fn () => ($this->inserer)(['type' => TypesDemande::ACCORDER_MORATOIRE]))->toThrow(QueryException::class, 'demandes_autorisation_check');
        // Le déclencheur d'avant fige toujours la demande, sans connaître la colonne retirée.
        ($this->inserer)(['type' => TypesDemande::DEVERROUILLER_COMPTE, 'entite_id' => $this->agent->id]);
        expect(fn () => DB::transaction(fn () => DB::table('demandes_autorisation')->where('numero', 'DM-2026-09999')->update(['motif' => 'Motif modifié après coup'])))
            ->toThrow(QueryException::class, 'figée');

        $migration->up();
        expect(Schema::hasColumn('demandes_autorisation', 'parametres'))->toBeTrue()
            ->and(($this->demander)()->fresh()->parametres['echeance'])->toBe('2027-01-15');
    });
});

describe('écrans', function (): void {
    it('propose « Demander un moratoire » à l\'agent seul ; le responsable débloque directement', function (string $role, bool $demande, bool $direct): void {
        $this->actingAs(($this->compte)($role));

        $page = ($this->page)();
        $demande ? $page->assertActionVisible('demanderMoratoire') : $page->assertActionHidden('demanderMoratoire');
        $direct ? $page->assertActionVisible('debloquerAcces') : $page->assertActionHidden('debloquerAcces');
    })->with([
        'agent' => ['scolarite_agent', true, true],
        'responsable' => ['scolarite_responsable', false, true],
        'admin' => ['admin', false, true],
        'service financier' => ['service_financier', false, false],
    ]);

    it('ne propose pas la demande sur des droits soldés ou un déblocage en cours', function (Closure $situation): void {
        $situation->call($this);
        $this->actingAs($this->agent);

        ($this->page)()->assertActionHidden('demanderMoratoire');
    })->with([
        'soldé' => [fn () => ($this->verser)()],
        'en cours' => [fn () => DeblocageAcces::factory()->create(['inscription_annuelle_id' => $this->inscription->id])],
    ]);

    it('soumet depuis la fiche, avec la demande écrite de l\'auditeur en pièce, puis mène à la demande en attente', function (): void {
        $this->actingAs($this->agent);

        ($this->page)()
            ->callAction('demanderMoratoire', data: [
                'echeance' => '2027-01-15', 'motif' => 'Demande écrite : salaire versé en fin de mois',
                'justificatif_path' => UploadedFile::fake()->createWithContent('demande.pdf', "%PDF-1.4\n%%EOF\n"),
            ])
            ->assertHasNoActionErrors();

        $d = DemandeAutorisation::query()->sole();
        expect($d)->type->toBe(TypesDemande::ACCORDER_MORATOIRE)->demandeur_id->toBe($this->agent->id)
            ->and($d->parametres['echeance'])->toBe('2027-01-15')
            ->and(DeblocageAcces::cheminPieceValide($d->parametres['justificatif_path']))->toBeTrue()
            ->and(Storage::disk(CandidatureObjectStorage::DISK)->exists($d->parametres['justificatif_path']))->toBeTrue()
            ->and(DeblocageAcces::query()->count())->toBe(0);
        ($this->page)()->assertActionHidden('demanderMoratoire')->assertActionVisible('demandeMoratoireEnAttente');
    });

    it('borne l\'échéance proposée à 180 jours dès le formulaire', function (): void {
        $this->actingAs($this->agent);

        ($this->page)()
            ->callAction('demanderMoratoire', data: ['echeance' => '2027-06-01', 'motif' => 'Demande écrite de l\'auditeur'])
            ->assertHasActionErrors(['echeance']);

        expect(DemandeAutorisation::query()->count())->toBe(0);
    });

    it('mène le responsable à la demande, lui montre l\'échéance et la pièce, et l\'approuve depuis sa fiche', function (): void {
        $d = ($this->demander)();
        $this->actingAs($this->responsable);

        ($this->page)()->assertActionHidden('demanderMoratoire')->assertActionVisible('demandeMoratoireEnAttente');
        $this->livewire(ViewDemande::class, ['record' => $d->uuid])
            ->assertSee('Échéance proposée : 15/01/2027')
            ->assertActionVisible('voirPiece')
            ->callAction('voirPiece')->assertRedirect();
        expect(Activity::query()->where('event', 'demande_piece_consultee')->count())->toBe(1);

        $this->livewire(ViewDemande::class, ['record' => $d->uuid])->callAction('approuver', ['commentaire' => 'Accord']);
        expect($d->refresh()->statut)->toBe(DemandeAutorisation::EXECUTEE)
            ->and(DeblocageAcces::query()->sole()->accorde_par)->toBe($this->responsable->id);
        ($this->page)()->assertActionHidden('demandeMoratoireEnAttente');
    });

    it('n\'offre pas de pièce quand l\'agent n\'en a pas joint', function (): void {
        $d = ($this->demander)(['justificatif_path' => null]);
        $this->actingAs($this->responsable);

        $this->livewire(ViewDemande::class, ['record' => $d->uuid])->assertActionHidden('voirPiece')->assertSee('aucune pièce jointe');
    });
});
