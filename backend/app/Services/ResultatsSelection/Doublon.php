<?php

declare(strict_types=1);

namespace App\Services\ResultatsSelection;

/**
 * Une même personne retenue au titre de plusieurs dossiers, signalée à
 * l'agent avant l'export.
 */
final readonly class Doublon
{
    /**
     * @param  list<string>  $numeros  numéros de dossier concernés
     */
    public function __construct(
        public string $nomComplet,
        public array $numeros,
        /** Dossier gardé si les doublons ne sont pas conservés. */
        public string $conserve,
    ) {}

    public function description(): string
    {
        return $this->nomComplet.' : dossiers '.implode(', ', $this->numeros).' — conservé : '.$this->conserve;
    }
}
