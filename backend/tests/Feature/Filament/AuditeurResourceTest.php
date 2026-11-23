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
use App\Models\DeblocageAcces;
use App\Models\InscriptionAnnuelle;
use App\Models\RecuVersement;
use App\Models\User;
use App\Models\Versement;
use App\Services\Ecampus\ComptesEcampus;
use App\Services\ExportsRetenus\AttributionMatricules;
use App\Services\Scolarite\ImputationsCompte;
use App\Services\Scolarite\LibelleSituation;
use App\Services\Scolarite\OuvertureAuditeur;
use App\Services\Scolarite\RecusVersements;
use App\Services\Scolarite\RegistreVersements;
use App\Services\Scolarite\SituationFinanciereExcel;
use App\Services\Sms\SmsServiceInterface;
use App\Support\AppSettings;
use App\Support\CandidatureObjectStorage;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
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

    AppSettings::set(RecusVersements::CLE_DEPUIS, now()->subYear()->toIso8601String());
    $this->sms = [];
    $this->mock(SmsServiceInterface::class, function ($mock): void {
        $mock->shouldReceive('send')->andReturnUsing(function (string $tel, string $texte): void {
            // L'avis du reçu (émis dans le même geste que la saisie) n'est pas un SMS des accès.
            if (! str_starts_with($texte, 'PSSFP : votre recu ')) {
                $this->sms[] = $tel;
            }
        });
    });
    Storage::fake(CandidatureObjectStorage::DISK);
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

it('enregistre un versement depuis la fiche ; le SMS des accès part à sa confirmation par la scolarité', function (): void {
    $this->actingAs(($this->compte)('service_financier'));

    // Le service financier enregistre et émet le reçu en un geste (15/11/2026).
    $this->livewire(ViewAuditeur::class, ['record' => $this->fiche->getRouteKey()])
        ->callAction('enregistrerVersement', data: ($this->saisie)(['reference' => 'BOR-101']) + ['justificatif_path' => bordereauScanne()])
        ->assertHasNoActionErrors()
        ->assertNotified('Versement enregistré, reçu '.RecuVersement::query()->sole()->numero.' émis');

    // Depuis le 15/11/2026, l'argent ne compte pour l'accès qu'une fois confirmé par la scolarité.
    $v = Versement::query()->where('reference', 'BOR-101')->sole();
    expect($this->sms)->toBe([]);
    confirmerVersement($v);
    expect($this->sms)->toBe([$this->awa->phone_e164]);
});

it('laisse la fenêtre ouverte et explique un doublon de référence', function (): void {
    $this->actingAs(($this->compte)('service_financier'));
    $page = $this->livewire(ViewAuditeur::class, ['record' => $this->fiche->getRouteKey()]);
    $page->callAction('enregistrerVersement', data: ($this->saisie)(['reference' => 'BOR-7']) + ['justificatif_path' => bordereauScanne()]);

    $page->callAction('enregistrerVersement', data: ($this->saisie)(['reference' => 'bor-7']) + ['justificatif_path' => bordereauScanne()])
        ->assertNotified('Versement non enregistré');

    expect(Versement::query()->count())->toBe(1);
});

it('exige la confirmation d\'un versement au-delà du reste à payer, en annonçant l\'avance', function (): void {
    $this->actingAs(($this->compte)('service_financier'));

    $this->livewire(ViewAuditeur::class, ['record' => $this->fiche->getRouteKey()])
        ->mountAction('enregistrerVersement')
        ->assertSee('Reste à payer : '.LibelleSituation::fcfa(1185000))
        ->setActionData(($this->saisie)(['montant' => 1500000]))
        ->assertSee('Je confirme : '.LibelleSituation::fcfa(315000).' dépassent le reste à payer et iront en avance sur la scolarité à venir.')
        ->assertDontSee('trop-perçu')
        ->callMountedAction()
        ->assertHasActionErrors(['confirmer_avance' => 'accepted']);

    expect(Versement::query()->count())->toBe(0);
});

it('annonce que l\'excédent d\'un employeur attend la décision du responsable', function (): void {
    $this->actingAs(($this->compte)('service_financier'));

    $this->livewire(ViewAuditeur::class, ['record' => $this->fiche->getRouteKey()])
        ->mountAction('enregistrerVersement')
        ->setActionData(($this->saisie)(['montant' => 1285000, 'payeur' => 'employeur']) + ['justificatif_path' => bordereauScanne()])
        ->assertSee('Je confirme : '.LibelleSituation::fcfa(100000).' dépassent le reste à payer et resteront en avance, en attente de la décision du responsable.')
        ->setActionData(['confirmer_avance' => true])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect(Versement::query()->sole()->payeur)->toBe('employeur');
});

