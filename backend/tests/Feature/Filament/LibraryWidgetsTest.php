<?php

declare(strict_types=1);

use App\Filament\Resources\DocumentResource;
use App\Filament\Resources\DocumentResource\Pages\ListDocuments;
use App\Filament\Widgets\LibraryOverview;
use App\Filament\Widgets\LibraryTopDownloadedDocuments;
use App\Filament\Widgets\LibraryTopViewedDocuments;
use App\Filament\Widgets\LibraryZeroResultSearches;
use App\Models\Document;
use App\Models\DocumentDownload;
use App\Models\SearchLog;
use App\Models\User;
use App\Services\Library\LibraryStatsCsvExport;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses()->group('filament', 'module-3');

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    $this->seed([RolePermissionSeeder::class]);
    $this->librarian = User::factory()->create();
    $this->librarian->assignRole('librarian');
});

function overviewStats(): array
{
    $method = new ReflectionMethod(LibraryOverview::class, 'getStats');
    $stats = $method->invoke(new LibraryOverview);

    return collect($stats)->mapWithKeys(fn ($s): array => [(string) $s->getLabel() => $s])->all();
}

it('affiche les compteurs de la bibliothèque avec les valeurs exactes', function (): void {
    $doc = Document::factory()->published()->create(['type' => 'memoire']);
    Document::factory()->published()->create(['type' => 'memoire']);
    Document::factory()->published()->create(['type' => 'these']);
    Document::factory()->create(['status' => Document::STATUS_IN_REVIEW, 'type' => 'cours']);
    Document::factory()->create(['status' => Document::STATUS_DRAFT]);
    Document::factory()->create(['status' => Document::STATUS_DRAFT]);
    Document::factory()->create(['status' => Document::STATUS_ARCHIVED]);
    Document::factory()->create(['status' => Document::STATUS_ARCHIVED])->delete();
    DocumentDownload::create(['document_id' => $doc->id, 'downloaded_at' => now()->subDays(3)]);
    DocumentDownload::create(['document_id' => $doc->id, 'downloaded_at' => now()->subDays(40)]);

    $this->actingAs($this->librarian);
    $stats = overviewStats();

    expect($stats['Publiés']->getValue())->toBe(3)
        ->and($stats['À valider']->getValue())->toBe(1)
        ->and($stats['Brouillons']->getValue())->toBe(2)
        ->and($stats['Archivés']->getValue())->toBe(1)
        ->and($stats['Téléchargements (30 j)']->getValue())->toBe(1)
        ->and($stats['Par type']->getValue())->toBe(2)
        ->and((string) $stats['Par type']->getDescription())->toBe('Mémoire 2 · Thèse 1');

    $this->livewire(LibraryOverview::class)->assertSee('Mémoire 2 · Thèse 1');
});

it('classe les recherches sans résultat récentes, par fréquence', function (): void {
    foreach (['cadastre', 'loi de finances 2027', 'loi de finances 2027'] as $q) {
        SearchLog::create(['query' => $q, 'results_count' => 0, 'searched_at' => now()->subDay()]);
    }
    SearchLog::create(['query' => 'tva', 'results_count' => 12, 'searched_at' => now()->subDay()]);
    SearchLog::create(['query' => 'ancienne requete', 'results_count' => 0, 'searched_at' => now()->subDays(45)]);

    $this->actingAs($this->librarian);
    $component = $this->livewire(LibraryZeroResultSearches::class)
        ->assertSeeInOrder(['loi de finances 2027', 'cadastre'])
        ->assertDontSee('tva')
        ->assertDontSee('ancienne requete');

    $rows = $component->instance()->getTableRecords()->map(fn ($r): array => [$r->query, (int) $r->total])->all();
    expect($rows)->toBe([['loi de finances 2027', 2], ['cadastre', 1]]);
});

it('affiche les widgets au bibliothécaire', function (): void {
    $this->actingAs($this->librarian);

    expect(LibraryOverview::canView())->toBeTrue()->and(LibraryZeroResultSearches::canView())->toBeTrue();
});

