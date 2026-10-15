<?php

declare(strict_types=1);

use App\Filament\Resources\CampagneCandidatureResource\Pages\EditCampagneCandidature;
use App\Filament\Resources\CampagneCandidatureResource\Pages\ListCampagneCandidatures;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\User;
use App\Services\ResultatsSelection\ResultatsSelectionExport;
use App\Services\ResultatsSelection\SeanceJury;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Activitylog\Models\Activity;

uses()->group('filament', 'resultats-selection');

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    $this->seed([PaysSeeder::class, RegionsCamerounSeeder::class, DepartementsCamerounSeeder::class, RolePermissionSeeder::class]);

    $this->campagne = CampagneCandidature::factory()->create([
        'nom' => ['fr' => 'Année académique 2026-2027', 'en' => 'Academic year 2026-2027'],
        'promotion_numero' => 14,
    ]);

    Candidature::factory()->forCampagne($this->campagne)->submitted()->create([
        'nom' => 'Ndzana & Fils', 'prenom' => 'Aurélie', 'depot_physique_at' => now()->subDay(),
        'domaine_diplome_requis' => 'economie', 'specialite_diplome_requis' => null,
        'specialite' => 'Audit et contrôle des Finances Publiques',
    ]);
    Candidature::factory()->forCampagne($this->campagne)->submitted()->create(['nom' => 'Absent', 'prenom' => 'Pierre']);

    $this->agent = fn (string $role): User => tap(
        User::factory()->create(['email' => $role.'-resultats@pssfp.local']),
        fn (User $u) => $u->assignRole($role),
    );
});

it('réserve le téléchargement à super_admin, admin et comité d\'admission', function (string $role, bool $autorise): void {
    expect(($this->agent)($role)->can('campagne.download_resultats'))->toBe($autorise);
})->with([
    ['super_admin', true],
    ['admin', true],
    ['admission_committee', true],
    ['receptionniste', false],
    ['librarian', false],
    ['editor', false],
]);

it('masque les actions aux rôles sans la permission', function (): void {
    $this->actingAs(($this->agent)('receptionniste'));

    $this->livewire(ListCampagneCandidatures::class)
        ->assertTableActionHidden('pvJury', $this->campagne)
        ->assertTableActionHidden('communiqueResultats', $this->campagne);
});

it('ne produit aucun document si l\'action est forcée sans la permission', function (): void {
    $this->actingAs(($this->agent)('receptionniste'));

    // Appels Livewire bruts, comme le ferait un client forgé : Filament ne
    // monte pas une action masquée, rien n'est généré ni journalisé.
    $this->livewire(ListCampagneCandidatures::class)
        ->call('mountTableAction', 'pvJury', (string) $this->campagne->getKey())
        ->call('callMountedTableAction')
        ->assertNoFileDownloaded();

    expect(Activity::query()->where('event', 'resultats_selection_downloaded')->exists())->toBeFalse();
});

it('télécharge le PV en Word depuis la liste et trace le téléchargement', function (): void {
    $comite = ($this->agent)('admission_committee');
    $this->actingAs($comite);

    $this->livewire(ListCampagneCandidatures::class)
        ->assertTableActionVisible('pvJury', $this->campagne)
        ->callTableAction('pvJury', $this->campagne, data: [
            'date_deliberation' => '2026-10-14',
            'heure_deliberation' => '10 heures',
        ])
        ->assertHasNoTableActionErrors()
        ->assertFileDownloaded('PV-jury-selection-P14-2026-2027.docx');

    $log = Activity::query()->where('event', 'resultats_selection_downloaded')->sole();
    expect($log->causer_id)->toBe($comite->id)
        ->and($log->subject_id)->toBe($this->campagne->id)
        ->and($log->properties->all())->toMatchArray(['document' => 'pv', 'format' => 'docx', 'retenus' => 1, 'en_ligne' => 2,
            'doublons_conserves' => false, 'doublons_retires' => 0]);
});

it('télécharge le communiqué en Word depuis la page d\'édition', function (): void {
    $this->actingAs(($this->agent)('admin'));

    $this->livewire(EditCampagneCandidature::class, ['record' => $this->campagne->getRouteKey()])
        ->callAction('communiqueResultats')
        ->assertHasNoActionErrors()
        ->assertFileDownloaded('Communique-resultats-P14-2026-2027.docx');
});

it('produit un .docx valide qui liste les retenus, caractères spéciaux échappés', function (string $document): void {
    $fichier = app(ResultatsSelectionExport::class)->generer(
        $this->campagne, $document, SeanceJury::vide(), ($this->agent)('admin'),
    );

    $chemin = tempnam(sys_get_temp_dir(), 'docx');
    file_put_contents($chemin, $fichier['contenu']);
    $zip = new ZipArchive;
    expect($zip->open($chemin))->toBeTrue();
    $xml = (string) $zip->getFromName('word/document.xml');
    $zip->close();
    unlink($chemin);

    expect(simplexml_load_string($xml))->not->toBeFalse()
        ->and($xml)->toContain('NDZANA &amp; FILS')
        ->and($xml)->not->toContain('ABSENT')
        ->and($fichier['mime'])->toBe('application/vnd.openxmlformats-officedocument.wordprocessingml.document');
})->with(['communique', 'pv']);

