<?php

declare(strict_types=1);

namespace App\Services\Library\Import;

/**
 * Transforme le dump de l'ancienne bibliothèque Joomla (DOCman + Alexandria + menus)
 * en structure prête pour l'import (plan 3, task 2). Aucune règle métier ici :
 * les valeurs sont reprises telles quelles, seules les dates nulles MySQL
 * (« 0000-00-00 … ») deviennent null et les hiérarchies sont résolues.
 */
final class JoomlaLibraryExtractor
{
    private const TABLES = [
        'docman_documents', 'docman_categories', 'docman_category_relations',
        'abbook', 'abcategories', 'abauthor', 'abbookauth', 'menu',
    ];

    private const LIBRARY_COMPONENTS = ['com_docman', 'com_abook'];

    /**
     * @return array{
     *     docman: array{categories: list<array<string, mixed>>, documents: list<array<string, mixed>>},
     *     alexandria: array{categories: list<array<string, mixed>>, books: list<array<string, mixed>>},
     *     menus: list<array<string, mixed>>
     * }
     */
    public function extract(string $dumpPath, string $prefix): array
    {
        $tables = [];
        foreach (self::TABLES as $name) {
            $tables[$prefix.$name] = $name;
        }

        $raw = array_fill_keys(self::TABLES, []);
        foreach ((new MysqlDumpReader($dumpPath))->rows(array_keys($tables)) as $table => $row) {
            $raw[$tables[$table]][] = $row;
        }

        return [
            'docman' => [
                'categories' => $this->docmanCategories($raw['docman_categories'], $raw['docman_category_relations']),
                'documents' => $this->sortById(array_map($this->docmanDocument(...), $raw['docman_documents'])),
            ],
            'alexandria' => [
                'categories' => $this->sortById(array_map($this->alexandriaCategory(...), $raw['abcategories'])),
                'books' => $this->books($raw['abbook'], $raw['abauthor'], $raw['abbookauth']),
            ],
            'menus' => $this->menus($raw['menu']),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $categories
     * @param  list<array<string, mixed>>  $relations
     * @return list<array<string, mixed>>
     */
    private function docmanCategories(array $categories, array $relations): array
    {
        $parents = [];
        foreach ($relations as $relation) {
            if ((int) ($relation['level'] ?? 0) === 1) {
                $parents[(int) $relation['descendant_id']] = (int) $relation['ancestor_id'];
            }
        }

        $slugs = [];
        foreach ($categories as $category) {
            $slugs[(int) $category['docman_category_id']] = (string) $category['slug'];
        }

        $result = array_map(function (array $category) use ($parents, $slugs): array {
            $id = (int) $category['docman_category_id'];

            return [
                'id' => $id,
                'parent_id' => $parents[$id] ?? null,
                'slug' => $category['slug'],
                'title' => $category['title'],
                'path' => $this->slugPath($id, $parents, $slugs),
                'enabled' => $category['enabled'] ?? null,
                'access' => $category['access'] ?? null,
            ];
        }, $categories);

        return $this->sortById($result);
    }

    /**
     * Chemin racine → catégorie fait des slugs (garde contre les cycles).
     *
     * @param  array<int, int>  $parents
     * @param  array<int, string>  $slugs
     */
    private function slugPath(int $id, array $parents, array $slugs): string
    {
        $chain = [];
        $seen = [];
        for ($current = $id; $current !== null && ! isset($seen[$current]); $current = $parents[$current] ?? null) {
            $seen[$current] = true;
            if (isset($slugs[$current])) {
                $chain[] = $slugs[$current];
            }
        }

        return implode('/', array_reverse($chain));
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function docmanDocument(array $row): array
    {
        return [
            'id' => $row['docman_document_id'],
            'category_id' => $row['docman_category_id'] ?? null,
            'title' => $row['title'] ?? null,
            'slug' => $row['slug'] ?? null,
            'description' => $row['description'] ?? null,
            'storage_type' => $row['storage_type'] ?? null,
            'storage_path' => $row['storage_path'] ?? null,
            'hits' => $row['hits'] ?? null,
            'enabled' => $row['enabled'] ?? null,
            'access' => $row['access'] ?? null,
            'publish_on' => $this->date($row['publish_on'] ?? null),
            'created_on' => $this->date($row['created_on'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function alexandriaCategory(array $row): array
    {
        $parent = $row['parent_id'] ?? null;

        return [
            'id' => $row['id'],
            'parent_id' => $parent === 0 ? null : $parent,
            'alias' => $row['alias'] ?? null,
            'title' => $row['title'] ?? null,
            'path' => $row['path'] ?? null,
            'published' => $row['published'] ?? null,
            'access' => $row['access'] ?? null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $books
     * @param  list<array<string, mixed>>  $authors
     * @param  list<array<string, mixed>>  $links
     * @return list<array<string, mixed>>
     */
    private function books(array $books, array $authors, array $links): array
    {
        $authorsById = [];
        foreach ($authors as $author) {
            $authorsById[(int) $author['id']] = [
                'id' => $author['id'],
                'lastname' => $author['lastname'] ?? null,
                'name' => $author['name'] ?? null,
            ];
        }

        usort($links, fn (array $a, array $b): int => (int) $a['id'] <=> (int) $b['id']);
        $authorsByBook = [];
        foreach ($links as $link) {
            $author = $authorsById[(int) $link['idauth']] ?? null;
            if ($author !== null) {
                $authorsByBook[(int) $link['idbook']][] = $author;
            }
        }

        $result = array_map(fn (array $row): array => [
            'id' => $row['id'],
            'catid' => $row['catid'] ?? null,
            'title' => $row['title'] ?? null,
            'subtitle' => $row['subtitle'] ?? null,
            'alias' => $row['alias'] ?? null,
            'description' => $row['description'] ?? null,
            'file' => $row['file'] ?? null,
            'image' => $row['image'] ?? null,
            'year' => $row['year'] ?? null,
            'hits' => $row['hits'] ?? null,
            'state' => $row['state'] ?? null,
            'approved' => $row['approved'] ?? null,
            'access' => $row['access'] ?? null,
            'url' => $row['url'] ?? null,
            'isbn' => $row['isbn'] ?? null,
            'pag' => $row['pag'] ?? null,
            'dateinsert' => $this->date($row['dateinsert'] ?? null),
            'authors' => $authorsByBook[(int) $row['id']] ?? [],
        ], $books);

        return $this->sortById($result);
    }

    /**
     * Menus publiés du site (pas de l'administration) pointant vers DOCman ou Alexandria.
     *
     * @param  list<array<string, mixed>>  $menus
     * @return list<array<string, mixed>>
     */
    private function menus(array $menus): array
    {
        $result = [];
        foreach ($menus as $menu) {
            if ((int) ($menu['published'] ?? 0) !== 1 || (int) ($menu['client_id'] ?? 0) !== 0) {
                continue;
            }
            parse_str((string) parse_url((string) ($menu['link'] ?? ''), PHP_URL_QUERY), $query);
            $component = $query['option'] ?? null;
            if (! in_array($component, self::LIBRARY_COMPONENTS, true)) {
                continue;
            }
            $view = is_string($query['view'] ?? null) ? $query['view'] : null;

            $result[] = [
                'path' => $menu['path'],
                'component' => $component,
                'view' => $view,
                'category_key' => $this->categoryKey($component, $view, $query),
            ];
        }

        usort($result, fn (array $a, array $b): int => strcmp((string) $a['path'], (string) $b['path']));

        return $result;
    }

    /**
     * Slug de catégorie DOCman (vue liste) ou identifiant de catégorie Alexandria.
     *
     * @param  array<mixed>  $query
     */
    private function categoryKey(string $component, ?string $view, array $query): int|string|null
    {
        if ($component === 'com_docman') {
            $slug = $query['slug'] ?? null;

            return $view === 'list' && is_string($slug) && $slug !== '' ? $slug : null;
        }

        $id = $query['id'] ?? null;

        return in_array($view, ['category', 'categories'], true) && is_string($id) && ctype_digit($id) ? (int) $id : null;
    }

    private function date(mixed $value): mixed
    {
        return is_string($value) && str_starts_with($value, '0000-00-00') ? null : $value;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function sortById(array $rows): array
    {
        usort($rows, fn (array $a, array $b): int => (int) $a['id'] <=> (int) $b['id']);

        return $rows;
    }
}
