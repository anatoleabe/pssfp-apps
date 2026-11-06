<?php

declare(strict_types=1);

use App\Models\Auditeur;
use App\Models\BaremeScolarite;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\User;
use App\Models\Versement;
use App\Services\Ecampus\ComptesEcampus;
use App\Services\Scolarite\AccesEcampus;
use App\Services\Scolarite\ImportAuditeurs;
use App\Services\Sms\SmsServiceInterface;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

uses()->group('scolarite');

/** Fichier « Full data » fictif, au format de la scolarité. */
function fullDataEssai(array $lignes): string
{
    $classeur = new Spreadsheet;
    $f = $classeur->getActiveSheet();
    $f->setTitle('PROMOTION 13 - Full Data');
    $f->fromArray(['id', 'LETTRENUMERO', 'Matricule (Généré)', 'Nom', 'Prenom', 'Nom & Prenom', 'civilite', 'Date de Naissance', 'nationalite', 'lieu_de_naissce',
        'region_dorigine', 'sexe', 'statu_matrimonial', 'adresse_candidat', 'Colonne 1', 'Colonne 2', 'email', 'ville_residence', 'dernier_diplome',
        'annee_optention_diplome', 'statut_prof', 'structure', 'langue', 'Type Etude', 'Spécialité Choisie', 'Pays'], null, 'A1');
    foreach ($lignes as $i => $l) {
        $f->fromArray(array_merge(['id' => $i, 'num' => $i], $l), null, 'A'.($i + 2));
    }
    $fichier = tempnam(sys_get_temp_dir(), 'fulldata').'.xlsx';
    (new Xlsx($classeur))->save($fichier);

    return $fichier;
}

function ligneEssai(string $matricule, string $tel, string $nom = 'ESSAI', string $naissance = '1990-05-04'): array
{
    return [$matricule, $nom, 'Awa', "$nom Awa", 'Madame', $naissance, 'Camerounaise', 'Yaoundé', 'Centre', 'F', 'Célibataire', 'Messa',
        $tel, '', Str::lower($nom).'@example.org', 'Yaoundé', 'Master', '2015', 'Fonctionnaire', 'MINFI', 'Français', 'Distanciel',
        'Audit et contrôle', 'Cameroun'];
}

beforeEach(function (): void {
    $this->seed([PaysSeeder::class, RegionsCamerounSeeder::class, DepartementsCamerounSeeder::class, RolePermissionSeeder::class]);
    $this->mock(SmsServiceInterface::class)->shouldNotReceive('send');
    $this->usi = tap(User::factory()->create(['email' => 'usi-'.Str::random(5).'@pssfp.local']), fn (User $u) => $u->assignRole('admin'));
    BaremeScolarite::factory()->m2()->create(['annee_academique' => '2026-2027']);
    $this->source = fullDataEssai([
        ligneEssai('25EFP001', '699000101', 'ADMISE'),
        ligneEssai('25EFSC002', '237699000102', 'RECALEE', '45000'),
        ligneEssai('25EFP003', '', 'SANSTEL'),
    ]);
    $this->importer = fn (bool $appliquer = true, array $admis = ['25EFP001', '24EFP090']) => app(ImportAuditeurs::class)
        ->importer([$this->source], $admis, '2026-2027', 'M2', $this->usi, $appliquer);
});

it('n\'écrit rien en essai à blanc', function (): void {
    $b = ($this->importer)(false);

    expect($b['crees'])->toBe(2)->and($b['inscrits'])->toBe(1)
        ->and(Candidature::query()->count())->toBe(0)
        ->and(CampagneCandidature::query()->count())->toBe(0);
});

