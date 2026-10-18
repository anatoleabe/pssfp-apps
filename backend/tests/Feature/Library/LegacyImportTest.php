<?php

declare(strict_types=1);

use App\Models\Document;
use App\Models\LibraryRedirect;
use App\Models\MotCle;
use App\Models\User;
use App\Services\Library\Files\CoverRenderer;
use App\Services\Library\Import\JoomlaLibraryExtractor;
use App\Services\Library\Import\LegacyImporter;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

uses()->group('module-3', 'library', 'biblio-import');

/*
 * biblio:import sur le mini-dump FICTIF (tests/Fixtures/joomla/mini-dump.sql) et des PDF générés :
 *  - DOCman 101 (catégorie 2, publié), 102 (catégorie 3 désactivée, document désactivé), 103 (distant) ;
 *  - Alexandria 10 (publié, 2 auteurs, couverture), 11 (dépublié, sous-titre).
 */

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-18T09:00:00Z'));
    Storage::fake('minio_documents');
    Storage::fake('minio_media');
    $this->renderer = new class implements CoverRenderer
    {
        public int $calls = 0;

        public function render(string $pdfPath): ?string
        {
            $this->calls++;

            return file_exists($pdfPath) ? 'JPEG-GENERE' : null;
        }
    };
    app()->instance(CoverRenderer::class, $this->renderer);

    // Catégories fictives du mini-dump → règles de test (le thésaurus réel est conservé).
    config([
        'biblio_migration.docman' => [
            'textes-fictifs' => ['type' => 'texte_loi'],
            'textes-fictifs/arretes-fictifs' => ['keywords' => ['arretes']],
            'textes-fictifs/arretes-fictifs/arretes-exemple' => ['keywords' => ['decentralisation']],
        ],
        'biblio_migration.alexandria' => [
            '' => ['type' => 'ouvrage_numerique'],
            'ouvrages-fictifs' => ['type' => 'ouvrage_numerique'],
            'ouvrages-fictifs/finances-imaginaires' => ['keywords' => ['finances-publiques']],
        ],
    ]);

    $this->dir = sys_get_temp_dir().'/biblio-import-'.bin2hex(random_bytes(4));
    $this->files = $this->dir.'/files';
    $this->export = (new JoomlaLibraryExtractor)->extract(__DIR__.'/../../Fixtures/joomla/mini-dump.sql', 'tst01_');
    $this->pdf = [
        'Documentations/ARRETES FICTIFS/Arrêté n°001.pdf' => fakePdf('Arrêté fictif 001'),
        'Documentations/CIRCULAIRES/c-fictive.pdf' => fakePdf('Circulaire fictive'),
        'livres/manuel-imaginaire.pdf' => fakePdf('Manuel imaginaire'),
        'livres/guide-fictif.pdf' => fakePdf('Guide fictif'),
    ];
    foreach ($this->pdf as $relative => $bytes) {
        putImportFile($this->files, $relative, $bytes);
    }
    putImportFile($this->files, 'images/couvertures/manuel-imaginaire.jpg', $this->cover = fakeJpeg());
    $this->logsBefore = glob(storage_path('logs/biblio-import-*.{json,journal}'), GLOB_BRACE) ?: [];
});

afterEach(function (): void {
    Carbon::setTestNow();
    File::deleteDirectory($this->dir);
    foreach (array_diff(glob(storage_path('logs/biblio-import-*.{json,journal}'), GLOB_BRACE) ?: [], $this->logsBefore) as $report) {
        @unlink($report);
    }
});

function fakePdf(string $title): string
{
    return Pdf::loadHTML('<h1>'.e($title).'</h1><p>Document fictif de test.</p>')->output();
}

function fakeJpeg(): string
{
    $image = imagecreatetruecolor(12, 16);
    ob_start();
    imagejpeg($image);

    return (string) ob_get_clean();
}

function putImportFile(string $root, string $relative, string $bytes): void
{
    File::ensureDirectoryExists(dirname($root.'/'.$relative));
    file_put_contents($root.'/'.$relative, $bytes);
}

function writeExport(object $test): string
{
    $path = $test->dir.'/export.json';
    file_put_contents($path, json_encode($test->export, JSON_THROW_ON_ERROR));

    return $path;
}

