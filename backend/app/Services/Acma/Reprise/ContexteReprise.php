<?php

declare(strict_types=1);

namespace App\Services\Acma\Reprise;

use App\Models\User;
use Carbon\CarbonImmutable;

/** État partagé d'une exécution de `acma:reprendre` : horloge, auteur, listes nominatives pour les classeurs privés. */
final class ContexteReprise
{
    /** Listes nominatives (classeur des cas à arbitrer) : jamais affichées à la console ni journalisées. @var array<string, list<array<string, mixed>>> */
    public array $listes = [];

    /** Informations chiffrées des étapes, reprises par les contrôles. @var array<string, mixed> */
    public array $infos = [];

    /** Comptes du modèle vivant et des envois avant la reprise (C09, C10). @var array<string, int> */
    public array $vivantAvant = [];

    public int $requetesHttp = 0;

    public function __construct(
        public readonly CarbonImmutable $maintenant,
        public readonly User $auteur,
        public readonly ?int $repriseId,
    ) {}

    /** @param  array<string, mixed>  $ligne */
    public function lister(string $liste, array $ligne): void
    {
        $this->listes[$liste][] = $ligne;
    }
}
