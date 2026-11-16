<?php

declare(strict_types=1);

namespace App\Services\Acma;

use Illuminate\Support\Facades\File;

/**
 * Lot 15a (spec du lot 15, §10.1) : CSV des tables utiles d'ACMA et manifeste, sur le poste de l'USI, puis archive
 * chiffrée. Le dossier en clair est supprimé, même après un échec.
 *
 * Liste blanche (ADR-0014 et son avenant du 16/11/2026) : sortent d'ACMA toutes les données des auditeurs, coordonnées
 * comprises (format /3, spec de la réinscription des anciens §4.1), et les colonnes que la reprise, l'inventaire et le
 * rapport des écarts lisent. Restent dans l'export chiffré : mots de passe et identifiants FTP, clé de plateforme,
 * groupware, journal, identifiants du personnel d'ACMA, copies de l'identité dans les autres tables, colonnes de cumul
 * fausses. La même liste est revérifiée au chargement : une colonne en trop ou en moins fait refuser l'archive.
 */
final class ExtractionAcma
{
    public const FORMAT = 'pssfp-acma-transit/3';

    /** Coordonnées et autres colonnes de la fiche, ajoutées au format /3 (décision du 16/11/2026). */
    public const COORDONNEES = ['telephone', 'mobile', 'email', 'EmailB', 'adresse', 'region', 'RegionBis', 'departement', 'numcni', 'expression', 'Suspendu', 'idcampus'];

    /** Table → colonnes extraites, dans cet ordre. */
    public const COLONNES = [
        'anneeacademic' => ['anneeacademic', 'debutannee'],
        'attestationr' => ['Idattes', 'Matricule', 'Niveau', 'datedujour', 'specialite', 'session', 'Memoire', 'Parcourt'],
        'certificat' => ['IdCertificat', 'Matricule', 'Niveau', 'Datedujour', 'specialite', 'Parcourt'],
        'configurationdu' => ['IDdroitC', 'anneaacademic', 'Categorie', 'montantAregler', 'niveau', 'tranche1', 'tranche2', 'tranche3', 'inscription', 'TenuSport'],
        'du' => ['IddroitU', 'matricule', 'Niveau', 'anneacademic', 'Montant', 'inscriptions', 'bibliotheque', 'fraisperiscol', 'tenuesport', 'dateRecu', 'NumeroRecu', 'NomBanque', 'codephase'],
        'etudiant' => ['matricule', 'parcours', 'codcategorie', 'nometudiant', 'datenaissance', 'sexe', 'lieunaissance', 'nationalite', 'typedatenais', 'datenaisbis',
            'telephone', 'mobile', 'email', 'EmailB', 'adresse', 'region', 'RegionBis', 'departement', 'numcni', 'expression', 'Suspendu', 'idcampus'],
        'grpue' => ['codgrp', 'libelegrp', 'codniveau'],
        'inscription_academic' => ['id_inscription', 'matricule', 'anneeacademic', 'niveau', 'moydunivo', 'admis', 'redouble', 'Fraiscolarite', 'NbreDette', 'Totalcredit', 'parcours'],
        'misedispos' => ['Idmise', 'Matricule', 'Niveau', 'jourOperation', 'specialite', 'session', 'NoteMemoire', 'Mention', 'Memoire', 'Parcourt'],
        'nonperceptionb' => ['Idnonper', 'Matricule', 'Niveau', 'datedujour', 'Parcourt'],
        'notes' => ['idnote', 'idprog', 'codue', 'matricule', 'cc', 'tp', 'exam', 'ratra', 'ok', 'anneeacademic', 'dateoperation'],
        'notes_hist' => ['idnotes', 'changedon', 'idprog', 'codue', 'matricule', 'cc', 'tp', 'exem', 'Rattra', 'ok', 'validation'],
        'prisecours' => ['IdPrise', 'Matricule', 'Niveau', 'datedujour', 'Specialite', 'DebutPriseCours', 'FinpriseCours', 'themestage', 'structureAcueil', 'anneeacademique', 'dureestage', 'Parcourt'],
        'programe' => ['idprog', 'anneeacademic', 'codniveau', 'codgrp', 'codue', 'semestre', 'pcc', 'ptp', 'pexam', 'coef', 'credit', 'cm', 'tp', 'semin', 'tpe', 'tge'],
        'pvsoutenance' => ['idpvSoute', 'matricule', 'niveau', 'jourDeSoutence', 'Memoire', 'President', 'Rapporteur', 'Membre1', 'Membre2', 'NoteSoutenance', 'MentionSoutena', 'dateoperation', 'observation'],
        'ue' => ['codue', 'libeleue'],
    ];

