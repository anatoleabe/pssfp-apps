<?php

declare(strict_types=1);

namespace App\Services\Library\Import;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Document DOCman ou livre Alexandria de l'export, normalisé pour l'import (plan 3, task 4).
 *
 * Publication d'office (décision du 2026-10-18) : publié s'il l'était dans Joomla — DOCman
 * `enabled = 1` et `publish_on` passé ; Alexandria `state = 1` —, dans une catégorie publiée
 * (ancêtres compris) et en accès public ; sinon brouillon, avec la raison. La colonne Alexandria
 * `approved` n'est pas lue : elle vaut 0 pour les 1 270 livres de l'ancienne bibliothèque, y
 * compris les 1 256 publiés (relevé du 2026-10-18). Ignorés : fichier distant ou absent, corbeille.
 */
final readonly class LegacyItem
{
    /** Niveaux d'accès Joomla publics : 1 « Public », 5 « Guest » ; 0 ou -1 : hérité de la catégorie. */
    public const JOOMLA_PUBLIC_ACCESS = 1;

    private const PUBLIC_ACCESS_LEVELS = [1, 5];

    private const JOOMLA_TRASHED = -2;

    private const AUTHOR_MAX_LENGTH = 200;

    private const ISBN_MAX_LENGTH = 30;

    /**
     * @param  list<string>  $authors  dans l'ordre Joomla
     * @param  array<string, mixed>  $raw  ligne de l'export (pour les anciennes URL)
     */
    public function __construct(
        public string $source,
        public int $id,
        public ?int $categoryId,
        public string $title,
        public ?string $subtitle,
        public ?string $abstract,
        public string $alias,
        public ?string $file,
        public ?string $image,
        public ?int $declaredYear,
        public int $hits,
        public ?CarbonImmutable $publishedAt,
        public ?string $draftReason,
        public ?string $skipReason,
        public array $authors,
        public ?string $isbn,
        public ?int $pages,
        public array $raw,
    ) {}

    /** @param  array<string, mixed>  $row */
    public static function fromDocman(array $row, LegacyCategories $categories): self
    {
        $source = LegacyMapping::SOURCE_DOCMAN;
        $categoryId = isset($row['category_id']) ? (int) $row['category_id'] : null;
        $file = self::nullableString($row['storage_path'] ?? null);
        $publishOn = self::date($row['publish_on'] ?? null);
        $access = (int) ($row['access'] ?? 0);
        if ($access <= 0 && $categoryId !== null) {
            $access = $categories->access($source, $categoryId);
        }

        $skip = match (true) {
            ($row['storage_type'] ?? 'file') !== 'file' => 'stockage distant (pas de fichier)',
            $file === null => 'aucun fichier déclaré',
            default => null,
        };
        $draft = match (true) {
            (int) ($row['enabled'] ?? 0) !== 1 => 'dépublié dans Joomla',
            $publishOn !== null && $publishOn->isFuture() => 'publication programmée dans Joomla',
            default => self::categoryAndAccessReason($categories, $source, $categoryId, $access),
        };

        return new self(
            source: $source,
            id: (int) $row['id'],
            categoryId: $categoryId,
            title: self::title($row['title'] ?? null, $file),
            subtitle: null,
            abstract: LegacyText::abstract(self::nullableString($row['description'] ?? null)),
            alias: (string) ($row['slug'] ?? ''),
            file: $file,
            image: null,
            declaredYear: null,
            hits: max(0, (int) ($row['hits'] ?? 0)),
            publishedAt: $publishOn ?? self::date($row['created_on'] ?? null),
            draftReason: $draft,
            skipReason: $skip,
            authors: [],
            isbn: null,
            pages: null,
            raw: $row,
        );
    }

    /** @param  array<string, mixed>  $row */
    public static function fromAlexandria(array $row, LegacyCategories $categories): self
    {
        $source = LegacyMapping::SOURCE_ALEXANDRIA;
        $categoryId = isset($row['catid']) ? (int) $row['catid'] : null;
        $file = self::nullableString($row['file'] ?? null);
        $state = (int) ($row['state'] ?? 0);
        $year = (int) ($row['year'] ?? 0);
        $pages = (int) ($row['pag'] ?? 0);
        $isbn = self::nullableString($row['isbn'] ?? null);
        $subtitle = self::nullableString($row['subtitle'] ?? null);

        $skip = match (true) {
            $state === self::JOOMLA_TRASHED => 'à la corbeille dans Joomla',
            $file === null => 'aucun fichier déclaré',
            default => null,
        };
        $draft = $state !== 1
            ? 'dépublié dans Joomla'
            : self::categoryAndAccessReason($categories, $source, $categoryId, (int) ($row['access'] ?? 1));

        return new self(
            source: $source,
            id: (int) $row['id'],
            categoryId: $categoryId,
            title: self::title($row['title'] ?? null, $file),
            subtitle: $subtitle === null ? null : LegacyText::title($subtitle),
            abstract: LegacyText::abstract(self::nullableString($row['description'] ?? null)),
            alias: (string) ($row['alias'] ?? ''),
            file: $file,
            image: self::nullableString($row['image'] ?? null),
            declaredYear: $year > 0 ? $year : null,
            hits: max(0, (int) ($row['hits'] ?? 0)),
            publishedAt: self::date($row['dateinsert'] ?? null),
            draftReason: $draft,
            skipReason: $skip,
            authors: self::authors((array) ($row['authors'] ?? [])),
            isbn: $isbn !== null && mb_strlen($isbn) <= self::ISBN_MAX_LENGTH ? $isbn : null,
            pages: $pages > 0 ? $pages : null,
            raw: $row,
        );
    }

    public function isPublished(): bool
    {
        return $this->draftReason === null;
    }

    public function year(): ?int
    {
        return LegacyText::year($this->declaredYear, $this->title);
    }

    public function language(): string
    {
        return LegacyText::language($this->title);
    }

    private static function categoryAndAccessReason(LegacyCategories $categories, string $source, ?int $categoryId, int $access): ?string
    {
        if ($categoryId !== null && $categories->exists($source, $categoryId) && ! $categories->isPublished($source, $categoryId)) {
            return 'catégorie dépubliée dans Joomla';
        }

        return in_array($access, self::PUBLIC_ACCESS_LEVELS, true) ? null : 'accès restreint dans Joomla';
    }

    /** Titre normalisé ; vide → nom du fichier (sans extension). */
    private static function title(mixed $raw, ?string $file): string
    {
        $title = LegacyText::title((string) $raw);
        if ($title === '' && $file !== null) {
            $title = LegacyText::title(pathinfo($file, PATHINFO_FILENAME));
        }

        return $title === '' ? 'Document sans titre' : $title;
    }

    /**
     * @param  array<array-key, mixed>  $authors
     * @return list<string> « Prénom Nom », sans doublon
     */
    private static function authors(array $authors): array
    {
        $names = [];
        foreach ($authors as $author) {
            $name = trim((string) preg_replace('/\s+/u', ' ', trim(($author['name'] ?? '').' '.($author['lastname'] ?? ''))));
            if ($name !== '' && mb_strlen($name) <= self::AUTHOR_MAX_LENGTH && ! in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    private static function nullableString(mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value === '' ? null : $value;
    }

    /** Date Joomla (UTC en base) ; vide ou illisible → null. */
    private static function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('Y-m-d H:i:s', $value, 'UTC') ?: null;
        } catch (Throwable) {
            return null;
        }
    }
}
