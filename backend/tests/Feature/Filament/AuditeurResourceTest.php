<?php

declare(strict_types=1);

use App\Filament\Resources\AuditeurResource;
use App\Filament\Resources\AuditeurResource\Pages\ListAuditeurs;
use App\Filament\Resources\AuditeurResource\Pages\ViewAuditeur;
use App\Filament\Resources\AuditeurResource\RelationManagers\VersementsRelationManager;
use App\Filament\Resources\BaremeScolariteResource;
use App\Filament\Resources\BaremeScolariteResource\Pages\CreateBaremeScolarite;
use App\Filament\Resources\CandidatureResource\Pages\ListCandidatures;
use App\Models\Auditeur;
use App\Models\BaremeScolarite;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\InscriptionAnnuelle;
use App\Models\User;
use App\Models\Versement;
use App\Services\Ecampus\ComptesEcampus;
use App\Services\ExportsRetenus\AttributionMatricules;
use App\Services\Scolarite\OuvertureAuditeur;
use App\Services\Scolarite\RegistreVersements;
use App\Services\Scolarite\SituationFinanciereExcel;
use App\Services\Sms\SmsServiceInterface;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses()->group('filament', 'scolarite');

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    $this->seed([PaysSeeder::class, RegionsCamerounSeeder::class, DepartementsCamerounSeeder::class, RolePermissionSeeder::class]);
    $this->compte = fn (string $role): User => tap(User::factory()->create(['email' => $role.'-'.Str::random(6).'@pssfp.local']), fn (User $u) => $u->assignRole($role));
    $this->campagne = CampagneCandidature::factory()->create(['nom' => ['fr' => 'Année académique 2026-2027'], 'promotion_numero' => 14]);
    BaremeScolarite::factory()->create(['annee_academique' => '2026-2027', 'niveau' => 'M1', 'tranche1_echeance' => '2020-01-01']);
    $retenu = fn (string $nom, string $tel): Candidature => Candidature::factory()->forCampagne($this->campagne)->submitted()->create([
        'nom' => $nom, 'prenom' => 'Essai', 'statut' => Candidature::STATUT_ACCEPTE, 'phone_e164' => $tel, 'email' => Str::random(8).'@example.org',
        'ecampus_statut' => ComptesEcampus::CREE,
    ]);
    $this->awa = $retenu('Abena', '+237699001001');
    $this->bruno = $retenu('Bekono', '+237699001002');
    app(AttributionMatricules::class)->attribuer($this->campagne, '26', ($this->compte)('admin'));
    $this->awa->refresh();
    $this->bruno->refresh();
    $this->fiche = $this->awa->auditeur;

    $this->sms = [];
    $this->mock(SmsServiceInterface::class, function ($mock): void {
        $mock->shouldReceive('send')->andReturnUsing(function (string $tel): void {
            $this->sms[] = $tel;
        });
    });
    $this->saisie = fn (array $champs = []): array => $champs + [
        'montant' => 185000, 'date_versement' => '2026-11-02', 'mode' => 'cremincam_agence', 'reference' => 'BOR-'.Str::random(6), 'payeur' => 'auditeur',
    ];
});

it('ouvre la liste et la fiche aux agents de scolarité, pas aux autres rôles', function (string $role, bool $ouvert): void {
    $this->actingAs(($this->compte)($role));

    $this->get(AuditeurResource::getUrl('index'))->assertStatus($ouvert ? 200 : 403);
    $this->get(AuditeurResource::getUrl('view', ['record' => $this->fiche]))->assertStatus($ouvert ? 200 : 403);
})->with([
    ['scolarite_agent', true],
    ['scolarite_responsable', true],
    ['admin', true],
    ['caisse', false],
    ['receptionniste', false],
    ['admission_committee', false],
    ['editor', false],
]);

it('ne montre jamais le mot de passe FOAD sur la fiche', function (): void {
    $this->actingAs(($this->compte)('scolarite_responsable'));

    $this->get(AuditeurResource::getUrl('view', ['record' => $this->fiche]))
        ->assertOk()
        ->assertSee((string) $this->awa->matricule)
        ->assertDontSee((string) $this->awa->moodle_password);
});

