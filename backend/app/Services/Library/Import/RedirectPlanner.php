<?php

declare(strict_types=1);

namespace App\Services\Library\Import;

/**
 * Anciennes URL de la bibliothèque Joomla d'un document ou d'une catégorie (plan 3, task 5).
 * Fonctions pures, d'après l'export (catégories et menus publiés). Formes relevées sur
 * bibliotheque.pssfp.net le 2026-10-18 (curl, lecture seule) :
 *
 *  - DOCman : `/index.php/{menu}/{sous-catégories}/{id}-{slug}` et `…/file`, où {menu} est le menu
 *    d'une catégorie de la chaîne (la catégorie elle-même ou un ancêtre, suivi des slugs des
 *    sous-catégories) ; à défaut, menu DOCman général + chemin complet de la catégorie ;
 *  - Alexandria : `/index.php/{menu}/{id}-{alias}` (menu de la catégorie), à défaut
 *    `/index.php/{menu des catégories}/{catid}-{chemin}/{id}-{alias}` ; module « derniers livres » :
 *    `/index.php/component/abook/book/{catid}-{alias catégorie}/{id}-{alias}` (catégorie :
 *    `/index.php/component/abook/category/{id}-{alias}`) ; PDF direct
 *    `/livres/{fichier}` (lien `/images/../livres/…` des fiches) ;
 *  - toutes les formes SEF répondent aussi sans `/index.php` ; formes à query string
 *    `/index.php?option=com_docman&view=document&slug=…` et `…com_abook&view=book&id=…`.
 *
 * La forme `/index.php/e-books/{catégorie}/{alias}` (sans id) affiche la catégorie, pas le livre :
 * elle n'est pas générée. Chemins encodés comme un navigateur les envoie (WHATWG), ≤ 500 caractères.
 */
final class RedirectPlanner
{
    public const MAX_PATH_LENGTH = 500;

    private const NO_INDEX = '.sans-index';

    /** @var array<int, array{slug: string, parent_id: ?int, path: string}> */
    private array $docmanCategories = [];

    /** @var array<string, list<string>> slug de catégorie → chemins de menu */
    private array $docmanMenus = [];

    /** @var list<string> menus DOCman sans catégorie (liste générale) */
    private array $docmanRootMenus = [];

    /** @var array<int, array{alias: string, path: string, root: bool}> */
    private array $alexandriaCategories = [];

    /** @var array<int, list<string>> id de catégorie → chemins de menu */
    private array $alexandriaMenus = [];

    /** @var list<string> menus Alexandria « catégories » (repli des catégories sans menu) */
    private array $alexandriaRootMenus = [];

    private int $discarded = 0;

    /** @param  array<string, mixed>  $export  sortie de biblio:extract-joomla */
    public function __construct(array $export)
    {
        foreach ((array) ($export['docman']['categories'] ?? []) as $category) {
            $this->docmanCategories[(int) $category['id']] = [
                'slug' => (string) ($category['slug'] ?? ''),
                'parent_id' => isset($category['parent_id']) ? (int) $category['parent_id'] : null,
                'path' => (string) ($category['path'] ?? ''),
            ];
        }
        foreach ((array) ($export['alexandria']['categories'] ?? []) as $category) {
            $path = (string) ($category['path'] ?? '');
            $this->alexandriaCategories[(int) $category['id']] = [
                'alias' => (string) ($category['alias'] ?? ''),
                'path' => $path,
                'root' => $path === '',
            ];
        }
        foreach ((array) ($export['menus'] ?? []) as $menu) {
            $this->addMenu((array) $menu);
        }
    }

    /**
     * @param  array<string, mixed>  $document  document DOCman de l'export
     * @return list<LegacyPath>
     */
    public function docmanDocument(array $document): array
    {
        $leaf = $document['id'].'-'.$document['slug'];
        $paths = [];
        foreach ($this->docmanRoutes((int) ($document['category_id'] ?? 0)) as $route) {
            array_push($paths, ...$this->sef($route.'/'.$leaf, 'docman.fiche'));
            array_push($paths, ...$this->sef($route.'/'.$leaf.'/file', 'docman.fichier'));
        }
        $paths[] = $this->query('option=com_docman&view=document&slug='.self::encodeQuery((string) $document['slug']), 'docman.requete');

        return $this->keep($paths);
    }

    /** @return list<LegacyPath> */
    public function docmanCategory(int $categoryId): array
    {
        $paths = [];
        foreach ($this->docmanRoutes($categoryId) as $route) {
            array_push($paths, ...$this->sef($route, 'docman.categorie'));
        }

        return $this->keep($paths);
    }

    /**
     * @param  array<string, mixed>  $book  livre Alexandria de l'export
     * @return list<LegacyPath>
     */
    public function alexandriaBook(array $book): array
    {
        $categoryId = (int) ($book['catid'] ?? 0);
        $category = $this->alexandriaCategories[$categoryId] ?? null;
        $leaf = $book['id'].'-'.$book['alias'];
        $paths = [];

        foreach ($this->alexandriaRoutes($categoryId) as $route) {
            array_push($paths, ...$this->sef($route.'/'.$leaf, 'alexandria.fiche'));
        }
        if ($category !== null && ! $category['root'] && $category['alias'] !== '') {
            array_push($paths, ...$this->sef("component/abook/book/{$categoryId}-{$category['alias']}/{$leaf}", 'alexandria.composant'));
        }

        $file = (string) ($book['file'] ?? '');
        if ($file !== '') {
            if ($this->isSafeFileName($file)) {
                $paths[] = new LegacyPath('/livres/'.self::encodePath($file), 'alexandria.pdf');
            } else {
                $this->discarded++;
            }
        }
        $paths[] = $this->query('option=com_abook&view=book&id='.(int) $book['id'], 'alexandria.requete');

        return $this->keep($paths);
    }

