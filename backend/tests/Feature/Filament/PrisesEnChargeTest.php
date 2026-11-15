<?php

declare(strict_types=1);

use App\Filament\Resources\AuditeurResource\Pages\ViewAuditeur;
use App\Filament\Resources\AuditeurResource\RelationManagers\VersementsRelationManager;
use App\Filament\Resources\EngagementPriseEnChargeResource;
use App\Filament\Resources\EngagementPriseEnChargeResource\Pages\ListEngagements;
use App\Filament\Resources\EngagementPriseEnChargeResource\Pages\ViewEngagement;
use App\Filament\Resources\EngagementPriseEnChargeResource\RelationManagers\AuditeursCouvertsRelationManager;
use App\Filament\Resources\EngagementPriseEnChargeResource\RelationManagers\ReglementsRelationManager;
use App\Jobs\SendThrottledMail;
use App\Models\Auditeur;
use App\Models\BaremeScolarite;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\DeblocageAcces;
use App\Models\EngagementPriseEnCharge;
use App\Models\ReglementOrganisme;
use App\Models\User;
use App\Models\Versement;
use App\Services\Ecampus\ComptesEcampus;
use App\Services\ExportsRetenus\AttributionMatricules;
use App\Services\Scolarite\EngagementsPriseEnCharge;
use App\Services\Scolarite\LibelleSituation;
use App\Services\Scolarite\ReglementsOrganismes;
use App\Services\Sms\SmsServiceInterface;
use App\Support\CandidatureObjectStorage;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

uses()->group('filament', 'scolarite', 'prises-en-charge');

/* Écran « Prises en charge » et cas « Prise en charge » de la fiche auditeur (spec module 4, lot A2 §9.9 et §9.10). */

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    Storage::fake(CandidatureObjectStorage::DISK);
    $this->seed([PaysSeeder::class, RegionsCamerounSeeder::class, DepartementsCamerounSeeder::class, RolePermissionSeeder::class]);
    $this->travelTo(now('Africa/Douala')->setDate(2026, 11, 16)->setTime(9, 0));
    $this->compte = fn (string $role): User => tap(User::factory()->create(['email' => $role.'-'.Str::random(6).'@pssfp.local', 'name' => 'Agent '.$role]), fn (User $u) => $u->assignRole($role));
    $campagne = CampagneCandidature::factory()->create(['nom' => ['fr' => 'Année académique 2026-2027'], 'promotion_numero' => 14]);
    BaremeScolarite::factory()->create(['annee_academique' => '2026-2027', 'niveau' => 'M1']);
    $retenu = fn (string $nom, string $tel): Candidature => Candidature::factory()->forCampagne($campagne)->submitted()->create([
        'nom' => $nom, 'prenom' => 'Essai', 'statut' => Candidature::STATUT_ACCEPTE, 'phone_e164' => $tel,
        'email' => Str::random(8).'@example.org', 'ecampus_statut' => ComptesEcampus::CREE,
    ]);
    $this->awa = $retenu('Abena', '+237699009001');
    $this->bruno = $retenu('Bekono', '+237699009002');
    app(AttributionMatricules::class)->attribuer($campagne, '26', ($this->compte)('admin'));
    $this->awa->refresh();
    $this->bruno->refresh();
    $this->mock(SmsServiceInterface::class)->shouldReceive('send')->andReturnNull();
    Mail::fake();

    $this->agent = ($this->compte)('scolarite_agent');
    // Depuis le 15/11/2026, le service financier enregistre l'argent, règlements d'organismes compris.
    $this->encaisseur = ($this->compte)('service_financier');
    $this->engagement = app(EngagementsPriseEnCharge::class)->creer([
        'organisme' => 'Ministère de test', 'lettre_reference' => 'LT-2026-001', 'lettre_date' => '2026-11-10',
        'lettre_path' => 'scolarite/justificatifs/01JLETTRE0000000000000000A.pdf', 'contact_nom' => 'Service RH',
        'contact_email' => 'rh@organisme.example', 'portee' => EngagementPriseEnCharge::DROITS_INSCRIPTION,
        'annee_academique' => '2026-2027', 'echeance' => '2027-01-15',
    ], $this->agent);
    $this->rattacher = fn (array $dossiers) => app(EngagementsPriseEnCharge::class)
        ->rattacher($this->engagement, array_map(fn (Candidature $c): Auditeur => $c->fresh()->auditeur, $dossiers), $this->agent);
    $this->fiche = fn () => $this->livewire(ViewEngagement::class, ['record' => $this->engagement->getRouteKey()]);
    $this->lettre = fn (): UploadedFile => UploadedFile::fake()->createWithContent('lettre.pdf', "%PDF-1.4\n%%EOF\n");
});