/** Lance biblio:import sur $this->export (écrit en JSON) et renvoie le rapport du dernier lancement. */
function runLegacyImport(object $test, array $options = [], int $exitCode = 0): array
{
    $path = writeExport($test);

    $test->artisan('biblio:import', ['export' => $path, '--files' => $test->files] + $options)->assertExitCode($exitCode);

    // Heure figée : un seul fichier par mode (réel, dry-run), réécrit à chaque lancement.
    $dryRun = (bool) ($options['--dry-run'] ?? false);
    $reports = array_filter(
        array_diff(glob(storage_path('logs/biblio-import-*.json')) ?: [], $test->logsBefore),
        fn (string $file): bool => str_ends_with($file, '-dry-run.json') === $dryRun,
    );
    expect($reports)->toHaveCount(1);

    return json_decode((string) file_get_contents((string) reset($reports)), true, flags: JSON_THROW_ON_ERROR);
}

function importedDocument(string $source, int $id): Document
{
    return Document::withTrashed()->where('legacy_source', $source)->where('legacy_id', $id)->firstOrFail();
}

/** @return array<string, int> */
function libraryCounts(): array
{
    return collect(['documents', 'document_authors', 'document_mot_cle', 'mots_cles', 'library_redirects', 'users', 'activity_log'])
        ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])
        ->all();
}

it('importe documents et livres avec fichiers, couvertures, auteurs et mots-clés', function (): void {
    $report = runLegacyImport($this);

    expect(Document::count())->toBe(4)
        ->and($report['totals']['created'])->toBe(4)
        ->and($report['totals']['skipped'])->toBe(1)
        ->and($report['skipped'][0])->toMatchArray(['source' => 'docman', 'id' => 103, 'reason' => 'stockage distant (pas de fichier)']);

    $system = User::query()->where('email', LegacyImporter::SYSTEM_USER_EMAIL)->sole();

    $arrete = importedDocument('docman', 101);
    expect($arrete->status)->toBe(Document::STATUS_PUBLISHED)
        ->and($arrete->type)->toBe('texte_loi')
        ->and($arrete->slug)->toBe('arrete-001')
        ->and($arrete->getTranslation('title', 'fr'))->toBe("Arrêté n°001 portant règle de l'exemple")
        ->and($arrete->views_count)->toBe(12)
        ->and($arrete->published_at?->toIso8601String())->toBe('2019-05-02T08:30:00+00:00')
        ->and($arrete->approved_by)->toBe($system->id)
        ->and($arrete->access_level)->toBe('public')
        ->and($arrete->price_xaf)->toBeNull()
        ->and($arrete->file_path)->toBe("documents/{$arrete->uuid}.pdf")
        ->and($arrete->file_checksum_md5)->toBe(md5($this->pdf['Documentations/ARRETES FICTIFS/Arrêté n°001.pdf']))
        ->and($arrete->cover_path)->toBe("library/covers/{$arrete->uuid}.jpg")
        ->and($arrete->legacy_url)->toBe('/index.php/textes/arretes-fictifs/101-arrete-001')
        ->and($arrete->motsCles->pluck('slug')->all())->toBe(['arretes']);
    expect(Storage::disk('minio_documents')->get($arrete->file_path))->toBe($this->pdf['Documentations/ARRETES FICTIFS/Arrêté n°001.pdf'])
        ->and(Storage::disk('minio_media')->get($arrete->cover_path))->toBe('JPEG-GENERE');

    $circulaire = importedDocument('docman', 102);
    expect($circulaire->status)->toBe(Document::STATUS_DRAFT)
        ->and($circulaire->approved_by)->toBeNull()
        ->and($circulaire->motsCles->pluck('slug')->sort()->values()->all())->toBe(['arretes', 'decentralisation']);

    $manuel = importedDocument('alexandria', 10);
    expect($manuel->status)->toBe(Document::STATUS_PUBLISHED)
        ->and($manuel->type)->toBe('ouvrage_numerique')
        ->and($manuel->authors->pluck('author_name')->all())->toBe(['Bruno Imaginaire', 'Alba Fictivus'])
        ->and($manuel->authors->pluck('order')->all())->toBe([1, 2])
        ->and($manuel->year)->toBe(2015)
        ->and($manuel->isbn)->toBe('978-0-00-000000-0')
        ->and($manuel->pages_count)->toBe(120)
        ->and($manuel->getTranslation('abstract', 'fr'))->toBe("Premier paragraphe.\n\nSecond paragraphe ; avec point-virgule.")
        ->and($manuel->cover_path)->toBe("library/covers/{$manuel->uuid}.jpg")
        ->and($manuel->motsCles->pluck('slug')->all())->toBe(['finances-publiques']);
    expect(Storage::disk('minio_media')->get($manuel->cover_path))->toBe($this->cover)
        ->and($report['covers'])->toMatchArray(['copied' => 1, 'generated' => 3])
        // Traitement synchrone unique : l'observateur ne remet pas le PDF en file.
        ->and($this->renderer->calls)->toBe(3);

    $guide = importedDocument('alexandria', 11);
    expect($guide->status)->toBe(Document::STATUS_DRAFT)
        ->and($guide->getTranslation('subtitle', 'fr'))->toBe('Tome 2')
        ->and($report['draft_reasons'])->toBe(['dépublié dans Joomla' => 2]);

    expect(MotCle::query()->where('slug', 'arretes')->sole()->parent?->slug)->toBe('types-de-textes')
        ->and(MotCle::query()->where('slug', 'arretes')->sole()->getTranslation('label', 'fr'))->toBe('Arrêtés');
});

