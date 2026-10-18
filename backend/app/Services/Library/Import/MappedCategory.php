<?php

declare(strict_types=1);

namespace App\Services\Library\Import;

/** Correspondance d'une catégorie Joomla : type de document, niveau d'accès et mots-clés. */
final readonly class MappedCategory
{
    /** @param  list<KeywordRef>  $keywords  du plus général au plus spécifique */
    public function __construct(
        public string $type,
        public string $accessLevel,
        public array $keywords,
    ) {}

    /** Mot-clé le plus spécifique (cible des redirections de catégorie), null s'il n'y en a pas. */
    public function primaryKeyword(): ?KeywordRef
    {
        return $this->keywords === [] ? null : $this->keywords[array_key_last($this->keywords)];
    }
}
