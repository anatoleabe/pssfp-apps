<?php

declare(strict_types=1);

namespace App\Services\Library\Import;

use App\Models\Document;
use InvalidArgumentException;

/**
 * Catégories Joomla (DOCman, Alexandria) → type de document, accès et mots-clés,
 * d'après les règles déclaratives de config/biblio_migration.php (plan 3, task 3).
 * Fonctions pures : aucune écriture en base ; l'import (task 4) persiste le thésaurus.
 */
final class LegacyMapping
{
    public const SOURCE_DOCMAN = 'docman';

    public const SOURCE_ALEXANDRIA = 'alexandria';

    public const SOURCE_SERVER = 'serveur';

    private const SLUG_PATTERN = '/^[a-z0-9]+(-[a-z0-9]+)*$/';

    private const KEYWORD_SLUG_MAX = 120;

    /** @var array<string, KeywordRef> */
    private array $thesaurus = [];

    /** @var array<string, array<string, array{type: ?string, access_level: ?string, keywords: list<string>}>> */
    private array $rules = [];

    /** @var array<string, array{key: string, category: ?MappedCategory, exclude: ?string}> clé normalisée → règle de dossier */
    private array $serverFolders = [];

    /** @var array<string, array{key: string, reason: string}> chemin normalisé → fichier exclu */
    private array $serverExcludedFiles = [];

    private string $defaultAccessLevel;

    /** @param  array<string, mixed>|null  $config  par défaut config('biblio_migration') */
    public function __construct(?array $config = null)
    {
        $config ??= (array) config('biblio_migration');

        $this->defaultAccessLevel = $this->accessLevel($config['default_access_level'] ?? 'public');
        $this->loadThesaurus((array) ($config['thesaurus'] ?? []));
        foreach ([self::SOURCE_DOCMAN, self::SOURCE_ALEXANDRIA] as $source) {
            $this->rules[$source] = $this->loadRules($source, (array) ($config[$source] ?? []));
        }
        $this->loadServerFolders((array) ($config['server_folders'] ?? []));
        $this->loadServerExcludedFiles((array) ($config['server_excluded_files'] ?? []));
    }

    public function forDocmanPath(string $path): MappedCategory
    {
        return $this->map(self::SOURCE_DOCMAN, $path);
    }

    /** @param  string  $path  chemin d'alias depuis la racine « ROOT » exclue ('' = racine) */
    public function forAlexandriaCategory(string $path): MappedCategory
    {
        return $this->map(self::SOURCE_ALEXANDRIA, $path);
    }

    /**
     * Règle applicable à un fichier de l'ancien serveur (chemin relatif à la racine des fichiers) :
     * exclusion si le fichier est listé, sinon règle du dossier le plus spécifique (par segment).
     */
    public function forServerFile(string $relativePath): ServerFileRule
    {
        $segments = $this->serverSegments($relativePath);
        if ($segments === null) {
            return new ServerFileRule(null, 'chemin non canonique', '');
        }
        $path = implode('/', $segments);

        if (isset($this->serverExcludedFiles[$path])) {
            $excluded = $this->serverExcludedFiles[$path];

            return new ServerFileRule(null, $excluded['reason'], $excluded['key']);
        }

        array_pop($segments); // le nom du fichier n'est pas un dossier
        while ($segments !== []) {
            $rule = $this->serverFolders[implode('/', $segments)] ?? null;
            if ($rule !== null) {
                return new ServerFileRule($rule['category'], $rule['exclude'], $rule['key']);
            }
            array_pop($segments);
        }

        return new ServerFileRule(null, null, '');
    }

    /** @return list<KeywordRef> tout le thésaurus, chaque parent avant ses enfants */
    public function thesaurus(): array
    {
        return array_values($this->thesaurus);
    }

