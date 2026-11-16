<?php

declare(strict_types=1);

namespace App\Services\Acma;

use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Lot 15a (spec du lot 15, §10.1) : CSV des tables utiles d'ACMA et manifeste, sur le poste de l'USI, puis archive
 * chiffrée. Le dossier en clair est supprimé, même après un échec.
 *
 * Ne sortent jamais d'ACMA (listes figées, ADR-0014) : la table `utilisateur` (mots de passe en clair), les identifiants
 * FTP et la clé de la plateforme (`etablissement`), le groupware et le journal d'ACMA. Les coordonnées et la région des
 * auditeurs ne sortent pas non plus : ni l'archive ni le rapport des écarts ne s'en servent (§6.2, Q16).
 */
final class ExtractionAcma
{
    public const FORMAT = 'pssfp-acma-transit/1';

    /** Tables utiles (§3.1), seules extraites. */
    public const TABLES = [
        'anneeacademic', 'attestationr', 'categorie', 'certificat', 'configurationdu', 'du', 'etablissement', 'etudiant',
        'filiere', 'grpue', 'inscription_academic', 'misedispos', 'niveau', 'nonperceptionb', 'notes', 'notes_hist',
        'phasepayement', 'prisecours', 'programe', 'pvsoutenance', 'ue',
    ];

    /** Colonnes jamais extraites, même si la table l'est. */
    public const COLONNES_EXCLUES = [
        'etablissement' => ['userftp', 'paswdftp', 'serverftp', 'repftp', 'keyplateforme', 'plateforme'],
        'etudiant' => ['telephone', 'mobile', 'adresse', 'email', 'EmailB', 'numcni', 'region', 'RegionBis', 'departement'],
    ];

    /** Tables jamais extraites, citées au manifeste pour mémoire (les autres tables absentes de TABLES le sont aussi). */
    public const TABLES_EXCLUES = ['utilisateur', 'tachesutili', 'etudiant_hist', 'ue_hist', 'grpue_hist', 'encaissement'];

    public function __construct(private readonly ArchiveChiffree $chiffrement) {}

    /**
     * @param  array{fichier: string|null, sha256: string|null, date: string|null}  $export
     * @return array{archive: string, manifeste: array<string, mixed>}
     */
    public function extraire(SourceAcma $source, string $sortie, array $export, string $phrase): array
    {
        ArchiveChiffree::verifierPhrase($phrase);
        if (! is_dir($sortie) && ! mkdir($sortie, 0700, true)) {
            throw new RuntimeException('Dossier de sortie impossible à créer.');
        }
        $horodatage = now()->format('Ymd-His');
        $dossier = rtrim($sortie, '/').'/acma-transit-'.$horodatage;
        $archive = $dossier.'.chiffre';
        if (! mkdir($dossier, 0700)) {
            throw new RuntimeException('Dossier d\'extraction impossible à créer.');
        }
        try {
            $manifeste = $this->ecrire($source, $dossier, $export);
            file_put_contents($dossier.'/manifeste.json', json_encode($manifeste, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            $this->chiffrement->chiffrer($dossier, $archive, $phrase);
            chmod($archive, 0600);
        } catch (\Throwable $e) {
            @unlink($archive);
            throw $e;
        } finally {
            File::deleteDirectory($dossier);
        }

        return ['archive' => $archive, 'manifeste' => $manifeste];
    }

    /**
     * @param  array{fichier: string|null, sha256: string|null, date: string|null}  $export
     * @return array<string, mixed>
     */
    private function ecrire(SourceAcma $source, string $dossier, array $export): array
    {
        $presentes = $source->tables();
        $manquantes = array_values(array_diff(self::TABLES, $presentes));
        if ($manquantes !== []) {
            throw new RuntimeException('Tables ACMA absentes de la source : '.implode(', ', $manquantes));
        }
        $serveur = $source->serveur();
        $tables = [];
        foreach (self::TABLES as $table) {
            $tables[$table] = $this->ecrireTable($source, $table, $dossier);
        }

        return [
            'format' => self::FORMAT,
            'cree_le' => now()->toIso8601String(),
            'source' => [
                'fichier' => $export['fichier'],
                'sha256' => $export['sha256'],
                'date_export' => $export['date'],
                'version_serveur' => $serveur['version'],
                'base' => $serveur['base'],
            ],
            'tables' => $tables,
            'exclusions' => [
                'tables' => array_values(array_diff($presentes, self::TABLES)),
                'colonnes' => self::COLONNES_EXCLUES,
            ],
        ];
    }

    /** @return array{fichier: string, colonnes: list<array{nom: string, type: string}>, lignes: int, sha256: string, utf8_corriges: int} */
    private function ecrireTable(SourceAcma $source, string $table, string $dossier): array
    {
        $exclues = self::COLONNES_EXCLUES[$table] ?? [];
        $colonnes = array_values(array_filter($source->colonnes($table), fn (array $c): bool => ! in_array($c['nom'], $exclues, true)));
        $noms = array_column($colonnes, 'nom');
        $ordre = array_column(array_filter($colonnes, fn (array $c): bool => $c['cle']), 'nom');
        $binaires = array_map(fn (array $c): bool => (bool) preg_match('/binary|blob/i', $c['type']), $colonnes);

        $fichier = $table.'.csv';
        $flux = fopen($dossier.'/'.$fichier, 'wb');
        if ($flux === false) {
            throw new RuntimeException('Écriture impossible : '.$fichier);
        }
        $lignes = 0;
        $corriges = 0;
        try {
            fwrite($flux, CsvAcma::ligne($noms));
            foreach ($source->lignes($table, $noms, $ordre) as $ligne) {
                foreach ($ligne as $i => $valeur) {
                    if ($valeur === null) {
                        continue;
                    }
                    if ($binaires[$i]) {
                        $ligne[$i] = bin2hex($valeur);
                    } elseif (! mb_check_encoding($valeur, 'UTF-8')) {
                        $ligne[$i] = mb_convert_encoding($valeur, 'UTF-8', 'UTF-8');
                        $corriges++;
                    }
                }
                fwrite($flux, CsvAcma::ligne($ligne));
                $lignes++;
            }
        } finally {
            fclose($flux);
        }

        return [
            'fichier' => $fichier,
            'colonnes' => array_map(fn (array $c): array => ['nom' => $c['nom'], 'type' => $c['type']], $colonnes),
            'lignes' => $lignes,
            'sha256' => (string) hash_file('sha256', $dossier.'/'.$fichier),
            'utf8_corriges' => $corriges,
        ];
    }
}