it('crée une seule fois l\'utilisateur système, sans rôle ni mot de passe connu', function (): void {
    runLegacyImport($this);
    runLegacyImport($this);

    $system = User::query()->where('email', LegacyImporter::SYSTEM_USER_EMAIL)->sole();

    expect($system->name)->toBe('Import Joomla')
        ->and($system->roles)->toHaveCount(0)
        ->and($system->hasBackOfficePerimeter())->toBeFalse()
        ->and($system->email_verified_at)->toBeNull()
        ->and(Hash::check('', $system->password))->toBeFalse()
        ->and(Hash::check('password', $system->password))->toBeFalse();
});

it('relance sans doublon ni nouveau fichier', function (): void {
    runLegacyImport($this);
    $counts = libraryCounts();
    $objects = count(Storage::disk('minio_documents')->allFiles());

    $report = runLegacyImport($this);

    expect(libraryCounts())->toMatchArray(array_diff_key($counts, ['activity_log' => true]))
        ->and(count(Storage::disk('minio_documents')->allFiles()))->toBe($objects)
        ->and($report['totals'])->toMatchArray(['created' => 0, 'unchanged' => 4, 'merged' => 0])
        ->and($report['redirects']['created'])->toBe(0);
});

it('relance sans écraser statut, accès, slug ni vues posés depuis l\'administration', function (): void {
    runLegacyImport($this);
    $arrete = importedDocument('docman', 101);
    $arrete->update(['status' => Document::STATUS_ARCHIVED, 'access_level' => 'auditor', 'slug' => 'arrete-renomme', 'views_count' => 500]);
    importedDocument('alexandria', 11)->delete();
    $manuel = importedDocument('alexandria', 10);
    $manuel->update(['title' => ['fr' => 'Titre corrigé'], 'year' => 1999, 'isbn' => '978-9-99-999999-9', 'type' => 'rapport']);
    $ajoute = MotCle::query()->where('slug', 'fiscalite')->sole();
    $manuel->motsCles()->attach($ajoute);
    $manuel->motsCles()->detach(MotCle::query()->where('slug', 'finances-publiques')->sole());

    $report = runLegacyImport($this);

    expect($arrete->fresh())->status->toBe(Document::STATUS_ARCHIVED)
        ->access_level->toBe('auditor')
        ->slug->toBe('arrete-renomme')
        ->views_count->toBe(500)
        ->and($manuel->fresh())->getTranslation('title', 'fr')->toBe('Titre corrigé')
        ->and($manuel->fresh())->year->toBe(1999)->isbn->toBe('978-9-99-999999-9')->type->toBe('rapport')
        // Mots-clés : ceux de l'import manquants sont rajoutés, aucun n'est retiré.
        ->and($manuel->fresh()->motsCles->pluck('slug')->sort()->values()->all())->toBe(['finances-publiques', 'fiscalite'])
        ->and(importedDocument('alexandria', 11)->trashed())->toBeTrue()
        ->and($report['skipped'])->toContain(['source' => 'alexandria', 'id' => 11, 'reason' => 'supprimé dans la nouvelle bibliothèque'])
        ->and(LibraryRedirect::query()->where('from_path', '/index.php?option=com_docman&view=document&slug=arrete-001')->value('to_path'))->toBe('/documents/arrete-001');
});