    /** @return list<LegacyPath> */
    public function alexandriaCategory(int $categoryId): array
    {
        $paths = [];
        foreach ($this->alexandriaRoutes($categoryId) as $route) {
            array_push($paths, ...$this->sef($route, 'alexandria.categorie'));
        }
        $category = $this->alexandriaCategories[$categoryId] ?? null;
        if ($category !== null && ! $category['root'] && $category['alias'] !== '') {
            array_push($paths, ...$this->sef("component/abook/category/{$categoryId}-{$category['alias']}", 'alexandria.categorie.composant'));
        }

        return $this->keep($paths);
    }

    /** Chemins écartés depuis la création (trop longs, noms de fichier piégés). */
    public function discardedCount(): int
    {
        return $this->discarded;
    }

    /** @param  array<string, mixed>  $menu */
    private function addMenu(array $menu): void
    {
        $path = trim((string) ($menu['path'] ?? ''), '/');
        $key = $menu['category_key'] ?? null;
        if ($path === '') {
            return;
        }

        if (($menu['component'] ?? null) === 'com_docman' && ($menu['view'] ?? null) === 'list') {
            if (is_string($key) && $key !== '') {
                $this->docmanMenus[$key][] = $path;
            } elseif ($key === null) {
                $this->docmanRootMenus[] = $path;
            }

            return;
        }

        if (($menu['component'] ?? null) === 'com_abook' && is_int($key)) {
            $this->alexandriaMenus[$key][] = $path;
            if (($menu['view'] ?? null) === 'categories') {
                $this->alexandriaRootMenus[] = $path;
            }
        }
    }

    /**
     * Chemins (sans `/index.php`) sous lesquels l'ancien site servait la catégorie : menus des
     * catégories de la chaîne, complétés des slugs des sous-catégories ; à défaut, menus généraux.
     *
     * @return list<string>
     */
    private function docmanRoutes(int $categoryId): array
    {
        if (! isset($this->docmanCategories[$categoryId])) {
            return [];
        }

        $routes = [];
        $below = [];
        $seen = [];
        for ($id = $categoryId; $id !== null && isset($this->docmanCategories[$id]) && ! isset($seen[$id]); $id = $this->docmanCategories[$id]['parent_id']) {
            $seen[$id] = true;
            $slug = $this->docmanCategories[$id]['slug'];
            foreach ($this->docmanMenus[$slug] ?? [] as $menu) {
                $routes[] = implode('/', [$menu, ...array_reverse($below)]);
            }
            $below[] = $slug;
        }

        if ($routes === []) {
            $path = $this->docmanCategories[$categoryId]['path'];
            foreach ($this->docmanRootMenus as $menu) {
                $routes[] = $menu.'/'.$path;
            }
        }

        return array_values(array_unique($routes));
    }

    /** @return list<string> */
    private function alexandriaRoutes(int $categoryId): array
    {
        if (isset($this->alexandriaMenus[$categoryId])) {
            return array_values(array_unique($this->alexandriaMenus[$categoryId]));
        }

        $category = $this->alexandriaCategories[$categoryId] ?? null;
        if ($category === null || $category['root']) {
            return [];
        }

        return array_values(array_unique(array_map(
            fn (string $menu): string => "{$menu}/{$categoryId}-{$category['path']}",
            $this->alexandriaRootMenus,
        )));
    }

    /** @return list<LegacyPath> forme `/index.php/…` puis la même sans `/index.php` */
    private function sef(string $route, string $form): array
    {
        $encoded = self::encodePath($route);

        return [new LegacyPath('/index.php/'.$encoded, $form), new LegacyPath('/'.$encoded, $form.self::NO_INDEX)];
    }

    private function query(string $query, string $form): LegacyPath
    {
        return new LegacyPath('/index.php?'.$query, $form);
    }

    /**
     * @param  list<LegacyPath>  $paths
     * @return list<LegacyPath> sans doublon ni chemin trop long
     */
    private function keep(array $paths): array
    {
        $kept = [];
        foreach ($paths as $path) {
            if (strlen($path->path) > self::MAX_PATH_LENGTH) {
                $this->discarded++;

                continue;
            }
            $kept[$path->path] ??= $path;
        }

        return array_values($kept);
    }

    private function isSafeFileName(string $file): bool
    {
        return ! str_contains($file, "\0") && ! str_contains($file, '\\')
            && ! in_array('..', explode('/', $file), true) && ! str_starts_with($file, '/');
    }

    /**
     * Encodage du chemin par un navigateur (« path percent-encode set » WHATWG) : contrôles,
     * espace, `"`, `#`, `<`, `>`, `?`, `` ` ``, `{`, `}` et tout octet non ASCII ; `/` séparateur.
     */
    private static function encodePath(string $path): string
    {
        return (string) preg_replace_callback(
            '/[\x00-\x20"#<>?`{}\x7F-\xFF]/',
            fn (array $m): string => '%'.strtoupper(bin2hex($m[0])),
            $path,
        );
    }

    /** « Query percent-encode set » WHATWG (schéma spécial) : contrôles, espace, `"#<>'`, non ASCII. */
    private static function encodeQuery(string $value): string
    {
        return (string) preg_replace_callback(
            '/[\x00-\x20"#<>\'&=\x7F-\xFF]/',
            fn (array $m): string => '%'.strtoupper(bin2hex($m[0])),
            $value,
        );
    }
}
