<?php

declare(strict_types=1);

/*
 * Règle du 25/11/2026 : toute modification ou annulation d'un versement ou d'un reçu est validée par un administrateur.
 */

use App\Filament\Resources\AuditeurResource\Pages\ViewAuditeur;
use App\Filament\Resources\AuditeurResource\RelationManagers\VersementsRelationManager;
use App\Filament\Resources\RecuVersementResource;
use App\Models\BaremeScolarite;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\DemandeAutorisation;
use App\Models\RecuVersement;
use App\Models\Role;
use App\Models\User;
use App\Models\Versement;
use App\Services\Ecampus\ComptesEcampus;
use App\Services\ExportsRetenus\AttributionMatricules;
use App\Services\Messagerie\Demandes;
use App\Services\Messagerie\TypesDemande;
use App\Services\Scolarite\CorrectionsVersements;
use App\Services\Scolarite\RecusVersements;
use App\Services\Scolarite\RegistreVersements;
use App\Services\Scolarite\ReglementsOrganismes;
use App\Services\Sms\SmsServiceInterface;
use App\Support\AppSettings;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses()->group('scolarite');

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    $this->seed([PaysSeeder::class, RegionsCamerounSeeder::class, DepartementsCamerounSeeder::class, RolePermissionSeeder::class]);
    $this->mock(SmsServiceInterface::class)->shouldReceive('send')->andReturnNull();
    $this->compte = fn (string $role): User => tap(User::factory()->create(['email' => $role.'-'.Str::random(6).'@pssfp.local', 'name' => 'Agent '.$role]), fn (User $u) => $u->assignRole($role));
    $this->agent = ($this->compte)('scolarite_agent');
    $this->responsable = ($this->compte)('scolarite_responsable');
    $this->financier = ($this->compte)('service_financier');
    $this->admin = ($this->compte)('admin');
    $this->superAdmin = ($this->compte)('super_admin');
    $this->travelTo(now('Africa/Douala')->setDate(2026, 11, 10)->setTime(9, 0));
    AppSettings::set(RecusVersements::CLE_DEPUIS, now()->subDay()->toIso8601String());

    $campagne = CampagneCandidature::factory()->closed()->create(['nom' => ['fr' => 'Année académique 2026-2027'], 'promotion_numero' => 14]);
    BaremeScolarite::factory()->create(['annee_academique' => '2026-2027', 'niveau' => 'M1']);
    $retenu = fn (string $nom, string $tel): Candidature => Candidature::factory()->forCampagne($campagne)->submitted()->create([
        'nom' => $nom, 'prenom' => 'Essai', 'statut' => Candidature::STATUT_ACCEPTE, 'phone_e164' => $tel, 'email' => Str::random(8).'@example.org',
        'ecampus_statut' => ComptesEcampus::CREE,
        'user_id' => User::factory()->create(['phone_e164' => $tel, 'email' => Str::random(8).'@example.org'])->id,
    ]);
    $this->awa = $retenu('Abena', '+237699003001');
    $this->bruno = $retenu('Bekono', '+237699003002');
    app(AttributionMatricules::class)->attribuer($campagne, '26', $this->admin);
    $this->awa->refresh();
    $this->bruno->refresh();
    $this->inscription = $this->awa->auditeur->inscriptionCourante;
    $this->service = app(RecusVersements::class);
    $this->verser = fn (int $montant, string $date = '2026-11-02'): Versement => app(RegistreVersements::class)->enregistrer($this->inscription,
        ['montant' => $montant, 'date_versement' => $date, 'mode' => 'cremincam_agence', 'reference' => 'BOR-'.Str::random(6), 'payeur' => 'auditeur'], $this->financier)->versement;
    $this->jeton = fn (Candidature $c): string => $c->user->createToken('candidat', ['profile:read', 'application:read'])->plainTextToken;
});

const MIGRATION_ANNULER = '2026_12_21_100100_revoke_versement_annuler_responsable.php';