it('planifie la réindexation puis le contrôle de cohérence', function (): void {
    $commands = collect(app(Schedule::class)->events())->pluck('command')->implode("\n");

    expect($commands)->toContain('scout:import')->and($commands)->toContain('library:check-index');
});

it('masque les widgets aux rôles sans accès bibliothèque', function (): void {
    $editor = User::factory()->create();
    $editor->assignRole('editor');
    $this->actingAs($editor);

    expect(LibraryOverview::canView())->toBeFalse()->and(LibraryZeroResultSearches::canView())->toBeFalse();
});

it('rafraîchit le tableau de bord bibliothèque toutes les cinq minutes', function (): void {
    $method = new ReflectionMethod(LibraryOverview::class, 'getPollingInterval');

    expect($method->invoke(new LibraryOverview))->toBe('300s');
});

dataset('widgets', [LibraryOverview::class, LibraryZeroResultSearches::class, LibraryTopViewedDocuments::class, LibraryTopDownloadedDocuments::class]);

it('montre les widgets aux rôles bibliothèque et les cache aux autres', function (string $role, bool $sees): void {
    $user = User::factory()->create();
    $user->assignRole($role);
    $this->actingAs($user);

    foreach ([LibraryOverview::class, LibraryZeroResultSearches::class, LibraryTopViewedDocuments::class, LibraryTopDownloadedDocuments::class] as $w) {
        expect($w::canView())->toBe($sees, "$role / $w");
    }
})->with([
    ['librarian', true], ['library_manager', true], ['library_viewer', true],
    ['library_contributor', false], ['caisse', false], ['editor', false],
]);

it('classe les 10 documents publiés les plus consultés avec lien vers la fiche', function (): void {
    foreach (range(1, 12) as $i) {
        Document::factory()->published()->create(['views_count' => $i * 10]);
    }
    Document::factory()->create(['views_count' => 9999]); // brouillon : exclu
    $this->actingAs($this->librarian);

    $component = $this->livewire(LibraryTopViewedDocuments::class);
    $records = $component->instance()->getTableRecords();

    expect($records)->toHaveCount(10)
        ->and($records->pluck('views_count')->all())->toBe([120, 110, 100, 90, 80, 70, 60, 50, 40, 30]);
    $component->assertSee(DocumentResource::recordUrl($records->first()));
});

it('classe les téléchargements des 30 derniers jours en une requête groupée', function (): void {
    $a = Document::factory()->published()->create(['type' => 'these']);
    $b = Document::factory()->published()->create(['type' => 'cours']);
    $old = Document::factory()->published()->create();
    foreach ([1, 2, 3] as $d) {
        DocumentDownload::create(['document_id' => $b->id, 'downloaded_at' => now()->subDays($d)]);
    }
    DocumentDownload::create(['document_id' => $a->id, 'downloaded_at' => now()->subDay()]);
    foreach (range(1, 5) as $d) {
        DocumentDownload::create(['document_id' => $old->id, 'downloaded_at' => now()->subDays(40 + $d)]);
    }
    $this->actingAs($this->librarian);

    $records = $this->livewire(LibraryTopDownloadedDocuments::class)->instance()->getTableRecords();

    expect($records->map(fn ($r): array => [$r->id, (int) $r->downloads_30d])->all())->toBe([[$b->id, 3], [$a->id, 1]]);
});

it('limite le classement des téléchargements à 10 documents', function (): void {
    foreach (Document::factory()->published()->count(12)->create() as $doc) {
        DocumentDownload::create(['document_id' => $doc->id, 'downloaded_at' => now()->subDay()]);
    }
    $this->actingAs($this->librarian);

    expect($this->livewire(LibraryTopDownloadedDocuments::class)->instance()->getTableRecords())->toHaveCount(10);
});