it('annonce dans la fenêtre d\'annulation qu\'un versement imputé sur deux années ne s\'annule pas en D1', function (): void {
    $v = Versement::factory()->create(['inscription_annuelle_id' => $this->fiche->inscriptionCourante->id, 'montant' => 1385000]);
    InscriptionAnnuelle::factory()->create(['auditeur_id' => $this->fiche->id,
        'bareme_id' => BaremeScolarite::factory()->m2()->create(['annee_academique' => '2027-2028'])->id]);
    app(ImputationsCompte::class)->sousVerrou($this->fiche->id, fn (ImputationsCompte $c) => $c->imputerAvance($this->fiche->id, 'ouverture', null));
    $this->actingAs(($this->compte)('scolarite_responsable'));

    $this->livewire(VersementsRelationManager::class, ['ownerRecord' => $this->fiche, 'pageClass' => ViewAuditeur::class])
        ->mountTableAction('annuler', $v)
        ->assertSee('Ce versement est imputé sur plusieurs années (2026-2027, 2027-2028)', false)
        ->setTableActionData(['categorie' => 'rejet_banque', 'motif' => 'Virement rejeté par la banque'])
        ->callMountedTableAction()
        ->assertNotified('Annulation impossible');

    expect($v->fresh()->estAnnule())->toBeFalse();
});

it('présente l\'avance du compte sur la fiche, avec la part réservée de l\'employeur', function (): void {
    Versement::factory()->create(['inscription_annuelle_id' => $this->fiche->inscriptionCourante->id, 'montant' => 1285000]);
    Versement::factory()->create(['inscription_annuelle_id' => $this->fiche->inscriptionCourante->id, 'montant' => 50000, 'payeur' => 'employeur']);
    $this->actingAs(($this->compte)('service_financier'));

    $this->get(AuditeurResource::getUrl('view', ['record' => $this->fiche]))
        ->assertOk()
        ->assertSee('Avance au compte : '.LibelleSituation::fcfa(150000))
        ->assertSee('dont '.LibelleSituation::fcfa(50000).' versés par l\'employeur, non imputés d\'office sur une autre année (décision du responsable)', false)
        ->assertDontSee('Trop-perçu');
});

it('accepte la date du jour, y compris le soir à Yaoundé', function (string $heure): void {
    Carbon::setTestNow($heure);
    $this->actingAs(($this->compte)('service_financier'));

    $this->livewire(ViewAuditeur::class, ['record' => $this->fiche->getRouteKey()])
        ->callAction('enregistrerVersement', data: ($this->saisie)(['date_versement' => InscriptionAnnuelle::aujourdhui()->toDateString()]) + ['justificatif_path' => bordereauScanne()])
        ->assertHasNoActionErrors();
    Carbon::setTestNow();

    expect(Versement::query()->count())->toBe(1);
})->with(['matin' => '2026-11-05 08:00:00', 'soir' => '2026-11-05 19:44:00', 'nuit (déjà le 6 à Yaoundé)' => '2026-11-05 23:30:00']);

it('refuse une date future et un montant nul dans le formulaire', function (): void {
    $this->actingAs(($this->compte)('service_financier'));

    $this->livewire(ViewAuditeur::class, ['record' => $this->fiche->getRouteKey()])
        ->callAction('enregistrerVersement', data: ($this->saisie)(['montant' => 0, 'date_versement' => '2099-01-01']))
        ->assertHasActionErrors(['montant', 'date_versement']);
});

it('réserve l\'annulation et la remise au responsable', function (): void {
    $v = Versement::factory()->create(['inscription_annuelle_id' => $this->fiche->inscriptionCourante->id, 'reference' => 'BOR-9']);

    $this->actingAs(($this->compte)('service_financier'));
    $this->livewire(ViewAuditeur::class, ['record' => $this->fiche->getRouteKey()])->assertActionHidden('accorderRemise');
    $this->livewire(VersementsRelationManager::class, ['ownerRecord' => $this->fiche, 'pageClass' => ViewAuditeur::class])
        ->assertTableActionHidden('annuler', $v);

    $this->actingAs(($this->compte)('scolarite_responsable'));
    $this->livewire(VersementsRelationManager::class, ['ownerRecord' => $this->fiche, 'pageClass' => ViewAuditeur::class])
        ->assertCanSeeTableRecords([$v])
        ->callTableAction('annuler', $v, data: ['categorie' => 'doublon', 'motif' => 'Doublon de saisie', 'fermeture' => true])
        ->assertHasNoTableActionErrors();
    // Annulation directe avec sa pièce de correction (décision Q11 du 16/11/2026).
    expect($v->fresh()->annule_motif)->toEndWith('Doublon de saisie')
        ->and($v->fresh()->correction?->categorie)->toBe('doublon');

    $this->livewire(ViewAuditeur::class, ['record' => $this->fiche->getRouteKey()])
        ->callAction('accorderRemise', data: ['montant' => 200000, 'motif' => 'Bourse'])
        ->assertHasNoActionErrors();
    expect($this->fiche->inscriptionCourante->fresh()->remise_montant)->toBe(200000);
});

