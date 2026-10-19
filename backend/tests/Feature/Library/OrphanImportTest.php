<?php

declare(strict_types=1);

use App\Models\Document;
use App\Models\LibraryRedirect;
use App\Models\User;
use App\Services\Library\Files\CoverRenderer;
use App\Services\Library\Import\LegacyImporter;
use App\Services\Library\Import\OrphanFileImporter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

uses()->group('module-3', 'library', 'biblio-import');

/*
 * biblio:import-orphans sur une arborescence FICTIVE (faux PDF « %PDF-1.4 … ») et un export minimal
 * fictif : un document DOCman et un livre Alexandria référencent chacun un fichier, tous les autres
 * fichiers sont « orphelins ». Règles des dossiers du serveur fictives, alignées sur les catégories
 * fictives ci-dessous.
 */

const ORPHAN_MANUEL = 'livres/Manuel imaginaire de finances 2014.pdf';
const ORPHAN_ARRETE = 'Documentations/ARRETES FICTIFS/Arrêté fictif n°7.pdf';

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-19T09:00:00Z'));
    Storage::fake('minio_documents');
    Storage::fake('minio_media');
    app()->instance(CoverRenderer::class, new class implements CoverRenderer
    {
        public function render(string $pdfPath): ?string
        {
            return file_exists($pdfPath) ? 'JPEG-GENERE' : null;
        }
    });

    config([
        'biblio_migration.docman' => [
            'textes-fictifs' => ['type' => 'texte_loi'],
            'textes-fictifs/arretes-fictifs' => ['keywords' => ['arretes']],
        ],
        'biblio_migration.alexandria' => [
            'ouvrages-fictifs' => ['type' => 'ouvrage_numerique'],
            'ouvrages-fictifs/finances-imaginaires' => ['keywords' => ['finances-publiques']],
        ],
        'biblio_migration.server_folders' => [
            'livres' => ['source' => 'alexandria', 'category' => 'ouvrages-fictifs/finances-imaginaires'],
            'Documentations/ARRETES FICTIFS' => ['source' => 'docman', 'category' => 'textes-fictifs/arretes-fictifs'],
            'Documentations/PRIVE' => ['exclude' => 'données nominatives fictives'],
        ],
        'biblio_migration.server_excluded_files' => [
            'livres/hors-sujet-fictif.pdf' => 'hors sujet (fictif)',
        ],
    ]);

    $this->dir = sys_get_temp_dir().'/biblio-orphelins-'.bin2hex(random_bytes(4));
    $this->files = $this->dir.'/files';
    $this->export = [
        'docman' => ['categories' => [], 'documents' => [
            ['id' => 1, 'storage_type' => 'file', 'storage_path' => 'ARRETES FICTIFS/arrete-reference.pdf'],
        ]],
        'alexandria' => ['categories' => [], 'books' => [
            ['id' => 1, 'file' => 'livre-reference.pdf'],
        ]],
        'menus' => [],
    ];
    $this->content = [
        'livres/livre-reference.pdf' => orphanPdf('livre référencé'),
        'Documentations/ARRETES FICTIFS/arrete-reference.pdf' => orphanPdf('arrêté référencé'),
        ORPHAN_MANUEL => orphanPdf('manuel'),
        ORPHAN_ARRETE => orphanPdf('arrêté'),
        'livres/notes-fictives.txt' => 'texte brut fictif',
        'livres/faux-pdf.pdf' => 'pas un PDF',
        'Documentations/PRIVE/liste-fictive.pdf' => orphanPdf('liste'),
        'livres/hors-sujet-fictif.pdf' => orphanPdf('hors sujet'),
        'Documentations/DIVERS/note-sans-regle.pdf' => orphanPdf('note'),
        'livres/livre-reference (2).pdf' => orphanPdf('variante'),
        'Documentations/ARRETES FICTIFS/arrete-reference-1.pdf' => orphanPdf('autre variante'),
        'livres/copie-du-manuel.pdf' => orphanPdf('manuel'),
        'livres/.cache/cache-fictif.pdf' => orphanPdf('cache'),
        'Documentations/.tmp/temporaire.pdf' => orphanPdf('temporaire'),
        'livres/.masque.pdf' => orphanPdf('masqué'),
        'images/couverture-fictive.pdf' => orphanPdf('image'),
    ];
    foreach ($this->content as $relative => $bytes) {
        putOrphanFile($this->files, $relative, $bytes);
    }
    $this->mtime = (int) Carbon::parse('2015-06-01T10:00:00Z')->timestamp;
    touch($this->files.'/'.ORPHAN_MANUEL, $this->mtime);
    $this->logsBefore = glob(storage_path('logs/biblio-{orphelins,import}-*.{json,journal}'), GLOB_BRACE) ?: [];
});

