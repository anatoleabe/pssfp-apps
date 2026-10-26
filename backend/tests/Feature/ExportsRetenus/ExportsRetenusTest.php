<?php

declare(strict_types=1);

use App\Filament\Resources\CampagneCandidatureResource\Pages\EditCampagneCandidature;
use App\Filament\Resources\CampagneCandidatureResource\Pages\ListCampagneCandidatures;
use App\Filament\Resources\CandidatureResource\Pages\ListCandidatures;
use App\Filament\Resources\CandidatureResource\Pages\ViewCandidature;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\User;
use App\Services\ExportsRetenus\AttributionMatricules;
use App\Services\ExportsRetenus\ExportsRetenus;
use App\Services\ExportsRetenus\ExportsRetenusExcel;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Spatie\Activitylog\Models\Activity;

uses()->group('filament', 'exports-retenus');

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    $this->seed([PaysSeeder::class, RegionsCamerounSeeder::class, DepartementsCamerounSeeder::class, RolePermissionSeeder::class]);

    $this->campagne = CampagneCandidature::factory()->create([
        'nom' => ['fr' => 'Année académique 2026-2027', 'en' => 'Academic year 2026-2027'],
        'promotion_numero' => 14,
    ]);

    $this->retenu = fn (string $nom, string $prenom, array $attributs = []): Candidature => Candidature::factory()
        ->forCampagne($this->campagne)->submitted()->create(array_merge([
            'nom' => $nom, 'prenom' => $prenom, 'statut' => Candidature::STATUT_ACCEPTE, 'decided_at' => now(),
        ], $attributs));

    $this->agent = fn (string $role): User => tap(
        User::factory()->create(['email' => $role.'-'.Str::random(6).'-exports@pssfp.local']),
        fn (User $u) => $u->assignRole($role),
    );
});

/** Feuille unique d'un fichier produit, relue comme le ferait l'application cible. */
function feuilleExport(string $contenu): Worksheet
{
    $chemin = tempnam(sys_get_temp_dir(), 'export').'.xlsx';
    file_put_contents($chemin, $contenu);
    $feuille = IOFactory::load($chemin)->getActiveSheet();
    unlink($chemin);

    return $feuille;
}

it('réserve matricules et exports à super_admin, admin et comité d\'admission', function (string $role, bool $autorise): void {
    expect(($this->agent)($role)->can('campagne.export_retenus'))->toBe($autorise);
})->with([
    ['super_admin', true],
    ['admin', true],
    ['admission_committee', true],
    ['receptionniste', false],
    ['librarian', false],
]);

it('numérote les retenus dans l\'ordre alphabétique, compteur commun EFP / EFSC', function (): void {
    $zoa = ($this->retenu)('Zoa', 'Paul');
    $abena = ($this->retenu)('Abena', 'Marie', ['categorie_matricule' => Candidature::CATEGORIE_EFSC]);
    $ebode = ($this->retenu)('Ébodé', 'Luc');
    Candidature::factory()->forCampagne($this->campagne)->submitted()->create(['nom' => 'Aaron', 'statut' => Candidature::STATUT_REFUSE]);
    ($this->retenu)('Aba', 'Retiré', ['withdrawn_at' => now()]);

    $r = app(AttributionMatricules::class)->attribuer($this->campagne, '26', ($this->agent)('admin'));

    expect($r)->toBe(['attribues' => 3, 'deja' => 0])
        ->and($abena->fresh()->matricule)->toBe('26EFSC001')
        ->and($ebode->fresh()->matricule)->toBe('26EFP002')
        ->and($zoa->fresh()->matricule)->toBe('26EFP003');
});

it('fige les matricules : un second passage ne change rien, un nouveau retenu prend la suite', function (): void {
    $premier = ($this->retenu)('Mballa', 'Anne');
    $service = app(AttributionMatricules::class);
    $service->attribuer($this->campagne, '26', ($this->agent)('admin'));
    $avant = $premier->fresh();

    $tardif = ($this->retenu)('Abanda', 'Jean');
    $r = $service->attribuer($this->campagne, '26', ($this->agent)('super_admin'));

    expect($r)->toBe(['attribues' => 1, 'deja' => 1])
        ->and($premier->fresh()->matricule)->toBe($avant->matricule)
        ->and($premier->fresh()->moodle_password)->toBe($avant->moodle_password)
        ->and($tardif->fresh()->matricule)->toBe('26EFP002');
});