it('exporte les statistiques en CSV pour library.stats_export, sans donnée personnelle', function (): void {
    $manager = User::factory()->create();
    $manager->assignRole('library_viewer');
    $doc = Document::factory()->published()->create(['type' => 'memoire', 'views_count' => 7, 'downloads_count' => 12, 'title' => ['fr' => '=Titre; piégé']]);
    Document::factory()->published()->create()->delete();
    DocumentDownload::create(['document_id' => $doc->id, 'user_id' => $manager->id, 'ip' => '203.0.113.9', 'user_agent' => 'SecretAgent/1', 'downloaded_at' => now()->subDay()]);
    DocumentDownload::create(['document_id' => $doc->id, 'downloaded_at' => now()->subDays(60)]);
    $this->actingAs($manager);

    $response = app(LibraryStatsCsvExport::class)->download();
    ob_start();
    $response->sendContent();
    $csv = ob_get_clean();

    expect($response)->toBeInstanceOf(StreamedResponse::class)
        ->and($response->headers->get('content-disposition'))->toContain('bibliotheque-statistiques-'.now()->toDateString().'.csv')
        ->and(str_starts_with($csv, "\xEF\xBB\xBF"))->toBeTrue()
        ->and($csv)->toContain('Titre;Type;Statut;Vues;"Téléchargements (30 j)";"Téléchargements (total)";"Date de publication"')
        ->and($csv)->toContain("\"'=Titre; piégé\";Mémoire;Publié;7;1;12;")
        ->and($csv)->not->toContain('203.0.113.9')->not->toContain('SecretAgent')
        ->and(substr_count(trim($csv), "\n"))->toBe(1);
});

it('masque l\'export aux comptes sans library.stats_export et refuse l\'appel forgé', function (): void {
    $contributor = User::factory()->create();
    $contributor->assignRole('library_contributor');
    $this->actingAs($contributor);

    $this->livewire(ListDocuments::class)
        ->assertActionHidden('exportStats')
        ->call('mountAction', 'exportStats')
        ->call('callMountedAction')
        ->assertNoFileDownloaded();
});

it('montre l\'export à library_viewer', function (): void {
    $viewer = User::factory()->create();
    $viewer->assignRole('library_viewer');
    $this->actingAs($viewer);

    $this->livewire(ListDocuments::class)->assertActionVisible('exportStats')
        ->callAction('exportStats')
        ->assertFileDownloaded('bibliotheque-statistiques-'.now()->toDateString().'.csv');
});

it('refuse l\'export des statistiques appelé hors de l\'écran sans library.stats_export', function (?string $role): void {
    if ($role !== null) {
        $user = User::factory()->create();
        $user->assignRole($role);
        $this->actingAs($user);
    }

    expect(fn () => app(LibraryStatsCsvExport::class)->download())
        ->toThrow(HttpException::class);
})->with([
    'invité' => [null],
    'contributeur' => ['library_contributor'],
    'éditeur' => ['editor'],
]);

it('neutralise les formules précédées d\'une tabulation ou d\'un retour chariot', function (): void {
    expect(LibraryStatsCsvExport::safe("\t=SOMME(A1:A2)"))->toBe("'\t=SOMME(A1:A2)")
        ->and(LibraryStatsCsvExport::safe("\r=1+1"))->toBe("'\r=1+1")
        ->and(LibraryStatsCsvExport::safe('-2'))->toBe("'-2")
        ->and(LibraryStatsCsvExport::safe('Titre ordinaire'))->toBe('Titre ordinaire')
        ->and(LibraryStatsCsvExport::safe(''))->toBe('')
        ->and(LibraryStatsCsvExport::safe(12))->toBe(12);
});

it('signale les documents publiés à une date future sans les compter parmi les publiés', function (): void {
    Document::factory()->published()->create();
    Document::factory()->published()->create(['published_at' => now()->addWeek()]);
    Document::factory()->published()->create(['published_at' => now()->addMonth()]);

    $this->actingAs($this->librarian);
    $stats = overviewStats();

    expect($stats['Publiés']->getValue())->toBe(1)
        ->and((string) $stats['Publiés']->getDescription())->toBe('+ 2 programmés');
});

it('n\'affiche aucune mention de programmation quand rien n\'est programmé', function (): void {
    Document::factory()->published()->create();

    $this->actingAs($this->librarian);

    expect(overviewStats()['Publiés']->getDescription())->toBeNull();
});
