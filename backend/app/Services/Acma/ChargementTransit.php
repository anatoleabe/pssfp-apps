<?php

declare(strict_types=1);

namespace App\Services\Acma;

use App\Models\User;
use App\Services\Acma\Reprise\RapportsReprise;
use App\Support\Journaux;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Lot 15a (spec du lot 15, §10.1, étape 4) : l'archive chiffrée remplace le schéma `acma_transit`. Le manifeste est
 * vérifié avant tout (empreintes, colonnes exclues), le nombre de lignes pendant le chargement ; en cas d'échec, la
 * transaction est annulée et le transit précédent reste intact. Les fichiers en clair sont supprimés dans tous les cas.
 *
 * SQL brut autorisé par l'ADR-0014 (décision 8) : création, chargement (COPY) et suppression du schéma de transit.
 * Toutes les colonnes sont en `text`, plus `_ligne`, le rang de la ligne dans le CSV (lecture par lots).
 */
final class ChargementTransit
{
    public const SCHEMA = 'acma_transit';

    private const LOT_COPY = 5000;

    /**
     * Marqueur NULL passé à COPY. PDO l'écrit dans une chaîne E'…' : il faut doubler la barre oblique pour que le
     * serveur reçoive `\N`, le marqueur par défaut du format texte (sinon NULL devient la lettre N).
     */
    private const NUL_COPY = '\\\\N';

    public function __construct(private readonly ArchiveChiffree $chiffrement) {}

    /** @return array{tables: int, lignes: int, manifeste_sha256: string} */
    public function charger(string $archive, string $phrase, User $auteur): array
    {
        if (! is_file($archive)) {
            throw new ErreurAcma('Archive introuvable.');
        }
        // Le dossier des rapports est créé par le disque (droits du groupe partagé avec PHP-FPM)
        // avant le temporaire en 0700 : créé par mkdir récursif, il serait fermé à l'écran des archives.
        Storage::disk(RapportsReprise::DISQUE)->makeDirectory(RapportsReprise::DOSSIER);
        // Restes en clair d'un chargement interrompu (processus tué) : purgés avant tout.
        File::deleteDirectory(storage_path('app/private/acma/tmp'));
        $temporaire = storage_path('app/private/acma/tmp/'.Str::uuid()->toString());
        try {
            $this->chiffrement->dechiffrer($archive, $temporaire, $phrase);
            $brut = @file_get_contents($temporaire.'/manifeste.json');
            if ($brut === false) {
                throw new ErreurAcma('Manifeste absent de l\'archive.');
            }
            $manifeste = json_decode($brut, true, 64, JSON_THROW_ON_ERROR);
            $definitions = self::verifierManifeste($manifeste);
            foreach ($definitions as $table => $definition) {
                $fichier = $temporaire.'/'.$definition['fichier'];
                if (! is_file($fichier) || ! hash_equals($definition['sha256'], (string) hash_file('sha256', $fichier))) {
                    throw new ErreurAcma("Empreinte du fichier de la table {$table} différente du manifeste : chargement refusé.");
                }
            }

            $lignes = $this->remplacerSchema(
                $definitions,
                fn (string $table, array $definition) => self::lignesCsv($temporaire.'/'.$definition['fichier'], $definition['colonnes']),
                $manifeste,
                hash('sha256', $brut),
                basename($archive),
                (int) $auteur->getKey(),
            );
        } catch (Throwable $e) {
            throw ErreurAcma::sansDonnees($e, 'du chargement');
        } finally {
            File::deleteDirectory($temporaire);
        }

        $resume = ['tables' => count($definitions), 'lignes' => $lignes, 'manifeste_sha256' => hash('sha256', $brut)];
        activity(Journaux::ARCHIVES)->causedBy($auteur)->event('transit_charge')
            ->withProperties($resume + ['export' => ['fichier' => $manifeste['source']['fichier'] ?? null, 'date' => $manifeste['source']['date_export'] ?? null]])
            ->log('Transit ACMA chargé');

        return $resume;
    }