it('ne crée pas le document dont le fichier est introuvable et le consigne', function (): void {
    unlink($this->files.'/livres/guide-fictif.pdf');

    $report = runLegacyImport($this);

    expect(Document::query()->where('legacy_source', 'alexandria')->where('legacy_id', 11)->exists())->toBeFalse()
        ->and($report['totals']['missing_files'])->toBe(1)
        ->and($report['missing_files'][0])->toMatchArray(['source' => 'alexandria', 'id' => 11, 'file' => 'guide-fictif.pdf']);
});

it('retrouve un fichier dont le nom diffère par l\'encodage, la casse ou les apostrophes', function (): void {
    $this->export['docman']['documents'][0]['storage_path'] = 'ARRETES FICTIFS/Arrêté n°001 de l’exemple.pdf';
    unlink($this->files.'/Documentations/ARRETES FICTIFS/Arrêté n°001.pdf');
    putImportFile($this->files, "Documentations/ARRETES FICTIFS/ARRETE N 001 DE L'EXEMPLE.PDF", 'contenu approchant');

    $report = runLegacyImport($this);

    expect(importedDocument('docman', 101)->file_checksum_md5)->toBe(md5('contenu approchant'))
        ->and($report['files'])->toMatchArray(['exact' => 3, 'tolerant' => 1]);
});

it('fusionne un doublon MD5 : redirections vers la fiche existante et vues cumulées', function (): void {
    putImportFile($this->files, 'livres/manuel-imaginaire.pdf', $this->pdf['Documentations/ARRETES FICTIFS/Arrêté n°001.pdf']);

    $report = runLegacyImport($this);

    expect(Document::count())->toBe(3)
        ->and(Document::query()->where('legacy_source', 'alexandria')->where('legacy_id', 10)->exists())->toBeFalse()
        ->and(importedDocument('docman', 101)->views_count)->toBe(12 + 42)
        ->and(LibraryRedirect::query()->where('from_path', '/index.php?option=com_abook&view=book&id=10')->value('to_path'))->toBe('/documents/arrete-001')
        ->and($report['totals']['merged'])->toBe(1)
        ->and($report['merged'][0])->toMatchArray(['source' => 'alexandria', 'id' => 10, 'into' => 'arrete-001']);

    runLegacyImport($this);

    expect(importedDocument('docman', 101)->views_count)->toBe(54)->and(Document::count())->toBe(3);
});

it('rattache un doublon MD5 à un document déposé à la main', function (): void {
    $existing = Document::factory()->published()->create([
        'slug' => 'depose-a-la-main',
        'file_checksum_md5' => md5($this->pdf['livres/manuel-imaginaire.pdf']),
        'views_count' => 5,
    ]);

    runLegacyImport($this, ['--only' => 'alexandria']);

    expect(Document::query()->where('legacy_source', 'alexandria')->where('legacy_id', 10)->exists())->toBeFalse()
        ->and($existing->fresh()->views_count)->toBe(47)
        ->and(LibraryRedirect::query()->where('from_path', '/livres/manuel-imaginaire.pdf')->value('to_path'))->toBe('/documents/depose-a-la-main');
});

