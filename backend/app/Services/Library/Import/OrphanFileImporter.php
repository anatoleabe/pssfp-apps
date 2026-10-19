<?php

declare(strict_types=1);

namespace App\Services\Library\Import;

use App\Models\Document;
use Carbon\CarbonImmutable;
use Closure;
use FilesystemIterator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;
use UnexpectedValueException;

/**
 * Publie les fichiers de l'ancien serveur qu'aucune fiche Joomla ne référence (plan 4, task 2),
 * avec la mécanique de biblio:import (stockage MinIO, couverture, slugs, redirections, journal).
 *
 * Fichiers de `livres/` et `Documentations/` (dossiers et fichiers cachés ignorés), par chemin
 * croissant (octets). Chaque fichier reçoit une seule issue, dans cet ordre : référencé par
 * l'export (ignoré), non PDF, exclu, sans règle, déjà au catalogue (MD5), variante d'un fichier
 * référencé (nom quasi identique), doublon d'un orphelin de ce lancement (MD5), sinon créé.
 *
 * Une transaction par fichier ; un échec n'annule que lui (objets MinIO retirés, slug libéré).
 * Dry-run : aucune écriture (ni base, ni MinIO, ni redirection), même rapport. Relance
 * idempotente : un fichier déjà importé a un MD5 connu.
 */
final class OrphanFileImporter
{
    public const FOLDERS = ['livres', 'Documentations'];

    public const REDIRECT_FORM = 'serveur.fichier';

    public const SERVEUR_MIGRATION = '2026_10_19_100000_add_serveur_to_documents_legacy_source_check';

    /**
     * Suffixes de copie retirés avant la comparaison des noms : « nom (2) », « nom-1 ». Un ou deux
     * chiffres seulement après le tiret : « Rapport annuel-2015 » n'est pas une copie de « Rapport annuel ».
     */
    private const COPY_SUFFIXES = '/(?: \(\d+\)|-\d{1,2})+$/';

    private bool $dryRun = false;

    private ?OrphanImportReport $report = null;

    private ImportedFileStore $files;

    private RedirectWriter $redirects;

    private SlugAllocator $slugs;

    private ImportCatalogSetup $catalog;

    /** @var array<string, true> chemins absolus des fichiers référencés par l'export */
    private array $referenced = [];

    /** @var array<string, string> squelette du nom sans extension → fichier référencé (UTF-8) */
    private array $variants = [];

    /** @var array<string, array{slug: string, path: string}> MD5 → fiche créée dans ce lancement */
    private array $created = [];

    public function __construct(private readonly LegacyMapping $mapping) {}

    /**
     * @param  array<string, mixed>  $export  sortie de biblio:extract-joomla
     * @param  string  $files  dossier des fichiers copiés du serveur (Documentations/, livres/, images/)
     * @param  (Closure(int, int): void)|null  $progress  (traités, total)
     */
    public function import(array $export, string $files, bool $dryRun = false, ?int $limit = null, ?string $journalDirectory = null, ?Closure $progress = null): OrphanImportReport
    {
        $this->report = null;
        self::validate($export, $files, $limit);

        $this->start($dryRun, $limit, $journalDirectory);
        if (! $dryRun) {
            ImportPreflight::check();
            self::checkServerSource();
            if ($journalDirectory !== null) {
                // Signalés seulement : biblio:import --clean-orphans les supprime.
                $this->report->pendingObjects(ImportPreflight::pendingObjects($journalDirectory));
            }
        }
        $this->indexReferenced($export, ImportSources::fromDirectory($files));
        $candidates = self::walk($files);

        $run = function () use ($candidates, $limit, $progress): void {
            if ($this->catalog->ensureSystemUser()) {
                $this->report->systemUserCreated();
            }
            $this->report->keywordsCreated($this->catalog->ensureThesaurus());
            $done = 0;
            foreach ($candidates as $relative => $absolute) {
                if ($limit !== null && $this->report->totals()['created'] >= $limit) {
                    $this->report->notProcessed(count($candidates) - $done);

                    break;
                }
                $this->process((string) $relative, $absolute);
                $progress?->__invoke(++$done, count($candidates));
            }
        };

        if ($dryRun) {
            // Garde-fou : même si une écriture échappait aux gardes, elle serait annulée.
            DB::beginTransaction();
            try {
                $run();
            } finally {
                DB::rollBack();
            }
        } else {
            $run();
        }

        return $this->report;
    }