it('enregistre un versement depuis la fiche, et prévient par SMS au premier solde', function (): void {
    $this->actingAs(($this->compte)('scolarite_agent'));

    $this->livewire(ViewAuditeur::class, ['record' => $this->fiche->getRouteKey()])
        ->callAction('enregistrerVersement', data: ($this->saisie)(['reference' => 'BOR-101']))
        ->assertHasNoActionErrors()
        ->assertNotified('Versement enregistré');

    expect(Versement::query()->where('reference', 'BOR-101')->exists())->toBeTrue()
        ->and($this->sms)->toBe([$this->awa->phone_e164]);
});

it('laisse la fenêtre ouverte et explique un doublon de référence', function (): void {
    $this->actingAs(($this->compte)('scolarite_agent'));
    $page = $this->livewire(ViewAuditeur::class, ['record' => $this->fiche->getRouteKey()]);
    $page->callAction('enregistrerVersement', data: ($this->saisie)(['reference' => 'BOR-7']));

    $page->callAction('enregistrerVersement', data: ($this->saisie)(['reference' => 'bor-7']))
        ->assertNotified('Versement non enregistré');

    expect(Versement::query()->count())->toBe(1);
});

it('exige la confirmation d\'un trop-perçu', function (): void {
    $this->actingAs(($this->compte)('scolarite_agent'));

    $this->livewire(ViewAuditeur::class, ['record' => $this->fiche->getRouteKey()])
        ->callAction('enregistrerVersement', data: ($this->saisie)(['montant' => 1500000]))
        ->assertHasActionErrors(['confirmer_trop_percu' => 'accepted']);

    expect(Versement::query()->count())->toBe(0);
});

it('accepte la date du jour, y compris le soir à Yaoundé', function (string $heure): void {
    Carbon::setTestNow($heure);
    $this->actingAs(($this->compte)('scolarite_agent'));

    $this->livewire(ViewAuditeur::class, ['record' => $this->fiche->getRouteKey()])
        ->callAction('enregistrerVersement', data: ($this->saisie)(['date_versement' => InscriptionAnnuelle::aujourdhui()->toDateString()]))
        ->assertHasNoActionErrors();
    Carbon::setTestNow();

    expect(Versement::query()->count())->toBe(1);
})->with(['matin' => '2026-11-05 08:00:00', 'soir' => '2026-11-05 19:44:00', 'nuit (déjà le 6 à Yaoundé)' => '2026-11-05 23:30:00']);

it('refuse une date future et un montant nul dans le formulaire', function (): void {
    $this->actingAs(($this->compte)('scolarite_agent'));

    $this->livewire(ViewAuditeur::class, ['record' => $this->fiche->getRouteKey()])
        ->callAction('enregistrerVersement', data: ($this->saisie)(['montant' => 0, 'date_versement' => '2099-01-01']))
        ->assertHasActionErrors(['montant', 'date_versement']);
});

it('réserve l\'annulation et la remise au responsable', function (): void {
    $v = Versement::factory()->create(['inscription_annuelle_id' => $this->fiche->inscriptionCourante->id, 'reference' => 'BOR-9']);

    $this->actingAs(($this->compte)('scolarite_agent'));
    $this->livewire(ViewAuditeur::class, ['record' => $this->fiche->getRouteKey()])->assertActionHidden('accorderRemise');
    $this->livewire(VersementsRelationManager::class, ['ownerRecord' => $this->fiche, 'pageClass' => ViewAuditeur::class])
        ->assertTableActionHidden('annuler', $v);

    $this->actingAs(($this->compte)('scolarite_responsable'));
    $this->livewire(VersementsRelationManager::class, ['ownerRecord' => $this->fiche, 'pageClass' => ViewAuditeur::class])
        ->assertCanSeeTableRecords([$v])
        ->callTableAction('annuler', $v, data: ['motif' => 'Doublon'])
        ->assertHasNoTableActionErrors();
    expect($v->fresh()->annule_motif)->toBe('Doublon');

    $this->livewire(ViewAuditeur::class, ['record' => $this->fiche->getRouteKey()])
        ->callAction('accorderRemise', data: ['montant' => 200000, 'motif' => 'Bourse'])
        ->assertHasNoActionErrors();
    expect($this->fiche->inscriptionCourante->fresh()->remise_montant)->toBe(200000);
});