afterEach(function (): void {
    Carbon::setTestNow();
    File::deleteDirectory($this->dir);
    foreach (array_diff(glob(storage_path('logs/biblio-{orphelins,import}-*.{json,journal}'), GLOB_BRACE) ?: [], $this->logsBefore) as $file) {
        @unlink($file);
    }
});

function orphanPdf(string $label): string
{
    return "%PDF-1.4\n% document fictif de test : {$label}\n%%EOF\n";
}

function putOrphanFile(string $root, string $relative, string $bytes): void
{
    File::ensureDirectoryExists(dirname($root.'/'.$relative));
    file_put_contents($root.'/'.$relative, $bytes);
}

/** Lance biblio:import-orphans et renvoie le rapport JSON du dernier lancement. */
function runOrphanImport(object $test, array $options = [], int $exitCode = 0): array
{
    $path = $test->dir.'/export.json';
    file_put_contents($path, json_encode($test->export, JSON_THROW_ON_ERROR));

    $test->artisan('biblio:import-orphans', ['export' => $path, '--files' => $test->files] + $options)->assertExitCode($exitCode);

    $dryRun = (bool) ($options['--dry-run'] ?? false);
    $reports = array_filter(
        array_diff(glob(storage_path('logs/biblio-orphelins-*.json')) ?: [], $test->logsBefore),
        fn (string $file): bool => str_ends_with($file, '-dry-run.json') === $dryRun,
    );
    expect($reports)->toHaveCount(1);

    return json_decode((string) file_get_contents((string) reset($reports)), true, flags: JSON_THROW_ON_ERROR);
}

/** @return array<string, array<string, mixed>> chemin relatif → entrée du rapport */
function orphanOutcomes(array $report): array
{
    return collect($report['files'])->keyBy('path')->all();
}

function serverDocument(string $relative): ?Document
{
    return Document::withTrashed()
        ->where('legacy_source', 'serveur')
        ->where('legacy_url', '/'.implode('/', array_map('rawurlencode', explode('/', $relative))))
        ->first();
}

it('publie un fichier jamais catalogué : titre, type, mots-clés, date, fichier et redirection', function (): void {
    $report = runOrphanImport($this);

    $manuel = serverDocument(ORPHAN_MANUEL);
    $system = User::query()->where('email', LegacyImporter::SYSTEM_USER_EMAIL)->sole();

    expect($manuel)->not->toBeNull()
        ->and($manuel->getTranslation('title', 'fr'))->toBe('Manuel imaginaire de finances 2014')
        ->and($manuel->slug)->toBe('manuel-imaginaire-de-finances-2014')
        ->and($manuel->type)->toBe('ouvrage_numerique')
        ->and($manuel->access_level)->toBe('public')
        ->and($manuel->year)->toBe(2014)
        ->and($manuel->language)->toBe('fr')
        ->and($manuel->status)->toBe(Document::STATUS_PUBLISHED)
        ->and($manuel->published_at?->getTimestamp())->toBe($this->mtime)
        ->and($manuel->approved_by)->toBe($system->id)
        ->and($manuel->uploaded_by)->toBe($system->id)
        ->and($manuel->approved_at)->not->toBeNull()
        ->and($manuel->views_count)->toBe(0)
        ->and($manuel->legacy_source)->toBe('serveur')
        ->and($manuel->legacy_id)->toBeNull()
        ->and($manuel->legacy_url)->toBe('/livres/Manuel%20imaginaire%20de%20finances%202014.pdf')
        ->and($manuel->file_path)->toBe("documents/{$manuel->uuid}.pdf")
        ->and($manuel->file_checksum_md5)->toBe(md5($this->content[ORPHAN_MANUEL]))
        ->and($manuel->cover_path)->toBe("library/covers/{$manuel->uuid}.jpg")
        ->and($manuel->motsCles->pluck('slug')->all())->toBe(['finances-publiques']);
    expect(Storage::disk('minio_documents')->get($manuel->file_path))->toBe($this->content[ORPHAN_MANUEL])
        ->and(Storage::disk('minio_media')->get($manuel->cover_path))->toBe('JPEG-GENERE')
        ->and(LibraryRedirect::query()->where('from_path', '/livres/Manuel%20imaginaire%20de%20finances%202014.pdf')->value('to_path'))
        ->toBe('/documents/manuel-imaginaire-de-finances-2014');

    $arrete = serverDocument(ORPHAN_ARRETE);
    expect($arrete)->not->toBeNull()
        ->and($arrete->type)->toBe('texte_loi')
        ->and($arrete->motsCles->pluck('slug')->all())->toBe(['arretes'])
        ->and(LibraryRedirect::query()->where('from_path', '/Documentations/ARRETES%20FICTIFS/Arr%C3%AAt%C3%A9%20fictif%20n%C2%B07.pdf')->value('to_path'))
        ->toBe('/documents/'.$arrete->slug);

    expect($report['totals'])->toMatchArray(['created' => 2, 'errors' => 0])
        ->and($report['created_by_type'])->toBe(['ouvrage_numerique' => 1, 'texte_loi' => 1])
        ->and($report['covers'])->toMatchArray(['generated' => 2, 'failed' => 0]);
});