    private function map(string $source, string $path): MappedCategory
    {
        $path = $this->normalisePath($path);
        $type = null;
        $access = null;
        $keywords = [];

        foreach ($this->matchingRules($source, $path) as $rule) {
            $type = $rule['type'] ?? $type;
            $access = $rule['access_level'] ?? $access;
            foreach ($rule['keywords'] as $slug) {
                $keywords[$slug] = $this->thesaurus[$slug];
            }
        }

        if ($type === null) {
            throw UnmappedLegacyCategory::for($source, $path);
        }

        return new MappedCategory($type, $access ?? $this->defaultAccessLevel, array_values($keywords));
    }

    /**
     * Règles dont la clé est un préfixe (par segment) du chemin, de la plus générale à la plus
     * spécifique. La clé '' ne vaut que pour la racine elle-même.
     *
     * @return list<array{type: ?string, access_level: ?string, keywords: list<string>}>
     */
    private function matchingRules(string $source, string $path): array
    {
        if ($path === '') {
            return isset($this->rules[$source]['']) ? [$this->rules[$source]['']] : [];
        }

        $matches = [];
        $prefix = '';
        foreach (explode('/', $path) as $segment) {
            $prefix = $prefix === '' ? $segment : $prefix.'/'.$segment;
            if (isset($this->rules[$source][$prefix])) {
                $matches[] = $this->rules[$source][$prefix];
            }
        }

        return $matches;
    }

    private function normalisePath(string $path): string
    {
        return mb_strtolower(trim($path, " \t/"));
    }

    /** @param  array<array-key, mixed>  $definitions */
    private function loadThesaurus(array $definitions): void
    {
        foreach ($definitions as $slug => $definition) {
            $slug = (string) $slug;
            $label = trim((string) ($definition['label'] ?? ''));
            $parent = $definition['parent'] ?? null;

            if (preg_match(self::SLUG_PATTERN, $slug) !== 1 || strlen($slug) > self::KEYWORD_SLUG_MAX) {
                throw new InvalidArgumentException("Thésaurus : slug non conforme « {$slug} ».");
            }
            if ($label === '') {
                throw new InvalidArgumentException("Thésaurus : libellé vide pour « {$slug} ».");
            }
            if ($parent !== null && ! isset($this->thesaurus[$parent])) {
                throw new InvalidArgumentException("Thésaurus : le parent « {$parent} » de « {$slug} » doit être déclaré avant lui.");
            }

            $this->thesaurus[$slug] = new KeywordRef($slug, $label, $parent);
        }
    }

    /**
     * @param  array<array-key, mixed>  $definitions
     * @return array<string, array{type: ?string, access_level: ?string, keywords: list<string>}>
     */
    private function loadRules(string $source, array $definitions): array
    {
        $rules = [];
        foreach ($definitions as $path => $definition) {
            $path = $this->normalisePath((string) $path);
            $type = $definition['type'] ?? null;
            if ($type !== null && ! array_key_exists($type, Document::TYPES)) {
                throw new InvalidArgumentException("Règle {$source} « {$path} » : type inconnu « {$type} ».");
            }

            $keywords = array_values(array_map('strval', (array) ($definition['keywords'] ?? [])));
            foreach ($keywords as $slug) {
                if (! isset($this->thesaurus[$slug])) {
                    throw new InvalidArgumentException("Règle {$source} « {$path} » : mot-clé « {$slug} » absent du thésaurus.");
                }
            }

            $access = $definition['access_level'] ?? null;
            $rules[$path] = [
                'type' => $type,
                'access_level' => $access === null ? null : $this->accessLevel($access),
                'keywords' => $keywords,
            ];
        }

        return $rules;
    }