    /** Rapport du lancement en cours ou interrompu (null si l'export a été refusé d'emblée). */
    public function partialReport(): ?OrphanImportReport
    {
        return $this->report;
    }

    /**
     * Arguments du lancement : export (fiches DOCman et livres Alexandria), dossier des fichiers, limite.
     *
     * @param  array<string, mixed>  $export
     *
     * @throws InvalidArgumentException message lisible, rien n'a été lancé
     */
    public static function validate(array $export, string $files, ?int $limit): void
    {
        foreach (['docman.documents', 'alexandria.books'] as $key) {
            if (! is_array(data_get($export, $key))) {
                throw new InvalidArgumentException("Export Joomla invalide : « {$key} » manquant ou mal formé.");
            }
        }
        if ($limit !== null && $limit < 1) {
            throw new InvalidArgumentException('--limit attend un entier strictement positif.');
        }
        if ($files === '' || ! is_dir($files)) {
            throw new InvalidArgumentException("Dossier des fichiers introuvable (--files) : {$files}");
        }
    }

    /** Titre tiré du nom de fichier (octets bruts, Windows-1252 converti en UTF-8), sans extension. */
    public static function titleFromFileName(string $name): string
    {
        $stem = self::utf8(self::stem($name));
        $title = LegacyText::title($stem);

        return $title === '' ? $stem : $title;
    }

    private static function checkServerSource(): void
    {
        if (! DB::table('migrations')->where('migration', self::SERVEUR_MIGRATION)->exists()) {
            throw new RuntimeException('Préalable non rempli : la migration de la source « serveur » ('.self::SERVEUR_MIGRATION.') n\'est pas passée (php artisan migrate).');
        }
    }

    private function start(bool $dryRun, ?int $limit, ?string $journalDirectory): void
    {
        $this->dryRun = $dryRun;
        $this->report = new OrphanImportReport($dryRun, ['limit' => $limit]);
        $this->redirects = new RedirectWriter($this->report, $dryRun);
        $this->slugs = new SlugAllocator;
        $this->files = new ImportedFileStore($journalDirectory === null
            ? null
            : ImportJournal::inDirectory($journalDirectory, Carbon::now()->format('Ymd-His')));
        $this->catalog = new ImportCatalogSetup($this->mapping, $dryRun);
        $this->referenced = [];
        $this->variants = [];
        $this->created = [];
    }

    /**
     * Fichiers des fiches de l'export, retrouvés comme biblio:import les retrouve (chemin exact
     * puis correspondance tolérante) ; chemins absolus et squelettes des noms.
     *
     * @param  array<string, mixed>  $export
     */
    private function indexReferenced(array $export, ImportSources $sources): void
    {
        $records = [
            ['Documentations', $sources->documentations, $export['docman']['documents'], 'storage_path'],
            ['livres', $sources->books, $export['alexandria']['books'], 'file'],
        ];
        $found = [];
        foreach ($records as [$folder, $locator, $rows, $field]) {
            foreach ($rows as $row) {
                $file = is_array($row) && is_scalar($row[$field] ?? null) ? trim((string) $row[$field]) : '';
                $located = $file === '' ? null : $locator->locate($file);
                if ($located !== null) {
                    $this->referenced[$located->path] = true;
                    $found[$folder.'/'.$located->relative] = true;
                }
            }
        }

        ksort($found, SORT_STRING);
        foreach (array_keys($found) as $relative) {
            $skeleton = FileLocator::skeleton(self::stem(self::fileName((string) $relative)));
            if ($skeleton !== '') {
                $this->variants[$skeleton] ??= self::utf8((string) $relative);
            }
        }
    }