it('n\'écrit rien en dry-run et produit le même rapport', function (): void {
    $counts = libraryCounts();

    $report = runLegacyImport($this, ['--dry-run' => true]);

    expect(libraryCounts())->toBe($counts)
        ->and(Storage::disk('minio_documents')->allFiles())->toBe([])
        ->and(Storage::disk('minio_media')->allFiles())->toBe([])
        ->and($report['dry_run'])->toBeTrue()
        ->and($report['totals'])->toMatchArray(['created' => 4, 'skipped' => 1, 'missing_files' => 0, 'errors' => 0])
        ->and($report['created_by_type'])->toBe(['docman' => ['texte_loi' => 2], 'alexandria' => ['ouvrage_numerique' => 2]])
        ->and($report['redirects']['created'])->toBeGreaterThan(0)
        ->and($report['keywords_created'])->toBeGreaterThan(0);

    $real = runLegacyImport($this);

    expect(array_diff_key($real['totals'], ['errors' => 0]))->toBe(array_diff_key($report['totals'], ['errors' => 0]))
        ->and($real['redirects']['created'])->toBe($report['redirects']['created']);
});

it('limite l\'import à une source ou à N éléments', function (): void {
    runLegacyImport($this, ['--only' => 'alexandria']);
    expect(Document::query()->pluck('legacy_source')->unique()->values()->all())->toBe(['alexandria']);

    runLegacyImport($this, ['--limit' => 1]);
    expect(Document::query()->where('legacy_source', 'docman')->pluck('legacy_id')->all())->toBe([101]);
});

it('refuse une source inconnue, une limite invalide ou un export illisible', function (array $options, ?string $export): void {
    if ($export !== null) {
        file_put_contents($this->dir.'/export.json', $export);
    }
    $this->artisan('biblio:import', $options + ['export' => $this->dir.'/export.json', '--files' => $this->files])->assertExitCode(1);

    expect(Document::count())->toBe(0);
})->with([
    'source inconnue' => [['--only' => 'k2'], '{}'],
    'limite nulle' => [['--limit' => '0'], '{}'],
    'export absent' => [[], null],
    'JSON invalide' => [[], '{pas du json'],
    'structure inattendue' => [[], '{"docman": "x"}'],
    'dossier de fichiers absent' => [['--files' => '/chemin/inexistant'], '{"docman":{"categories":[],"documents":[]},"alexandria":{"categories":[],"books":[]},"menus":[]}'],
]);

it('écrit le rapport JSON dans storage/logs', function (): void {
    runLegacyImport($this);

    $reports = array_values(array_diff(glob(storage_path('logs/biblio-import-*.json')) ?: [], $this->logsBefore));
    expect($reports)->toHaveCount(1)
        ->and(fileperms($reports[0]) & 0777)->toBe(0600);

    $json = json_decode((string) file_get_contents($reports[0]), true, flags: JSON_THROW_ON_ERROR);
    expect($json['totals']['created'])->toBe(4)->and($json['dry_run'])->toBeFalse();
});

it('dérive des slugs conformes et uniques des alias Joomla', function (): void {
    $this->export['alexandria']['books'][0]['alias'] = 'arrete-001';
    $this->export['alexandria']['books'][1]['alias'] = 'Énoncé  Spécial_'.str_repeat('tres-long-', 30);

    runLegacyImport($this);

    $slugs = Document::query()->pluck('slug', 'legacy_id');
    expect($slugs[101])->toBe('arrete-001')
        ->and($slugs[10])->toBe('arrete-001-2')
        ->and($slugs[11])->toStartWith('enonce-special-tres-long')
        ->and(strlen($slugs[11]))->toBeLessThanOrEqual(160);
    foreach ($slugs as $slug) {
        expect($slug)->toMatch('/^[a-z0-9]+(-[a-z0-9]+)*$/');
    }
});

it('n\'enregistre pas le document si l\'envoi sur MinIO échoue', function (): void {
    $readOnly = $this->dir.'/minio-lecture-seule';
    File::ensureDirectoryExists($readOnly.'/documents');
    chmod($readOnly.'/documents', 0500);
    Storage::set('minio_documents', Storage::build(['driver' => 'local', 'root' => $readOnly, 'throw' => false]));

    try {
        $report = runLegacyImport($this, ['--only' => 'docman'], exitCode: 1);
    } finally {
        chmod($readOnly.'/documents', 0700);
    }

    expect(Document::count())->toBe(0)
        ->and(LibraryRedirect::query()->where('to_path', 'like', '/documents/%')->count())->toBe(0)
        ->and($report['totals'])->toMatchArray(['created' => 0, 'errors' => 2])
        ->and($report['errors'][0]['message'])->toContain('Envoi impossible')
        // Compteurs de l'élément annulés avec lui.
        ->and($report['files']['exact'])->toBe(0)
        ->and($report['redirects']['created'])->toBe(LibraryRedirect::count());
});

