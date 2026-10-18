<?php

declare(strict_types=1);

use App\Services\Library\Import\JoomlaLibraryExtractor;
use App\Services\Library\Import\LegacyPath;
use App\Services\Library\Import\RedirectPlanner;

uses()->group('module-3', 'library', 'biblio-import');

const PLANNER_MINI_DUMP = __DIR__.'/../../../Fixtures/joomla/mini-dump.sql';

/** @return array<string, mixed> export du mini-dump fictif */
function plannerExport(): array
{
    return (new JoomlaLibraryExtractor)->extract(PLANNER_MINI_DUMP, 'tst01_');
}

/** @return array<string, mixed> */
function plannerItem(array $items, int $id): array
{
    return collect($items)->firstWhere('id', $id);
}

/**
 * @param  list<LegacyPath>  $paths
 * @return array<string, string> chemin → forme
 */
function plannedForms(array $paths): array
{
    return collect($paths)->mapWithKeys(fn (LegacyPath $p): array => [$p->path => $p->form])->all();
}

it('génère les URL d\'une fiche DOCman sous le menu de sa catégorie', function (): void {
    $export = plannerExport();
    $planner = new RedirectPlanner($export);

    expect(plannedForms($planner->docmanDocument(plannerItem($export['docman']['documents'], 101))))->toBe([
        '/index.php/textes/arretes-fictifs/101-arrete-001' => 'docman.fiche',
        '/textes/arretes-fictifs/101-arrete-001' => 'docman.fiche.sans-index',
        '/index.php/textes/arretes-fictifs/101-arrete-001/file' => 'docman.fichier',
        '/textes/arretes-fictifs/101-arrete-001/file' => 'docman.fichier.sans-index',
        '/index.php?option=com_docman&view=document&slug=arrete-001' => 'docman.requete',
    ]);
});

it('complète le chemin du menu de l\'ancêtre par les slugs des sous-catégories', function (): void {
    $export = plannerExport();
    $paths = array_keys(plannedForms((new RedirectPlanner($export))->docmanDocument(plannerItem($export['docman']['documents'], 102))));

    expect($paths)->toContain('/index.php/textes/arretes-fictifs/arretes-exemple/102-circulaire-fictive')
        ->toContain('/textes/arretes-fictifs/arretes-exemple/102-circulaire-fictive/file');
});

it('se rabat sur le menu général DOCman quand aucun menu ne vise la catégorie ni ses ancêtres', function (): void {
    $export = plannerExport();
    $paths = array_keys(plannedForms((new RedirectPlanner($export))->docmanDocument(plannerItem($export['docman']['documents'], 103))));

    expect($paths)->toContain('/index.php/bibliotheque/textes-fictifs/103-note-double')
        ->toContain('/index.php?option=com_docman&view=document&slug=note-double');
});

it('génère les pages de catégorie DOCman', function (): void {
    $planner = new RedirectPlanner(plannerExport());

    expect(array_keys(plannedForms($planner->docmanCategory(2))))->toBe(['/index.php/textes/arretes-fictifs', '/textes/arretes-fictifs'])
        ->and(array_keys(plannedForms($planner->docmanCategory(3))))->toBe(['/index.php/textes/arretes-fictifs/arretes-exemple', '/textes/arretes-fictifs/arretes-exemple'])
        ->and(array_keys(plannedForms($planner->docmanCategory(1))))->toBe(['/index.php/bibliotheque/textes-fictifs', '/bibliotheque/textes-fictifs'])
        ->and($planner->docmanCategory(999))->toBe([]);
});

it('génère les URL d\'un livre Alexandria : menu, composant, PDF direct et query string', function (): void {
    $export = plannerExport();
    $planner = new RedirectPlanner($export);

    expect(plannedForms($planner->alexandriaBook(plannerItem($export['alexandria']['books'], 10))))->toBe([
        '/index.php/e-books/finances-imaginaires/10-manuel-imaginaire-de-finances' => 'alexandria.fiche',
        '/e-books/finances-imaginaires/10-manuel-imaginaire-de-finances' => 'alexandria.fiche.sans-index',
        '/index.php/component/abook/book/3-finances-imaginaires/10-manuel-imaginaire-de-finances' => 'alexandria.composant',
        '/component/abook/book/3-finances-imaginaires/10-manuel-imaginaire-de-finances' => 'alexandria.composant.sans-index',
        '/livres/manuel-imaginaire.pdf' => 'alexandria.pdf',
        '/index.php?option=com_abook&view=book&id=10' => 'alexandria.requete',
    ]);
});

it('passe par le menu des catégories (id de catégorie + chemin) quand la catégorie n\'a pas de menu', function (): void {
    $export = plannerExport();
    $export['menus'] = [['path' => 'e-books', 'component' => 'com_abook', 'view' => 'categories', 'category_key' => 2]];
    $planner = new RedirectPlanner($export);

    expect(array_keys(plannedForms($planner->alexandriaBook(plannerItem($export['alexandria']['books'], 10)))))
        ->toContain('/index.php/e-books/3-ouvrages-fictifs/finances-imaginaires/10-manuel-imaginaire-de-finances')
        ->and(array_keys(plannedForms($planner->alexandriaCategory(2))))->toBe([
            '/index.php/e-books', '/e-books',
            '/index.php/component/abook/category/2-ouvrages-fictifs', '/component/abook/category/2-ouvrages-fictifs',
        ])
        ->and(array_keys(plannedForms($planner->alexandriaCategory(3))))->toBe([
            '/index.php/e-books/3-ouvrages-fictifs/finances-imaginaires', '/e-books/3-ouvrages-fictifs/finances-imaginaires',
            '/index.php/component/abook/category/3-finances-imaginaires', '/component/abook/category/3-finances-imaginaires',
        ])
        ->and($planner->alexandriaCategory(1))->toBe([]);
});

it('encode les noms de fichier comme un navigateur (forme WHATWG)', function (): void {
    $export = plannerExport();
    $book = ['id' => 7, 'catid' => 3, 'alias' => 'guide', 'file' => 'Guide pratique "été" #1.pdf'];

    expect(array_keys(plannedForms((new RedirectPlanner($export))->alexandriaBook($book))))
        ->toContain('/livres/Guide%20pratique%20%22%C3%A9t%C3%A9%22%20%231.pdf');
});

it('écarte les chemins trop longs et les noms de fichier piégés', function (): void {
    $export = plannerExport();
    $planner = new RedirectPlanner($export);
    $long = ['id' => 8, 'catid' => 3, 'alias' => str_repeat('a', 520), 'file' => '../secret.pdf'];

    expect(array_keys(plannedForms($planner->alexandriaBook($long))))->toBe(['/index.php?option=com_abook&view=book&id=8'])
        ->and($planner->discardedCount())->toBe(5);
});
