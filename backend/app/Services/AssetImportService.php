<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Asset;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Image\Image;
use SplFileInfo;
use Symfony\Component\Finder\Finder;

/**
 * AssetImportService — scanne assets-source/ et importe vers MinIO + table assets.
 *
 * Cf. spec sprint S5 PR V.
 *
 * Catégorisation :
 *   - logos/...        : logo (subcategory selon dossier)
 *   - photos/{cat}/... : photo + subcategory mappée
 *   - conventions/...  : document/convention
 *   - Catalogues/*.pdf : document/catalogue
 *   - textes/Appel*    : document/appel-candidature ou photo/evenements
 *
 * Génération variantes images : thumb 400px / medium 1200px / full 1920px (WebP qualité 85).
 * Originaux : conservés bruts pour les SVG, convertis en WebP pour JPG/PNG.
 */
class AssetImportService
{
    public const VARIANT_THUMB = 'thumb';

    public const VARIANT_MEDIUM = 'medium';

    public const VARIANT_FULL = 'full';

    public const VARIANT_SIZES = [
        self::VARIANT_THUMB => 400,
        self::VARIANT_MEDIUM => 1200,
        self::VARIANT_FULL => 1920,
    ];

    public const MEDIA_DISK = 'minio_media';

    public const DOCUMENTS_DISK = 'minio_documents';

    /** @var array{imported: int, skipped: int, errors: int, files: array<int, array<string, mixed>>} */
    private array $report = [
        'imported' => 0,
        'skipped' => 0,
        'errors' => 0,
        'files' => [],
    ];

    private readonly string $sourceDir;

    public function __construct(string $sourceDir)
    {
        $resolved = realpath($sourceDir);
        $this->sourceDir = $resolved !== false ? $resolved : rtrim($sourceDir, '/');
    }

    /**
     * @return array{imported: int, skipped: int, errors: int, files: array<int, array<string, mixed>>}
     */
    public function run(?\Closure $progress = null): array
    {
        $files = $this->collectFiles();

        foreach ($files as $file) {
            try {
                $mapping = $this->mapPath($file->getRealPath());

                if ($mapping === null) {
                    $this->report['skipped']++;

                    continue;
                }

                $existing = Asset::where('disk', $mapping['disk'])
                    ->where('path', $mapping['path'])
                    ->first();

                if ($existing !== null) {
                    $this->report['skipped']++;
                    if ($progress !== null) {
                        $progress($file->getFilename(), 'skipped');
                    }

                    continue;
                }

                $asset = $this->importFile($file, $mapping);
                $this->report['imported']++;
                $this->report['files'][] = [
                    'path' => $asset->path,
                    'category' => $asset->category,
                    'subcategory' => $asset->subcategory,
                    'size' => $asset->size,
                    'tags' => $asset->tags,
                ];

                if ($progress !== null) {
                    $progress($file->getFilename(), 'imported');
                }
            } catch (\Throwable $e) {
                $this->report['errors']++;
                Log::error('AssetImportService: failed to import file', [
                    'file' => $file->getRealPath(),
                    'error' => $e->getMessage(),
                ]);
                if ($progress !== null) {
                    $progress($file->getFilename(), 'error');
                }
            }
        }

        return $this->report;
    }

    /**
     * @return iterable<SplFileInfo>
     */
    private function collectFiles(): iterable
    {
        return (new Finder)
            ->files()
            ->in($this->sourceDir)
            ->name('/\.(svg|png|jpg|jpeg|webp|pdf)$/i')
            ->ignoreDotFiles(true)
            ->notName('.DS_Store')
            ->notPath('BD bibliotheque PSSFP')
            ->sortByName();
    }