it('consigne une catégorie sans correspondance sans créer le document', function (): void {
    config(['biblio_migration.docman' => ['autre-chose' => ['type' => 'texte_loi']]]);

    $report = runLegacyImport($this, ['--only' => 'docman'], exitCode: 1);

    expect(Document::count())->toBe(0)
        ->and($report['totals']['errors'])->toBe(2)
        ->and($report['errors'][0]['message'])->toContain('sans correspondance');
});

it('publie le brouillon importé dont un élément publié partage le fichier', function (): void {
    putImportFile($this->files, 'livres/manuel-imaginaire.pdf', $this->pdf['Documentations/CIRCULAIRES/c-fictive.pdf']);

    $report = runLegacyImport($this);

    $circulaire = importedDocument('docman', 102);
    expect($circulaire->status)->toBe(Document::STATUS_PUBLISHED)
        ->and($circulaire->approved_by)->toBe(User::query()->where('email', LegacyImporter::SYSTEM_USER_EMAIL)->value('id'))
        ->and($circulaire->approved_at)->not->toBeNull()
        ->and($circulaire->published_at?->toIso8601String())->toBe('2016-03-01T10:00:00+00:00')
        ->and($report['merged_into_draft'])->toBe([['source' => 'alexandria', 'id' => 10, 'into' => 'circulaire-fictive']]);

    $dry = runLegacyImport($this, ['--dry-run' => true]);
    expect($dry['merged_into_draft'])->toBe([]);
});

it('ne republie pas à la relance un brouillon remis en brouillon par l\'administration', function (): void {
    putImportFile($this->files, 'livres/manuel-imaginaire.pdf', $this->pdf['Documentations/CIRCULAIRES/c-fictive.pdf']);
    runLegacyImport($this);
    expect(importedDocument('docman', 102)->status)->toBe(Document::STATUS_PUBLISHED);

    importedDocument('docman', 102)->update(['status' => Document::STATUS_DRAFT]);
    $report = runLegacyImport($this);

    expect(importedDocument('docman', 102)->status)->toBe(Document::STATUS_DRAFT)
        ->and($report['merged_into_draft'])->toBe([]);
});

