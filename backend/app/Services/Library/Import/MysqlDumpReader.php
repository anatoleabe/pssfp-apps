<?php

declare(strict_types=1);

namespace App\Services\Library\Import;

use Generator;
use RuntimeException;

/**
 * Lecture en flux d'un dump MySQL texte (phpMyAdmin / mysqldump) sans base MySQL.
 *
 * Le fichier est lu ligne à ligne : seules la ligne courante et l'instruction INSERT
 * en cours d'analyse (tuples incomplets) sont gardées en mémoire. Les chaînes
 * échappées MySQL (\' \\ \n \r \t \0 \" \Z, '') sont décodées ; une chaîne qui
 * n'est pas de l'UTF-8 valide est convertie depuis Windows-1252.
 */
final class MysqlDumpReader
{
    private const UNESCAPE = [
        '\\0' => "\0",
        "\\'" => "'",
        '\\"' => '"',
        '\\b' => "\x08",
        '\\n' => "\n",
        '\\r' => "\r",
        '\\t' => "\t",
        '\\Z' => "\x1A",
        '\\\\' => '\\',
        "''" => "'",
    ];

    private const HEADER = '/^(?:INSERT(?:\s+IGNORE)?|REPLACE)\s+INTO\s+`([^`]+)`\s*(\([^)]*\))?\s*VALUES\b(.*)$/s';

    private const CREATE_TABLE = '/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`([^`]+)`/';

    public function __construct(private readonly string $path) {}

