<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;

uses()->group('module-3', 'library', 'biblio-import');

const EXTRACT_MINI_DUMP = __DIR__.'/../../Fixtures/joomla/mini-dump.sql';

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-18T09:00:00Z'));
    $this->output = sys_get_temp_dir().'/joomla-export-'.bin2hex(random_bytes(4)).'.json';
});

afterEach(function (): void {
    Carbon::setTestNow();
    @unlink($this->output);
});

/** @return array<string, mixed> */
function exportJson(string $path): array
{
    return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
}

it('produit le JSON attendu à partir du mini-dump fictif', function (): void {
    $this->artisan('biblio:extract-joomla', [
        'dump' => EXTRACT_MINI_DUMP,
        'sortie' => $this->output,
        '--prefix' => 'tst01_',
    ])->expectsOutputToContain('3 documents')->assertExitCode(0);

    expect(exportJson($this->output))->toBe([
        'generated_at' => '2026-10-18T09:00:00+00:00',
        'docman' => [
            'categories' => [
                ['id' => 1, 'parent_id' => null, 'slug' => 'textes-fictifs', 'title' => 'Textes fictifs', 'path' => 'textes-fictifs', 'enabled' => 1, 'access' => 1],
                ['id' => 2, 'parent_id' => 1, 'slug' => 'arretes-fictifs', 'title' => 'Arrêtés fictifs', 'path' => 'textes-fictifs/arretes-fictifs', 'enabled' => 1, 'access' => 1],
                ['id' => 3, 'parent_id' => 2, 'slug' => 'arretes-exemple', 'title' => "Arrêtés de l'exemple", 'path' => 'textes-fictifs/arretes-fictifs/arretes-exemple', 'enabled' => 0, 'access' => 2],
            ],
            'documents' => [
                [
                    'id' => 101, 'category_id' => 2, 'title' => "Arrêté n°001 portant règle de l'exemple", 'slug' => 'arrete-001',
                    'description' => "<p>Ligne une\nLigne deux avec \"guillemets\" échappés : \"ok\"</p>",
                    'storage_type' => 'file', 'storage_path' => 'ARRETES FICTIFS/Arrêté n°001.pdf', 'hits' => 12, 'enabled' => 1, 'access' => 1,
                    'publish_on' => '2019-05-02 08:30:00', 'created_on' => '2019-05-01 08:30:00',
                ],
                [
                    'id' => 102, 'category_id' => 3, 'title' => 'Circulaire C:\\chemin\\fictif', 'slug' => 'circulaire-fictive',
                    'description' => null,
                    'storage_type' => 'file', 'storage_path' => 'CIRCULAIRES/c-fictive.pdf', 'hits' => 0, 'enabled' => 0, 'access' => 1,
                    'publish_on' => null, 'created_on' => '2020-01-15 00:00:00',
                ],
                [
                    'id' => 103, 'category_id' => 1, 'title' => "Note 'double' et tab\tici", 'slug' => 'note-double',
                    'description' => "Texte\0nul",
                    'storage_type' => 'remote', 'storage_path' => 'https://exemple.invalid/note.pdf', 'hits' => -1, 'enabled' => 1, 'access' => 1,
                    'publish_on' => '2021-07-01 00:00:00', 'created_on' => '2021-06-30 12:00:00',
                ],
            ],
        ],
        'alexandria' => [
            'categories' => [
                ['id' => 1, 'parent_id' => null, 'alias' => 'root', 'title' => 'ROOT', 'path' => '', 'published' => 1, 'access' => 1],
                ['id' => 2, 'parent_id' => 1, 'alias' => 'ouvrages-fictifs', 'title' => 'OUVRAGES FICTIFS', 'path' => 'ouvrages-fictifs', 'published' => 1, 'access' => 1],
                ['id' => 3, 'parent_id' => 2, 'alias' => 'finances-imaginaires', 'title' => 'FINANCES IMAGINAIRES', 'path' => 'ouvrages-fictifs/finances-imaginaires', 'published' => 1, 'access' => 1],
            ],
            'books' => [
                [
                    'id' => 10, 'catid' => 3, 'title' => 'Manuel imaginaire de finances', 'subtitle' => null, 'alias' => 'manuel-imaginaire-de-finances',
                    'description' => "<p>Premier paragraphe.</p>\r\n<p>Second paragraphe ; avec point-virgule.</p>",
                    'file' => 'manuel-imaginaire.pdf', 'image' => 'images/couvertures/manuel-imaginaire.jpg', 'year' => 2015, 'hits' => 42,
                    'state' => 1, 'approved' => 1, 'access' => 1, 'url' => '', 'isbn' => '978-0-00-000000-0', 'pag' => 120,
                    'dateinsert' => '2016-03-01 10:00:00',
                    'authors' => [
                        ['id' => 2, 'lastname' => 'Imaginaire', 'name' => 'Bruno'],
                        ['id' => 1, 'lastname' => 'Fictivus', 'name' => 'Alba'],
                    ],
                ],
                [
                    'id' => 11, 'catid' => 3, 'title' => 'Guide (fictif) du budget', 'subtitle' => 'Tome 2', 'alias' => 'guide-fictif-du-budget',
                    'description' => '',
                    'file' => 'guide-fictif.pdf', 'image' => '', 'year' => 0, 'hits' => 0,
                    'state' => 0, 'approved' => 0, 'access' => 1, 'url' => null, 'isbn' => null, 'pag' => 0,
                    'dateinsert' => null,
                    'authors' => [
                        ['id' => 3, 'lastname' => "N'Exemple", 'name' => 'Chloé'],
                        ['id' => 1, 'lastname' => 'Fictivus', 'name' => 'Alba'],
                    ],
                ],
            ],
        ],
        'menus' => [
            ['path' => 'bibliotheque', 'component' => 'com_docman', 'view' => 'list', 'category_key' => null],
            ['path' => 'e-books/finances-imaginaires', 'component' => 'com_abook', 'view' => 'category', 'category_key' => 3],
            ['path' => 'textes/arretes-fictifs', 'component' => 'com_docman', 'view' => 'list', 'category_key' => 'arretes-fictifs'],
        ],
    ]);
});

