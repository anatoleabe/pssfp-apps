<?php

declare(strict_types=1);

use App\Services\Library\Import\FileLocator;
use Illuminate\Support\Facades\File;

uses()->group('module-3', 'library', 'biblio-import');

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/file-locator-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->root.'/ARRETES');
    File::ensureDirectoryExists($this->root.'/CIRCULAIRES/2019');
    file_put_contents($this->root.'/ARRETES/Arrêté n°001 de l’exemple.pdf', 'a');
    file_put_contents($this->root.'/CIRCULAIRES/2019/note.pdf', 'b');
    file_put_contents($this->root.'/racine.pdf', 'c');
});

afterEach(function (): void {
    File::deleteDirectory($this->root);
});

it('trouve un chemin exact, y compris en sous-dossier', function (): void {
    $located = FileLocator::scan($this->root)->locate('CIRCULAIRES/2019/note.pdf');

    expect($located?->path)->toBe($this->root.'/CIRCULAIRES/2019/note.pdf')
        ->and($located?->tolerant)->toBeFalse();
});

it('tolère casse, accents, apostrophes typographiques et signes ° du nom', function (string $requested): void {
    $located = FileLocator::scan($this->root)->locate($requested);

    expect($located?->path)->toBe($this->root.'/ARRETES/Arrêté n°001 de l’exemple.pdf')
        ->and($located?->tolerant)->toBeTrue();
})->with([
    'apostrophe droite' => "ARRETES/Arrêté n°001 de l'exemple.pdf",
    'majuscules sans accents' => 'arretes/ARRETE N001 DE LEXEMPLE.PDF',
    'soulignés' => 'ARRETES/Arrete_n_001_de_l_exemple.pdf',
]);

it('rapproche un nom Latin-1 (Windows-1252) d\'un nom UTF-8', function (): void {
    $locator = FileLocator::fromEntries([
        "D\xE9veloppement de l\x92\x8Cuvre n\xB01.pdf" => '/fichiers/latin1.pdf',
        'autre.pdf' => '/fichiers/autre.pdf',
    ]);

    expect($locator->locate('Développement de l’Œuvre n°1.pdf')?->path)->toBe('/fichiers/latin1.pdf')
        ->and($locator->locate('Developpement de l\'OEuvre n 1.pdf')?->path)->toBe('/fichiers/latin1.pdf');
});

it('rapproche un nom dont les caractères non ASCII ont été perdus', function (): void {
    $locator = FileLocator::fromEntries(['D?veloppement r?gional.pdf' => '/fichiers/perdu.pdf']);

    expect($locator->locate('Développement régional.pdf')?->path)->toBe('/fichiers/perdu.pdf');
});

it('retrouve un fichier rangé dans un autre dossier quand son nom est unique', function (): void {
    expect(FileLocator::scan($this->root)->locate('AUTRE DOSSIER/racine.pdf')?->path)->toBe($this->root.'/racine.pdf');
});

it('refuse de choisir entre deux fichiers de même nom dans des dossiers différents', function (): void {
    $locator = FileLocator::fromEntries(['A/rapport.pdf' => '/a.pdf', 'B/rapport.pdf' => '/b.pdf']);

    expect($locator->locate('C/rapport.pdf'))->toBeNull()
        ->and($locator->locate('B/rapport.pdf')?->path)->toBe('/b.pdf');
});

it('ne trouve rien pour un fichier absent, vide ou hors du dossier', function (string $requested): void {
    expect(FileLocator::scan($this->root)->locate($requested))->toBeNull();
})->with([
    'absent' => 'ARRETES/inexistant.pdf',
    'vide' => '',
    'remontée' => '../racine.pdf',
    'remontée intermédiaire' => 'ARRETES/../racine.pdf',
    'octet nul' => "racine.pdf\0.jpg",
]);

it('ne lève pas d\'erreur sur un dossier racine absent', function (): void {
    expect(FileLocator::scan($this->root.'/absent')->locate('racine.pdf'))->toBeNull();
});