it('ouvre l\'écran aux rôles de la scolarité et du service financier seulement', function (string $role, bool $ouvert): void {
    $this->actingAs(($this->compte)($role));

    $reponse = $this->get(EngagementPriseEnChargeResource::getUrl('index'));
    $ouvert ? $reponse->assertOk() : $reponse->assertForbidden();
})->with([
    ['scolarite_agent', true], ['scolarite_responsable', true], ['service_financier', true], ['admin', true],
    ['editor', false], ['receptionniste', false],
]);

it('liste les engagements avec auditeurs, couvert, réglé, reste et état', function (): void {
    ($this->rattacher)([$this->awa, $this->bruno]);
    app(ReglementsOrganismes::class)->enregistrer($this->engagement, [
        'montant' => 185000, 'date_reglement' => '2026-11-16', 'mode' => 'virement', 'reference' => 'OV-1',
        'parts' => [$this->awa->fresh()->auditeur->inscriptionCourante->uuid => 185000],
    ], $this->encaisseur);
    $this->actingAs($this->agent);

    $this->livewire(ListEngagements::class)
        ->assertCanSeeTableRecords([$this->engagement])
        ->assertTableColumnStateSet('auditeurs', 2, $this->engagement)
        ->assertTableColumnStateSet('couvert', LibelleSituation::fcfa(370000), $this->engagement)
        ->assertTableColumnStateSet('regle', LibelleSituation::fcfa(185000), $this->engagement)
        ->assertTableColumnStateSet('reste', LibelleSituation::fcfa(185000), $this->engagement)
        ->assertTableColumnStateSet('etat', 'En cours', $this->engagement)
        ->assertSee('Ministère de test')
        ->assertSee('LT-2026-001');
});

it('crée un engagement depuis la liste, lettre obligatoire, pour qui peut débloquer', function (): void {
    $this->actingAs(($this->compte)('service_financier'));
    $this->livewire(ListEngagements::class)->assertActionHidden('nouvelEngagement');

    $this->actingAs($this->agent);
    $saisie = ['organisme' => 'Société de test', 'lettre_reference' => 'DRH-42', 'lettre_date' => '2026-11-12',
        'portee' => EngagementPriseEnCharge::SCOLARITE_ANNEE, 'annee_academique' => '2026-2027', 'echeance' => '2027-01-10',
        'contact_email' => 'drh@societe.example'];
    $this->livewire(ListEngagements::class)
        ->callAction('nouvelEngagement', data: $saisie)
        ->assertHasActionErrors(['lettre_path' => 'required']);
    expect(EngagementPriseEnCharge::query()->count())->toBe(1);

    $this->livewire(ListEngagements::class)
        ->callAction('nouvelEngagement', data: $saisie + ['lettre_path' => ($this->lettre)()])
        ->assertHasNoActionErrors();
    $e = EngagementPriseEnCharge::query()->where('organisme', 'Société de test')->sole();
    expect($e->portee)->toBe(EngagementPriseEnCharge::SCOLARITE_ANNEE)
        ->and(Storage::disk(CandidatureObjectStorage::DISK)->exists($e->lettre_path))->toBeTrue();
});

it('propose les actions de la fiche selon les droits', function (string $role, array $visibles, array $cachees): void {
    ($this->rattacher)([$this->awa]);
    $this->actingAs(($this->compte)($role));

    $page = ($this->fiche)();
    foreach ($visibles as $a) {
        $page->assertActionVisible($a);
    }
    foreach ($cachees as $a) {
        $page->assertActionHidden($a);
    }
})->with([
    'agent' => ['scolarite_agent', ['rattacher', 'relancer', 'lettre', 'modifierContact'], ['prolonger', 'enregistrerReglement']],
    'responsable' => ['scolarite_responsable', ['rattacher', 'relancer', 'lettre', 'prolonger'], ['enregistrerReglement']],
    'service financier' => ['service_financier', ['enregistrerReglement', 'lettre'], ['rattacher', 'relancer', 'prolonger', 'modifierContact']],
]);