it('réserve l\'annulation des versements et la décision des corrections à l\'administrateur', function (): void {
    $v = ($this->verser)(185000);

    expect(CorrectionsVersements::peutDecider($this->responsable))->toBeFalse()
        ->and(CorrectionsVersements::peutDecider($this->admin))->toBeTrue()
        ->and(CorrectionsVersements::peutDecider($this->superAdmin))->toBeTrue()
        ->and(ReglementsOrganismes::peutAnnuler($this->responsable))->toBeFalse()
        ->and(fn () => app(CorrectionsVersements::class)->annulerDirectement($v, 'doublon', 'Saisi deux fois au guichet', $this->responsable))->toThrow(HttpException::class);

    $c = app(CorrectionsVersements::class)->annulerDirectement($v, 'doublon', 'Saisi deux fois au guichet', $this->admin);
    expect($c->executee_par)->toBe($this->admin->id)->and($v->refresh()->estAnnule())->toBeTrue();

    $d = app(Demandes::class)->soumettre($this->financier, TypesDemande::CORRIGER_VERSEMENT, ($this->verser)(50000)->id,
        'Erreur de date au guichet du service financier', null, ['nature' => 'remplacement', 'categorie' => 'date', 'date_versement' => '2026-11-03']);
    expect(app(Demandes::class)->peutDecider($this->responsable, $d))->toBeFalse()
        ->and(app(Demandes::class)->peutDecider($this->admin, $d))->toBeTrue();
});

it('fait décider la réémission d\'un reçu par un administrateur, sans jamais laisser le versement sans reçu', function (): void {
    $v = ($this->verser)(185000);
    $r = $this->service->emettre($v, $this->financier);

    // Le service financier n'annule plus seul.
    expect(fn () => $this->service->annuler($r, 'Reçu froissé, à refaire', $this->financier))->toThrow(HttpException::class);

    $d = app(Demandes::class)->soumettre($this->financier, TypesDemande::REEMETTRE_RECU, $r->id, 'Reçu taché, illisible pour l\'auditeur');
    expect($d->statut)->toBe(DemandeAutorisation::SOUMISE)
        ->and($d->objet)->toContain('RCPT-2026-000001')
        ->and(app(Demandes::class)->peutDecider($this->responsable, $d))->toBeFalse()
        ->and(fn () => app(Demandes::class)->soumettre($this->financier, TypesDemande::REEMETTRE_RECU, $r->id, 'Seconde demande pour le même reçu'))
        ->toThrow(Exception::class);

    $resultat = app(Demandes::class)->decider($this->admin, $d->refresh(), true, null, DemandeAutorisation::SOUMISE);
    $nouveau = RecuVersement::query()->where('versement_id', $v->id)->whereDoesntHave('annulation')->sole();

    expect($resultat)->toBe('Reçu RCPT-2026-000001 annulé ; reçu RCPT-2026-000002 émis')
        ->and($d->refresh()->statut)->toBe(DemandeAutorisation::EXECUTEE)
        ->and($r->refresh()->annulation->annule_par)->toBe($this->admin->id)
        ->and($r->annulation->motif)->toStartWith('Réémission : ')
        ->and($nouveau->remplace_recu_id)->toBe($r->id)
        ->and($nouveau->emis_par)->toBe($this->financier->id)
        ->and($this->service->enAttente($this->financier)->pluck('id')->all())->not->toContain($v->id);

    // Un administrateur sans droit d'émission ne réémet pas lui-même : il décide les demandes.
    expect(fn () => $this->service->reemettre($nouveau, 'Réémission demandée au guichet', $this->admin, $this->admin))->toThrow(HttpException::class);
});

