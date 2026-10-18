<?php

declare(strict_types=1);

namespace App\Services\Library\Import;

/**
 * Arborescences DOCman et Alexandria de l'export : chemin, publication et niveau d'accès
 * Joomla hérités le long de la chaîne des ancêtres (une catégorie dépubliée masque toute
 * sa descendance dans Joomla).
 */
final class LegacyCategories
{
    /** @var array<string, array<int, array<string, mixed>>> source → id → catégorie */
    private array $categories = [];

    /** @param  array<string, mixed>  $export */
    public function __construct(array $export)
    {
        foreach ((array) ($export['docman']['categories'] ?? []) as $category) {
            $this->categories[LegacyMapping::SOURCE_DOCMAN][(int) $category['id']] = [
                'path' => (string) ($category['path'] ?? ''),
                'parent_id' => $category['parent_id'] ?? null,
                'published' => (int) ($category['enabled'] ?? 1) === 1,
                'access' => (int) ($category['access'] ?? 1),
            ];
        }
        foreach ((array) ($export['alexandria']['categories'] ?? []) as $category) {
            $this->categories[LegacyMapping::SOURCE_ALEXANDRIA][(int) $category['id']] = [
                'path' => (string) ($category['path'] ?? ''),
                'parent_id' => $category['parent_id'] ?? null,
                'published' => (int) ($category['published'] ?? 1) === 1,
                'access' => (int) ($category['access'] ?? 1),
            ];
        }
    }

    /** @return list<int> */
    public function ids(string $source): array
    {
        return array_keys($this->categories[$source] ?? []);
    }

    public function exists(string $source, ?int $id): bool
    {
        return $id !== null && isset($this->categories[$source][$id]);
    }

    public function path(string $source, int $id): string
    {
        return (string) ($this->categories[$source][$id]['path'] ?? '');
    }

    /** Publiée elle-même et tous ses ancêtres. */
    public function isPublished(string $source, int $id): bool
    {
        foreach ($this->chain($source, $id) as $category) {
            if (! $category['published']) {
                return false;
            }
        }

        return true;
    }

    /** Niveau d'accès Joomla effectif : le premier strictement positif en remontant la chaîne. */
    public function access(string $source, int $id): int
    {
        foreach ($this->chain($source, $id) as $category) {
            if ($category['access'] > 0) {
                return $category['access'];
            }
        }

        return LegacyItem::JOOMLA_PUBLIC_ACCESS;
    }

    /** @return list<array<string, mixed>> la catégorie puis ses ancêtres (garde contre les cycles) */
    private function chain(string $source, int $id): array
    {
        $chain = [];
        $seen = [];
        for ($current = $id; $current !== null && isset($this->categories[$source][$current]) && ! isset($seen[$current]);) {
            $seen[$current] = true;
            $chain[] = $this->categories[$source][$current];
            $parent = $this->categories[$source][$current]['parent_id'];
            $current = $parent === null ? null : (int) $parent;
        }

        return $chain;
    }
}