it('rattache des auditeurs depuis la fiche et nomme les refus', function (): void {
    verserConfirme($this->bruno->fresh()->auditeur->inscriptionCourante, [
        'montant' => 185000, 'date_versement' => '2026-11-16', 'mode' => 'especes', 'reference' => 'ESP-9', 'payeur' => 'auditeur',
    ], $this->encaisseur);
    $this->actingAs($this->agent);

    ($this->fiche)()
        ->callAction('rattacher', data: ['auditeurs' => [$this->awa->fresh()->auditeur->uuid, $this->bruno->fresh()->auditeur->uuid]])
        ->assertHasNoActionErrors()
        ->assertNotified();

    expect(DeblocageAcces::query()->where('engagement_id', $this->engagement->id)->count())->toBe(1);
    $this->livewire(AuditeursCouvertsRelationManager::class, ['ownerRecord' => $this->engagement, 'pageClass' => ViewEngagement::class])
        ->assertSee((string) $this->awa->matricule)
        ->assertDontSee((string) $this->bruno->matricule);
});

it('enregistre un règlement réparti depuis la fiche, parts proposées au reste', function (): void {
    ($this->rattacher)([$this->awa, $this->bruno]);
    $this->actingAs($this->encaisseur);
    $parts = ReglementsOrganismes::partsProposees($this->engagement);

    ($this->fiche)()
        ->mountAction('enregistrerReglement')
        ->assertActionDataSet(['parts' => $parts])
        ->setActionData(['montant' => 370000, 'date_reglement' => '2026-11-16', 'mode' => 'virement', 'reference' => 'OV-55', 'parts' => $parts])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect(ReglementOrganisme::query()->sole()->montant)->toBe(370000)
        ->and(Versement::query()->where('payeur', 'employeur')->count())->toBe(2);
});

it('refuse depuis la fiche une somme des parts différente du montant, et exige la confirmation d\'un dépassement', function (): void {
    ($this->rattacher)([$this->awa, $this->bruno]);
    $this->actingAs($this->encaisseur);
    $parts = ReglementsOrganismes::partsProposees($this->engagement);
    $saisie = ['date_reglement' => '2026-11-16', 'mode' => 'virement', 'reference' => 'OV-56'];

    ($this->fiche)()->callAction('enregistrerReglement', data: $saisie + ['montant' => 400000, 'parts' => $parts])
        ->assertNotified('Règlement non enregistré');
    $trop = array_map(fn (int $p): int => $p + 1000, $parts);
    ($this->fiche)()->callAction('enregistrerReglement', data: $saisie + ['montant' => 372000, 'parts' => $trop])
        ->assertHasActionErrors(['confirmer_depassement' => 'accepted']);

    expect(ReglementOrganisme::query()->count())->toBe(0)->and(Versement::query()->count())->toBe(0);
});

it('charge l\'onglet des auditeurs couverts sans requête par ligne', function (): void {
    ($this->rattacher)([$this->awa, $this->bruno]);
    $this->actingAs($this->agent);
    DB::enableQueryLog();

    $this->livewire(AuditeursCouvertsRelationManager::class, ['ownerRecord' => $this->engagement, 'pageClass' => ViewEngagement::class])
        ->assertSee((string) $this->bruno->matricule);

    // L'imputé est préchargé avec l'inscription : aucune somme lue ligne à ligne.
    $sommes = collect(DB::getQueryLog())->filter(fn (array $q): bool => str_starts_with($q['query'], 'select sum("montant") as aggregate from "imputations_versements"'));
    expect($sommes)->toHaveCount(0);
});