it('annule et réémet en un geste pour le super administrateur, et n\'annule rien si le nouveau reçu ne peut pas être émis', function (): void {
    $v = ($this->verser)(185000);
    $r = $this->service->emettre($v, $this->financier);

    $nouveau = $this->service->reemettre($r, 'Reçu réimprimé à la demande de l\'auditeur', $this->superAdmin, $this->superAdmin);
    expect($nouveau->numero)->toBe('RCPT-2026-000002')->and($nouveau->emis_par)->toBe($this->superAdmin->id);

    $this->awa->forceFill(['withdrawn_at' => now()])->save();
    expect(fn () => $this->service->reemettre($nouveau, 'Reçu réimprimé à la demande de l\'auditeur', $this->superAdmin, $this->superAdmin))
        ->toThrow(InvalidArgumentException::class, 'retiré')
        ->and($nouveau->refresh()->annulation)->toBeNull();
});

it('donne au service financier la demande de réémission et le renvoi du reçu, jamais l\'annulation seule', function (): void {
    $v = ($this->verser)(185000);
    $r = $this->service->emettre($v, $this->financier);
    $fiche = $this->awa->auditeur;
    $avis = fn (): int => Activity::query()->where('event', 'recu_avis')->where('subject_id', $r->id)->count();

    $this->actingAs($this->agent);
    $this->livewire(VersementsRelationManager::class, ['ownerRecord' => $fiche, 'pageClass' => ViewAuditeur::class])
        ->assertTableActionHidden('reemettreRecu', $v)->assertTableActionHidden('renvoyerRecu', $v);

    $this->actingAs($this->financier);
    $avant = $avis();
    $this->livewire(VersementsRelationManager::class, ['ownerRecord' => $fiche, 'pageClass' => ViewAuditeur::class])
        ->assertTableActionDoesNotExist('annulerRecu')
        ->assertTableActionHidden('rectifier', $v)
        ->callTableAction('renvoyerRecu', $v)->assertHasNoTableActionErrors()
        ->callTableAction('reemettreRecu', $v, data: ['motif' => 'court'])->assertHasTableActionErrors(['motif'])
        ->callTableAction('reemettreRecu', $v, data: ['motif' => 'Reçu perdu par l\'auditeur, à refaire'])->assertHasNoTableActionErrors();

    expect($avis())->toBe($avant + 1)
        ->and($r->refresh()->annulation)->toBeNull()
        ->and(DemandeAutorisation::query()->where('type', TypesDemande::REEMETTRE_RECU)->where('entite_id', $r->id)->value('statut'))->toBe(DemandeAutorisation::SOUMISE);

    $this->get(RecuVersementResource::getUrl('index'))->assertOk()->assertSee('RCPT-2026-000001');
});

it('retire la rectification immédiate au service financier', function (): void {
    $v = ($this->verser)(185000);

    expect(fn () => app(CorrectionsVersements::class)->rectifier($v, ['categorie' => 'reference', 'reference' => 'BOR-JUSTE'],
        'Faute de frappe sur la référence du bordereau', $this->financier))->toThrow(HttpException::class);
    $c = app(CorrectionsVersements::class)->rectifier($v, ['categorie' => 'reference', 'reference' => 'BOR-JUSTE'],
        'Faute de frappe sur la référence du bordereau', $this->superAdmin);
    expect($c->rectification_immediate)->toBeTrue()->and($c->executee_par)->toBe($this->superAdmin->id);
});

it('retire le droit d\'annuler au responsable par une migration réversible', function (): void {
    $migration = require database_path('migrations/'.MIGRATION_ANNULER);
    $responsable = fn (): bool => Role::findByName('scolarite_responsable', 'web')->hasPermissionTo(RegistreVersements::PERMISSION_ANNULER);

    $migration->down();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    expect($responsable())->toBeTrue();

    $migration->up();
    expect($responsable())->toBeFalse()
        ->and(Role::findByName('admin', 'web')->hasPermissionTo(RegistreVersements::PERMISSION_ANNULER))->toBeTrue()
        ->and(Role::findByName('scolarite_responsable', 'web')->description)->toContain('revient à un administrateur');
});