    /**
     * @return array<string, string> chemin relatif à --files (octets bruts) → chemin absolu, trié
     */
    private static function walk(string $files): array
    {
        $entries = [];
        foreach (self::FOLDERS as $folder) {
            $root = rtrim($files, '/').'/'.$folder;
            if (! is_dir($root)) {
                continue;
            }
            try {
                $iterator = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
                    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                    // Dossiers et fichiers cachés (« .tmp », « .DS_Store ») ignorés, contenu compris.
                    fn (SplFileInfo $file): bool => ! str_starts_with($file->getFilename(), '.'),
                ));
                /** @var SplFileInfo $file */
                foreach ($iterator as $file) {
                    // Liens symboliques ignorés : ils peuvent pointer hors de --files.
                    if ($file->isFile() && ! $file->isLink()) {
                        $entries[$folder.'/'.substr($file->getPathname(), strlen($root) + 1)] = $file->getPathname();
                    }
                }
            } catch (UnexpectedValueException $e) {
                throw new RuntimeException("Dossier illisible sous {$root} : {$e->getMessage()}", 0, $e);
            }
        }
        ksort($entries, SORT_STRING);

        return $entries;
    }

    private function process(string $relative, string $absolute): void
    {
        if (isset($this->referenced[$absolute])) {
            $this->report->referenced();

            return;
        }

        $path = self::utf8($relative);
        $this->report->begin();
        $this->redirects->begin();
        $this->files->begin(LegacyMapping::SOURCE_SERVER, 0);
        $pending = ['md5' => null, 'slug' => null];

        try {
            DB::transaction(function () use ($relative, $absolute, $path, &$pending): void {
                $this->classify($relative, $absolute, $path, $pending);
            });
        } catch (Throwable $e) {
            $this->redirects->rollback();
            $this->report->rollback();
            if ($pending['md5'] !== null) {
                unset($this->created[$pending['md5']]);
            }
            if ($pending['slug'] !== null) {
                $this->slugs->release($pending['slug']);
            }
            foreach ($this->files->discard() as [$disk, $key]) {
                $this->report->cleanupFailure($disk, $key);
            }
            $this->report->error($path, $e->getMessage());

            return;
        }

        $this->files->committed();
    }

    /** @param  array{md5: ?string, slug: ?string}  $pending  MD5 et slug retenus (annulés si le fichier échoue) */
    private function classify(string $relative, string $absolute, string $path, array &$pending): void
    {
        $name = self::fileName($relative);
        $stem = self::stem($name);
        $extension = strtolower($stem === $name ? '' : substr($name, strlen($stem) + 1));
        if ($extension !== 'pdf' || ! self::hasPdfSignature($absolute)) {
            $this->report->outcome($path, OrphanImportReport::NON_PDF, ['extension' => self::utf8($extension)]);

            return;
        }

        $rule = $this->mapping->forServerFile($relative);
        if ($rule->exclusionReason !== null) {
            $this->report->outcome($path, OrphanImportReport::EXCLUDED, ['reason' => $rule->exclusionReason, 'rule' => $rule->folderRule]);

            return;
        }
        if ($rule->category === null) {
            $this->report->outcome($path, OrphanImportReport::UNMAPPED);

            return;
        }

        $md5 = md5_file($absolute);
        if ($md5 === false) {
            throw new RuntimeException("Lecture impossible du fichier « {$path} ».");
        }

        if (! isset($this->created[$md5]) && Document::withTrashed()->where('file_checksum_md5', $md5)->exists()) {
            $target = Document::query()->where('file_checksum_md5', $md5)->orderBy('id')->first(['id', 'slug']);
            if ($target !== null) {
                $this->redirects->ensure($this->legacyPaths($relative), '/documents/'.$target->slug);
            }
            $this->report->outcome($path, OrphanImportReport::KNOWN, ['into' => $target?->slug]);

            return;
        }

        // Comparaison à sens unique : seuls les suffixes de l'orphelin sont retirés. Un orphelin
        // « nom » dont le fichier référencé est « nom (2) » ou « nom-1 » n'est donc pas reconnu
        // comme variante (il serait créé) : cas non observé sur l'ancien serveur, accepté.
        $skeleton = FileLocator::skeleton((string) preg_replace(self::COPY_SUFFIXES, '', $stem));
        if ($skeleton !== '' && isset($this->variants[$skeleton])) {
            $this->report->outcome($path, OrphanImportReport::VARIANT, ['of' => $this->variants[$skeleton]]);

            return;
        }

        if (isset($this->created[$md5])) {
            $first = $this->created[$md5];
            $this->redirects->ensure($this->legacyPaths($relative), '/documents/'.$first['slug']);
            $this->report->outcome($path, OrphanImportReport::DUPLICATE, ['of' => $first['path'], 'into' => $first['slug']]);

            return;
        }

        $this->create($relative, $absolute, $path, $rule->category, $md5, $pending);
    }

    /** @param  array{md5: ?string, slug: ?string}  $pending */
    private function create(string $relative, string $absolute, string $path, MappedCategory $mapped, string $md5, array &$pending): void
    {
        $title = self::titleFromFileName(self::fileName($relative));
        $slug = $this->slugs->allocate(LegacyText::slug('', $title));
        $pending['slug'] = $slug;
        $this->created[$md5] = ['slug' => $slug, 'path' => $path];
        $pending['md5'] = $md5;
        $paths = $this->legacyPaths($relative);

        if ($this->dryRun) {
            $this->report->cover('generated');
            $this->redirects->ensure($paths, '/documents/'.$slug);
            $this->report->created($path, $mapped->type, $slug);

            return;
        }

        $modified = filemtime($absolute);
        if ($modified === false) {
            throw new RuntimeException("Date de modification illisible : « {$path} ».");
        }

        $document = Document::query()->create([
            'slug' => $slug,
            'title' => ['fr' => $title],
            'type' => $mapped->type,
            'access_level' => $mapped->accessLevel,
            'year' => LegacyText::year(null, $title),
            'language' => LegacyText::language($title),
            'status' => Document::STATUS_PUBLISHED,
            'published_at' => CarbonImmutable::createFromTimestamp($modified, (string) config('app.timezone')),
            'approved_by' => $this->catalog->systemUserId(),
            'approved_at' => Carbon::now(),
            'uploaded_by' => $this->catalog->systemUserId(),
            'views_count' => 0,
            'legacy_source' => LegacyMapping::SOURCE_SERVER,
            'legacy_id' => null,
            'legacy_url' => $paths === [] ? null : $paths[0]->path,
        ]);
        $document->motsCles()->syncWithoutDetaching($this->catalog->keywordIdsFor($mapped));

        $coverFailed = $this->files->store($document, $absolute, $md5, 'pdf') === 'failed';
        $this->report->cover($coverFailed ? 'failed' : 'generated');
        if ($coverFailed) {
            $this->report->coverFailure($path, 'génération de la couverture impossible');
        }

        $this->redirects->ensure($paths, '/documents/'.$slug);
        $document->refresh()->syncSearchIndex();
        $this->report->created($path, $mapped->type, $slug, $coverFailed);
    }

    /** @return list<LegacyPath> URL directe du fichier sur l'ancien serveur ; vide si trop longue */
    private function legacyPaths(string $relative): array
    {
        $path = '/'.RedirectPlanner::encodePath($relative);
        if (strlen($path) > RedirectPlanner::MAX_PATH_LENGTH) {
            $this->report->redirectDiscarded();

            return [];
        }

        return [new LegacyPath($path, self::REDIRECT_FORM)];
    }

    private static function hasPdfSignature(string $absolute): bool
    {
        $handle = @fopen($absolute, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Lecture impossible du fichier.');
        }
        try {
            return fread($handle, 5) === '%PDF-';
        } finally {
            fclose($handle);
        }
    }

    /** Dernier segment du chemin, octet par octet (basename() dépend de la locale). */
    private static function fileName(string $relative): string
    {
        $slash = strrpos($relative, '/');

        return $slash === false ? $relative : substr($relative, $slash + 1);
    }

    /** Nom sans son extension. */
    private static function stem(string $name): string
    {
        $dot = strrpos($name, '.');

        return $dot === false || $dot === 0 ? $name : substr($name, 0, $dot);
    }

    /** Nom de fichier en UTF-8 (Windows-1252 sinon), comme FileLocator::key. */
    private static function utf8(string $value): string
    {
        return mb_check_encoding($value, 'UTF-8') ? $value : mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
    }
}
