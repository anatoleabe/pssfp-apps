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

    private const SLUG_PATTERN = '/^[a-z0-9]+(-[a-z0-9]+)*$/';

    private const KEYWORD_SLUG_MAX = 120;

    /** @var array<string, KeywordRef> */
    private array $thesaurus = [];

    /** @var array<string, array<string, array{type: ?string, access_level: ?string, keywords: list<string>}>> */
    private array $rules = [];

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

    private function accessLevel(mixed $value): string
    {
        $value = (string) $value;
        if (! array_key_exists($value, Document::ACCESS_LEVELS)) {
            throw new InvalidArgumentException("Niveau d'accès inconnu « {$value} ».");
        }

        return $value;
    }
}