it('donne à chaque fichier une seule issue et ignore les fichiers référencés et cachés', function (): void {
    putOrphanFile($this->files, 'livres/deja-depose.pdf', $this->content['livres/deja-depose.pdf'] = orphanPdf('déjà déposé'));
    $known = Document::factory()->published()->create(['slug' => 'depose-a-la-main', 'file_checksum_md5' => md5($this->content['livres/deja-depose.pdf'])]);

    $report = runOrphanImport($this);
    $outcomes = orphanOutcomes($report);

    expect(array_keys($outcomes))->toBe([
        'Documentations/ARRETES FICTIFS/Arrêté fictif n°7.pdf',
        'Documentations/ARRETES FICTIFS/arrete-reference-1.pdf',
        'Documentations/DIVERS/note-sans-regle.pdf',
        'Documentations/PRIVE/liste-fictive.pdf',
        'livres/Manuel imaginaire de finances 2014.pdf',
        'livres/copie-du-manuel.pdf',
        'livres/deja-depose.pdf',
        'livres/faux-pdf.pdf',
        'livres/hors-sujet-fictif.pdf',
        'livres/livre-reference (2).pdf',
        'livres/notes-fictives.txt',
    ])
        ->and($outcomes[ORPHAN_ARRETE]['outcome'])->toBe('created')
        ->and($outcomes[ORPHAN_MANUEL]['outcome'])->toBe('created')
        ->and($outcomes['livres/notes-fictives.txt'])->toMatchArray(['outcome' => 'non_pdf'])
        ->and($outcomes['livres/faux-pdf.pdf'])->toMatchArray(['outcome' => 'non_pdf'])
        ->and($outcomes['Documentations/PRIVE/liste-fictive.pdf'])->toMatchArray(['outcome' => 'excluded', 'reason' => 'données nominatives fictives'])
        ->and($outcomes['livres/hors-sujet-fictif.pdf'])->toMatchArray(['outcome' => 'excluded', 'reason' => 'hors sujet (fictif)'])
        ->and($outcomes['Documentations/DIVERS/note-sans-regle.pdf'])->toMatchArray(['outcome' => 'unmapped'])
        ->and($outcomes['livres/deja-depose.pdf'])->toMatchArray(['outcome' => 'known', 'into' => 'depose-a-la-main'])
        ->and($outcomes['livres/livre-reference (2).pdf'])->toMatchArray(['outcome' => 'variant', 'of' => 'livres/livre-reference.pdf'])
        ->and($outcomes['Documentations/ARRETES FICTIFS/arrete-reference-1.pdf'])->toMatchArray(['outcome' => 'variant', 'of' => 'Documentations/ARRETES FICTIFS/arrete-reference.pdf'])
        ->and($outcomes['livres/copie-du-manuel.pdf'])->toMatchArray(['outcome' => 'duplicate', 'of' => ORPHAN_MANUEL, 'into' => 'manuel-imaginaire-de-finances-2014'])
        ->and($report['referenced'])->toBe(2)
        ->and($report['totals'])->toBe([
            'created' => 2, 'known' => 1, 'variant' => 2, 'duplicate' => 1,
            'excluded' => 2, 'unmapped' => 1, 'non_pdf' => 2, 'errors' => 0,
        ]);

    // Seules les deux fiches créées s'ajoutent au document déposé à la main.
    expect(Document::count())->toBe(3)
        ->and(LibraryRedirect::query()->where('from_path', '/livres/deja-depose.pdf')->value('to_path'))->toBe('/documents/'.$known->slug)
        ->and(LibraryRedirect::query()->where('from_path', '/livres/copie-du-manuel.pdf')->value('to_path'))->toBe('/documents/manuel-imaginaire-de-finances-2014')
        ->and(LibraryRedirect::query()->where('from_path', 'like', '%reference%')->exists())->toBeFalse()
        ->and(LibraryRedirect::query()->where('from_path', 'like', '%PRIVE%')->exists())->toBeFalse();
});

