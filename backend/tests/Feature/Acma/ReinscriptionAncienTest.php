<?php

declare(strict_types=1);

use App\Filament\Resources\ArchiveAcmaResource\Pages\ViewArchiveAcma;
use App\Models\AcmaDossier;
use App\Models\Auditeur;
use App\Models\BaremeScolarite;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\InscriptionAnnuelle;
use App\Models\Parcours;
use App\Models\ReinscriptionAcma;
use App\Models\User;
use App\Services\Acma\ReinscriptionAncien;
use App\Services\Acma\Reprise\RepriseAcma;
use App\Services\Ecampus\ComptesEcampus;
use App\Services\ExportsRetenus\AttributionMatricules;
use App\Services\Scolarite\AccesEcampus;
use App\Services\Sms\SmsServiceInterface;
use App\Support\Journaux;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\Acma\TransitAcma;

uses()->group('acma', 'scolarite');

/* Lot 15k (spec de la réinscription des anciens, §7, §8.2) : réinscription d'un ancien depuis l'archive. Jeu fictif. */

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    config()->set('scolarite.annee_en_cours', '2026-2027');
    Storage::fake('local');
    Notification::fake();
    Queue::fake();
    Http::fake();
    Mail::fake();
    $this->mock(SmsServiceInterface::class, fn ($m) => $m->shouldNotReceive('send'));
    $this->compte = fn (string $role): User => tap(User::factory()->create(['email' => $role.'-'.Str::random(6).'@pssfp.local']), fn (User $u) => $u->assignRole($role));
    $this->p14 = CampagneCandidature::factory()->closed()->create(['nom' => ['fr' => 'Année académique 2026-2027'], 'promotion_numero' => 14, 'slug' => 'p14-2026', 'prefix_numero' => 'P14026-']);
    $this->p13 = CampagneCandidature::factory()->closed()->create(['nom' => ['fr' => 'Année académique 2025-2026'], 'promotion_numero' => 13, 'slug' => 'p13-2025', 'prefix_numero' => 'P13025-']);
    BaremeScolarite::factory()->create(['annee_academique' => '2026-2027']);
    BaremeScolarite::factory()->m2()->create(['annee_academique' => '2026-2027']);
    Parcours::query()->create(['code' => 'TC', 'libelle' => 'Tronc commun', 'niveau' => 'M1', 'actif' => true, 'ordre' => 1]);
    Parcours::query()->create(['code' => 'ACFP', 'libelle' => 'Audit et contrôle', 'niveau' => 'M2', 'specialite' => 'Audit et contrôle des finances publiques', 'actif' => true, 'ordre' => 2]);
    TransitAcma::construire();
    app(RepriseAcma::class)->executer(($this->compte)('admin'), null, true);
    $this->responsable = ($this->compte)('scolarite_responsable');
    $this->dossier = fn (string $m): AcmaDossier => AcmaDossier::query()->where('matricule', $m)->firstOrFail();
    $this->saisie = fn (array $autres = []): array => array_merge([
        'nom' => 'ESSAI', 'prenom' => 'Alpha Béta', 'date_naissance' => '1985-03-12', 'genre' => 'M', 'nationalite' => 'CM',
        'phone_e164' => '+237 699 000 001', 'email' => 'essai.alpha@exemple.invalid', 'adresse' => 'ADRESSE FICTIVE',
        'campagne_id' => $this->p13->id, 'niveau' => 'M2', 'parcours' => 'ACFP', 'motif' => 'Demande écrite de reprise des études',
        'coordonnees_verifiees' => true,
    ], $autres);
    $this->reinscrire = fn (string $m, array $autres = [], ?User $auteur = null): Candidature => app(ReinscriptionAncien::class)
        ->reinscrire(($this->dossier)($m), ($this->saisie)($autres), $auteur ?? $this->responsable);
});