it('renvoie des tableaux vides quand les tables attendues sont absentes', function (): void {
    // Préfixe par défaut (oe179_) : aucune table du mini-dump ne correspond.
    $this->artisan('biblio:extract-joomla', ['dump' => EXTRACT_MINI_DUMP, 'sortie' => $this->output])
        ->assertExitCode(0);

    $json = exportJson($this->output);

    expect($json['docman'])->toBe(['categories' => [], 'documents' => []])
        ->and($json['alexandria'])->toBe(['categories' => [], 'books' => []])
        ->and($json['menus'])->toBe([]);
});

it('protège le fichier produit (lecture propriétaire seule)', function (): void {
    $this->artisan('biblio:extract-joomla', ['dump' => EXTRACT_MINI_DUMP, 'sortie' => $this->output, '--prefix' => 'tst01_'])
        ->assertExitCode(0);

    expect(fileperms($this->output) & 0777)->toBe(0600);
});

it('échoue si le dump est introuvable', function (): void {
    $this->artisan('biblio:extract-joomla', ['dump' => '/chemin/inexistant.sql', 'sortie' => $this->output])
        ->expectsOutputToContain('introuvable')
        ->assertExitCode(1);

    expect(file_exists($this->output))->toBeFalse();
});

it('refuse un préfixe de table invalide', function (): void {
    $this->artisan('biblio:extract-joomla', ['dump' => EXTRACT_MINI_DUMP, 'sortie' => $this->output, '--prefix' => 'x`; DROP'])
        ->assertExitCode(1);

    expect(file_exists($this->output))->toBeFalse();
});

it('échoue sans écrire si le dump est tronqué', function (): void {
    $dump = tempnam(sys_get_temp_dir(), 'dump-').'.sql';
    file_put_contents($dump, "INSERT INTO `oe179_abbook` (`id`, `title`) VALUES\n(1, 'pas fini\n");

    try {
        $this->artisan('biblio:extract-joomla', ['dump' => $dump, 'sortie' => $this->output])
            ->expectsOutputToContain('tronqué')
            ->assertExitCode(1);

        expect(file_exists($this->output))->toBeFalse();
    } finally {
        unlink($dump);
    }
});