it('filtre la liste par situation et retrouve un auditeur par son matricule', function (): void {
    Versement::factory()->create(['inscription_annuelle_id' => $this->fiche->inscriptionCourante->id, 'montant' => 185000]);
    $this->actingAs(($this->compte)('service_financier'));
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

it('exporte « Versé » imputé à l\'année et l\'avance dans une colonne L, sans bouger les colonnes A à K', function (): void {
    Versement::factory()->create(['inscription_annuelle_id' => $this->fiche->inscriptionCourante->id, 'montant' => 1285000]);
    $contenu = app(SituationFinanciereExcel::class)->generer(Auditeur::query()->with(['candidature.campagne', 'inscriptionCourante.bareme'])->get());
    $fichier = tempnam(sys_get_temp_dir(), 'sit').'.xlsx';
    file_put_contents($fichier, $contenu);
    $feuille = IOFactory::load($fichier)->getActiveSheet();
    unlink($fichier);
    $ligne = fn (Candidature $c): int => collect($feuille->toArray())->search(fn ($l) => $l[0] === $c->matricule) + 1;

    expect(array_slice(SituationFinanciereExcel::ENTETES, 0, 11))->toBe(['Matricule', 'Nom', 'Prénom', 'Promotion', 'Niveau', 'Année', 'Dû', 'Remise', 'Versé', 'Reste', 'Situation'])
        ->and(SituationFinanciereExcel::ENTETES[11])->toBe('Avance')
        ->and($feuille->getCell('I'.$ligne($this->awa))->getValue())->toBe(1185000)
        ->and($feuille->getCell('L'.$ligne($this->awa))->getValue())->toBe(100000)
        ->and($feuille->getCell('L'.$ligne($this->bruno))->getValue())->toBe(0);
});

it('cache l\'export à qui n\'a pas le droit', function (): void {
    $agent = ($this->compte)('service_financier');
    $agent->revokePermissionTo('scolarite.export');
    $agent->syncRoles([]);
    $agent->givePermissionTo(['view_any_auditeur', 'view_auditeur']);
    $this->actingAs($agent);

    $this->livewire(ListAuditeurs::class)->assertActionHidden('exporterSituation');
});

it('réserve les barèmes au responsable et refuse un doublon année + niveau', function (): void {
    $this->actingAs(($this->compte)('service_financier'));
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
    $agent = ($this->compte)('service_financier');
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
    'saut de ligne final' => ["scolarite/justificatifs/01JABCDEFGHJKMNPQRSTVWXYZ0.pdf\n"],
]);

it('joint un bordereau valide une seule fois, et le journalise', function (): void {
    $agent = ($this->compte)('service_financier');
    $v = Versement::factory()->create(['inscription_annuelle_id' => $this->fiche->inscriptionCourante->id]);
    $chemin = 'scolarite/justificatifs/01JABCDEFGHJKMNPQRSTVWXYZ0.pdf';

    app(RegistreVersements::class)->joindreJustificatif($v, $chemin, $agent);

    expect($v->fresh()->justificatif_path)->toBe($chemin)
        ->and(Activity::query()->where('event', 'justificatif_joint')->count())->toBe(1)
        ->and(fn () => app(RegistreVersements::class)->joindreJustificatif($v, $chemin, $agent))->toThrow(InvalidArgumentException::class);
});

it('refuse le bordereau déjà joint à un autre versement ou à un accès anticipé, à la saisie comme après coup', function (string $origine): void {
    $agent = ($this->compte)('service_financier');
    $chemin = 'scolarite/justificatifs/01JBORDEREAUAUTREDOSSIER00.pdf';
    $ailleurs = ['inscription_annuelle_id' => $this->bruno->auditeur->inscriptionCourante->id, 'justificatif_path' => $chemin];
    $origine === 'versement' ? Versement::factory()->create($ailleurs) : DeblocageAcces::factory()->create($ailleurs);
    $sansScan = Versement::factory()->create(['inscription_annuelle_id' => $this->fiche->inscriptionCourante->id]);

    expect(fn () => app(RegistreVersements::class)->enregistrer($this->fiche->inscriptionCourante,
        ($this->saisie)(['reference' => 'BOR-301', 'justificatif_path' => $chemin]), $agent))
        ->toThrow(InvalidArgumentException::class, 'déjà joint')
        ->and(fn () => app(RegistreVersements::class)->joindreJustificatif($sansScan, $chemin, $agent))
        ->toThrow(InvalidArgumentException::class, 'déjà joint')
        ->and(Versement::query()->where('reference', 'BOR-301')->exists())->toBeFalse()
        ->and($sansScan->fresh()->justificatif_path)->toBeNull()
        ->and(Activity::query()->where('event', 'justificatif_joint')->exists())->toBeFalse();
})->with(['versement', 'déblocage']);

describe('bordereau déposé par le formulaire', function (): void {
    beforeEach(function (): void {
        Storage::fake(CandidatureObjectStorage::DISK);
        $this->scan = fn (): UploadedFile => UploadedFile::fake()->createWithContent('bordereau.pdf', "%PDF-1.4\n%%EOF\n");
        // Bordereau d'un autre dossier, présent dans le bucket ; ou fichier du bucket rattaché à rien.
        $this->recopie = function (string $origine): string {
            $chemin = 'scolarite/justificatifs/01JZZZZZZZZZZZZZZZZZZZZZZZ.pdf';
            Storage::disk(CandidatureObjectStorage::DISK)->put($chemin, '%PDF-1.4');
            if ($origine === 'autre versement') {
                Versement::factory()->create(['inscription_annuelle_id' => $this->bruno->auditeur->inscriptionCourante->id, 'justificatif_path' => $chemin]);
            }

            return $chemin;
        };
        $this->actingAs(($this->compte)('service_financier'));
    });

    it('enregistre un versement avec le scan déposé par le formulaire', function (): void {
        $this->livewire(ViewAuditeur::class, ['record' => $this->fiche->getRouteKey()])
            ->callAction('enregistrerVersement', data: ($this->saisie)(['reference' => 'BOR-201', 'justificatif_path' => ($this->scan)()]))
            ->assertHasNoActionErrors()
            ->assertNotified('Versement enregistré, reçu '.RecuVersement::query()->sole()->numero.' émis');

        $v = Versement::query()->where('reference', 'BOR-201')->sole();
        expect(Versement::cheminJustificatifValide($v->justificatif_path))->toBeTrue()
            ->and(Storage::disk(CandidatureObjectStorage::DISK)->exists((string) $v->justificatif_path))->toBeTrue();
    });

    it('refuse à la saisie d\'un versement un chemin recopié dans l\'état du formulaire, sans effacer le fichier', function (string $origine): void {
        $chemin = ($this->recopie)($origine);

        $this->livewire(ViewAuditeur::class, ['record' => $this->fiche->getRouteKey()])
            ->callAction('enregistrerVersement', data: ($this->saisie)(['reference' => 'BOR-202', 'justificatif_path' => [(string) Str::uuid() => $chemin]]))
            ->assertNotified('Versement non enregistré');

        expect(Versement::query()->where('reference', 'BOR-202')->exists())->toBeFalse()
            ->and(Versement::query()->where('justificatif_path', $chemin)->count())->toBe($origine === 'autre versement' ? 1 : 0)
            ->and(Storage::disk(CandidatureObjectStorage::DISK)->exists($chemin))->toBeTrue();
    })->with(['autre versement', 'fichier rattaché à rien']);

    it('joint après coup le scan déposé par le formulaire', function (): void {
        $v = Versement::factory()->create(['inscription_annuelle_id' => $this->fiche->inscriptionCourante->id]);

        $this->livewire(VersementsRelationManager::class, ['ownerRecord' => $this->fiche, 'pageClass' => ViewAuditeur::class])
            ->callTableAction('ajouterJustificatif', $v, data: ['justificatif_path' => ($this->scan)()])
            ->assertNotified('Bordereau joint');

        expect(Versement::cheminJustificatifValide($v->fresh()->justificatif_path))->toBeTrue()
            ->and(Storage::disk(CandidatureObjectStorage::DISK)->exists((string) $v->fresh()->justificatif_path))->toBeTrue();
    });

    it('refuse de joindre après coup un chemin recopié dans l\'état du formulaire, sans effacer le fichier', function (string $origine): void {
        $chemin = ($this->recopie)($origine);
        $v = Versement::factory()->create(['inscription_annuelle_id' => $this->fiche->inscriptionCourante->id]);

        $this->livewire(VersementsRelationManager::class, ['ownerRecord' => $this->fiche, 'pageClass' => ViewAuditeur::class])
            ->callTableAction('ajouterJustificatif', $v, data: ['justificatif_path' => [(string) Str::uuid() => $chemin]])
            ->assertNotified('Bordereau non joint');

        expect($v->fresh()->justificatif_path)->toBeNull()
            ->and(Storage::disk(CandidatureObjectStorage::DISK)->exists($chemin))->toBeTrue();
    })->with(['autre versement', 'fichier rattaché à rien']);
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
        ->toThrow(HttpException::class)
        ->and(fn () => app(OuvertureAuditeur::class)->creerInscription($this->fiche->fresh(), ($this->compte)('service_financier')))
        ->toThrow(HttpException::class);
});