it('prolonge l\'engagement (responsable) et modifie le contact depuis la fiche', function (): void {
    ($this->rattacher)([$this->awa]);
    $this->actingAs(($this->compte)('scolarite_responsable'));

    ($this->fiche)()->callAction('prolonger', data: ['echeance' => '2027-03-31', 'motif' => 'Courrier du ministère : paiement en mars'])
        ->assertHasNoActionErrors();
    ($this->fiche)()->callAction('modifierContact', data: ['contact_nom' => 'Direction financière', 'contact_email' => 'df@organisme.example'])
        ->assertHasNoActionErrors();

    expect($this->engagement->fresh())->contact_email->toBe('df@organisme.example')
        ->and($this->engagement->fresh()->echeance->toDateString())->toBe('2027-03-31')
        ->and(DeblocageAcces::query()->sole()->echeance->toDateString())->toBe('2027-03-31');
});

it('ne propose plus de rattacher sur un engagement échu', function (): void {
    $this->travelTo(now('Africa/Douala')->setDate(2027, 1, 20)->setTime(9, 0));
    $this->actingAs($this->agent);

    ($this->fiche)()->assertActionHidden('rattacher');
});

it('réserve l\'annulation d\'un règlement au responsable, depuis l\'onglet des règlements', function (): void {
    ($this->rattacher)([$this->awa]);
    $r = app(ReglementsOrganismes::class)->enregistrer($this->engagement, [
        'montant' => 185000, 'date_reglement' => '2026-11-16', 'mode' => 'virement', 'reference' => 'OV-1',
        'parts' => ReglementsOrganismes::partsProposees($this->engagement),
    ], $this->encaisseur)->reglement;
    $onglet = fn () => $this->livewire(ReglementsRelationManager::class, ['ownerRecord' => $this->engagement, 'pageClass' => ViewEngagement::class]);

    $this->actingAs($this->agent);
    $onglet()->assertCanSeeTableRecords([$r])->assertTableActionHidden('annuler', $r);

    $this->actingAs(($this->compte)('scolarite_responsable'));
    $onglet()->callTableAction('annuler', $r, data: ['motif' => 'Virement rejeté par la banque'])->assertHasNoTableActionErrors();
    expect($r->fresh()->annule_at)->not->toBeNull();
});

it('relance l\'organisme sur clic, et ouvre la lettre par un lien signé journalisé', function (): void {
    Bus::fake([SendThrottledMail::class]);
    ($this->rattacher)([$this->awa]);
    $this->actingAs($this->agent);

    ($this->fiche)()->callAction('relancer')->assertHasNoActionErrors()->assertNotified();
    ($this->fiche)()->callAction('lettre')->assertRedirect();

    Bus::assertDispatchedTimes(SendThrottledMail::class, 1);
    expect(Activity::query()->where('event', 'engagement_lettre_consultee')->count())->toBe(1);
});

it('rattache depuis « Débloquer l\'accès » de la fiche auditeur, cas « Prise en charge »', function (): void {
    $this->actingAs($this->agent);
    $auditeur = $this->awa->fresh()->auditeur;

    $this->livewire(ViewAuditeur::class, ['record' => $auditeur->getRouteKey()])
        ->callAction('debloquerAcces', data: ['type' => DeblocageAcces::PRISE_EN_CHARGE, 'engagement' => $this->engagement->uuid])
        ->assertHasNoActionErrors()
        ->assertSee('Ministère de test');

    expect(DeblocageAcces::query()->sole())->type->toBe(DeblocageAcces::PRISE_EN_CHARGE)->engagement_id->toBe($this->engagement->id);
    $this->livewire(ViewAuditeur::class, ['record' => $auditeur->getRouteKey()])
        ->assertActionHidden('prolongerDeblocage')
        ->assertActionVisible('voirPieceDeblocage');
});

it('ne propose pas d\'annuler seul, sur la fiche, un versement issu d\'un règlement', function (): void {
    ($this->rattacher)([$this->awa]);
    app(ReglementsOrganismes::class)->enregistrer($this->engagement, [
        'montant' => 185000, 'date_reglement' => '2026-11-16', 'mode' => 'virement', 'reference' => 'OV-1',
        'parts' => ReglementsOrganismes::partsProposees($this->engagement),
    ], $this->encaisseur);
    $this->actingAs(($this->compte)('scolarite_responsable'));

    $this->livewire(VersementsRelationManager::class, ['ownerRecord' => $this->awa->fresh()->auditeur, 'pageClass' => ViewAuditeur::class])
        ->assertTableActionHidden('annuler', Versement::query()->sole());
});
