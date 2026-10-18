<?php

declare(strict_types=1);

use App\Services\Library\Import\JoomlaLibraryExtractor;
use App\Services\Library\Import\LegacyCategories;
use App\Services\Library\Import\LegacyItem;
use Illuminate\Support\Carbon;

uses()->group('module-3', 'library', 'biblio-import');

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-18T09:00:00Z'));
    $this->export = (new JoomlaLibraryExtractor)->extract(__DIR__.'/../../../Fixtures/joomla/mini-dump.sql', 'tst01_');
    $this->categories = new LegacyCategories($this->export);
});

afterEach(fn () => Carbon::setTestNow());

function docmanItem(array $export, LegacyCategories $categories, int $id, array $override = []): LegacyItem
{
    $row = array_merge(collect($export['docman']['documents'])->firstWhere('id', $id), $override);

    return LegacyItem::fromDocman($row, $categories);
}

function bookItem(array $export, LegacyCategories $categories, int $id, array $override = []): LegacyItem
{
    $row = array_merge(collect($export['alexandria']['books'])->firstWhere('id', $id), $override);

    return LegacyItem::fromAlexandria($row, $categories);
}

it('normalise un document DOCman publié', function (): void {
    $item = docmanItem($this->export, $this->categories, 101);

    expect($item->source)->toBe('docman')
        ->and($item->id)->toBe(101)
        ->and($item->title)->toBe("Arrêté n°001 portant règle de l'exemple")
        ->and($item->abstract)->toBe("Ligne une\nLigne deux avec \"guillemets\" échappés : \"ok\"")
        ->and($item->file)->toBe('ARRETES FICTIFS/Arrêté n°001.pdf')
        ->and($item->hits)->toBe(12)
        ->and($item->publishedAt?->toIso8601String())->toBe('2019-05-02T08:30:00+00:00')
        ->and($item->draftReason)->toBeNull()
        ->and($item->skipReason)->toBeNull()
        ->and($item->isPublished())->toBeTrue();
});

it('met en brouillon un document DOCman désactivé, programmé, en catégorie dépubliée ou réservé', function (array $override, string $reason): void {
    expect(docmanItem($this->export, $this->categories, 101, $override)->draftReason)->toBe($reason);
})->with([
    'désactivé' => [['enabled' => 0], 'dépublié dans Joomla'],
    'publication future' => [['publish_on' => '2027-01-01 00:00:00'], 'publication programmée dans Joomla'],
    'catégorie dépubliée' => [['category_id' => 3], 'catégorie dépubliée dans Joomla'],
    'accès enregistré' => [['access' => 2], 'accès restreint dans Joomla'],
]);

it('date la publication DOCman à la création à défaut de date de publication', function (): void {
    $item = docmanItem($this->export, $this->categories, 102);

    expect($item->publishedAt?->toIso8601String())->toBe('2020-01-15T00:00:00+00:00')
        ->and($item->hits)->toBe(0);
});

it('ignore un document DOCman stocké hors du serveur ou sans fichier', function (array $override, string $reason): void {
    expect(docmanItem($this->export, $this->categories, 101, $override)->skipReason)->toBe($reason);
})->with([
    'distant' => [['storage_type' => 'remote'], 'stockage distant (pas de fichier)'],
    'sans chemin' => [['storage_path' => ''], 'aucun fichier déclaré'],
]);

it('normalise un livre Alexandria avec ses auteurs dans l\'ordre', function (): void {
    $item = bookItem($this->export, $this->categories, 10);

    expect($item->source)->toBe('alexandria')
        ->and($item->authors)->toBe(['Bruno Imaginaire', 'Alba Fictivus'])
        ->and($item->abstract)->toBe("Premier paragraphe.\n\nSecond paragraphe ; avec point-virgule.")
        ->and($item->image)->toBe('images/couvertures/manuel-imaginaire.jpg')
        ->and($item->declaredYear)->toBe(2015)
        ->and($item->isbn)->toBe('978-0-00-000000-0')
        ->and($item->pages)->toBe(120)
        ->and($item->publishedAt?->toIso8601String())->toBe('2016-03-01T10:00:00+00:00')
        ->and($item->isPublished())->toBeTrue();
});

it('met en brouillon un livre dépublié et ignore un livre à la corbeille', function (): void {
    expect(bookItem($this->export, $this->categories, 11)->draftReason)->toBe('dépublié dans Joomla')
        ->and(bookItem($this->export, $this->categories, 11)->subtitle)->toBe('Tome 2')
        ->and(bookItem($this->export, $this->categories, 11)->declaredYear)->toBeNull()
        ->and(bookItem($this->export, $this->categories, 10, ['state' => -2])->skipReason)->toBe('à la corbeille dans Joomla')
        ->and(bookItem($this->export, $this->categories, 10, ['file' => ''])->skipReason)->toBe('aucun fichier déclaré');
});

it('reprend le nom du fichier quand le titre est vide', function (): void {
    expect(docmanItem($this->export, $this->categories, 101, ['title' => '  '])->title)->toBe('Arrêté n°001');
});
