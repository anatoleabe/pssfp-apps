<?php

declare(strict_types=1);

namespace App\Services\Lmd;

use InvalidArgumentException;

/**
 * Import Excel de notes refusé (spec lot 20) : rien n'est enregistré. `lignes` donne, pour chaque
 * ligne du fichier en erreur, son numéro dans Excel, le matricule et le problème.
 */
final class ImportRefuse extends InvalidArgumentException
{
    /** @param  list<array{ligne: int, matricule: string, message: string}>  $lignes  triées par numéro de ligne */
    public function __construct(public readonly array $lignes)
    {
        $n = count($lignes);
        $exemples = implode(' ; ', array_map(
            fn (array $l): string => "ligne {$l['ligne']} ({$l['matricule']}) : {$l['message']}", array_slice($lignes, 0, 3)));
        parent::__construct(sprintf('%d ligne%s en erreur, rien n\'a été enregistré. %s%s', $n, $n > 1 ? 's' : '', $exemples, $n > 3 ? ' ; … Le rapport donne toutes les lignes.' : ''));
    }
}