it('importe tous les auditeurs, inscrit seulement les admis, et signale les manques', function (): void {
    $b = ($this->importer)();

    expect($b['crees'])->toBe(2)->and($b['inscrits'])->toBe(1)
        ->and($b['sans_telephone'])->toBe(['25EFP003'])
        ->and($b['admis_introuvables'])->toBe(['24EFP090']);

    $admise = Candidature::query()->where('matricule', '25EFP001')->sole();
    $recalee = Candidature::query()->where('matricule', '25EFSC002')->sole();
    expect($admise->phone_e164)->toBe('+237699000101')
        ->and($admise->statut)->toBe(Candidature::STATUT_ACCEPTE)
        ->and($admise->submitted_at)->toBeNull()
        ->and($admise->ajoute_par)->toBe($this->usi->id)
        ->and($admise->civilite)->toBe('Mme')
        ->and($admise->type_etude)->toBe('distanciel')
        ->and($admise->nationalite)->toBe('CM')
        ->and($admise->campagne->promotion_numero)->toBe(13)
        ->and($admise->campagne->status)->toBe('closed')
        ->and($admise->auditeur->inscriptionCourante->niveau)->toBe('M2')
        ->and($admise->auditeur->inscriptionCourante->montant_inscription)->toBe(135000)
        ->and($admise->auditeur->inscriptionCourante->acces_notifie_at)->not->toBeNull()
        ->and($recalee->categorie_matricule)->toBe('EFSC')
        ->and($recalee->date_naissance->toDateString())->toBe('2023-03-15')
        ->and($recalee->auditeur)->not->toBeNull()
        ->and($recalee->auditeur->inscriptionCourante)->toBeNull();
});

it('est idempotent', function (): void {
    ($this->importer)();
    $b = ($this->importer)();

    expect($b['crees'])->toBe(0)->and($b['deja'])->toBe(['25EFP001', '25EFSC002'])
        ->and(Candidature::query()->count())->toBe(2)
        ->and(Auditeur::query()->count())->toBe(2);
});

it('n\'utilise jamais le téléphone d\'un compte du personnel', function (): void {
    tap(User::factory()->create(['phone_e164' => '+237699000101', 'email' => 'agent@pssfp.local']), fn (User $u) => $u->assignRole('editor'));

    $b = ($this->importer)();

    expect($b['compte_occupe'])->toBe(['25EFP001'])
        ->and(Candidature::query()->where('matricule', '25EFP001')->exists())->toBeFalse();
});

it('ouvre l\'accès sans mot de passe connu une fois l\'inscription soldée', function (): void {
    ($this->importer)();
    $admise = Candidature::query()->where('matricule', '25EFP001')->sole();
    $admise->forceFill(['ecampus_statut' => ComptesEcampus::EXISTANT])->save();
    Versement::factory()->create(['inscription_annuelle_id' => $admise->auditeur->inscriptionCourante->id, 'montant' => 135000]);

    $dossier = Candidature::query()->findOrFail($admise->id);
    expect(AccesEcampus::etat($dossier))->toBe(AccesEcampus::DISPONIBLE)
        ->and(AccesEcampus::motDePasseConnu($dossier))->toBeFalse();

    $jeton = $admise->user->createToken('candidat', ['application:read'])->plainTextToken;
    $this->withToken($jeton)->getJson('/v1/applications/me/scolarite')->assertJsonPath('data.acces.mot_de_passe_connu', false);
    $this->withToken($jeton)->postJson('/v1/applications/me/acces/reveler')->assertNotFound()->assertJsonPath('code', 'mot_de_passe_inconnu');
});

it('lit les matricules d\'une liste texte', function (): void {
    $f = tempnam(sys_get_temp_dir(), 'admis');
    file_put_contents($f, "25efp001\tNOM\n 25EFSC002 \n\n");

    expect(ImportAuditeurs::listeAdmis($f))->toBe(['25EFP001', '25EFSC002']);
});

it('normalise les téléphones', function (string $saisie, string $pays, ?string $attendu): void {
    expect(ImportAuditeurs::telephone($saisie, $pays))->toBe($attendu);
})->with([
    ['699000101', 'Cameroun', '+237699000101'],
    ['237 699 00 01 01', 'Cameroun', '+237699000101'],
    ['23566423680', 'Tchad', '+23566423680'],
    ['12345', 'Cameroun', null],
    ['', 'Cameroun', null],
]);

it('écarte un téléphone en double et le compte d\'une autre personne', function (): void {
    $source = fullDataEssai([ligneEssai('25EFP010', '699000201', 'PREMIER'), ligneEssai('25EFP011', '699000201', 'DOUBLON'), ligneEssai('25EFP012', '699000202', 'AUTRE')]);
    User::factory()->create(['phone_e164' => '+237699000202', 'email' => null, 'date_naissance' => '1970-01-01'])->assignRole('candidat');

    $b = app(ImportAuditeurs::class)->importer([$source], [], '2026-2027', 'M2', $this->usi, true);

    expect($b['crees'])->toBe(1)->and($b['telephone_en_double'])->toBe(['25EFP011'])->and($b['autre_personne'])->toBe(['25EFP012']);
});