it('réinscrit un ancien en M2 sous son matricule ACMA, hors PV, inscrit dans l\'année en cours, sans rien envoyer', function (): void {
    $c = ($this->reinscrire)('90EFP001');

    expect($c->matricule)->toBe('90EFP001')->and($c->rang_retenu)->toBeNull()->and($c->submitted_at)->toBeNull()
        ->and($c->ajoute_par)->toBe($this->responsable->id)->and($c->statut)->toBe(Candidature::STATUT_ACCEPTE)
        ->and($c->campagne_id)->toBe($this->p13->id)->and($c->phone_e164)->toBe('+237699000001')
        ->and($c->moodle_password)->not->toBeEmpty()->and($c->specialite)->toBe('Audit et contrôle des finances publiques')
        ->and($c->user->roles->pluck('name')->all())->toBe(['candidat']);
    $inscription = $c->auditeur->inscriptionCourante;
    expect($inscription->annee_academique)->toBe('2026-2027')->and($inscription->niveau)->toBe('M2')
        ->and($inscription->parcours->code)->toBe('ACFP')->and($inscription->montant_inscription)->toBe(135000)
        ->and($inscription->acces_notifie_at)->toBeNull()
        ->and(AccesEcampus::etat($c->fresh()))->toBe(AccesEcampus::BLOQUE);

    $d = ($this->dossier)('90EFP001');
    expect($d->statut_rapprochement)->toBe('lie_arbitre')->and($d->auditeur_id)->toBe($c->auditeur->id);
    $decision = DB::table('acma_rapprochements')->where('dossier_id', $d->id)->whereNotNull('decide_par')->sole();
    expect($decision->decision)->toBe('lie')->and($decision->decide_par)->toBe($this->responsable->id);
    $trace = ReinscriptionAcma::query()->sole();
    expect($trace->mode)->toBe('creation')->and($trace->niveau)->toBe('M2')->and($trace->parcours)->toBe('ACFP')
        ->and($trace->coordonnees_modifiees)->toBe(['telephone', 'email']);

    foreach (['ancien_reinscrit' => Journaux::SCOLARITE, 'rapprochement_decide' => Journaux::ARCHIVES, 'retenu_reinscrit_acma' => Journaux::CANDIDATURES] as $evenement => $journal) {
        expect(DB::table('activity_log')->where('log_name', $journal)->where('event', $evenement)->count())->toBe(1);
    }
    // Le journal des comptes garde le téléphone du compte (règle existante) ; ceux de la réinscription, aucune coordonnée.
    $proprietes = DB::table('activity_log')->whereIn('event', ['ancien_reinscrit', 'rapprochement_decide', 'retenu_reinscrit_acma', 'inscription_creee'])->pluck('properties')->implode(' ');
    expect($proprietes)->not->toContain('699000001')->not->toContain('ADRESSE FICTIVE')->not->toContain('exemple.invalid');
    Notification::assertNothingSent();
    Mail::assertNothingOutgoing();
    expect(app(ReinscriptionAncien::class)->ouvrirEcampus($c, $this->responsable))->toBeNull();
});

it('ouvre l\'accès par le seul chemin normal : droits payés et confirmés, puis un avis, une fois', function (): void {
    $c = ($this->reinscrire)('90EFP001');
    $c->forceFill(['ecampus_statut' => ComptesEcampus::CREE])->save();
    $sms = [];
    $this->mock(SmsServiceInterface::class, function ($m) use (&$sms): void {
        $m->shouldReceive('send')->andReturnUsing(function (string $tel, string $texte) use (&$sms): void {
            $sms[] = $tel;
        });
    });
    expect(AccesEcampus::etat($c->fresh()))->toBe(AccesEcampus::BLOQUE)->and($sms)->toBe([]);

    $encaisseur = ($this->compte)('service_financier');
    $verser = fn (int $montant) => verserConfirme($c->auditeur->inscriptionCourante->fresh(), [
        'montant' => $montant, 'date_versement' => '2026-11-16', 'mode' => 'virement', 'reference' => 'BOR-'.Str::random(8), 'payeur' => 'auditeur',
    ], $encaisseur);
    $verser(100000);
    expect(AccesEcampus::etat($c->fresh()))->toBe(AccesEcampus::BLOQUE)->and($sms)->toBe([]);
    $verser(35000);
    expect(AccesEcampus::etat($c->fresh()))->toBe(AccesEcampus::DISPONIBLE)->and($sms)->toBe(['+237699000001']);
    $verser(50000);
    expect($sms)->toHaveCount(1);
});

it('réinscrit en M1 un ancien non admis, rattaché à la P14, et ne lui donne aucune voie de contournement', function (): void {
    $c = ($this->reinscrire)('90EFP012', ['nom' => 'ESSAI', 'prenom' => 'Zeta', 'date_naissance' => '1984-04-04', 'phone_e164' => '+237699000012', 'email' => null,
        'campagne_id' => $this->p14->id, 'niveau' => 'M1', 'parcours' => 'TC']);

    $i = $c->auditeur->inscriptionCourante;
    expect($i->niveau)->toBe('M1')->and($i->montant_inscription)->toBe(185000)->and($i->remise_montant)->toBe(0)
        ->and($i->deblocages()->count())->toBe(0)->and($i->versements()->count())->toBe(0)
        ->and($c->campagne_id)->toBe($this->p14->id)->and($c->email)->toBeNull();
    expect(InscriptionAnnuelle::query()->where('auditeur_id', $c->auditeur->id)->count())->toBe(1);
});

