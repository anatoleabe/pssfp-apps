<?php

declare(strict_types=1);

namespace App\Services\Sms;

/**
 * État d'un message chez la passerelle.
 *
 * `brut` est toujours conservé : la liste des statuts possibles n'est pas
 * documentée exhaustivement, et un statut inconnu doit s'afficher tel quel
 * plutôt que d'être écrasé par un libellé inventé.
 */
final class MessageStatus
{
    public function __construct(
        public readonly string $brut,
        public readonly string $libelle,
        public readonly bool $livre,
        public readonly ?string $cout,
    ) {}
}
