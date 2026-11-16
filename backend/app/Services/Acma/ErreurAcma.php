<?php

declare(strict_types=1);

namespace App\Services\Acma;

use Illuminate\Database\QueryException;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Erreur de la reprise d'ACMA dont le message ne porte aucune donnée : codes, tables, comptes seulement.
 *
 * Toute autre erreur (base de données notamment) passe par sansDonnees() : le message du serveur peut citer une valeur
 * (« Failing row contains … ») ; on n'en garde que le SQLSTATE, la table et la contrainte. Rien d'autre n'est affiché ni
 * journalisé (lot 15, §7.11 et §13).
 */
final class ErreurAcma extends RuntimeException
{
    public static function sansDonnees(Throwable $e, string $contexte): self
    {
        if ($e instanceof self) {
            return $e;
        }

        return new self("Échec {$contexte} : ".self::resume($e).'.');
    }

    /** Résumé sans donnée d'une erreur : SQLSTATE, table et contrainte pour la base, classe sinon. */
    public static function resume(Throwable $e): string
    {
        if (! $e instanceof QueryException && ! $e instanceof PDOException) {
            return 'erreur interne ('.class_basename($e).')';
        }
        $etat = $e instanceof QueryException ? (string) ($e->errorInfo[0] ?? $e->getCode()) : (string) $e->getCode();
        // Première ligne du message du serveur seulement : le DETAIL (« Failing row contains ») n'est jamais lu.
        $premiere = strtok((string) ($e instanceof QueryException ? ($e->getPrevious()?->getMessage() ?? '') : $e->getMessage()), "\n");
        $parties = ['erreur de base de données (SQLSTATE '.$etat.')'];
        if (is_string($premiere) && preg_match('/relation "([a-z0-9_.]+)"/', $premiere, $m) === 1) {
            $parties[] = 'table '.$m[1];
        } elseif ($e instanceof QueryException && preg_match('/^\s*(?:insert into|update|delete from)\s+"?([a-z0-9_.]+)"?/i', $e->getSql(), $m) === 1) {
            $parties[] = 'table '.$m[1];
        }
        if (is_string($premiere) && preg_match('/constraint "([a-z0-9_]+)"/', $premiere, $m) === 1) {
            $parties[] = 'contrainte '.$m[1];
        }
        if (is_string($premiere) && preg_match('/(Archive ACMA [^.\n]*|Reprises ACMA [^.\n]*|Compléments ACMA [^.\n]*|Rapprochements ACMA [^.\n]*|Lien d\'un dossier ACMA [^.\n]*|Réinscriptions ACMA [^.\n]*|Matricule attribué [^.\n]*)/u', $premiere, $m) === 1) {
            $parties[] = $m[1];
        }

        return implode(', ', $parties);
    }
}