it('ne redirige pas un fichier déjà connu vers un document supprimé', function (): void {
    putOrphanFile($this->files, 'livres/deja-depose.pdf', $this->content['livres/deja-depose.pdf'] = orphanPdf('déjà déposé'));
    Document::factory()->create(['slug' => 'supprime-fictif', 'file_checksum_md5' => md5($this->content['livres/deja-depose.pdf'])])->delete();

    $outcomes = orphanOutcomes(runOrphanImport($this));

    expect($outcomes['livres/deja-depose.pdf'])->toMatchArray(['outcome' => 'known', 'into' => null])
        ->and(LibraryRedirect::query()->where('from_path', '/livres/deja-depose.pdf')->exists())->toBeFalse();
});

it('n\'écrit rien en dry-run et annonce le même résultat', function (): void {
    $tables = ['documents', 'document_mot_cle', 'mots_cles', 'library_redirects', 'users'];
    $counts = collect($tables)->mapWithKeys(fn (string $t): array => [$t => DB::table($t)->count()])->all();

    $dry = runOrphanImport($this, ['--dry-run' => true]);

    expect(collect($tables)->mapWithKeys(fn (string $t): array => [$t => DB::table($t)->count()])->all())->toBe($counts)
        ->and(Storage::disk('minio_documents')->allFiles())->toBe([])
        ->and(Storage::disk('minio_media')->allFiles())->toBe([])
        ->and($dry['dry_run'])->toBeTrue()
        ->and($dry['totals']['created'])->toBe(2)
        ->and($dry['redirects']['created'])->toBe(3);

    $real = runOrphanImport($this);

    expect($real['totals'])->toBe($dry['totals'])
        ->and($real['redirects']['created'])->toBe($dry['redirects']['created']);
});

it('relance sans rien recréer : les fiches créées deviennent connues', function (): void {
    runOrphanImport($this);
    $documents = Document::count();
    $redirects = LibraryRedirect::count();
    $objects = count(Storage::disk('minio_documents')->allFiles());

    $report = runOrphanImport($this);
    $outcomes = orphanOutcomes($report);

    expect(Document::count())->toBe($documents)
        ->and(LibraryRedirect::count())->toBe($redirects)
        ->and(count(Storage::disk('minio_documents')->allFiles()))->toBe($objects)
        ->and($report['totals']['created'])->toBe(0)
        ->and($report['redirects']['created'])->toBe(0)
        ->and($outcomes[ORPHAN_MANUEL])->toMatchArray(['outcome' => 'known', 'into' => 'manuel-imaginaire-de-finances-2014'])
        ->and($outcomes['livres/copie-du-manuel.pdf'])->toMatchArray(['outcome' => 'known', 'into' => 'manuel-imaginaire-de-finances-2014']);
});

it('borne le nombre de fiches créées avec --limit', function (): void {
    $report = runOrphanImport($this, ['--limit' => '1']);

    expect(Document::count())->toBe(1)
        ->and($report['totals']['created'])->toBe(1)
        ->and(serverDocument(ORPHAN_ARRETE))->not->toBeNull();
});

it('n\'enregistre aucune fiche si l\'envoi du fichier échoue', function (): void {
    $readOnly = $this->dir.'/minio-lecture-seule';
    File::ensureDirectoryExists($readOnly.'/documents');
    chmod($readOnly.'/documents', 0500);
    Storage::set('minio_documents', Storage::build(['driver' => 'local', 'root' => $readOnly, 'throw' => false]));

    try {
        $report = runOrphanImport($this, exitCode: 1);
    } finally {
        chmod($readOnly.'/documents', 0700);
    }

    expect(Document::query()->where('legacy_source', 'serveur')->count())->toBe(0)
        ->and(LibraryRedirect::query()->where('to_path', 'like', '/documents/%')->count())->toBe(0)
        ->and(Storage::disk('minio_media')->allFiles())->toBe([])
        ->and($report['totals'])->toMatchArray(['created' => 0, 'errors' => 3])
        ->and(orphanOutcomes($report)[ORPHAN_MANUEL])->toMatchArray(['outcome' => 'error'])
        ->and(orphanOutcomes($report)[ORPHAN_MANUEL]['message'])->toContain('Envoi impossible')
        // Le doublon du manuel en échec n'est pas rattaché à une fiche inexistante.
        ->and(orphanOutcomes($report)['livres/copie-du-manuel.pdf']['outcome'])->toBe('error');
});

