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
 * n'est pas importé deux fois : ses anciennes URL mènent à la fiche existante, qui cumule ses vues
 * (et la publie si c'était un brouillon de l'import et que l'élément est publié).
 *
 * Reprise après incident : une transaction par élément, validée aussitôt (un échec n'annule que
 * lui) ; chaque objet MinIO est inscrit au journal avant son envoi puis marqué validé (voir
 * ImportJournal). Une relance ne modifie pas ce qui a été retouché depuis l'administration : elle
 * ne complète que les champs vides et n'ajoute que les mots-clés manquants.
 * Dry-run : tout se déroule dans une transaction annulée, sans aucun envoi sur MinIO.
 */
final class LegacyImporter
{
    public const SYSTEM_USER_EMAIL = 'import-joomla@pssfp.invalid';

    public const SYSTEM_USER_NAME = 'Import Joomla';

    private const COVER_EXTENSIONS = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'];

    private const FILE_EXTENSION = '/^[a-z0-9]{1,5}$/';

    private ImportOptions $options;

    private ?ImportReport $report = null;

    private ?ImportJournal $journal = null;

    private ?LegacyItem $current = null;

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

    /** @var array<string, array{id: ?int, slug: string, trashed: bool, draft: bool, imported: bool}> MD5 → document */
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
        $this->report = null;
        self::assertShape($export);
        $this->start($export, $sources, $options);
        $items = $this->items($export);

        if (! $options->dryRun) {
            ImportPreflight::check();
        }
        if ($options->journalDirectory !== null && $this->journal !== null) {
            ImportPreflight::orphans($options->journalDirectory, $this->journal, $this->report, $options->cleanOrphans && ! $options->dryRun);
        }

        $run = function () use ($items, $progress): void {
            $this->ensureSystemUser();
            $this->ensureThesaurus();
            foreach ($items as $index => $item) {
                $this->importItem($item);
                $progress?->__invoke($index + 1, count($items));
            }
            $this->categoryRedirects();
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

    /** Rapport du lancement en cours ou interrompu (null si l'export a été refusé d'emblée). */
    public function partialReport(): ?ImportReport
    {
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
        $this->journal = $options->journalDirectory === null
            ? null
            : ImportJournal::inDirectory($options->journalDirectory, Carbon::now()->format('Ymd-His'));
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
        $this->current = $item;
        $this->report->begin();
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
            $this->report->rollback();
            foreach ($this->pendingChecksums as $md5) {
                unset($this->checksums[$md5]);
            }
            foreach ($allocatedSlugs as $slug) {
                $this->slugs->release($slug);
            }
            $this->discardUploads($item);
            $this->report->error($item->source, $item->id, $e->getMessage());

            return;
        }

        // Document validé en base : ses objets ne sont plus des orphelins potentiels.
        foreach ($this->uploaded as [$disk, $key]) {
            $this->journal?->committed($disk, $key, $item->source, $item->id);
        }
        $this->uploaded = [];
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
        $this->rememberChecksum($md5, null, $slug, false, ! $item->isPublished(), true);
        $cover = $item->source === LegacyMapping::SOURCE_ALEXANDRIA ? $this->coverImage($item) : null;
        $extension = self::fileExtension($file);
        if ($extension !== 'pdf') {
            $this->report->nonPdfFile($item, $extension);
        }

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
            // Accès restreint dans Joomla : réservé, pour qu'une publication ultérieure ne l'expose pas.
            'access_level' => $item->restricted ? 'auditor' : $mapped->accessLevel,
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
        $this->rememberChecksum($md5, $document->id, $slug, false, ! $published, true);

        $document->authors()->createMany(array_map(
            fn (string $name, int $i): array => ['author_name' => $name, 'order' => $i + 1],
            $item->authors,
            array_keys($item->authors),
        ));
        $this->addKeywords($document, $mapped);
        $this->storeFile($document, $file, $md5, $cover, $extension);
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
    private function storeFile(Document $document, LocatedFile $file, string $md5, ?array $cover, string $extension): void
    {
        $key = 'documents/'.$document->uuid.'.'.$extension;
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
        // Couverture générée par le job : journalisée d'avance, retirée si l'élément échoue.
        $disk = (string) config('library.covers_disk');
        $generated = config('library.covers_directory').'/'.$document->uuid.'.jpg';
        $this->journal?->sent($disk, $generated, $this->current->source, $this->current->id);
        $this->uploaded[] = [$disk, $generated];

        ProcessDocumentFile::dispatchSync($document->id);
        if ($document->refresh()->cover_path === null) {
            $this->report->cover('failed');
            $this->report->coverFailure($this->current, 'génération de la couverture impossible');
        } else {
            $this->report->cover('generated');
        }
    }

    private static function fileExtension(LocatedFile $file): string
    {
        $extension = strtolower(pathinfo($file->relative, PATHINFO_EXTENSION));

        return preg_match(self::FILE_EXTENSION, $extension) === 1 ? $extension : 'pdf';
    }

    /** @param  array<string, mixed>  $options */
    private function upload(string $disk, string $key, string $localPath, array $options): void
    {
        $stream = fopen($localPath, 'rb');
        if ($stream === false) {
            throw new RuntimeException("Lecture impossible : {$localPath}");
        }
        // Inscrit avant l'envoi : un arrêt brutal entre l'envoi et la validation laisse une trace.
        $this->journal?->sent($disk, $key, $this->current->source, $this->current->id);
        $this->uploaded[] = [$disk, $key];

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
    }

    private function discardUploads(LegacyItem $item): void
    {
        foreach ($this->uploaded as [$disk, $key]) {
            if (Storage::disk($disk)->delete($key)) {
                $this->journal?->deleted($disk, $key, $item->source, $item->id);
            } else {
                // Reste « envoi » au journal : signalé comme orphelin à la prochaine relance.
                $this->report->cleanupFailure($disk, $key);
            }
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
            $this->report->coverFailure($item, 'image de couverture introuvable');

            return null;
        }

        $info = @getimagesize($located->path);
        $extension = $info === false ? null : (self::COVER_EXTENSIONS[$info[2]] ?? null);
        if ($extension === null) {
            $this->report->cover('image_invalid');
            $this->report->coverFailure($item, 'image de couverture illisible');

            return null;
        }

        return ['path' => $located->path, 'extension' => $extension];
    }

    /**
     * Document déjà importé : seuls les champs encore vides sont complétés (année, ISBN, pages,
     * ancienne URL, sous-titre, résumé) et les mots-clés manquants ajoutés. Titre, type, statut,
     * slug, accès, fichier et couverture ne sont jamais modifiés ; les vues ne baissent jamais :
     * une retouche faite depuis l'administration survit à une relance.
     */
    private function refresh(Document $document, LegacyItem $item, MappedCategory $mapped): void
    {
        if ($document->trashed()) {
            $this->report->skipped($item, 'supprimé dans la nouvelle bibliothèque');

            return;
        }

        $paths = $this->legacyPaths($item);
        $metadata = $this->metadata($item, $mapped, $paths);
        foreach (['year', 'isbn', 'pages_count', 'legacy_url'] as $field) {
            if (($document->{$field} === null || $document->{$field} === '') && $metadata[$field] !== null) {
                $document->{$field} = $metadata[$field];
            }
        }
        foreach (['subtitle' => $item->subtitle, 'abstract' => $item->abstract] as $key => $value) {
            if ($value !== null && (string) $document->getTranslation($key, 'fr', false) === '') {
                $document->setTranslation($key, 'fr', $value);
            }
        }
        $document->views_count = max((int) $document->views_count, $item->hits);
        $changed = $document->isDirty();

        if (! $this->options->dryRun) {
            if ($changed) {
                $document->save();
            }
            $this->addKeywords($document, $mapped);
            if ($item->authors !== [] && ! $document->authors()->exists()) {
                $document->authors()->createMany(array_map(
                    fn (string $name, int $i): array => ['author_name' => $name, 'order' => $i + 1],
                    $item->authors,
                    array_keys($item->authors),
                ));
            }
        }
        if ($document->file_checksum_md5 !== null) {
            $this->rememberChecksum(
                $document->file_checksum_md5, $document->id, $document->slug, false,
                $document->status === Document::STATUS_DRAFT, $this->isImported($document),
            );
        }

        $this->redirects->ensure($paths, '/documents/'.$document->slug);
        if (! $this->options->dryRun) {
            $document->syncSearchIndex();
        }
        $this->report->updated($item, $changed);
    }

    /** @param  array{id: ?int, slug: string, trashed: bool, draft: bool, imported: bool}  $target */
    private function merge(LegacyItem $item, MappedCategory $mapped, array $target, string $md5): void
    {
        if ($target['trashed']) {
            $this->report->skipped($item, "doublon (MD5) d'un document supprimé : {$target['slug']}");

            return;
        }

        $paths = $this->legacyPaths($item);
        $created = $this->redirects->ensure($paths, '/documents/'.$target['slug']);
        // Fusion nouvelle dans ce lancement : l'URL à query string de l'élément vient d'être créée.
        // Vues cumulées et brouillon publié une seule fois (une relance ne republie pas un document
        // remis en brouillon depuis l'administration).
        $marker = collect($paths)->first(fn (LegacyPath $p): bool => str_ends_with($p->form, '.requete'))?->path;
        $isNewMerge = $marker !== null && ($created[$marker] ?? false);
        // Publié dans Joomla sous une autre fiche : le brouillon créé par l'import est publié.
        $publish = $isNewMerge && $item->isPublished() && $target['draft'] && $target['imported'];

        if (! $this->options->dryRun && $target['id'] !== null) {
            if ($isNewMerge && $item->hits > 0) {
                Document::withTrashed()->whereKey($target['id'])->increment('views_count', $item->hits);
            }
            $document = Document::query()->find($target['id']);
            if ($document !== null) {
                $this->addKeywords($document, $mapped);
                if ($publish) {
                    $document->forceFill([
                        'status' => Document::STATUS_PUBLISHED,
                        'approved_by' => $this->systemUserId,
                        'approved_at' => Carbon::now(),
                        'published_at' => $item->publishedAt ?? $document->published_at,
                    ])->save();
                    $document->syncSearchIndex();
                }
            }
        }

        if ($publish) {
            $this->rememberChecksum($md5, $target['id'], $target['slug'], false, false, true);
            $this->report->mergedIntoDraft($item, $target['slug']);
        }
        $this->report->merged($item, $target['slug'], $md5);
    }

    /** @return array{id: ?int, slug: string, trashed: bool, draft: bool, imported: bool}|null */
    private function duplicateOf(string $md5): ?array
    {
        if (isset($this->checksums[$md5])) {
            return $this->checksums[$md5];
        }

        $document = Document::withTrashed()
            ->where('file_checksum_md5', $md5)
            ->orderBy('id')
            ->first(['id', 'slug', 'status', 'legacy_source', 'uploaded_by', 'deleted_at']);
        if ($document === null) {
            return null;
        }

        return $this->checksums[$md5] = [
            'id' => $document->id, 'slug' => $document->slug, 'trashed' => $document->trashed(),
            'draft' => $document->status === Document::STATUS_DRAFT, 'imported' => $this->isImported($document),
        ];
    }

    /** Créé par cet import (et non déposé à la main) : source Joomla et déposant « Import Joomla ». */
    private function isImported(Document $document): bool
    {
        return $document->legacy_source !== null
            && $this->systemUserId !== null
            && (int) $document->uploaded_by === $this->systemUserId;
    }

    private function rememberChecksum(string $md5, ?int $id, string $slug, bool $trashed, bool $draft, bool $imported): void
    {
        $this->checksums[$md5] = ['id' => $id, 'slug' => $slug, 'trashed' => $trashed, 'draft' => $draft, 'imported' => $imported];
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

    /** Mots-clés de la correspondance ajoutés s'ils manquent ; aucun mot-clé n'est jamais retiré. */
    private function addKeywords(Document $document, MappedCategory $mapped): void
    {
        $document->motsCles()->syncWithoutDetaching($this->keywordIdsFor($mapped));
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

    /** Pages de catégorie : `/collections/{type}`, filtrée sur le mot-clé le plus spécifique ; un échec n'arrête pas les autres. */
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
                $paths = $source === LegacyMapping::SOURCE_DOCMAN ? $this->planner->docmanCategory($id) : $this->planner->alexandriaCategory($id);
                $this->report->begin();
                $this->redirects->begin();
                try {
                    DB::transaction(fn () => $this->redirects->ensure($paths, $target));
                } catch (Throwable $e) {
                    $this->redirects->rollback();
                    $this->report->rollback();
                    $this->report->error($source, $id, 'Page de catégorie : '.$e->getMessage());
                }
            }
        }
    }
}