    /** Tables extraites. */
    public const TABLES = [
        'anneeacademic', 'attestationr', 'certificat', 'configurationdu', 'du', 'etudiant', 'grpue', 'inscription_academic',
        'misedispos', 'nonperceptionb', 'notes', 'notes_hist', 'prisecours', 'programe', 'pvsoutenance', 'ue',
    ];

    public function __construct(private readonly ArchiveChiffree $chiffrement) {}

    /**
     * @param  array{fichier: string|null, sha256: string|null, date: string|null}  $export
     * @return array{archive: string, manifeste: array<string, mixed>}
     */
    public function extraire(SourceAcma $source, string $sortie, array $export, string $phrase): array
    {
        ArchiveChiffree::verifierPhrase($phrase);
        if (! is_dir($sortie) && ! mkdir($sortie, 0700, true)) {
            throw new ErreurAcma('Dossier de sortie impossible à créer.');
        }
        $horodatage = now()->format('Ymd-His');
        $dossier = rtrim($sortie, '/').'/acma-transit-'.$horodatage;
        $archive = $dossier.'.chiffre';
        if (! mkdir($dossier, 0700)) {
            throw new ErreurAcma('Dossier d\'extraction impossible à créer.');
        }
        $umask = umask(0077);
        try {
            $manifeste = $this->ecrire($source, $dossier, $export);
            file_put_contents($dossier.'/manifeste.json', json_encode($manifeste, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            $this->chiffrement->chiffrer($dossier, $archive, $phrase);
            chmod($archive, 0600);
        } catch (\Throwable $e) {
            @unlink($archive);
            throw ErreurAcma::sansDonnees($e, 'de l\'extraction');
        } finally {
            File::deleteDirectory($dossier);
            umask($umask);
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
            throw new ErreurAcma('Tables ACMA absentes de la source : '.implode(', ', $manquantes));
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
            // Pour mémoire : ce qui n'est pas sorti d'ACMA (noms seulement).
            'non_extraites' => [
                'tables' => array_values(array_diff($presentes, self::TABLES)),
                'colonnes' => array_map(fn (array $t): array => $t['non_extraites'], $tables),
            ],
        ];
    }

    /** @return array{fichier: string, colonnes: list<array{nom: string, type: string}>, lignes: int, sha256: string, utf8_corriges: int, non_extraites: list<string>} */
    private function ecrireTable(SourceAcma $source, string $table, string $dossier): array
    {
        $parNom = array_column($source->colonnes($table), null, 'nom');
        $manquantes = array_values(array_diff(self::COLONNES[$table], array_keys($parNom)));
        if ($manquantes !== []) {
            throw new ErreurAcma("Colonnes absentes de la table {$table} : ".implode(', ', $manquantes).'.');
        }
        // Liste blanche, dans son ordre ; jamais une colonne de plus.
        $colonnes = array_map(fn (string $nom): array => $parNom[$nom], self::COLONNES[$table]);
        $noms = array_column($colonnes, 'nom');
        $ordre = array_column(array_filter($colonnes, fn (array $c): bool => $c['cle']), 'nom');
        $binaires = array_map(fn (array $c): bool => (bool) preg_match('/binary|blob/i', $c['type']), $colonnes);

        $fichier = $table.'.csv';
        $flux = fopen($dossier.'/'.$fichier, 'wb');
        if ($flux === false) {
            throw new ErreurAcma('Écriture impossible : '.$fichier);
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
            'non_extraites' => array_values(array_diff(array_keys($parNom), $noms)),
        ];
    }
}
