<?php

declare(strict_types=1);

namespace App\Services\Supervision;

/**
 * Un contrôle du contrôle interne (lot 14a, D30). Il renvoie ses cas anormaux ; Supervision en tient les anomalies.
 */
interface Controle
{
    public const BLOQUANT = 'bloquant';

    public const MAJEUR = 'majeur';

    public const MINEUR = 'mineur';

    public function code(): string;

    public function libelle(): string;

    public function domaine(): string;

    public function criticite(): string;

    public function delaiJours(): int;

    /** Rôle chargé de traiter les anomalies, relancé au niveau 1 (lot 14b-2). */
    public function responsable(): string;

    /**
     * Cas anormaux, par clé stable. Le détail porte au moins `resume` (phrase lisible, sans donnée personnelle superflue).
     *
     * @return array<string, array<string, scalar|null>>
     */
    public function cas(): array;
}
