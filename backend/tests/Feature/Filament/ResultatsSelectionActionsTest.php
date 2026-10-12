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
        ->set('mountedTableActionsData.0.format', 'pdf')
        ->call('callMountedTableAction')
        ->assertNoFileDownloaded();

    expect(Activity::query()->where('event', 'resultats_selection_downloaded')->exists())->toBeFalse();
});

it('télécharge le PV en PDF depuis la liste et trace le téléchargement', function (): void {
    $comite = ($this->agent)('admission_committee');
    $this->actingAs($comite);

    $this->livewire(ListCampagneCandidatures::class)
        ->assertTableActionVisible('pvJury', $this->campagne)
        ->callTableAction('pvJury', $this->campagne, data: [
            'format' => 'pdf',
            'date_deliberation' => '2026-10-14',
            'heure_deliberation' => '10 heures',
        ])
        ->assertHasNoTableActionErrors()
        ->assertFileDownloaded('PV-jury-selection-P14-2026-2027.pdf');

    $log = Activity::query()->where('event', 'resultats_selection_downloaded')->sole();
    expect($log->causer_id)->toBe($comite->id)
        ->and($log->subject_id)->toBe($this->campagne->id)
        ->and($log->properties->all())->toMatchArray(['document' => 'pv', 'format' => 'pdf', 'retenus' => 1, 'en_ligne' => 2]);
});

it('télécharge le communiqué en Word depuis la page d\'édition', function (): void {
    $this->actingAs(($this->agent)('admin'));

    $this->livewire(EditCampagneCandidature::class, ['record' => $this->campagne->getRouteKey()])
        ->callAction('communiqueResultats', data: ['format' => 'docx'])
        ->assertHasNoActionErrors()
        ->assertFileDownloaded('Communique-resultats-P14-2026-2027.docx');
});

it('produit un .docx valide qui liste les retenus, caractères spéciaux échappés', function (string $document): void {
    $fichier = app(ResultatsSelectionExport::class)->generer(
        $this->campagne, $document, 'docx', SeanceJury::vide(), ($this->agent)('admin'),
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

it('produit un PDF pour chacun des deux documents', function (string $document): void {
    $fichier = app(ResultatsSelectionExport::class)->generer(
        $this->campagne, $document, 'pdf', SeanceJury::vide(), ($this->agent)('admin'),
    );

    expect($fichier['contenu'])->toStartWith('%PDF-')
        ->and($fichier['mime'])->toBe('application/pdf');
})->with(['communique', 'pv']);

it('refuse un format inconnu', function (): void {
    app(ResultatsSelectionExport::class)->generer(
        $this->campagne, 'pv', 'odt', SeanceJury::vide(), ($this->agent)('admin'),
    );
})->throws(InvalidArgumentException::class);