    /**
     * Mappe un chemin source vers (category, subcategory, tags, disk, path).
     *
     * @return array{category: string, subcategory: ?string, tags: array<int, string>, disk: string, path: string}|null
     */
    private function mapPath(string $sourcePath): ?array
    {
        $relative = ltrim(str_replace($this->sourceDir, '', $sourcePath), '/');
        $segments = explode('/', $relative);
        $extension = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));

        $root = $segments[0] ?? '';
        $sub = $segments[1] ?? null;
        $sub2 = $segments[2] ?? null;
        $filename = basename($sourcePath);
        $slugFilename = $this->slugifyFilename($filename);

        // logos/*
        if ($root === 'logos') {
            if ($sub === null || $sub === $filename) {
                return [
                    'category' => Asset::CATEGORY_LOGO,
                    'subcategory' => 'pssfp',
                    'tags' => ['pssfp'],
                    'disk' => self::MEDIA_DISK,
                    'path' => 'logos/'.$slugFilename,
                ];
            }
            if ($sub === 'partenaires') {
                $tag = $this->extractPartnerTag($filename);

                return [
                    'category' => Asset::CATEGORY_LOGO,
                    'subcategory' => 'partenaire',
                    'tags' => $tag !== null ? [$tag] : [],
                    'disk' => self::MEDIA_DISK,
                    'path' => 'logos/partenaires/'.$slugFilename,
                ];
            }
            if ($sub === 'institutions-coop') {
                $tag = $this->extractPartnerTag($filename);

                return [
                    'category' => Asset::CATEGORY_LOGO,
                    'subcategory' => 'institution-coop',
                    'tags' => $tag !== null ? [$tag] : [],
                    'disk' => self::MEDIA_DISK,
                    'path' => 'logos/institutions-coop/'.$slugFilename,
                ];
            }
        }

        // photos/{subcategory}/...
        if ($root === 'photos' && $sub !== null) {
            $mapped = $this->mapPhotoSubcategory($sub, $sub2);
            $subcategory = $mapped['subcategory'];
            $tags = $mapped['tags'];

            return [
                'category' => Asset::CATEGORY_PHOTO,
                'subcategory' => $subcategory,
                'tags' => $tags,
                'disk' => self::MEDIA_DISK,
                'path' => 'photos/'.$subcategory.'/'.$slugFilename,
            ];
        }

        // conventions/*
        if ($root === 'conventions' && $extension === 'pdf') {
            return [
                'category' => Asset::CATEGORY_DOCUMENT,
                'subcategory' => 'convention',
                'tags' => ['convention'],
                'disk' => self::DOCUMENTS_DISK,
                'path' => 'conventions/'.$slugFilename,
            ];
        }

        // Catalogues/*.pdf
        if ($root === 'Catalogues' && $extension === 'pdf') {
            return [
                'category' => Asset::CATEGORY_DOCUMENT,
                'subcategory' => 'catalogue',
                'tags' => ['catalogue', 'formation-continue'],
                'disk' => self::DOCUMENTS_DISK,
                'path' => 'documents/catalogue-pssfp-2026.pdf',
            ];
        }

        // textes/Appel*/* — visuels banderoles ou PDFs
        if ($root === 'textes' && $sub !== null && Str::startsWith(strtolower($sub), 'appel')) {
            $promo = Str::contains($sub, '12') ? 'promo-12' : (Str::contains($sub, '13') ? 'promo-13' : null);
            $tags = ['appel-candidature'];
            if ($promo !== null) {
                $tags[] = $promo;
            }

            $isImage = in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'svg'], true);
            $disk = $isImage ? self::MEDIA_DISK : self::DOCUMENTS_DISK;
            $folder = $isImage ? 'photos/evenements' : 'documents/appels-candidature';

            return [
                'category' => $isImage ? Asset::CATEGORY_PHOTO : Asset::CATEGORY_DOCUMENT,
                'subcategory' => $isImage ? 'evenements' : 'appel-candidature',
                'tags' => $tags,
                'disk' => $disk,
                'path' => $folder.'/'.$slugFilename,
            ];
        }

        return null;
    }

    /**
     * @return array{subcategory: string, tags: array<int, string>}
     */
    private function mapPhotoSubcategory(string $rawSub, ?string $sub2): array
    {
        $normalized = Str::lower($rawSub);
        $tags = [];

        $map = [
            'campus' => 'campus',
            'amphis' => 'amphis',
            'amphi' => 'amphis',
            'salles' => 'salles',
            'bibliotheque' => 'bibliotheque',
            'cours' => 'cours',
            'direction' => 'direction',
            'enseignants' => 'enseignants',
            'promotions' => 'promotions',
            'soutenances' => 'soutenances',
            'seminaires' => 'seminaires',
            'voyages' => 'voyages',
            'autres' => 'autres',
            'compositions' => 'autres',
            'necrologies' => 'autres',
        ];

        if (isset($map[$normalized])) {
            $subcategory = $map[$normalized];
            if ($sub2 !== null && preg_match('/^P(\d+)/i', $sub2, $m) === 1) {
                $tags[] = 'promo-'.$m[1];
            }
            // Cas particulier seminaires/Centre Pasteur
            if ($subcategory === 'seminaires' && $sub2 !== null && Str::contains($sub2, 'Pasteur')) {
                $tags[] = 'centre-pasteur';
                $tags[] = 'formation-continue';
            }

            return ['subcategory' => $subcategory, 'tags' => $tags];
        }

        // Évènements (sortie solennelle, marches sportives, amicale, parrainage, 8 mars, réunions)
        if (Str::contains($normalized, ['sortie solennelle', 'remise des diplomes'])) {
            $tags = ['sortie-solennelle'];
            if ($sub2 !== null) {
                if (preg_match('/Promotion (\d+)/i', $sub2, $m) === 1) {
                    $tags[] = 'promo-'.$m[1];
                } elseif (preg_match_all('/(\d+)/', $sub2, $matches) > 0) {
                    foreach ($matches[1] as $promo) {
                        $tags[] = 'promo-'.$promo;
                    }
                }
            }

            return ['subcategory' => 'evenements', 'tags' => $tags];
        }
        if (Str::contains($normalized, 'marches sportives')) {
            $tags = ['marche-sportive'];
            if ($sub2 !== null && preg_match('/^P(\d+)/i', $sub2, $m) === 1) {
                $tags[] = 'promo-'.$m[1];
            }

            return ['subcategory' => 'evenements', 'tags' => $tags];
        }
        if (Str::contains($normalized, 'amicale')) {
            return ['subcategory' => 'evenements', 'tags' => ['amicale']];
        }
        if (Str::contains($normalized, 'parrainage')) {
            $tags = ['parrainage'];
            if (preg_match('/promotion (\d+)/i', $rawSub, $m) === 1) {
                $tags[] = 'promo-'.$m[1];
            }

            return ['subcategory' => 'evenements', 'tags' => $tags];
        }
        if (Str::contains($normalized, ['reunion', 'réunion'])) {
            return ['subcategory' => 'evenements', 'tags' => ['reunion-coordination']];
        }
        if (Str::contains($normalized, '8 mars')) {
            return ['subcategory' => 'evenements', 'tags' => ['journee-femme', '8-mars']];
        }

        return ['subcategory' => 'autres', 'tags' => [Str::slug($rawSub)]];
    }

    private function extractPartnerTag(string $filename): ?string
    {
        $lower = Str::lower($filename);
        $patterns = [
            'minfi' => 'minfi',
            'minesup' => 'minesup',
            'uy2' => 'uy2',
            'expertise-france' => 'expertise-france',
            'expertise france' => 'expertise-france',
            'fmi' => 'fmi',
            'mef-maroc' => 'institut-finances-maroc',
            'maroc' => 'institut-finances-maroc',
            'assemble' => 'assemblee-nationale',
            'assemblee' => 'assemblee-nationale',
        ];

        foreach ($patterns as $pattern => $tag) {
            if (Str::contains($lower, $pattern)) {
                return $tag;
            }
        }

        return null;
    }

    /**
     * @param  array{category: string, subcategory: ?string, tags: array<int, string>, disk: string, path: string}  $mapping
     */
    private function importFile(SplFileInfo $file, array $mapping): Asset
    {
        $extension = strtolower($file->getExtension());
        $mime = $this->detectMime($file->getRealPath(), $extension);
        $size = $file->getSize();
        $filename = basename($mapping['path']);
        $variants = [];
        $width = null;
        $height = null;

        if ($mapping['category'] === Asset::CATEGORY_PHOTO && in_array($extension, ['jpg', 'jpeg', 'png'], true)) {
            // Photos JPG/PNG : convert to WebP variants + keep WebP "full" as canonical path
            $webpPath = preg_replace('/\.(jpg|jpeg|png)$/i', '.webp', $mapping['path']);
            $mapping['path'] = $webpPath;
            $filename = basename($webpPath);
            $mime = 'image/webp';
            $variants = $this->generateImageVariants($file->getRealPath(), $webpPath, $mapping['disk']);
            // Set width/height from full variant
            if (isset($variants['_dimensions'])) {
                $width = $variants['_dimensions']['width'];
                $height = $variants['_dimensions']['height'];
                unset($variants['_dimensions']);
            }
        } else {
            // Originals : SVG, PDF, WebP — uploaded as-is
            $stream = fopen($file->getRealPath(), 'rb');
            Storage::disk($mapping['disk'])->put($mapping['path'], $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }

            if (in_array($extension, ['png', 'webp'], true) && $mapping['category'] !== Asset::CATEGORY_PHOTO) {
                // Logos PNG : try to read dimensions only
                $info = @getimagesize($file->getRealPath());
                if ($info !== false) {
                    $width = $info[0];
                    $height = $info[1];
                }
            }
        }

        return Asset::create([
            'filename' => $filename,
            'original_filename' => $file->getFilename(),
            'category' => $mapping['category'],
            'subcategory' => $mapping['subcategory'],
            'mime' => $mime,
            'size' => $size,
            'disk' => $mapping['disk'],
            'path' => $mapping['path'],
            'width' => $width,
            'height' => $height,
            'tags' => $mapping['tags'],
            'variants' => $variants,
        ]);
    }

    /**
     * Generate WebP variants thumb/medium/full and upload to disk.
     *
     * @return array<string, mixed>
     */
    private function generateImageVariants(string $sourcePath, string $targetPath, string $disk): array
    {
        $variants = [];
        $tmpDir = sys_get_temp_dir().'/pssfp-asset-import-'.uniqid('', true);
        @mkdir($tmpDir, 0755, true);

        try {
            $info = @getimagesize($sourcePath);
            $origWidth = $info[0] ?? null;
            $origHeight = $info[1] ?? null;

            foreach (self::VARIANT_SIZES as $variant => $width) {
                $variantFilename = preg_replace('/\.webp$/', '-'.$variant.'.webp', $targetPath);
                $tmpFile = $tmpDir.'/'.basename($variantFilename);

                // Don't upscale: skip variants larger than original
                if ($origWidth !== null && $origWidth < $width && $variant !== self::VARIANT_THUMB) {
                    if ($variant === self::VARIANT_FULL) {
                        // Full = original size when smaller than 1920
                        Image::load($sourcePath)
                            ->format('webp')
                            ->quality(85)
                            ->save($tmpFile);
                    } else {
                        continue;
                    }
                } else {
                    Image::load($sourcePath)
                        ->width($width)
                        ->format('webp')
                        ->quality(85)
                        ->save($tmpFile);
                }

                $stream = fopen($tmpFile, 'rb');
                Storage::disk($disk)->put($variantFilename, $stream);
                if (is_resource($stream)) {
                    fclose($stream);
                }

                $variants[$variant] = $variantFilename;
            }

            // The "canonical" full path = full variant
            if (isset($variants[self::VARIANT_FULL])) {
                $stream = fopen($tmpDir.'/'.basename($variants[self::VARIANT_FULL]), 'rb');
                Storage::disk($disk)->put($targetPath, $stream);
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            $variants['_dimensions'] = ['width' => $origWidth, 'height' => $origHeight];
        } finally {
            // Cleanup tmp dir
            if (is_dir($tmpDir)) {
                $files = glob($tmpDir.'/*') ?: [];
                foreach ($files as $f) {
                    @unlink($f);
                }
                @rmdir($tmpDir);
            }
        }

        return $variants;
    }

    private function detectMime(string $path, string $extension): string
    {
        return match ($extension) {
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'pdf' => 'application/pdf',
            default => mime_content_type($path) ?: 'application/octet-stream',
        };
    }

    private function slugifyFilename(string $filename): string
    {
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $name = pathinfo($filename, PATHINFO_FILENAME);
        // Remove parenthesized suffixes like (1), (2)
        $name = preg_replace('/\s*\(\d+\)/', '', $name);
        $name = preg_replace('/\s+removebg-preview\s*/i', '', $name);
        $slug = Str::slug($name, '-');

        return $slug.'.'.strtolower($extension);
    }
}