it('exige des coordonnées vérifiées auprès de l\'auditeur, et la trace le garde', function (): void {
    expect(fn () => ($this->reinscrire)('90EFP001', ['coordonnees_verifiees' => false]))->toThrow(InvalidArgumentException::class, 'cochez la case');
    expect(ReinscriptionAcma::query()->count())->toBe(0);

    ($this->reinscrire)('90EFP001');
    expect(ReinscriptionAcma::query()->sole()->coordonnees_verifiees)->toBeTrue();
});

it('refuse un matricule déjà pris comme identifiant eCampus figé ou ancien matricule d\'un autre dossier', function (): void {
    Candidature::factory()->forCampagne($this->p14)->create()->forceFill(['identifiant_ecampus' => '90efp012'])->save();

    expect(fn () => ($this->reinscrire)('90EFP012', ['nom' => 'ESSAI', 'prenom' => 'Zeta', 'date_naissance' => '1984-04-04', 'phone_e164' => '+237699000012', 'niveau' => 'M1', 'parcours' => 'TC', 'email' => null]))
        ->toThrow(InvalidArgumentException::class, 'déjà porté');
});

it('refuse un dossier déjà réinscrit, un matricule déjà pris, un M2 sans M1 admis et une date sans pièce', function (): void {
    ($this->reinscrire)('90EFP001');
    expect(fn () => ($this->reinscrire)('90EFP001', ['phone_e164' => '+237699000099']))->toThrow(InvalidArgumentException::class, 'déjà');

    Candidature::factory()->forCampagne($this->p14)->create()->forceFill(['matricule' => '90EFP012'])->save();
    expect(fn () => ($this->reinscrire)('90EFP012', ['date_naissance' => '1984-04-04', 'niveau' => 'M1', 'parcours' => 'TC', 'phone_e164' => '+237699000012']))
        ->toThrow(InvalidArgumentException::class, 'déjà porté');

    $saisie02 = ['nom' => 'ESSAI', 'prenom' => 'Gamma', 'date_naissance' => '1990-05-05', 'genre' => 'F', 'phone_e164' => '+237699000002', 'email' => null];
    expect(fn () => ($this->reinscrire)('90EFP002', $saisie02 + ['date_naissance_piece' => 'Acte de naissance']))->toThrow(InvalidArgumentException::class, 'Aucun M1 admis');
    expect(fn () => ($this->reinscrire)('90EFP002', $saisie02 + ['derogation_m2' => true, 'derogation_piece' => 'Décision 1/2026']))->toThrow(InvalidArgumentException::class, 'pièce');
    expect(ReinscriptionAcma::query()->count())->toBe(1);

    $c = ($this->reinscrire)('90EFP002', $saisie02 + ['derogation_m2' => true, 'derogation_piece' => 'Décision 1/2026', 'date_naissance_piece' => 'Acte de naissance']);
    expect($c->date_naissance->toDateString())->toBe('1990-05-05')
        ->and(DB::table('acma_complements')->where('champ', 'date_naissance')->where('valeur', '1990-05-05')->count())->toBe(1)
        ->and(ReinscriptionAcma::query()->where('candidature_id', $c->id)->value('derogation_m2'))->toBeTrue();
});

it('refuse de créer un second dossier pour une personne déjà sur la plateforme, et signale un téléphone d\'un autre dossier', function (): void {
    $auditeur = Auditeur::factory()->create();
    $auditeur->candidature->forceFill(['campagne_id' => $this->p14->id, 'matricule' => '26EFP900', 'nom' => 'ESSAI', 'prenom' => 'Alpha Béta', 'epouse' => null, 'date_naissance' => '1985-03-12'])->save();
    expect(fn () => ($this->reinscrire)('90EFP001'))->toThrow(InvalidArgumentException::class, 'déjà auditeur');
    $auditeur->candidature->forceFill(['date_naissance' => '1960-01-01'])->save();

    $enCours = Candidature::factory()->forCampagne($this->p14)->submitted()->create(['nom' => 'ESSAI', 'prenom' => 'Zeta', 'date_naissance' => '1984-04-04']);
    $m1 = ['nom' => 'ESSAI', 'prenom' => 'Zeta', 'date_naissance' => '1984-04-04', 'phone_e164' => '+237699000012', 'niveau' => 'M1', 'parcours' => 'TC', 'email' => null];
    expect(fn () => ($this->reinscrire)('90EFP012', $m1))->toThrow(InvalidArgumentException::class, 'candidature en cours');
    $enCours->forceFill(['statut' => Candidature::STATUT_REFUSE])->save();
    expect(($this->reinscrire)('90EFP012', $m1)->matricule)->toBe('90EFP012');

    $autre = Candidature::factory()->forCampagne($this->p14)->create(['phone_e164' => '+237699000013']);
    $m1bis = ['nom' => 'ESSAI', 'prenom' => 'Eta Theta', 'date_naissance' => '1986-06-06', 'genre' => 'F', 'phone_e164' => '+237699000013', 'niveau' => 'M1', 'parcours' => 'TC', 'email' => null, 'campagne_id' => $this->p13->id];
    expect(fn () => ($this->reinscrire)('90EFP013', $m1bis))->toThrow(InvalidArgumentException::class, $autre->numero_dossier);
});