it('refuse de démarrer quand un autre import tient le verrou', function (): void {
    $handle = fopen(storage_path('logs/biblio-import.lock'), 'c');
    flock($handle, LOCK_EX | LOCK_NB);

    try {
        $this->artisan('biblio:import', ['export' => writeExport($this), '--files' => $this->files])
            ->expectsOutputToContain('déjà en cours')
            ->assertExitCode(1);
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    expect(Document::count())->toBe(0);
    runLegacyImport($this);
    expect(Document::count())->toBe(4);
});

it('ne publie pas un brouillon déposé à la main qui partage le fichier', function (): void {
    $manuel = Document::factory()->create(['slug' => 'brouillon-manuel', 'file_checksum_md5' => md5($this->pdf['livres/manuel-imaginaire.pdf'])]);

    $report = runLegacyImport($this, ['--only' => 'alexandria']);

    expect($manuel->fresh()->status)->toBe(Document::STATUS_DRAFT)
        ->and($report['merged_into_draft'])->toBe([]);
});

it('importe en brouillon réservé un document à accès restreint dans Joomla', function (): void {
    $this->export['docman']['documents'][0]['access'] = 2;

    $report = runLegacyImport($this);

    expect(importedDocument('docman', 101))->status->toBe(Document::STATUS_DRAFT)->access_level->toBe('auditor')
        ->and($report['drafts'])->toContain(['source' => 'docman', 'id' => 101, 'reason' => 'accès restreint dans Joomla']);
});

it('liste brouillons, fichiers non PDF et couvertures en échec', function (): void {
    $this->export['docman']['documents'][0]['storage_path'] = 'ARRETES FICTIFS/support.pptx';
    putImportFile($this->files, 'Documentations/ARRETES FICTIFS/support.pptx', 'présentation fictive');
    unlink($this->files.'/images/couvertures/manuel-imaginaire.jpg');

    $report = runLegacyImport($this);

    expect(importedDocument('docman', 101)->file_path)->toEndWith('.pptx')
        ->and($report['non_pdf_files'])->toBe([['source' => 'docman', 'id' => 101, 'file' => 'ARRETES FICTIFS/support.pptx', 'extension' => 'pptx']])
        ->and($report['drafts'])->toContain(['source' => 'docman', 'id' => 102, 'reason' => 'dépublié dans Joomla'])
        ->and($report['cover_failures'])->toContain(['source' => 'alexandria', 'id' => 10, 'reason' => 'image de couverture introuvable']);
});

it('journalise chaque objet envoyé puis sa validation', function (): void {
    runLegacyImport($this);

    $journals = array_values(array_diff(glob(storage_path('logs/biblio-import-*.journal')) ?: [], $this->logsBefore));
    expect($journals)->toHaveCount(1)->and(fileperms($journals[0]) & 0777)->toBe(0600);

    $lines = file($journals[0], FILE_IGNORE_NEW_LINES);
    $arrete = importedDocument('docman', 101);
    expect($lines)->toContain("envoi\tminio_documents\t{$arrete->file_path}\tdocman\t101")
        ->toContain("valide\tminio_documents\t{$arrete->file_path}\tdocman\t101")
        ->toContain("envoi\tminio_media\t{$arrete->cover_path}\tdocman\t101");
});

it('signale puis supprime sur demande les objets orphelins d\'un import interrompu', function (): void {
    $journal = storage_path('logs/biblio-import-20261017-000000.journal');
    file_put_contents($journal, "envoi\tminio_documents\tdocuments/orphelin.pdf\tdocman\t999\n");
    Storage::disk('minio_documents')->put('documents/orphelin.pdf', 'reste');

    $first = runLegacyImport($this, ['--dry-run' => true]);
    expect($first['orphans'])->toBe([['disk' => 'minio_documents', 'key' => 'documents/orphelin.pdf', 'source' => 'docman', 'id' => 999]])
        ->and(Storage::disk('minio_documents')->exists('documents/orphelin.pdf'))->toBeTrue();

    $second = runLegacyImport($this, ['--clean-orphans' => true]);
    expect($second['orphans_cleaned'])->toBe(1)
        ->and(Storage::disk('minio_documents')->exists('documents/orphelin.pdf'))->toBeFalse();

    expect(runLegacyImport($this)['orphans'])->toBe([]);
    @unlink($journal);
});

it('ne démarre pas sans la migration du type « rapport »', function (): void {
    DB::table('migrations')->where('migration', 'like', '%add_rapport_to_documents_type_check')->delete();

    $this->artisan('biblio:import', ['export' => writeExport($this), '--files' => $this->files])
        ->expectsOutputToContain('rapport')
        ->assertExitCode(1);

    expect(Document::count())->toBe(0);
});

it('ne démarre pas si un disque MinIO refuse l\'objet témoin', function (): void {
    $readOnly = $this->dir.'/media-lecture-seule';
    File::ensureDirectoryExists($readOnly);
    chmod($readOnly, 0500);
    Storage::set('minio_media', Storage::build(['driver' => 'local', 'root' => $readOnly, 'throw' => false]));

    try {
        $this->artisan('biblio:import', ['export' => writeExport($this), '--files' => $this->files])
            ->expectsOutputToContain('minio_media')
            ->assertExitCode(1);
    } finally {
        chmod($readOnly, 0700);
    }

    expect(Document::count())->toBe(0);
});

it('écrit un rapport partiel et sort en erreur sur un incident imprévu', function (): void {
    User::creating(fn () => throw new RuntimeException('base indisponible'));

    $report = runLegacyImport($this, exitCode: 1);

    expect($report['fatal'])->toContain('base indisponible')->and(Document::count())->toBe(0);
});

describe('redirections des anciennes URL', function (): void {
    it('crée chaque forme d\'ancienne URL vers la fiche', function (): void {
        runLegacyImport($this);

        $expected = [
            '/index.php/textes/arretes-fictifs/101-arrete-001' => '/documents/arrete-001',
            '/textes/arretes-fictifs/101-arrete-001' => '/documents/arrete-001',
            '/index.php/textes/arretes-fictifs/101-arrete-001/file' => '/documents/arrete-001',
            '/textes/arretes-fictifs/101-arrete-001/file' => '/documents/arrete-001',
            '/index.php?option=com_docman&view=document&slug=arrete-001' => '/documents/arrete-001',
            '/index.php/textes/arretes-fictifs/arretes-exemple/102-circulaire-fictive' => '/documents/circulaire-fictive',
            '/index.php/e-books/finances-imaginaires/10-manuel-imaginaire-de-finances' => '/documents/manuel-imaginaire-de-finances',
            '/e-books/finances-imaginaires/10-manuel-imaginaire-de-finances' => '/documents/manuel-imaginaire-de-finances',
            '/index.php/component/abook/book/3-finances-imaginaires/10-manuel-imaginaire-de-finances' => '/documents/manuel-imaginaire-de-finances',
            '/livres/manuel-imaginaire.pdf' => '/documents/manuel-imaginaire-de-finances',
            '/index.php?option=com_abook&view=book&id=10' => '/documents/manuel-imaginaire-de-finances',
            '/index.php/textes/arretes-fictifs' => '/collections/texte_loi?mot_cle=arretes',
            '/textes/arretes-fictifs' => '/collections/texte_loi?mot_cle=arretes',
            '/index.php/textes/arretes-fictifs/arretes-exemple' => '/collections/texte_loi?mot_cle=decentralisation',
            '/index.php/bibliotheque/textes-fictifs' => '/collections/texte_loi',
            '/index.php/e-books/finances-imaginaires' => '/collections/ouvrage_numerique?mot_cle=finances-publiques',
            '/index.php/component/abook/category/3-finances-imaginaires' => '/collections/ouvrage_numerique?mot_cle=finances-publiques',
        ];
        $actual = LibraryRedirect::query()->pluck('to_path', 'from_path')->all();

        expect(array_intersect_key($actual, $expected))->toEqual($expected)
            ->and(LibraryRedirect::query()->where('status_code', '!=', 301)->exists())->toBeFalse();
        foreach ($actual as $to) {
            expect(LibraryRedirect::isSafeTarget($to))->toBeTrue();
        }
    });

    it('ne crée aucune redirection pour un document ignoré ou introuvable', function (): void {
        unlink($this->files.'/livres/guide-fictif.pdf');

        runLegacyImport($this);

        expect(LibraryRedirect::query()->where('from_path', 'like', '%103-note-double%')->exists())->toBeFalse()
            ->and(LibraryRedirect::query()->where('from_path', '/index.php?option=com_abook&view=book&id=11')->exists())->toBeFalse();
    });

    it('isole l\'échec d\'une page de catégorie', function (): void {
        LibraryRedirect::creating(function (LibraryRedirect $redirect): void {
            if ($redirect->from_path === '/index.php/textes/arretes-fictifs') {
                throw new RuntimeException('incident sur une catégorie');
            }
        });

        $report = runLegacyImport($this, exitCode: 1);

        expect(LibraryRedirect::query()->where('from_path', '/index.php/e-books/finances-imaginaires')->exists())->toBeTrue()
            ->and(LibraryRedirect::query()->where('from_path', '/textes/arretes-fictifs')->exists())->toBeFalse()
            ->and($report['errors'])->toHaveCount(1)
            ->and($report['errors'][0])->toMatchArray(['source' => 'docman', 'id' => 2]);
    });

    it('relance sans doublon et respecte une redirection existante', function (): void {
        LibraryRedirect::create(['from_path' => '/livres/manuel-imaginaire.pdf', 'to_path' => '/collections/memoire']);

        $first = runLegacyImport($this);
        $count = LibraryRedirect::count();
        $second = runLegacyImport($this);

        expect(LibraryRedirect::count())->toBe($count)
            ->and(LibraryRedirect::query()->where('from_path', '/livres/manuel-imaginaire.pdf')->value('to_path'))->toBe('/collections/memoire')
            ->and($first['redirects']['conflicts'])->toBe(1)
            ->and($second['redirects']['created'])->toBe(0)
            ->and($second['redirects']['existing'])->toBe($count - 1);
    });
});
