<?php

declare(strict_types=1);

use App\Services\Library\Import\MysqlDumpReader;

uses()->group('module-3', 'library', 'biblio-import');

const MINI_DUMP = __DIR__.'/../../../Fixtures/joomla/mini-dump.sql';

/** @return array<string, list<array<string, mixed>>> */
function readDump(string $path, array $tables): array
{
    $rows = [];
    foreach ((new MysqlDumpReader($path))->rows($tables) as $table => $row) {
        $rows[$table][] = $row;
    }

    return $rows;
}

function tempDump(string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'dump-').'.sql';
    file_put_contents($path, $contents);

    return $path;
}

it('lit les lignes des tables demandées avec les échappements MySQL', function (): void {
    $rows = readDump(MINI_DUMP, ['tst01_docman_documents'])['tst01_docman_documents'];

    expect($rows)->toHaveCount(3)
        ->and($rows[0]['docman_document_id'])->toBe(101)
        ->and($rows[0]['title'])->toBe("Arrêté n°001 portant règle de l'exemple")
        ->and($rows[0]['description'])->toBe("<p>Ligne une\nLigne deux avec \"guillemets\" échappés : \"ok\"</p>")
        ->and($rows[1]['title'])->toBe('Circulaire C:\\chemin\\fictif')
        ->and($rows[1]['description'])->toBeNull()
        ->and($rows[1]['publish_on'])->toBe('0000-00-00 00:00:00')
        ->and($rows[2]['title'])->toBe("Note 'double' et tab\tici")
        ->and($rows[2]['description'])->toBe("Texte\0nul")
        ->and($rows[2]['hits'])->toBe(-1);
});

it('reprend les colonnes du CREATE TABLE quand l\'INSERT n\'en liste pas', function (): void {
    $rows = readDump(MINI_DUMP, ['tst01_abbookauth'])['tst01_abbookauth'];

    expect($rows)->toBe([
        ['id' => 3, 'idbook' => 11, 'idauth' => 3],
        ['id' => 1, 'idbook' => 10, 'idauth' => 2],
        ['id' => 2, 'idbook' => 10, 'idauth' => 1],
        ['id' => 4, 'idbook' => 11, 'idauth' => 1],
    ]);
});

it('ignore les tables non demandées même si leurs chaînes imitent un INSERT', function (): void {
    $rows = readDump(MINI_DUMP, ['tst01_menu', 'tst01_docman_category_relations']);

    expect($rows['tst01_menu'])->toHaveCount(7)
        ->and(array_column($rows['tst01_menu'], 'path'))->not->toContain('piege')
        ->and($rows['tst01_docman_category_relations'])->toHaveCount(6)
        ->and($rows)->not->toHaveKey('tst01_session');
});

it('ne renvoie rien pour une table absente du dump', function (): void {
    expect(readDump(MINI_DUMP, ['tst01_inexistante']))->toBe([]);
});

it('lit la table ignorée correctement si on la demande', function (): void {
    $rows = readDump(MINI_DUMP, ['tst01_session'])['tst01_session'];

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['data'])->toBe("ligne un ;\nINSERT INTO `tst01_menu` (`path`) VALUES\n('piege');\nfin \\")
        ->and($rows[1]['data'])->toBe('rien');
});

it('convertit en UTF-8 une chaîne Latin-1 invalide', function (): void {
    $path = tempDump("INSERT INTO `t` (`id`, `title`) VALUES\n(1, 'Arr\xEAt\xE9'),\n(2, 'D\xC3\xA9j\xC3\xA0');\n");

    try {
        expect(array_column(readDump($path, ['t'])['t'], 'title'))->toBe(['Arrêté', 'Déjà']);
    } finally {
        unlink($path);
    }
});

it('accepte une coupure de ligne hors chaîne au milieu d\'un tuple', function (): void {
    $path = tempDump("INSERT INTO `t` (`a`, `b`, `c`) VALUES (1,\n 2.5,\n 'x'), (0x41, NULL, 'y');\n");

    try {
        expect(readDump($path, ['t'])['t'])->toBe([
            ['a' => 1, 'b' => 2.5, 'c' => 'x'],
            ['a' => '0x41', 'b' => null, 'c' => 'y'],
        ]);
    } finally {
        unlink($path);
    }
});

it('refuse un tuple dont le nombre de valeurs ne correspond pas aux colonnes', function (): void {
    $path = tempDump("INSERT INTO `t` (`a`, `b`) VALUES (1, 2, 3);\n");

    try {
        expect(fn () => readDump($path, ['t']))->toThrow(RuntimeException::class, 't');
    } finally {
        unlink($path);
    }
});

it('signale un dump tronqué au milieu d\'une instruction', function (): void {
    $path = tempDump("INSERT INTO `t` (`a`, `b`) VALUES\n(1, 'pas fini\n");

    try {
        expect(fn () => readDump($path, ['t']))->toThrow(RuntimeException::class, 'tronqué');
    } finally {
        unlink($path);
    }
});

it('échoue proprement si le fichier est illisible', function (): void {
    expect(fn () => readDump('/chemin/inexistant.sql', ['t']))->toThrow(RuntimeException::class);
});

it('lit un dump de ~20 Mo en flux sans le charger en mémoire', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'dump-big-').'.sql';
    $handle = fopen($path, 'wb');
    $description = str_repeat('Paragraphe fictif l\\\'exemple ; (parenthèse) \\\\ fin.\\r\\n', 40);
    $rowsWritten = 0;
    for ($batch = 0; filesize($path) < 20 * 1024 * 1024; $batch++) {
        fwrite($handle, "INSERT INTO `big` (`id`, `title`, `description`) VALUES\n");
        for ($i = 0; $i < 500; $i++) {
            $rowsWritten++;
            fwrite($handle, "({$rowsWritten}, 'Titre {$rowsWritten}', '{$description}\nsuite')".($i === 499 ? ";\n" : ",\n"));
        }
        fflush($handle);
        clearstatcache(true, $path);
    }
    fclose($handle);

    gc_collect_cycles();
    $before = memory_get_usage();
    memory_reset_peak_usage();

    try {
        $count = 0;
        $lastId = 0;
        foreach ((new MysqlDumpReader($path))->rows(['big']) as $row) {
            $count++;
            $lastId = $row['id'];
        }
        $peak = memory_get_peak_usage();

        expect(filesize($path))->toBeGreaterThan(20 * 1024 * 1024)
            ->and($count)->toBe($rowsWritten)
            ->and($lastId)->toBe($rowsWritten)
            // Seul l'écart compte : dans la suite complète, la mémoire de base
            // du processus dépasse déjà largement un seuil absolu.
            ->and($peak - $before)->toBeLessThan(8 * 1024 * 1024);
    } finally {
        unlink($path);
    }
});
