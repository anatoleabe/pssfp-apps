<?php

declare(strict_types=1);

namespace App\Services\Library;

/** Résultat de DocumentAccess::decide(), lu par l'API, le téléchargement et le front. */
final readonly class AccessDecision
{
    public const PUBLIC = 'public';

    public const RESERVED = 'reserved';

    public const PAID = 'paid';

    public const UNPUBLISHED = 'unpublished';

    public const EXTERNAL = 'external';

    public function __construct(public bool $allowed, public string $reason) {}

    /** @return array{allowed: bool, reason: string} */
    public function toArray(): array
    {
        return ['allowed' => $this->allowed, 'reason' => $this->reason];
    }
}