    /**
     * Parcourt les lignes des tables demandées, dans l'ordre du dump.
     *
     * @param  list<string>  $tables  noms complets (préfixe compris)
     * @return Generator<string, array<string, mixed>> clé = table, valeur = ligne (colonne => valeur)
     */
    public function rows(array $tables): Generator
    {
        $wanted = array_fill_keys($tables, true);
        $handle = is_file($this->path) && is_readable($this->path) ? fopen($this->path, 'rb') : false;
        if ($handle === false) {
            throw new RuntimeException("Dump illisible : {$this->path}");
        }

        try {
            yield from $this->scan($handle, $wanted);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  resource  $handle
     * @param  array<string, true>  $wanted
     * @return Generator<string, array<string, mixed>>
     */
    private function scan($handle, array $wanted): Generator
    {
        $schemas = [];
        $schemaTable = null;
        $statement = null;
        $buffer = '';
        $inString = false;
        $lineNumber = 0;

        while (($line = fgets($handle)) !== false) {
            $lineNumber++;

            if ($statement === null) {
                if ($schemaTable !== null) {
                    $schemaTable = $this->collectSchemaColumn($line, $schemaTable, $schemas);

                    continue;
                }
                if (preg_match(self::CREATE_TABLE, $line, $m) === 1) {
                    $schemaTable = isset($wanted[$m[1]]) ? $m[1] : null;
                    if ($schemaTable !== null) {
                        $schemas[$schemaTable] = [];
                    }

                    continue;
                }
                if (! str_starts_with($line, 'INSERT') && ! str_starts_with($line, 'REPLACE')) {
                    continue;
                }
                $statement = $this->openStatement($line, $wanted, $schemas, $lineNumber);
                $line = $statement['rest'];
                $buffer = '';
                $inString = false;
            }

            $inString = $this->togglesString($line, $inString);

            if (! $statement['wanted']) {
                if (! $inString && str_ends_with(rtrim($line), ';')) {
                    $statement = null;
                }

                continue;
            }

            $buffer .= $line;
            if ($inString) {
                continue;
            }

            [$tuples, $buffer, $ended] = $this->consume($buffer, $statement['table'], $lineNumber);
            foreach ($tuples as $values) {
                yield $statement['table'] => $this->combine($statement['table'], $statement['columns'], $values, $lineNumber);
            }
            if ($ended) {
                $statement = null;
                $buffer = '';
            }
        }

        if ($statement !== null) {
            throw new RuntimeException("Dump tronqué : instruction INSERT de `{$statement['table']}` non terminée.");
        }
    }

    /**
     * @param  array<string, list<string>>  $schemas
     */
    private function collectSchemaColumn(string $line, string $table, array &$schemas): ?string
    {
        if (str_starts_with(ltrim($line), ')')) {
            return null;
        }
        if (preg_match('/^\s+`([^`]+)`\s/', $line, $m) === 1) {
            $schemas[$table][] = $m[1];
        }

        return $table;
    }

    /**
     * @param  array<string, true>  $wanted
     * @param  array<string, list<string>>  $schemas
     * @return array{table: string, columns: list<string>, wanted: bool, rest: string}
     */
    private function openStatement(string $line, array $wanted, array $schemas, int $lineNumber): array
    {
        if (preg_match(self::HEADER, $line, $m) !== 1) {
            // Instruction non reconnue : ignorée, mais suivie pour en trouver la fin.
            return ['table' => '?', 'columns' => [], 'wanted' => false, 'rest' => $line];
        }

        $table = $m[1];
        if (! isset($wanted[$table])) {
            return ['table' => $table, 'columns' => [], 'wanted' => false, 'rest' => $m[3]];
        }

        if ($m[2] !== '') {
            preg_match_all('/`([^`]+)`/', $m[2], $names);
            $columns = $names[1];
        } else {
            $columns = $schemas[$table] ?? [];
        }
        if ($columns === []) {
            throw new RuntimeException("Colonnes inconnues pour `{$table}` (ligne {$lineNumber}).");
        }

        return ['table' => $table, 'columns' => $columns, 'wanted' => true, 'rest' => $m[3]];
    }

    /** Indique si l'on est dans une chaîne à la fin de la ligne (parité des apostrophes non échappées). */
    private function togglesString(string $line, bool $inString): bool
    {
        if (! str_contains($line, "'")) {
            return $inString;
        }
        $quotes = substr_count(str_replace(['\\\\', "\\'"], '', $line), "'");

        return $quotes % 2 === 1 ? ! $inString : $inString;
    }

    /**
     * Extrait les tuples complets du tampon ; le reste (tuple incomplet) est rendu.
     *
     * @return array{0: list<list<mixed>>, 1: string, 2: bool}
     */
    private function consume(string $buffer, string $table, int $lineNumber): array
    {
        $tuples = [];
        $length = strlen($buffer);
        $pos = 0;
        $ended = false;

        while (true) {
            $pos += strspn($buffer, " \t\r\n,", $pos);
            if ($pos >= $length) {
                break;
            }
            if ($buffer[$pos] === ';') {
                $ended = true;
                $pos++;
                break;
            }
            if ($buffer[$pos] !== '(') {
                throw new RuntimeException("Syntaxe inattendue dans l'INSERT de `{$table}` (ligne {$lineNumber}).");
            }
            $tuple = $this->tuple($buffer, $pos, $table, $lineNumber);
            if ($tuple === null) {
                break;
            }
            [$tuples[], $pos] = $tuple;
        }

        return [$tuples, substr($buffer, $pos), $ended];
    }

    /**
     * Analyse un tuple qui commence à $start ('('). Null si le tuple est incomplet.
     *
     * @return array{0: list<mixed>, 1: int}|null
     */
    private function tuple(string $buffer, int $start, string $table, int $lineNumber): ?array
    {
        $length = strlen($buffer);
        $values = [];
        $pos = $start + 1;

        while (true) {
            $pos += strspn($buffer, " \t\r\n", $pos);
            if ($pos >= $length) {
                return null;
            }
            if ($buffer[$pos] === ')' && $values === []) {
                return [[], $pos + 1];
            }

            if ($buffer[$pos] === "'") {
                $end = $this->stringEnd($buffer, $pos + 1);
                if ($end === null) {
                    return null;
                }
                $values[] = $this->decodeString(substr($buffer, $pos + 1, $end - $pos - 1));
                $pos = $end + 1;
            } else {
                $size = strcspn($buffer, ",) \t\r\n", $pos);
                if ($size === 0 || $pos + $size >= $length) {
                    return null;
                }
                $values[] = $this->literal(substr($buffer, $pos, $size));
                $pos += $size;
            }

            $pos += strspn($buffer, " \t\r\n", $pos);
            if ($pos >= $length) {
                return null;
            }
            if ($buffer[$pos] === ')') {
                return [$values, $pos + 1];
            }
            if ($buffer[$pos] !== ',') {
                throw new RuntimeException("Tuple mal formé dans l'INSERT de `{$table}` (ligne {$lineNumber}).");
            }
            $pos++;
        }
    }

    /** Position de l'apostrophe fermante d'une chaîne ouverte juste avant $pos. */
    private function stringEnd(string $buffer, int $pos): ?int
    {
        $length = strlen($buffer);
        while ($pos < $length) {
            $pos += strcspn($buffer, "'\\", $pos);
            if ($pos >= $length) {
                return null;
            }
            if ($buffer[$pos] === '\\') {
                $pos += 2;

                continue;
            }
            if ($pos + 1 < $length && $buffer[$pos + 1] === "'") {
                $pos += 2;

                continue;
            }

            return $pos;
        }

        return null;
    }

    private function decodeString(string $raw): string
    {
        $value = str_contains($raw, '\\') || str_contains($raw, "''") ? strtr($raw, self::UNESCAPE) : $raw;

        return mb_check_encoding($value, 'UTF-8') ? $value : mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
    }

    private function literal(string $token): int|float|string|null
    {
        if (strcasecmp($token, 'NULL') === 0) {
            return null;
        }
        $int = filter_var($token, FILTER_VALIDATE_INT);
        if ($int !== false) {
            return $int;
        }

        return is_numeric($token) ? (float) $token : $token;
    }

    /**
     * @param  list<string>  $columns
     * @param  list<mixed>  $values
     * @return array<string, mixed>
     */
    private function combine(string $table, array $columns, array $values, int $lineNumber): array
    {
        if (count($columns) !== count($values)) {
            throw new RuntimeException(sprintf(
                'Ligne de `%s` incohérente (ligne %d) : %d valeurs pour %d colonnes.',
                $table, $lineNumber, count($values), count($columns),
            ));
        }

        return array_combine($columns, $values);
    }
}