it('retire de MinIO les objets envoyés quand la fiche échoue après l\'envoi', function (): void {
    LibraryRedirect::creating(function (LibraryRedirect $redirect): void {
        if (str_contains($redirect->from_path, 'Manuel')) {
            throw new RuntimeException('incident fictif sur la redirection');
        }
    });

    $report = runOrphanImport($this, exitCode: 1);

    expect(serverDocument(ORPHAN_MANUEL))->toBeNull()
        ->and(orphanOutcomes($report)[ORPHAN_MANUEL])->toMatchArray(['outcome' => 'error', 'message' => 'incident fictif sur la redirection'])
        // Le doublon prend la place du manuel en échec.
        ->and(orphanOutcomes($report)['livres/copie-du-manuel.pdf']['outcome'])->toBe('created')
        ->and(Document::query()->where('legacy_source', 'serveur')->count())->toBe(2)
        ->and(Storage::disk('minio_documents')->allFiles())->toEqualCanonicalizing(Document::query()->pluck('file_path')->all())
        ->and(Storage::disk('minio_media')->allFiles())->toEqualCanonicalizing(Document::query()->pluck('cover_path')->all());
});

it('refuse de démarrer quand un import tient le verrou', function (): void {
    $handle = fopen(storage_path('logs/biblio-import.lock'), 'c');
    flock($handle, LOCK_EX | LOCK_NB);

    try {
        $this->artisan('biblio:import-orphans', ['export' => $this->dir.'/absent.json', '--files' => $this->files])
            ->expectsOutputToContain('déjà en cours')
            ->assertExitCode(1);
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    expect(Document::count())->toBe(0);
});

it('écrit le rapport JSON lisible du seul propriétaire', function (): void {
    runOrphanImport($this);

    $reports = array_values(array_diff(glob(storage_path('logs/biblio-orphelins-*.json')) ?: [], $this->logsBefore));
    expect($reports)->toHaveCount(1)
        ->and(basename($reports[0]))->toBe('biblio-orphelins-20261019090000.json')
        ->and(fileperms($reports[0]) & 0777)->toBe(0600);
});

it('refuse un export illisible ou un dossier de fichiers absent', function (array $options, ?string $export): void {
    if ($export !== null) {
        file_put_contents($this->dir.'/export.json', $export);
    }
    $this->artisan('biblio:import-orphans', $options + ['export' => $this->dir.'/export.json', '--files' => $this->files])->assertExitCode(1);

    expect(Document::count())->toBe(0);
})->with([
    'export absent' => [[], null],
    'JSON invalide' => [[], '{pas du json'],
    'structure inattendue' => [[], '{"docman": "x"}'],
    'limite nulle' => [['--limit' => '0'], '{"docman":{"documents":[]},"alexandria":{"books":[]}}'],
    'dossier de fichiers absent' => [['--files' => '/chemin/inexistant'], '{"docman":{"documents":[]},"alexandria":{"books":[]}}'],
]);

it('ne démarre pas sans la migration de la source « serveur »', function (): void {
    DB::table('migrations')->where('migration', 'like', '%add_serveur_to_documents_legacy_source_check')->delete();
    file_put_contents($this->dir.'/export.json', json_encode($this->export, JSON_THROW_ON_ERROR));

    $this->artisan('biblio:import-orphans', ['export' => $this->dir.'/export.json', '--files' => $this->files])
        ->expectsOutputToContain('serveur')
        ->assertExitCode(1);

    expect(Document::count())->toBe(0)->and(Storage::disk('minio_documents')->allFiles())->toBe([]);
});

it('consigne la création de l\'utilisateur système et des mots-clés', function (): void {
    $first = runOrphanImport($this);
    $second = runOrphanImport($this);

    expect($first['system_user_created'])->toBeTrue()
        ->and($first['keywords_created'])->toBeGreaterThan(0)
        ->and($second['system_user_created'])->toBeFalse()
        ->and($second['keywords_created'])->toBe(0);
});

it('trace la couverture en échec du fichier concerné sans faire échouer la fiche', function (): void {
    app()->instance(CoverRenderer::class, new class implements CoverRenderer
    {
        public function render(string $pdfPath): ?string
        {
            return null;
        }
    });

    $report = runOrphanImport($this);

    expect(serverDocument(ORPHAN_MANUEL)?->cover_path)->toBeNull()
        ->and(orphanOutcomes($report)[ORPHAN_MANUEL])->toMatchArray(['outcome' => 'created', 'cover_failed' => true])
        ->and($report['cover_failures'])->toContain(['path' => ORPHAN_MANUEL, 'reason' => 'génération de la couverture impossible'])
        ->and($report['covers'])->toMatchArray(['generated' => 0, 'failed' => 2]);
});

it('ne prend pas une année en fin de nom pour un suffixe de copie', function (): void {
    $this->export['alexandria']['books'][] = ['id' => 2, 'file' => 'Rapport annuel fictif.pdf'];
    putOrphanFile($this->files, 'livres/Rapport annuel fictif.pdf', orphanPdf('rapport'));
    putOrphanFile($this->files, 'livres/Rapport annuel fictif-2015.pdf', orphanPdf('rapport 2015'));
    putOrphanFile($this->files, 'livres/Rapport annuel fictif-2.pdf', orphanPdf('rapport copie'));

    $outcomes = orphanOutcomes(runOrphanImport($this));

    expect($outcomes['livres/Rapport annuel fictif-2015.pdf']['outcome'])->toBe('created')
        ->and($outcomes['livres/Rapport annuel fictif-2.pdf'])->toMatchArray(['outcome' => 'variant', 'of' => 'livres/Rapport annuel fictif.pdf']);
});

it('liste sans les supprimer les objets d\'un import interrompu', function (): void {
    $journal = storage_path('logs/biblio-import-20261018-000000.journal');
    file_put_contents($journal, "envoi\tminio_documents\tdocuments/reste-fictif.pdf\tserveur\t0\n");
    Storage::disk('minio_documents')->put('documents/reste-fictif.pdf', 'reste');

    try {
        $path = $this->dir.'/export.json';
        file_put_contents($path, json_encode($this->export, JSON_THROW_ON_ERROR));
        $this->artisan('biblio:import-orphans', ['export' => $path, '--files' => $this->files])
            ->expectsOutputToContain('objet(s) MinIO en attente')
            ->assertExitCode(0);
        $report = runOrphanImport($this);
    } finally {
        @unlink($journal);
    }

    expect($report['pending_objects'])->toBe([['disk' => 'minio_documents', 'key' => 'documents/reste-fictif.pdf', 'source' => 'serveur', 'id' => 0]])
        ->and(Storage::disk('minio_documents')->get('documents/reste-fictif.pdf'))->toBe('reste');
});

it('ignore les liens symboliques vers un fichier ou un dossier', function (): void {
    putOrphanFile($this->dir.'/ailleurs', 'dossier/hors-arborescence.pdf', orphanPdf('hors arborescence'));
    symlink($this->dir.'/ailleurs/dossier/hors-arborescence.pdf', $this->files.'/livres/lien-fichier.pdf');
    symlink($this->dir.'/ailleurs/dossier', $this->files.'/livres/lien-dossier');

    $outcomes = orphanOutcomes(runOrphanImport($this));

    expect(array_filter(array_keys($outcomes), fn (string $path): bool => str_contains($path, 'lien-')))->toBe([]);
});

it('convertit en UTF-8 un nom de fichier Windows-1252 avant d\'en faire un titre', function (): void {
    expect(OrphanFileImporter::titleFromFileName("Arr\xEAt\xE9 fictif n\xB07.pdf"))->toBe('Arrêté fictif n°7')
        ->and(OrphanFileImporter::titleFromFileName('Arrêté fictif n°7.pdf'))->toBe('Arrêté fictif n°7')
        ->and(OrphanFileImporter::titleFromFileName('NOTE_FICTIVE.PDF'))->toBe('Note fictive');
});

it('n\'écrit aucun journal d\'objets en dry-run', function (): void {
    runOrphanImport($this, ['--dry-run' => true]);

    expect(array_diff(glob(storage_path('logs/biblio-import-*.journal')) ?: [], $this->logsBefore))->toBe([]);
});

it('écrit un rapport partiel quand un incident survient pendant le lancement', function (): void {
    User::creating(fn () => throw new InvalidArgumentException('base fictive indisponible'));

    $report = runOrphanImport($this, exitCode: 1);

    expect($report['fatal'])->toContain('base fictive indisponible')->and(Document::count())->toBe(0);
});
