<?php

declare(strict_types=1);

namespace App\Services\Scolarite;

use Carbon\CarbonImmutable;

/** Un palier de paiement d'une inscription annuelle : dû après remise, payé, reste. */
final readonly class Palier
{
    public const INSCRIPTION = 'inscription';

    public const TRANCHE1 = 'tranche1';

    public const TRANCHE2 = 'tranche2';

    public function __construct(
        public string $cle,
        public int $du,
        public int $paye,
        public int $reste,
        public bool $enRetard,
        public ?CarbonImmutable $echeance,
    ) {}

    public function solde(): bool
    {
        return $this->reste === 0;
    }

    public function libelle(): string
    {
        return match ($this->cle) {
            self::INSCRIPTION => 'Inscription',
            self::TRANCHE1 => '1re tranche',
            default => '2e tranche',
        };
    }
}
