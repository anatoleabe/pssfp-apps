<?php

declare(strict_types=1);

namespace App\Services\Library\Import;

/**
 * Fichiers copiés depuis le FTP de l'ancien Joomla, sous --files :
 * `Documentations/{storage_path}` (DOCman), `livres/{file}` (Alexandria), `images/…` (couvertures).
 */
final readonly class ImportSources
{
    public function __construct(
        public FileLocator $documentations,
        public FileLocator $books,
        public FileLocator $images,
    ) {}

    public static function fromDirectory(string $files): self
    {
        $files = rtrim($files, '/');

        return new self(
            FileLocator::scan($files.'/Documentations'),
            FileLocator::scan($files.'/livres'),
            FileLocator::scan($files.'/images'),
        );
    }

    public function forSource(string $source): FileLocator
    {
        return $source === LegacyMapping::SOURCE_DOCMAN ? $this->documentations : $this->books;
    }

    /** Couverture Alexandria (`images/…` dans Joomla) ; URL ou chemin vide → null. */
    public function cover(?string $image): ?LocatedFile
    {
        if ($image === null || preg_match('#^[a-z][a-z0-9+.-]*://#i', $image) === 1) {
            return null;
        }

        return $this->images->locate((string) preg_replace('#^/?images/#i', '', $image));
    }
}