it('filtre la liste par situation et retrouve un auditeur par son matricule', function (): void {
    Versement::factory()->create(['inscription_annuelle_id' => $this->fiche->inscriptionCourante->id, 'montant' => 185000]);
    $this->actingAs(($this->compte)('scolarite_agent'));
    $bruno = $this->bruno->auditeur;

    $this->livewire(ListAuditeurs::class)
        ->filterTable('situation', 'non_soldee')
        ->assertCanSeeTableRecords([$bruno])->assertCanNotSeeTableRecords([$this->fiche])
        // Même priorité que le badge : l'inscription non soldée de Bruno ne compte pas « en retard ».
        ->filterTable('situation', 'en_retard')
        ->assertCanSeeTableRecords([$this->fiche])->assertCanNotSeeTableRecords([$bruno])
        ->filterTable('situation', 'a_jour')
        ->assertCanNotSeeTableRecords([$this->fiche, $bruno])
        ->filterTable('situation', 'soldee')
        ->assertCanNotSeeTableRecords([$this->fiche, $bruno]);

    $this->livewire(ListAuditeurs::class)
        ->searchTable(mb_strtolower((string) $this->awa->matricule))
        ->assertCanSeeTableRecords([$this->fiche])->assertCanNotSeeTableRecords([$bruno]);
});

it('exporte la situation sans téléphone, en texte non interprété', function (): void {
    $this->awa->forceFill(['nom' => '=HYPERLINK("x")'])->save();
    $contenu = app(SituationFinanciereExcel::class)->generer(Auditeur::query()->with(['candidature.campagne', 'inscriptionCourante.bareme'])->get());
    $fichier = tempnam(sys_get_temp_dir(), 'sit').'.xlsx';
    file_put_contents($fichier, $contenu);
    $feuille = IOFactory::load($fichier)->getActiveSheet();
    $lignes = $feuille->toArray();
    unlink($fichier);

    expect($lignes[0])->toBe(SituationFinanciereExcel::ENTETES)
        ->and(collect($lignes)->flatten()->filter()->contains($this->awa->phone_e164))->toBeFalse()
        ->and($feuille->getCell('B'.(collect($lignes)->search(fn ($l) => $l[0] === $this->awa->matricule) + 1))->getDataType())->toBe('s');
});

it('cache l\'export à qui n\'a pas le droit', function (): void {
    $agent = ($this->compte)('scolarite_agent');
    $agent->revokePermissionTo('scolarite.export');
    $agent->syncRoles([]);
    $agent->givePermissionTo(['view_any_auditeur', 'view_auditeur']);
    $this->actingAs($agent);

    $this->livewire(ListAuditeurs::class)->assertActionHidden('exporterSituation');
});

it('réserve les barèmes au responsable et refuse un doublon année + niveau', function (): void {
    $this->actingAs(($this->compte)('scolarite_agent'));
    $this->get(BaremeScolariteResource::getUrl('index'))->assertForbidden();

    $this->actingAs(($this->compte)('scolarite_responsable'));
    $this->livewire(CreateBaremeScolarite::class)
        ->fillForm(['annee_academique' => '2026-2027', 'niveau' => 'M1', 'montant_inscription' => 1, 'montant_tranche1' => 1, 'montant_tranche2' => 1])
        ->call('create')
        ->assertHasFormErrors(['annee_academique' => 'unique']);

    $this->livewire(CreateBaremeScolarite::class)
        ->fillForm(['annee_academique' => '2026-2028', 'niveau' => 'M2', 'montant_inscription' => 1, 'montant_tranche1' => 1, 'montant_tranche2' => 1])
        ->call('create')
        ->assertHasFormErrors(['annee_academique']);

    $this->livewire(CreateBaremeScolarite::class)
        ->fillForm(['annee_academique' => '2026-2027', 'niveau' => 'M2', 'montant_inscription' => 135000, 'montant_tranche1' => 500000, 'montant_tranche2' => 500000])
        ->call('create')
        ->assertHasNoFormErrors();
    expect(BaremeScolarite::query()->where('niveau', 'M2')->value('montant_inscription'))->toBe(135000);
});

