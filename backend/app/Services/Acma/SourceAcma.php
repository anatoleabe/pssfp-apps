<?php

declare(strict_types=1);

namespace App\Services\Acma;

/**
 * Lot 15a (ADR-0014) : base ACMA lue par `acma:extraire`. En production, la base MariaDB jetable du poste de l'USI ;
 * dans les tests, une source factice (aucune dépendance à MySQL en CI).
 */
interface SourceAcma
{
    /** @return array{version: string, base: string} */
    public function serveur(): array;

    /** @return list<string> */
    public function tables(): array;

    /** @return list<array{nom: string, type: string, cle: bool}> */
    public function colonnes(string $table): array;

    /**
     * Lignes de la table, valeurs telles que le serveur les rend (texte), dans l'ordre des colonnes demandées.
     *
     * @param  list<string>  $colonnes
     * @param  list<string>  $ordre
     * @return iterable<int, list<string|null>>
     */
    public function lignes(string $table, array $colonnes, array $ordre): iterable;
}