it('refuse d\'attribuer un matricule P14 à un retenu déjà réinscrit depuis l\'archive', function (): void {
    ($this->reinscrire)('90EFP001');
    $retenu = Candidature::factory()->forCampagne($this->p14)->create(['statut' => Candidature::STATUT_ACCEPTE, 'nom' => 'ESSAI', 'prenom' => 'Alpha Béta', 'date_naissance' => '1985-03-12']);

    expect(fn () => app(AttributionMatricules::class)->attribuer($this->p14, '26', ($this->compte)('admin')))
        ->toThrow(RuntimeException::class, 'déjà auditeur depuis l\'archive');
    expect($retenu->fresh()->matricule)->toBeNull();
});

it('garde le lien au rejeu de la reprise et après le gel, et n\'accepte aucune modification de la trace', function (): void {
    $c = ($this->reinscrire)('90EFP001');
    $rejeu = app(RepriseAcma::class)->executer(($this->compte)('admin'), null, true);
    expect($rejeu['statut'])->toBe('appliquee')->and(($this->dossier)('90EFP001'))->statut_rapprochement->toBe('lie_arbitre')->auditeur_id->toBe($c->auditeur->id);

    DB::transaction(function (): void {
        RepriseAcma::mode(true);
        DB::table('acma_reprises')->where('id', DB::table('acma_reprises')->where('statut', 'appliquee')->max('id'))->update(['statut' => 'gelee', 'gelee_at' => now()]);
        RepriseAcma::mode(false);
    });
    $apresGel = ($this->reinscrire)('90EFP012', ['nom' => 'ESSAI', 'prenom' => 'Zeta', 'date_naissance' => '1984-04-04', 'phone_e164' => '+237699000012', 'niveau' => 'M1', 'parcours' => 'TC', 'email' => null]);
    expect(($this->dossier)('90EFP012')->auditeur_id)->toBe($apresGel->auditeur->id);

    try {
        DB::transaction(fn () => DB::table('reinscriptions_acma')->update(['motif' => 'Modification interdite ici']));
        $this->fail('Modification acceptée');
    } catch (QueryException $e) {
        expect($e->getMessage())->toContain('ajout seul');
    }
});

it('réserve le geste au responsable : l\'agent ne le voit ni ne l\'exécute', function (): void {
    $agent = ($this->compte)('scolarite_agent');
    expect(fn () => ($this->reinscrire)('90EFP001', [], $agent))->toThrow(HttpException::class);

    $this->actingAs($agent);
    $this->livewire(ViewArchiveAcma::class, ['record' => ($this->dossier)('90EFP001')->getRouteKey()])->assertActionHidden('reinscrire');

    $this->actingAs($this->responsable);
    $this->livewire(ViewArchiveAcma::class, ['record' => ($this->dossier)('90EFP001')->getRouteKey()])
        ->assertActionVisible('reinscrire')
        ->mountAction('reinscrire')
        ->assertActionDataSet(['nom' => 'ESSAI', 'prenom' => 'ALPHA BÉTA', 'phone_e164' => '+237600000000', 'adresse' => 'ADRESSE FICTIVE', 'campagne_id' => null])
        ->setActionData(($this->saisie)(['prenom' => 'Alpha Béta']))
        ->callMountedAction()
        ->assertHasNoActionErrors();
    expect(Candidature::query()->where('matricule', '90EFP001')->exists())->toBeTrue();
    $this->livewire(ViewArchiveAcma::class, ['record' => ($this->dossier)('90EFP001')->getRouteKey()])->assertActionHidden('reinscrire');
});

it('se défait tant qu\'aucun ancien n\'est réinscrit, et refuse ensuite', function (): void {
    $migration = require database_path('migrations/2026_12_10_100200_create_reinscriptions_acma_table.php');
    $migration->down();
    expect(Schema::hasTable('reinscriptions_acma'))->toBeFalse();
    $migration->up();
    ($this->reinscrire)('90EFP001');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'réinscrits');
});
