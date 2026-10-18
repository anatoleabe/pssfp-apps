<?php

declare(strict_types=1);

namespace App\Services\Library\Import;

use FilesystemIterator;
use Normalizer;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use UnexpectedValueException;

/**
 * Retrouve les fichiers de l'ancienne bibliothèque copiés depuis le FTP (plan 3, task 4).
 *
 * Chemin exact d'abord, puis correspondance tolérante dans un index construit une seule fois :
 * noms translittérés (Latin-1/Windows-1252 → UTF-8, NFKD sans accents, Œ → oe, minuscules,
 * ponctuation et espaces ignorés), puis « squelette » ASCII (caractères non ASCII retirés : un
 * nom dont les accents ont été perdus ou mal encodés), d'abord sur le chemin complet puis sur le
 * seul nom de fichier. Un candidat ambigu n'est jamais retenu : mieux vaut un fichier signalé
 * introuvable qu'un document rattaché au mauvais PDF.
 */
final class FileLocator
{
    private const LEVELS = ['path_key', 'path_skeleton', 'name_key', 'name_skeleton'];

    /** @var array<string, string>|null chemin relatif (octets bruts) → chemin absolu */
    private ?array $entries;

    /** @var array<string, array<string, list<string>>> niveau → clé → chemins relatifs */
    private array $index = [];

    private function __construct(private readonly ?string $root, ?array $entries)
    {
        $this->entries = $entries;
    }

    /** Index du dossier construit à la première recherche ; dossier absent → index vide. */
    public static function scan(string $root): self
    {
        return new self(rtrim($root, '/'), null);
    }

    /**
     * Index fourni tel quel (tests, simulation sur un inventaire FTP).
     *
     * @param  array<string, string>  $entries  chemin relatif (octets bruts) → chemin absolu
     */
    public static function fromEntries(array $entries): self
    {
        return new self(null, $entries);
    }

    public function locate(string $relative): ?LocatedFile
    {
        $relative = ltrim($relative, '/');
        if (! self::isSafeRelative($relative)) {
            return null;
        }

        $entries = $this->entries();
        if (isset($entries[$relative])) {
            return new LocatedFile($entries[$relative], $relative, false);
        }

        foreach (self::LEVELS as $level) {
            $candidates = $this->index[$level][self::levelKey($level, $relative)] ?? [];
            if (count($candidates) > 1) {
                $candidates = $this->sameDirectory($candidates, $relative);
            }
            if (count($candidates) === 1) {
                return new LocatedFile($entries[$candidates[0]], $candidates[0], true);
            }
        }

        return null;
    }

    /** Clé translittérée : « Arrêté n°001 de l’Œuvre.PDF » → « arreten001deloeuvrepdf ». */
    public static function key(string $name): string
    {
        $text = mb_check_encoding($name, 'UTF-8') ? $name : mb_convert_encoding($name, 'UTF-8', 'Windows-1252');
        $text = str_replace(['Œ', 'œ', 'Æ', 'æ', 'ß'], ['oe', 'oe', 'ae', 'ae', 'ss'], $text);
        $text = (string) Normalizer::normalize($text, Normalizer::FORM_KD);
        $text = mb_strtolower((string) preg_replace('/\p{Mn}+/u', '', $text));

        return (string) preg_replace('#[^a-z0-9/]+#', '', $text);
    }

    /** Squelette ASCII : seuls les octets [A-Za-z0-9/] sont gardés, quel que soit l'encodage. */
    public static function skeleton(string $name): string
    {
        return strtolower((string) preg_replace('#[^A-Za-z0-9/]+#', '', $name));
    }

    private static function levelKey(string $level, string $relative): string
    {
        return match ($level) {
            'path_key' => self::key($relative),
            'path_skeleton' => self::skeleton($relative),
            'name_key' => self::key(basename($relative)),
            'name_skeleton' => self::skeleton(basename($relative)),
        };
    }

    private static function isSafeRelative(string $relative): bool
    {
        if ($relative === '' || str_contains($relative, "\0")) {
            return false;
        }

        return ! in_array('..', explode('/', str_replace('\\', '/', $relative)), true);
    }

    /**
     * Candidats du même dossier (comparé par squelette) que le chemin demandé.
     *
     * @param  list<string>  $candidates
     * @return list<string>
     */
    private function sameDirectory(array $candidates, string $relative): array
    {
        $directory = self::skeleton(dirname($relative));

        return array_values(array_filter(
            $candidates,
            fn (string $candidate): bool => self::skeleton(dirname($candidate)) === $directory,
        ));
    }

    /** @return array<string, string> */
    private function entries(): array
    {
        if ($this->entries === null) {
            $this->entries = $this->scanDirectory();
        }
        if ($this->index === [] && $this->entries !== []) {
            foreach (array_keys($this->entries) as $relative) {
                foreach (self::LEVELS as $level) {
                    $this->index[$level][self::levelKey($level, (string) $relative)][] = (string) $relative;
                }
            }
        }

        return $this->entries;
    }

    /** @return array<string, string> */
    private function scanDirectory(): array
    {
        if ($this->root === null || ! is_dir($this->root)) {
            return [];
        }

        $entries = [];
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
            );
            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                // Liens symboliques ignorés : ils peuvent pointer hors de --files.
                if ($file->isFile() && ! $file->isLink()) {
                    $entries[substr($file->getPathname(), strlen($this->root) + 1)] = $file->getPathname();
                }
            }
        } catch (UnexpectedValueException) {
            // Dossier illisible : les fichiers seront signalés introuvables dans le rapport.
        }
        ksort($entries, SORT_STRING);

        return $entries;
    }
}
