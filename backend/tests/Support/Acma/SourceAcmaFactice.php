<?php

declare(strict_types=1);

namespace Tests\Support\Acma;

use App\Services\Acma\SourceAcma;

/** Source ACMA en mémoire (jeu fictif) : `acma:extraire` sans MySQL. */
final class SourceAcmaFactice implements SourceAcma
{
    /** @param  array<string, array{colonnes: list<array{nom: string, type: string, cle: bool}>, lignes: list<list<string|null>>}>  $tables */
    public function __construct(private readonly array $tables) {}

    public static function jeu(?array $lignes = null): self
    {
        return new self(JeuAcmaFictif::tables($lignes));
    }

    public function serveur(): array
    {
        return ['version' => '11.8.9-MariaDB-factice', 'base' => 'gestionacademic'];
    }

    public function tables(): array
    {
        return array_keys($this->tables);
    }

    public function colonnes(string $table): array
    {
        return $this->tables[$table]['colonnes'];
    }

    public function lignes(string $table, array $colonnes, array $ordre): iterable
    {
        $noms = array_column($this->tables[$table]['colonnes'], 'nom');
        $index = array_map(fn (string $c): int|false => array_search($c, $noms, true), $colonnes);
        foreach ($this->tables[$table]['lignes'] as $ligne) {
            yield array_map(fn (int|false $i): ?string => $i === false ? null : $ligne[$i], $index);
        }
    }
}