it('mène de la liste des candidatures à la fiche auditeur', function (): void {
    $this->actingAs(($this->compte)('admin'));

    $this->livewire(ListCandidatures::class)
        ->assertTableActionVisible('ficheAuditeur', $this->awa);
});

it('refuse un chemin de bordereau qui ne vient pas du formulaire', function (string $chemin): void {
    $agent = ($this->compte)('scolarite_agent');
    $v = Versement::factory()->create(['inscription_annuelle_id' => $this->fiche->inscriptionCourante->id]);

    expect(fn () => app(RegistreVersements::class)->joindreJustificatif($v, $chemin, $agent))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(RegistreVersements::class)->enregistrer($this->fiche->inscriptionCourante,
            ($this->saisie)(['justificatif_path' => $chemin]), $agent))
        ->toThrow(InvalidArgumentException::class)
        ->and($v->fresh()->justificatif_path)->toBeNull();

    // Un chemin posé à la main en base n'est jamais signé.
    $v->forceFill(['justificatif_path' => $chemin])->save();
    $this->actingAs($agent);
    $this->livewire(VersementsRelationManager::class, ['ownerRecord' => $this->fiche, 'pageClass' => ViewAuditeur::class])
        ->assertTableActionHidden('voirJustificatif', $v);
})->with([
    'récépissé d\'un candidat' => ['candidatures/0193a8b2-uuid/recipisse.pdf'],
    'remontée' => ['scolarite/justificatifs/../signatures/signature.png'],
    'signature' => ['signatures/signature-01JABCDEFGHJKMNPQRSTVWXYZ0.png'],
    'extension' => ['scolarite/justificatifs/01JABCDEFGHJKMNPQRSTVWXYZ0.php'],
]);

it('joint un bordereau valide une seule fois, et le journalise', function (): void {
    $agent = ($this->compte)('scolarite_agent');
    $v = Versement::factory()->create(['inscription_annuelle_id' => $this->fiche->inscriptionCourante->id]);
    $chemin = 'scolarite/justificatifs/01JABCDEFGHJKMNPQRSTVWXYZ0.pdf';

    app(RegistreVersements::class)->joindreJustificatif($v, $chemin, $agent);

    expect($v->fresh()->justificatif_path)->toBe($chemin)
        ->and(Activity::query()->where('event', 'justificatif_joint')->count())->toBe(1)
        ->and(fn () => app(RegistreVersements::class)->joindreJustificatif($v, $chemin, $agent))->toThrow(InvalidArgumentException::class);
});

it('journalise les montants d\'un barème modifié', function (): void {
    $this->actingAs(($this->compte)('scolarite_responsable'));
    $b = BaremeScolarite::query()->firstOrFail();
    $b->update(['montant_tranche2' => 450000]);

    $journal = Activity::query()->where('log_name', 'scolarite')->where('subject_type', $b->getMorphClass())->latest('id')->firstOrFail();
    expect($journal->properties['attributes']['montant_tranche2'])->toBe(450000)
        ->and($journal->properties['old']['montant_tranche2'])->toBe(500000);
});

it('refuse de créer une inscription sur un dossier retiré, et vérifie le droit', function (): void {
    $this->fiche->inscriptions()->delete();
    $this->awa->forceFill(['withdrawn_at' => now()])->save();

    expect(fn () => app(OuvertureAuditeur::class)->creerInscription($this->fiche->fresh(), ($this->compte)('scolarite_agent')))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(OuvertureAuditeur::class)->creerInscription($this->fiche->fresh(), ($this->compte)('caisse')))
        ->toThrow(HttpException::class);
});