it('chiffre le mot de passe Moodle en base et le génère conforme à la politique Moodle', function (): void {
    $c = ($this->retenu)('Owona', 'Rose');
    app(AttributionMatricules::class)->attribuer($this->campagne, '26', ($this->agent)('admin'));

    $clair = $c->fresh()->moodle_password;
    $brut = DB::table('candidatures')->where('id', $c->id)->value('moodle_password');

    expect($clair)->toMatch('/^(?=.*[A-Z])(?=.*[a-z])(?=.*\d)(?=.*@).{10}$/')
        ->and($clair)->not->toMatch('/[IlO01]/')
        ->and($brut)->not->toContain($clair);
});

it('refuse une année de matricule qui n\'a pas deux chiffres', function (): void {
    app(AttributionMatricules::class)->attribuer($this->campagne, '2026', ($this->agent)('admin'));
})->throws(InvalidArgumentException::class);

it('produit les trois fichiers avec les en-têtes exacts du modèle de la 13e promotion', function (string $type, array $entetes, string $feuille): void {
    ($this->retenu)('Ngono', 'Claire');
    app(AttributionMatricules::class)->attribuer($this->campagne, '26', ($this->agent)('admin'));

    $fichier = app(ExportsRetenus::class)->generer($this->campagne, $type, ($this->agent)('admin'), 'PROMO14_TC_2026_2027');
    $sheet = feuilleExport($fichier['contenu']);
    $derniere = $sheet->getHighestDataColumn();

    expect($sheet->rangeToArray("A1:{$derniere}1")[0])->toBe($entetes)
        ->and($sheet->getTitle())->toBe($feuille)
        ->and($sheet->getHighestDataRow())->toBe(2)
        ->and($fichier['nom'])->toBe($feuille.'.xlsx');
})->with([
    'données complètes' => [ExportsRetenusExcel::FULL_DATA, [
        'id', 'LETTRENUMERO', 'Matricule (Généré)', 'Nom', 'Prenom', 'Nom & Prenom', 'civilite', 'Date de Naissance',
        'nationalite', 'lieu_de_naissce', 'region_dorigine', 'sexe', 'statu_matrimonial', 'adresse_candidat', 'Colonne 1',
        'Colonne 2', 'email', 'ville_residence', 'dernier_diplome', 'annee_optention_diplome', 'statut_prof', 'structure',
        'adresse_structure', 'email_structure', 'ordre_candidature', 'date_enregistrement', 'langue', 'Type Etude',
        'dept_dorigine', 'Diplome Requis', 'Specialité Requise', 'Spécialité Choisie', 'Pays',
    ], 'PROMOTION 14 - Full Data'],
    'ACMA' => [ExportsRetenusExcel::ACMA, [
        'Num', 'Matricule', 'NomComplet', 'Datenais', 'LieuNais', 'Sex', 'Exp', 'Parcours', 'Categorie', 'Region',
        'Session', 'Pays', 'Telephone', 'departement', 'email',
    ], 'IMPORTATION ACMA P14'],
    'Moodle' => [ExportsRetenusExcel::MOODLE, [
        'username', 'lastname', 'firstname', 'password', 'phone1', 'phone2', 'email', 'cohort1', 'department', 'city',
    ], 'Access Plateforme Moodle'],
]);

it('remplit les colonnes clés et garde les saisies en texte', function (): void {
    $c = ($this->retenu)('=HYPERLINK("x")', 'Awa', [
        'phone_e164' => '+237699000010', 'genre' => 'F', 'civilite' => 'Mme', 'date_naissance' => '1994-03-29',
        'type_etude' => 'distanciel', 'premiere_langue' => 'fr', 'region' => 'CENTRE',
    ]);
    app(AttributionMatricules::class)->attribuer($this->campagne, '26', ($this->agent)('admin'));
    $admin = ($this->agent)('admin');

    $full = feuilleExport(app(ExportsRetenus::class)->generer($this->campagne, ExportsRetenusExcel::FULL_DATA, $admin)['contenu']);
    expect($full->getCell('B2')->getValue())->toBe('RS14001')
        ->and($full->getCell('C2')->getValue())->toBe('26EFP001')
        ->and($full->getCell('D2')->getDataType())->toBe('s')
        ->and($full->getCell('G2')->getValue())->toBe('Madame')
        ->and($full->getCell('H2')->getFormattedValue())->toBe('1994-03-29')
        ->and($full->getCell('L2')->getValue())->toBe('F')
        ->and($full->getCell('O2')->getValue())->toBe('237699000010')
        ->and($full->getCell('O2')->getDataType())->toBe('s')
        ->and($full->getCell('Y2')->getValue())->toBe($c->numero_dossier)
        ->and($full->getCell('AB2')->getValue())->toBe('Distanciel');

    $acma = feuilleExport(app(ExportsRetenus::class)->generer($this->campagne, ExportsRetenusExcel::ACMA, $admin)['contenu']);
    expect($acma->getCell('D2')->getFormattedValue())->toBe('19940329')
        ->and([$acma->getCell('G2')->getValue(), $acma->getCell('H2')->getValue(), $acma->getCell('I2')->getValue()])
        ->toBe(['FR', 'MFP', 'TC']);

    $moodle = feuilleExport(app(ExportsRetenus::class)->generer($this->campagne, ExportsRetenusExcel::MOODLE, $admin, 'PROMO14_TC_2026_2027')['contenu']);
    expect($moodle->getCell('A2')->getValue())->toBe('26efp001')
        ->and($moodle->getCell('D2')->getValue())->toBe($c->fresh()->moodle_password)
        ->and($moodle->getCell('H2')->getValue())->toBe('PROMO14_TC_2026_2027')
        ->and($moodle->getCell('I2')->getValue())->toBe('PSSFP')
        ->and($moodle->getCell('B2')->getDataType())->toBe('s')
        ->and($acma->getCell('C2')->getDataType())->toBe('s');
});