    /**
     * Remplace le schéma de transit dans une transaction. $lignesDe($table, $definition) rend les lignes de la table.
     *
     * @param  array<string, array{fichier: string, colonnes: list<string>, lignes: int, sha256: string}>  $definitions
     * @param  callable(string, array<string, mixed>): iterable<int, list<string|null>>  $lignesDe
     * @param  array<string, mixed>  $manifeste
     */
    public function remplacerSchema(array $definitions, callable $lignesDe, array $manifeste, string $empreinte, ?string $archive, ?int $auteurId): int
    {
        return DB::transaction(function () use ($definitions, $lignesDe, $manifeste, $empreinte, $archive, $auteurId): int {
            // Jamais pendant une reprise, un gel ou une purge (verrou commun).
            VerrouAcma::prendre();
            DB::statement('DROP SCHEMA IF EXISTS '.self::SCHEMA.' CASCADE');
            DB::statement('CREATE SCHEMA '.self::SCHEMA);
            $total = 0;
            foreach ($definitions as $table => $definition) {
                $colonnes = array_map(fn (string $c): string => self::identifiant($c).' text', $definition['colonnes']);
                DB::statement('CREATE TABLE '.self::SCHEMA.'.'.self::identifiant($table).' (_ligne bigint PRIMARY KEY, '.implode(', ', $colonnes).')');
                $n = $this->copier($table, $definition['colonnes'], $lignesDe($table, $definition));
                if ($n !== $definition['lignes']) {
                    throw new ErreurAcma("Table {$table} : {$n} lignes lues, {$definition['lignes']} annoncées par le manifeste. Chargement refusé.");
                }
                $total += $n;
            }
            DB::statement('CREATE TABLE '.self::SCHEMA.'._chargement (manifeste jsonb NOT NULL, manifeste_sha256 text NOT NULL, archive text, charge_le timestamptz NOT NULL, charge_par bigint)');
            DB::table(self::SCHEMA.'._chargement')->insert([
                'manifeste' => json_encode($manifeste, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'manifeste_sha256' => $empreinte,
                'archive' => $archive,
                'charge_le' => now(),
                'charge_par' => $auteurId,
            ]);

            return $total;
        });
    }

    /** Le schéma de transit est-il chargé ? */
    public static function charge(): bool
    {
        return DB::table('information_schema.tables')->where('table_schema', self::SCHEMA)->where('table_name', '_chargement')->exists();
    }

    /** Supprime le schéma de transit. SQL brut autorisé par l'ADR-0014. */
    public static function purger(): void
    {
        DB::statement('DROP SCHEMA IF EXISTS '.self::SCHEMA.' CASCADE');
    }

    /**
     * @param  array<string, mixed>  $manifeste
     * @return array<string, array{fichier: string, colonnes: list<string>, lignes: int, sha256: string}>
     */
    public static function verifierManifeste(array $manifeste): array
    {
        if (($manifeste['format'] ?? null) !== ExtractionAcma::FORMAT || ! is_array($manifeste['tables'] ?? null)) {
            throw new ErreurAcma('Manifeste de format inconnu.');
        }
        $definitions = [];
        foreach ($manifeste['tables'] as $table => $t) {
            if (! in_array($table, ExtractionAcma::TABLES, true)) {
                throw new ErreurAcma("Table {$table} hors de la liste des tables utiles : chargement refusé.");
            }
            $colonnes = array_map(fn (array $c): string => (string) $c['nom'], (array) ($t['colonnes'] ?? []));
            // Liste blanche : exactement les colonnes lues par la reprise, aucune en trop, aucune en moins.
            $attendues = ExtractionAcma::COLONNES[$table];
            if (array_diff($colonnes, $attendues) !== [] || array_diff($attendues, $colonnes) !== [] || count($colonnes) !== count(array_unique($colonnes))) {
                throw new ErreurAcma("Colonnes refusées dans la table {$table} (hors de la liste blanche) : chargement refusé.");
            }
            foreach ($colonnes as $colonne) {
                self::identifiant($colonne);
            }
            if (! is_string($t['sha256'] ?? null) || ! is_int($t['lignes'] ?? null) || ($t['fichier'] ?? null) !== $table.'.csv') {
                throw new ErreurAcma("Manifeste incomplet pour la table {$table}.");
            }
            $definitions[$table] = ['fichier' => $t['fichier'], 'colonnes' => $colonnes, 'lignes' => $t['lignes'], 'sha256' => $t['sha256']];
        }
        $manquantes = array_diff(ExtractionAcma::TABLES, array_keys($definitions));
        if ($manquantes !== []) {
            throw new ErreurAcma('Tables absentes du manifeste : '.implode(', ', $manquantes));
        }

        return $definitions;
    }

    /** Identifiant SQL entre guillemets ; seuls lettres, chiffres, espace et soulignement sont admis. */
    public static function identifiant(string $nom): string
    {
        if (preg_match('/^[A-Za-z0-9_][A-Za-z0-9_ ]{0,62}\z/', $nom) !== 1) {
            throw new ErreurAcma('Identifiant refusé dans le manifeste.');
        }

        return '"'.$nom.'"';
    }

    /**
     * @param  list<string>  $colonnes
     * @return \Generator<int, list<string|null>>
     */
    private static function lignesCsv(string $fichier, array $colonnes): \Generator
    {
        $premier = true;
        foreach (CsvAcma::lire($fichier) as $ligne) {
            if ($premier) {
                if ($ligne !== $colonnes) {
                    throw new ErreurAcma('En-tête du CSV '.basename($fichier).' différent du manifeste.');
                }
                $premier = false;

                continue;
            }
            yield $ligne;
        }
    }

    /**
     * @param  list<string>  $colonnes
     * @param  iterable<int, list<string|null>>  $lignes
     */
    private function copier(string $table, array $colonnes, iterable $lignes): int
    {
        $pdo = DB::connection()->getPdo();
        $champs = '_ligne, '.implode(', ', array_map(self::identifiant(...), $colonnes));
        $cible = self::SCHEMA.'.'.self::identifiant($table);
        $nombre = count($colonnes);
        $lot = [];
        $rang = 0;
        try {
            foreach ($lignes as $ligne) {
                $rang++;
                if (count($ligne) !== $nombre) {
                    throw new ErreurAcma("Table {$table}, ligne {$rang} : nombre de colonnes inattendu.");
                }
                $lot[] = $rang."\t".implode("\t", array_map(self::texteCopy(...), $ligne));
                if (count($lot) >= self::LOT_COPY) {
                    $pdo->pgsqlCopyFromArray($cible, $lot, "\t", self::NUL_COPY, $champs);
                    $lot = [];
                }
            }
            if ($lot !== []) {
                $pdo->pgsqlCopyFromArray($cible, $lot, "\t", self::NUL_COPY, $champs);
            }
        } catch (Throwable $e) {
            // Le message du serveur peut citer une valeur : il ne va pas à la console.
            throw $e instanceof RuntimeException ? $e : new ErreurAcma("Chargement de la table {$table} impossible (ligne {$rang} environ).", 0, $e);
        }

        return $rang;
    }

    private static function texteCopy(?string $valeur): string
    {
        if ($valeur === null) {
            return '\\N';
        }

        return strtr($valeur, ['\\' => '\\\\', "\t" => '\\t', "\n" => '\\n', "\r" => '\\r', "\0" => '']);
    }
}