    /** @param  array<array-key, mixed>  $definitions */
    private function loadServerFolders(array $definitions): void
    {
        foreach ($definitions as $key => $definition) {
            $key = (string) $key;
            $definition = (array) $definition;
            $normalised = $this->configServerPath($key, 'Dossier serveur');
            if (isset($this->serverFolders[$normalised])) {
                throw new InvalidArgumentException("Dossier serveur « {$key} » : doublon de « {$this->serverFolders[$normalised]['key']} » après normalisation.");
            }

            $exclude = isset($definition['exclude']) ? trim((string) $definition['exclude']) : null;
            if ($exclude !== null && (isset($definition['source']) || isset($definition['category']))) {
                throw new InvalidArgumentException("Dossier serveur « {$key} » : « exclude » est incompatible avec « source »/« category ».");
            }
            if ($exclude !== null) {
                if ($exclude === '') {
                    throw new InvalidArgumentException("Dossier serveur « {$key} » : raison d'exclusion vide.");
                }
                $this->serverFolders[$normalised] = ['key' => $key, 'category' => null, 'exclude' => $exclude];

                continue;
            }

            $source = (string) ($definition['source'] ?? '');
            $category = (string) ($definition['category'] ?? '');
            if (! in_array($source, [self::SOURCE_DOCMAN, self::SOURCE_ALEXANDRIA], true)) {
                throw new InvalidArgumentException("Dossier serveur « {$key} » : source inconnue « {$source} » (ni exclusion, ni source/catégorie valides).");
            }

            try {
                $mapped = $this->map($source, $category);
            } catch (UnmappedLegacyCategory) {
                throw new InvalidArgumentException("Dossier serveur « {$key} » : catégorie {$source} « {$category} » absente des règles.");
            }
            if (! isset($this->rules[$source][$this->normalisePath($category)])) {
                throw new InvalidArgumentException("Dossier serveur « {$key} » : catégorie {$source} « {$category} » absente des règles.");
            }
            $this->serverFolders[$normalised] = ['key' => $key, 'category' => $mapped, 'exclude' => null];
        }
    }

    /** @param  array<array-key, mixed>  $definitions */
    private function loadServerExcludedFiles(array $definitions): void
    {
        foreach ($definitions as $path => $reason) {
            $path = (string) $path;
            $reason = trim((string) $reason);
            $normalised = $this->configServerPath($path, 'Fichier serveur exclu');
            if ($reason === '') {
                throw new InvalidArgumentException("Fichier serveur exclu « {$path} » : raison vide.");
            }
            if (isset($this->serverExcludedFiles[$normalised])) {
                throw new InvalidArgumentException("Fichier serveur exclu « {$path} » : doublon de « {$this->serverExcludedFiles[$normalised]['key']} » après normalisation.");
            }
            $this->serverExcludedFiles[$normalised] = ['key' => $path, 'reason' => $reason];
        }
    }

    private function configServerPath(string $path, string $label): string
    {
        $segments = $this->serverSegments($path);
        if ($segments === null || $segments === []) {
            throw new InvalidArgumentException("{$label} « {$path} » : chemin vide ou non canonique.");
        }

        return implode('/', $segments);
    }

    /**
     * UTF-8 (Windows-1252 sinon), NFC, minuscules, découpé sur « / » et « \ » sans segments vides ni « . ».
     *
     * @return list<string>|null null si le chemin remonte (« .. ») : il n'est pas canonique
     */
    private function serverSegments(string $path): ?array
    {
        if (! mb_check_encoding($path, 'UTF-8')) {
            $path = mb_convert_encoding($path, 'UTF-8', 'Windows-1252');
        }
        if (class_exists(\Normalizer::class)) {
            $path = (string) \Normalizer::normalize($path, \Normalizer::FORM_C);
        }

        $segments = [];
        foreach (preg_split('#[/\\\\]#u', mb_strtolower($path)) ?: [] as $segment) {
            $segment = trim($segment);
            if ($segment === '..') {
                return null;
            }
            if ($segment !== '' && $segment !== '.') {
                $segments[] = $segment;
            }
        }

        return $segments;
    }

    private function accessLevel(mixed $value): string
    {
        $value = (string) $value;
        if (! array_key_exists($value, Document::ACCESS_LEVELS)) {
            throw new InvalidArgumentException("Niveau d'accès inconnu « {$value} ».");
        }

        return $value;
    }
}