it('refuse d\'exporter tant que des retenus n\'ont pas de matricule', function (): void {
    ($this->retenu)('Essomba', 'Yves');

    app(ExportsRetenus::class)->generer($this->campagne, ExportsRetenusExcel::FULL_DATA, ($this->agent)('admin'));
})->throws(RuntimeException::class);

it('propose la cohorte Moodle et l\'année de matricule de la promotion', function (): void {
    expect(ExportsRetenus::cohorteParDefaut($this->campagne))->toBe('PROMO14_TC_2026_2027')
        ->and(AttributionMatricules::anneeParDefaut($this->campagne))->toBe('26');
});

it('attribue puis télécharge depuis l\'admin, et journalise chaque export', function (): void {
    ($this->retenu)('Fouda', 'Eric');
    $this->actingAs(($this->agent)('admission_committee'));

    $this->livewire(ListCampagneCandidatures::class)
        ->callTableAction('attribuerMatricules', $this->campagne, data: ['annee' => '26'])
        ->assertHasNoTableActionErrors()
        ->callTableAction('exportFullData', $this->campagne)
        ->assertFileDownloaded('PROMOTION 14 - Full Data.xlsx');

    // La page d'édition de campagne est réservée à l'administration.
    $this->actingAs(($this->agent)('admin'));
    $this->livewire(EditCampagneCandidature::class, ['record' => $this->campagne->getRouteKey()])
        ->callAction('exportAcma')
        ->assertHasNoActionErrors()
        ->assertFileDownloaded('IMPORTATION ACMA P14.xlsx');

    expect(Activity::query()->where('event', 'export_retenus')->count())->toBe(2);
});

it('masque matricules et exports à un rôle non autorisé', function (): void {
    $this->actingAs(($this->agent)('receptionniste'));

    $this->livewire(ListCampagneCandidatures::class)
        ->assertTableActionHidden('attribuerMatricules', $this->campagne)
        ->assertTableActionHidden('exportMoodle', $this->campagne);
});

it('classe en EFSC les retenus sans matricule et ignore les autres', function (): void {
    $libre = ($this->retenu)('Atangana', 'Rita');
    $attribue = ($this->retenu)('Bilong', 'Max');
    $attribue->forceFill(['matricule' => '26EFP009', 'rang_retenu' => 9])->save();
    $candidat = Candidature::factory()->forCampagne($this->campagne)->submitted()->create();
    $this->actingAs(($this->agent)('admin'));

    $this->livewire(ListCandidatures::class)
        ->callTableBulkAction('categorieMatriculeBulk', [$libre, $attribue, $candidat], data: ['categorie' => 'EFSC'])
        ->assertHasNoTableBulkActionErrors();

    expect($libre->fresh()->categorie_matricule)->toBe('EFSC')
        ->and($attribue->fresh()->categorie_matricule)->toBe('EFP')
        ->and($candidat->fresh()->categorie_matricule)->toBe('EFP');
});

it('ne sérialise jamais le mot de passe Moodle, ni dans la fiche Filament du dossier', function (): void {
    $c = ($this->retenu)('Nkoulou', 'Ines');
    app(AttributionMatricules::class)->attribuer($this->campagne, '26', ($this->agent)('admin'));
    $clair = $c->fresh()->moodle_password;

    expect($c->fresh()->toArray())->not->toHaveKey('moodle_password');

    $this->actingAs(($this->agent)('admin'));
    $this->livewire(ViewCandidature::class, ['record' => $c->getRouteKey()])
        ->assertDontSee($clair, false);
});