it('écarte le doublon à l\'export sauf si l\'agent choisit de le conserver', function (bool $conserver, int $retenus): void {
    $this->actingAs(($this->agent)('admin'));
    foreach ([3, 1] as $jours) {
        Candidature::factory()->forCampagne($this->campagne)->submitted()->create([
            'nom' => 'Ekane', 'prenom' => 'Rose', 'date_naissance' => '1992-02-02',
            'depot_physique_at' => now()->subDays($jours),
        ]);
    }

    $this->livewire(ListCampagneCandidatures::class)
        ->mountTableAction('communiqueResultats', $this->campagne)
        ->assertSee('1 doublon détecté')
        ->assertSee('EKANE ROSE')
        ->setTableActionData(['conserver_doublons' => $conserver])
        ->callMountedTableAction()
        ->assertFileDownloaded('Communique-resultats-P14-2026-2027.docx');

    $log = Activity::query()->where('event', 'resultats_selection_downloaded')->sole();
    expect($log->properties['retenus'])->toBe($retenus)
        ->and($log->properties['doublons_conserves'])->toBe($conserver);
})->with([
    'par défaut, un seul dossier' => [false, 2],
    'conservés à la demande' => [true, 3],
]);

it('produit la liste Excel des retenus au format du modèle, sans formule injectable', function (): void {
    Candidature::factory()->forCampagne($this->campagne)->submitted()->create([
        'nom' => '=HYPERLINK("http://x")', 'prenom' => 'Eve', 'depot_physique_at' => now(),
        'date_naissance' => '1990-05-04', 'genre' => 'F', 'type_etude' => 'distanciel', 'region' => 'CENTRE',
        'diplome_requis' => 'licence-bachelor', 'domaine_diplome_requis' => 'autres', 'specialite_diplome_requis' => 'Droit public',
        'institut_diplome_requis' => 'Université de Douala', 'annee_diplome_requis' => 2015,
        'statut_actuel' => 'Contractuel-Etat', 'fonction_actuelle' => 'Comptable matières',
        'specialite' => 'Fiscalité, Finance et Comptabilité Publique',
    ]);

    $fichier = app(ResultatsSelectionExport::class)->generer(
        $this->campagne, 'liste', SeanceJury::vide(), ($this->agent)('admin'),
    );
    expect($fichier['nom'])->toBe('Liste-candidats-retenus-P14-2026-2027.xlsx')
        ->and($fichier['mime'])->toBe('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    $chemin = tempnam(sys_get_temp_dir(), 'xlsx');
    file_put_contents($chemin, $fichier['contenu']);
    $feuille = IOFactory::load($chemin)->getActiveSheet();
    unlink($chemin);

    $entetes = $feuille->rangeToArray('A14:R14')[0];
    expect($entetes)->toBe(['N°', 'N° dossier', 'NOM', 'PRENOM', 'Date Naiss.', 'Lieu Naissance', 'Région', 'Sexe',
        'T. Etude', 'Diplôme Requis', 'Spécialité Requise', 'Établissement de délivrance', 'Année de délivrance',
        'Statut actuel', 'Fonction', 'Spécialité', 'Pays', 'Décision'])
        ->and($feuille->getTableCollection())->toHaveCount(1)
        ->and($feuille->getPageSetup()->getRowsToRepeatAtTop())->toBe([14, 14]);

    // Ligne de la candidate « =HYPERLINK… » (tri alphabétique : « = » avant les lettres).
    $ligne = $feuille->rangeToArray('A15:R15', null, false, false)[0];
    expect($feuille->getCell('C15')->getDataType())->toBe('s')
        ->and($ligne[2])->toBe('=HYPERLINK("HTTP://X")')
        ->and($ligne[6])->toBe('Centre')
        ->and([$ligne[7], $ligne[8], $ligne[9]])->toBe(['F', 'D', 'Licence / Bachelor'])
        ->and($ligne[10])->toBe('Droit public')
        ->and([$ligne[11], $ligne[12], $ligne[13], $ligne[14]])->toBe(['Université de Douala', 2015, 'Agent contractuel de l’État', 'Comptable matières'])
        ->and([$ligne[15], $ligne[16], $ligne[17]])->toBe(['FFCP', 'Cameroun', null])
        ->and($feuille->getCell('E15')->getFormattedValue())->toBe('04/05/1990')
        // Domaine Droit / Économie / Gestion : le domaine tient lieu de spécialité.
        ->and($feuille->getCell('K16')->getValue())->toBe('Économie');
});

it('télécharge la liste Excel depuis la liste des campagnes', function (): void {
    $this->actingAs(($this->agent)('admission_committee'));

    $this->livewire(ListCampagneCandidatures::class)
        ->callTableAction('listeRetenus', $this->campagne)
        ->assertHasNoTableActionErrors()
        ->assertFileDownloaded('Liste-candidats-retenus-P14-2026-2027.xlsx');
});

it('insère les statistiques et deux graphiques dans le PV', function (): void {
    $fichier = app(ResultatsSelectionExport::class)->generer(
        $this->campagne, 'pv', SeanceJury::vide(), ($this->agent)('admin'),
    );

    $chemin = tempnam(sys_get_temp_dir(), 'docx');
    file_put_contents($chemin, $fichier['contenu']);
    $zip = new ZipArchive;
    $zip->open($chemin);
    $xml = (string) $zip->getFromName('word/document.xml');
    $graphique = (string) $zip->getFromName('word/charts/chart1.xml');
    $statuts = $zip->getFromName('word/charts/chart2.xml');
    $zip->close();
    unlink($chemin);

    expect($xml)->toContain('SYNTHESE DES DOSSIERS RETENUS')
        ->toContain('REPARTITION SELON LE STATUT ACTUEL')
        ->toContain('REPARTITION PAR TRANCHE D’AGE')
        ->toContain('REPARTITION SELON LE DOMAINE DU DIPLOME D’ACCES')
        ->and($graphique)->toContain('<c:overlay val="0"/>')
        ->and($statuts)->not->toBeFalse();
});

it('refuse un document inconnu', function (): void {
    app(ResultatsSelectionExport::class)->generer(
        $this->campagne, 'attestation', SeanceJury::vide(), ($this->agent)('admin'),
    );
})->throws(InvalidArgumentException::class);
