<?php

declare(strict_types=1);

namespace App\Services\Library\Import;

use App\Jobs\ProcessDocumentFile;
use App\Models\Document;
use App\Models\MotCle;
use App\Models\User;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Import idempotent de l'ancienne bibliothèque Joomla (plan 3, tasks 4 et 5) : documents DOCman
 * et livres Alexandria de l'export, fichiers envoyés sur MinIO, couvertures, auteurs, mots-clés,
 * redirections des anciennes URL, publication d'office.
 *
 * Clé d'idempotence : (legacy_source, legacy_id), documents supprimés compris (un document
 * supprimé dans la nouvelle bibliothèque n'est pas recréé). Un fichier dont le MD5 est déjà connu
 * n'est pas importé deux fois : ses anciennes URL mènent à la fiche existante, qui cumule ses vues.
 * Lots de 100 éléments par transaction, un point de sauvegarde par élément (un échec n'annule que
 * lui). Dry-run : tout se déroule dans une transaction annulée, sans aucun envoi sur MinIO.
 */
final class LegacyImporter
{
    public const SYSTEM_USER_EMAIL = 'import-joomla@pssfp.invalid';

    public const SYSTEM_USER_NAME = 'Import Joomla';

    private const BATCH_SIZE = 100;

    private const COVER_EXTENSIONS = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'];

    private const FILE_EXTENSION = '/^[a-z0-9]{1,5}$/';

    private ImportOptions $options;

    private ImportReport $report;

    private ImportSources $sources;

    private LegacyCategories $categories;

    private RedirectPlanner $planner;

    private RedirectWriter $redirects;

    private SlugAllocator $slugs;

    /** @var array<string, array<int, MappedCategory|string>> correspondance, ou message d'erreur */
    private array $mapped = [];

    /** @var array<string, int> slug du thésaurus → id */
    private array $keywordIds = [];

    private ?int $systemUserId = null;

    /** @var array<string, array{id: ?int, slug: string, trashed: bool}> MD5 → document */
    private array $checksums = [];

    /** @var list<string> MD5 retenus par l'élément en cours */
    private array $pendingChecksums = [];

    /** @var list<array{0: string, 1: string}> objets MinIO envoyés pour l'élément en cours */
    private array $uploaded = [];

    public function __construct(private readonly LegacyMapping $mapping) {}