it('ne réutilise pas le rang d\'un retenu supprimé', function (): void {
    $premier = ($this->retenu)('Abega', 'Leo');
    $service = app(AttributionMatricules::class);
    $service->attribuer($this->campagne, '26', ($this->agent)('admin'));
    $premier->delete();

    $suivant = ($this->retenu)('Bekono', 'Lise');
    $service->attribuer($this->campagne, '26', ($this->agent)('admin'));

    expect($suivant->fresh()->matricule)->toBe('26EFP002');
});

it('fige l\'année des matricules d\'une promotion après la première attribution', function (): void {
    ($this->retenu)('Edzoa', 'Tom');
    $service = app(AttributionMatricules::class);
    $service->attribuer($this->campagne, '26', ($this->agent)('admin'));
    ($this->retenu)('Fanta', 'Lea');

    $service->attribuer($this->campagne, '27', ($this->agent)('admin'));
})->throws(InvalidArgumentException::class);

it('refuse un matricule déjà distribué par une autre campagne, sans rien écrire', function (): void {
    $autre = CampagneCandidature::factory()->create(['nom' => ['fr' => 'Session complémentaire 2026-2027'], 'promotion_numero' => 14]);
    $ancien = Candidature::factory()->forCampagne($autre)->submitted()->create(['statut' => Candidature::STATUT_ACCEPTE]);
    $ancien->forceFill(['matricule' => '26EFP001', 'rang_retenu' => 1])->save();
    $nouveau = ($this->retenu)('Gweth', 'Ali');

    expect(fn () => app(AttributionMatricules::class)->attribuer($this->campagne, '26', ($this->agent)('admin')))
        ->toThrow(RuntimeException::class);
    expect($nouveau->fresh()->matricule)->toBeNull();
});

it('refuse le classement EFP / EFSC à un rôle non autorisé', function (): void {
    $c = ($this->retenu)('Hamadou', 'Sali');
    $this->actingAs(($this->agent)('receptionniste'));

    $this->livewire(ListCandidatures::class)
        ->assertTableBulkActionHidden('categorieMatriculeBulk');
    expect($c->fresh()->categorie_matricule)->toBe('EFP');
});

it('attribue le matricule d\'un seul retenu depuis la liste des candidatures', function (): void {
    $premier = ($this->retenu)('Abessolo', 'Jean');
    app(AttributionMatricules::class)->attribuer($this->campagne, '26', ($this->agent)('admin'));
    $tardif = ($this->retenu)('Aaron', 'Paul');
    $autre = ($this->retenu)('Biya', 'Nina');
    $this->actingAs(($this->agent)('admission_committee'));

    // Année saisie « 27 » ignorée : celle de la promotion est figée à « 26 ».
    $this->livewire(ListCandidatures::class)
        ->callTableAction('attribuerMatricule', $tardif, data: ['annee' => '27'])
        ->assertHasNoTableActionErrors();

    expect($tardif->fresh()->matricule)->toBe('26EFP002')
        ->and($autre->fresh()->matricule)->toBeNull()
        ->and($premier->fresh()->matricule)->toBe('26EFP001');
});

it('attribue les matricules d\'une sélection, dans l\'ordre alphabétique, en ignorant les autres dossiers', function (): void {
    $numerote = ($this->retenu)('Mvondo', 'Eve');
    $numerote->forceFill(['matricule' => '26EFP005', 'rang_retenu' => 5])->save();
    $zang = ($this->retenu)('Zang', 'Rene');
    $atem = ($this->retenu)('Atem', 'Joel');
    $horsSelection = ($this->retenu)('Bello', 'Ida');
    $candidat = Candidature::factory()->forCampagne($this->campagne)->submitted()->create();
    $this->actingAs(($this->agent)('admin'));

    $this->livewire(ListCandidatures::class)
        ->callTableBulkAction('attribuerMatriculesBulk', [$zang, $atem, $numerote, $candidat], data: ['annee' => null])
        ->assertHasNoTableBulkActionErrors();

    expect($atem->fresh()->matricule)->toBe('26EFP006')
        ->and($zang->fresh()->matricule)->toBe('26EFP007')
        ->and($numerote->fresh()->matricule)->toBe('26EFP005')
        ->and($horsSelection->fresh()->matricule)->toBeNull()
        ->and($candidat->fresh()->matricule)->toBeNull();
});

it('masque l\'attribution individuelle et groupée aux rôles non autorisés', function (): void {
    $c = ($this->retenu)('Ondoa', 'Max');
    $this->actingAs(($this->agent)('receptionniste'));

    $this->livewire(ListCandidatures::class)
        ->assertTableActionHidden('attribuerMatricule', $c)
        ->assertTableBulkActionHidden('attribuerMatriculesBulk');
});
