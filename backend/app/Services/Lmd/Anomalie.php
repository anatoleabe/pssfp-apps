<?php

declare(strict_types=1);

namespace App\Services\Lmd;

/** Anomalie d'un contrôle : « bloquant » interdit l'opération, « avertissement » la signale seulement. */
final readonly class Anomalie
{
    public const BLOQUANT = 'bloquant';

    public const AVERTISSEMENT = 'avertissement';

    public function __construct(
        public string $gravite,
        public string $code,
        public string $message,
    ) {}

    public function estBloquante(): bool
    {
        return $this->gravite === self::BLOQUANT;
    }
}