    /**
     * @param  array<string, mixed>  $export  sortie de biblio:extract-joomla
     * @param  (Closure(int, int): void)|null  $progress  (traités, total)
     */
    public function import(array $export, ImportSources $sources, ImportOptions $options, ?Closure $progress = null): ImportReport
    {
        self::assertShape($export);
        $this->start($export, $sources, $options);
        $items = $this->items($export);

        $run = function () use ($items, $progress): void {
            $this->ensureSystemUser();
            $this->ensureThesaurus();
            $done = 0;
            foreach (array_chunk($items, self::BATCH_SIZE) as $batch) {
                DB::transaction(function () use ($batch, $progress, &$done, $items): void {
                    foreach ($batch as $item) {
                        $this->importItem($item);
                        $progress?->__invoke(++$done, count($items));
                    }
                });
            }
            DB::transaction(fn () => $this->categoryRedirects());
        };

        if ($options->dryRun) {
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

        $this->report->redirectsDiscarded($this->planner->discardedCount());

        return $this->report;
    }

    /** @param  array<string, mixed>  $export */
    private static function assertShape(array $export): void
    {
        foreach (['docman.categories', 'docman.documents', 'alexandria.categories', 'alexandria.books', 'menus'] as $key) {
            if (! is_array(data_get($export, $key))) {
                throw new InvalidArgumentException("Export Joomla invalide : « {$key} » manquant ou mal formé.");
            }
        }
    }

    /** @param  array<string, mixed>  $export */
    private function start(array $export, ImportSources $sources, ImportOptions $options): void
    {
        $this->options = $options;
        $this->sources = $sources;
        $this->report = new ImportReport($options->dryRun, ['only' => $options->only, 'limit' => $options->limit]);
        $this->categories = new LegacyCategories($export);
        $this->planner = new RedirectPlanner($export);
        $this->redirects = new RedirectWriter($this->report, $options->dryRun);
        $this->slugs = new SlugAllocator;
        $this->mapped = [];
        $this->keywordIds = [];
        $this->systemUserId = null;
        $this->checksums = [];
    }

    /**
     * @param  array<string, mixed>  $export
     * @return list<LegacyItem> DOCman puis Alexandria, par identifiant croissant, limités à --limit
     */
    private function items(array $export): array
    {
        $items = [];
        $titles = [];
        if ($this->options->includes(LegacyMapping::SOURCE_DOCMAN)) {
            foreach (collect($export['docman']['documents'])->sortBy('id') as $row) {
                $items[] = LegacyItem::fromDocman((array) $row, $this->categories);
                $titles[] = (string) ($row['title'] ?? '');
            }
        }
        if ($this->options->includes(LegacyMapping::SOURCE_ALEXANDRIA)) {
            foreach (collect($export['alexandria']['books'])->sortBy('id') as $row) {
                $items[] = LegacyItem::fromAlexandria((array) $row, $this->categories);
                $titles[] = (string) ($row['title'] ?? '');
            }
        }
        $this->report->acronymCandidates(array_slice(LegacyText::lowercasedUppercaseWords($titles), 0, 40, true));

        return $this->options->limit === null ? $items : array_slice($items, 0, $this->options->limit);
    }

    private function ensureSystemUser(): void
    {
        $user = User::query()->where('email', self::SYSTEM_USER_EMAIL)->first();
        if ($user === null && ! $this->options->dryRun) {
            // Aucun rôle, e-mail non vérifié, mot de passe aléatoire jamais communiqué : compte inutilisable.
            $user = User::query()->firstOrCreate(
                ['email' => self::SYSTEM_USER_EMAIL],
                ['name' => self::SYSTEM_USER_NAME, 'password' => Str::password(64)],
            );
        }
        if ($user === null || $user->wasRecentlyCreated) {
            $this->report->systemUserCreated();
        }
        $this->systemUserId = $user?->id;
    }

    private function ensureThesaurus(): void
    {
        foreach ($this->mapping->thesaurus() as $ref) {
            $keyword = MotCle::query()->where('slug', $ref->slug)->first();
            if ($keyword === null && ! $this->options->dryRun) {
                $keyword = MotCle::query()->firstOrCreate(['slug' => $ref->slug], [
                    'label' => ['fr' => $ref->label],
                    'parent_id' => $ref->parentSlug === null ? null : ($this->keywordIds[$ref->parentSlug] ?? null),
                ]);
            }
            if ($keyword === null || $keyword->wasRecentlyCreated) {
                $this->report->keywordCreated();
            }
            if ($keyword !== null) {
                $this->keywordIds[$ref->slug] = $keyword->id;
            }
        }
    }

    private function importItem(LegacyItem $item): void
    {
        $this->redirects->begin();
        $this->pendingChecksums = [];
        $this->uploaded = [];
        $allocatedSlugs = [];

        try {
            DB::transaction(function () use ($item, &$allocatedSlugs): void {
                $this->importOne($item, $allocatedSlugs);
            });
        } catch (Throwable $e) {
            $this->redirects->rollback();
            foreach ($this->pendingChecksums as $md5) {
                unset($this->checksums[$md5]);
            }
            foreach ($allocatedSlugs as $slug) {
                $this->slugs->release($slug);
            }
            $this->discardUploads();
            $this->report->error($item->source, $item->id, $e->getMessage());
        }
    }

    /** @param  list<string>  $allocatedSlugs  slugs attribués (libérés si l'élément échoue) */
    private function importOne(LegacyItem $item, array &$allocatedSlugs): void
    {
        if ($item->skipReason !== null) {
            $this->report->skipped($item, $item->skipReason);

            return;
        }

        $mapped = $this->mappedCategory($item->source, $item->categoryId);
        $existing = Document::withTrashed()
            ->where('legacy_source', $item->source)
            ->where('legacy_id', $item->id)
            ->first();
        if ($existing !== null) {
            $this->refresh($existing, $item, $mapped);

            return;
        }

        $file = $this->sources->forSource($item->source)->locate((string) $item->file);
        if ($file === null) {
            $this->report->missingFile($item);

            return;
        }
        $this->report->fileFound($item, $file);

        $md5 = md5_file($file->path);
        if ($md5 === false) {
            throw new RuntimeException("Lecture impossible du fichier « {$item->file} ».");
        }

        $duplicate = $this->duplicateOf($md5);
        if ($duplicate !== null) {
            $this->merge($item, $mapped, $duplicate, $md5);

            return;
        }

        $slug = $this->slugs->allocate(LegacyText::slug($item->alias, $item->title));
        $allocatedSlugs[] = $slug;
        $this->create($item, $mapped, $file, $md5, $slug);
    }

    private function create(LegacyItem $item, MappedCategory $mapped, LocatedFile $file, string $md5, string $slug): void
    {
        $paths = $this->legacyPaths($item);
        $this->rememberChecksum($md5, null, $slug, false);
        $cover = $item->source === LegacyMapping::SOURCE_ALEXANDRIA ? $this->coverImage($item) : null;

        if ($this->options->dryRun) {
            $this->report->cover($cover === null ? 'generated' : 'copied');
            $this->redirects->ensure($paths, '/documents/'.$slug);
            $this->report->created($item, $mapped->type);

            return;
        }

        $published = $item->isPublished();
        $document = Document::query()->create($this->metadata($item, $mapped, $paths) + [
            'slug' => $slug,
            // Niveau d'accès fixé à la création seulement : une restriction posée depuis
            // l'administration survit à une relance de l'import.
            'access_level' => $mapped->accessLevel,
            'title' => ['fr' => $item->title],
            'legacy_source' => $item->source,
            'legacy_id' => $item->id,
            'status' => $published ? Document::STATUS_PUBLISHED : Document::STATUS_DRAFT,
            'published_at' => $item->publishedAt,
            'approved_by' => $published ? $this->systemUserId : null,
            'approved_at' => $published ? Carbon::now() : null,
            'uploaded_by' => $this->systemUserId,
            'views_count' => $item->hits,
        ] + array_filter([
            'subtitle' => $item->subtitle === null ? null : ['fr' => $item->subtitle],
            'abstract' => $item->abstract === null ? null : ['fr' => $item->abstract],
        ]));
        $this->rememberChecksum($md5, $document->id, $slug, false);

        $document->authors()->createMany(array_map(
            fn (string $name, int $i): array => ['author_name' => $name, 'order' => $i + 1],
            $item->authors,
            array_keys($item->authors),
        ));
        $this->syncKeywords($document, $mapped);
        $this->storeFile($document, $file, $md5, $cover);
        $this->redirects->ensure($paths, '/documents/'.$slug);
        $document->refresh()->syncSearchIndex();
        $this->report->created($item, $mapped->type);
    }

    /**
     * Fichier sur MinIO (writeStream, retour vérifié), puis couverture : celle d'Alexandria si elle
     * existe, sinon générée par ProcessDocumentFile, exécuté ici de façon synchrone (le fichier est
     * enregistré sans événement pour que l'observateur ne le mette pas aussi en file).
     *
     * @param  array{path: string, extension: string}|null  $cover
     */
    private function storeFile(Document $document, LocatedFile $file, string $md5, ?array $cover): void
    {
        $extension = strtolower(pathinfo($file->relative, PATHINFO_EXTENSION));
        $key = 'documents/'.$document->uuid.'.'.(preg_match(self::FILE_EXTENSION, $extension) === 1 ? $extension : 'pdf');
        $this->upload((string) config('library.documents_disk'), $key, $file->path, []);

        $coverPath = null;
        if ($cover !== null) {
            $coverPath = config('library.covers_directory').'/'.$document->uuid.'.'.$cover['extension'];
            $this->upload((string) config('library.covers_disk'), $coverPath, $cover['path'], ['visibility' => 'public']);
        }

        $document->forceFill([
            'file_path' => $key,
            'file_size_bytes' => filesize($file->path) ?: null,
            'file_checksum_md5' => $md5,
            'cover_path' => $coverPath,
        ])->saveQuietly();

        if ($coverPath !== null) {
            $this->report->cover('copied');

            return;
        }
        ProcessDocumentFile::dispatchSync($document->id);
        $this->report->cover($document->refresh()->cover_path === null ? 'failed' : 'generated');
    }

    /** @param  array<string, mixed>  $options */
    private function upload(string $disk, string $key, string $localPath, array $options): void
    {
        $stream = fopen($localPath, 'rb');
        if ($stream === false) {
            throw new RuntimeException("Lecture impossible : {$localPath}");
        }

        try {
            // Disques MinIO en throw=false : le booléen renvoyé est la seule preuve de l'envoi.
            $written = Storage::disk($disk)->writeStream($key, $stream, $options);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if ($written !== true) {
            throw new RuntimeException("Envoi impossible vers le disque {$disk} : {$key}");
        }
        $this->uploaded[] = [$disk, $key];
    }

    private function discardUploads(): void
    {
        foreach ($this->uploaded as [$disk, $key]) {
            Storage::disk($disk)->delete($key);
        }
        $this->uploaded = [];
    }

    /** @return array{path: string, extension: string}|null image de couverture Alexandria valide */
    private function coverImage(LegacyItem $item): ?array
    {
        if ($item->image === null) {
            return null;
        }

        $located = $this->sources->cover($item->image);
        if ($located === null) {
            $this->report->cover('image_missing');

            return null;
        }

        $info = @getimagesize($located->path);
        $extension = $info === false ? null : (self::COVER_EXTENSIONS[$info[2]] ?? null);
        if ($extension === null) {
            $this->report->cover('image_invalid');

            return null;
        }

        return ['path' => $located->path, 'extension' => $extension];
    }

    /**
     * Document déjà importé : métadonnées Joomla rafraîchies (type, titres, année, langue…) ;
     * statut, slug, niveau d'accès, fichier et couverture conservés ; vues jamais diminuées.
     */
    private function refresh(Document $document, LegacyItem $item, MappedCategory $mapped): void
    {
        if ($document->trashed()) {
            $this->report->skipped($item, 'supprimé dans la nouvelle bibliothèque');

            return;
        }

        $paths = $this->legacyPaths($item);
        $document->fill($this->metadata($item, $mapped, $paths));
        foreach (['title' => $item->title, 'subtitle' => $item->subtitle, 'abstract' => $item->abstract] as $key => $value) {
            if ($value !== null && $document->getTranslation($key, 'fr', false) !== $value) {
                $document->setTranslation($key, 'fr', $value);
            }
        }
        $document->views_count = max((int) $document->views_count, $item->hits);
        $changed = $document->isDirty();

        if (! $this->options->dryRun) {
            if ($changed) {
                $document->save();
            }
            $this->syncKeywords($document, $mapped);
            if ($item->authors !== [] && ! $document->authors()->exists()) {
                $document->authors()->createMany(array_map(
                    fn (string $name, int $i): array => ['author_name' => $name, 'order' => $i + 1],
                    $item->authors,
                    array_keys($item->authors),
                ));
            }
        }
        if ($document->file_checksum_md5 !== null) {
            $this->rememberChecksum($document->file_checksum_md5, $document->id, $document->slug, false);
        }

        $this->redirects->ensure($paths, '/documents/'.$document->slug);
        if (! $this->options->dryRun) {
            $document->syncSearchIndex();
        }
        $this->report->updated($item, $changed);
    }

    /** @param  array{id: ?int, slug: string, trashed: bool}  $target */
    private function merge(LegacyItem $item, MappedCategory $mapped, array $target, string $md5): void
    {
        if ($target['trashed']) {
            $this->report->skipped($item, "doublon (MD5) d'un document supprimé : {$target['slug']}");

            return;
        }

        $paths = $this->legacyPaths($item);
        $created = $this->redirects->ensure($paths, '/documents/'.$target['slug']);

        if (! $this->options->dryRun && $target['id'] !== null) {
            // Vues cumulées une seule fois : quand l'URL à query string de l'élément vient d'être créée.
            $marker = collect($paths)->first(fn (LegacyPath $p): bool => str_ends_with($p->form, '.requete'))?->path;
            if ($marker !== null && ($created[$marker] ?? false) && $item->hits > 0) {
                Document::withTrashed()->whereKey($target['id'])->increment('views_count', $item->hits);
            }
            $document = Document::query()->find($target['id']);
            if ($document !== null) {
                $document->motsCles()->syncWithoutDetaching($this->keywordIdsFor($mapped));
            }
        }

        $this->report->merged($item, $target['slug'], $md5);
    }

    /** @return array{id: ?int, slug: string, trashed: bool}|null */
    private function duplicateOf(string $md5): ?array
    {
        if (isset($this->checksums[$md5])) {
            return $this->checksums[$md5];
        }

        $document = Document::withTrashed()->where('file_checksum_md5', $md5)->orderBy('id')->first(['id', 'slug', 'deleted_at']);
        if ($document === null) {
            return null;
        }

        return $this->checksums[$md5] = ['id' => $document->id, 'slug' => $document->slug, 'trashed' => $document->trashed()];
    }

    private function rememberChecksum(string $md5, ?int $id, string $slug, bool $trashed): void
    {
        $this->checksums[$md5] = ['id' => $id, 'slug' => $slug, 'trashed' => $trashed];
        $this->pendingChecksums[] = $md5;
    }

    /**
     * @param  list<LegacyPath>  $paths
     * @return array<string, mixed> métadonnées issues de Joomla (hors titres traduisibles)
     */
    private function metadata(LegacyItem $item, MappedCategory $mapped, array $paths): array
    {
        return [
            'type' => $mapped->type,
            'year' => $item->year(),
            'language' => $item->language(),
            'isbn' => $item->isbn,
            'pages_count' => $item->pages,
            'legacy_url' => $paths === [] ? null : $paths[0]->path,
        ];
    }

    /** @return list<LegacyPath> */
    private function legacyPaths(LegacyItem $item): array
    {
        return $item->source === LegacyMapping::SOURCE_DOCMAN
            ? $this->planner->docmanDocument($item->raw)
            : $this->planner->alexandriaBook($item->raw);
    }

    /** Mots-clés de la correspondance ; les autres mots-clés du thésaurus d'import sont retirés, les autres conservés. */
    private function syncKeywords(Document $document, MappedCategory $mapped): void
    {
        $wanted = $this->keywordIdsFor($mapped);
        $current = $document->motsCles()->pluck('mots_cles.id')->all();
        $obsolete = array_diff(array_intersect($current, $this->keywordIds), $wanted);
        if ($obsolete !== []) {
            $document->motsCles()->detach($obsolete);
        }
        $document->motsCles()->syncWithoutDetaching($wanted);
    }

    /** @return list<int> */
    private function keywordIdsFor(MappedCategory $mapped): array
    {
        return array_values(array_filter(array_map(
            fn (KeywordRef $keyword): ?int => $this->keywordIds[$keyword->slug] ?? null,
            $mapped->keywords,
        )));
    }

    private function mappedCategory(string $source, ?int $categoryId): MappedCategory
    {
        if ($categoryId === null || ! $this->categories->exists($source, $categoryId)) {
            throw new RuntimeException("Catégorie {$source} n° {$categoryId} absente de l'export.");
        }

        if (! isset($this->mapped[$source][$categoryId])) {
            $path = $this->categories->path($source, $categoryId);
            try {
                $this->mapped[$source][$categoryId] = $source === LegacyMapping::SOURCE_DOCMAN
                    ? $this->mapping->forDocmanPath($path)
                    : $this->mapping->forAlexandriaCategory($path);
            } catch (UnmappedLegacyCategory $e) {
                $this->mapped[$source][$categoryId] = $e->getMessage();
            }
        }

        $mapped = $this->mapped[$source][$categoryId];
        if (is_string($mapped)) {
            throw new RuntimeException($mapped);
        }

        return $mapped;
    }

    /** Pages de catégorie : `/collections/{type}`, filtrée sur le mot-clé le plus spécifique s'il y en a un. */
    private function categoryRedirects(): void
    {
        foreach (ImportOptions::SOURCES as $source) {
            if (! $this->options->includes($source)) {
                continue;
            }
            foreach ($this->categories->ids($source) as $id) {
                try {
                    $mapped = $this->mappedCategory($source, $id);
                } catch (RuntimeException) {
                    continue;
                }

                $keyword = $mapped->primaryKeyword();
                $target = '/collections/'.$mapped->type.($keyword === null ? '' : '?mot_cle='.$keyword->slug);
                $this->redirects->begin();
                $this->redirects->ensure(
                    $source === LegacyMapping::SOURCE_DOCMAN ? $this->planner->docmanCategory($id) : $this->planner->alexandriaCategory($id),
                    $target,
                );
            }
        }
    }
}
