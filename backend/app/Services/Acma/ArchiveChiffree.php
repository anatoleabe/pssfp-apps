<?php

declare(strict_types=1);

namespace App\Services\Acma;

use RuntimeException;
use ZipArchive;

/**
 * Archive chiffrée du transit ACMA (spec du lot 15, §10.1) : les fichiers d'un dossier, mis dans un zip, puis chiffrés
 * en AES-256-GCM par morceaux d'un mébioctet. La clé dérive de la phrase de passe de l'USI (PBKDF2-SHA256).
 *
 * Chaque morceau a son étiquette, son numéro et un drapeau « dernier » dans les données authentifiées : une archive
 * altérée, réordonnée ou tronquée est refusée.
 */
final class ArchiveChiffree
{
    private const MAGIQUE = "PSSFP-ACMA-1\n";

    public const ITERATIONS = 600_000;

    private const MORCEAU = 1_048_576;

    private const LONGUEUR_MINIMALE_PHRASE = 12;

    public function __construct(private readonly int $iterations = self::ITERATIONS) {}

    /** Chiffre les fichiers (à plat) de $dossier dans $cible. */
    public function chiffrer(string $dossier, string $cible, string $phrase): void
    {
        self::verifierPhrase($phrase);
        $zip = $cible.'.zip.tmp';
        try {
            $archive = new ZipArchive;
            if ($archive->open($zip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Création du zip impossible.');
            }
            foreach (glob(rtrim($dossier, '/').'/*') ?: [] as $fichier) {
                if (is_file($fichier)) {
                    $archive->addFile($fichier, basename($fichier));
                }
            }
            $archive->close();
            $this->chiffrerFichier($zip, $cible, $phrase);
        } finally {
            @unlink($zip);
        }
    }

    /**
     * Déchiffre $source dans $dossier (créé, privé). Seuls des fichiers à plat sont acceptés.
     *
     * @return list<string> noms des fichiers extraits
     */
    public function dechiffrer(string $source, string $dossier, string $phrase): array
    {
        if (! is_dir($dossier) && ! mkdir($dossier, 0700, true)) {
            throw new RuntimeException('Dossier temporaire impossible à créer.');
        }
        $zip = rtrim($dossier, '/').'/.archive.zip';
        try {
            $this->dechiffrerFichier($source, $zip, $phrase);
            $archive = new ZipArchive;
            if ($archive->open($zip) !== true) {
                throw new RuntimeException('Archive illisible après déchiffrement.');
            }
            $noms = [];
            for ($i = 0; $i < $archive->numFiles; $i++) {
                $nom = (string) $archive->getNameIndex($i);
                if (preg_match('/^[a-z0-9_]+\.(csv|json)$/', $nom) !== 1) {
                    $archive->close();
                    throw new RuntimeException('Nom de fichier refusé dans l\'archive.');
                }
                $noms[] = $nom;
            }
            if (! $archive->extractTo($dossier)) {
                $archive->close();
                throw new RuntimeException('Extraction de l\'archive impossible.');
            }
            $archive->close();
            foreach ($noms as $nom) {
                chmod($dossier.'/'.$nom, 0600);
            }

            return $noms;
        } finally {
            @unlink($zip);
        }
    }

    public static function verifierPhrase(string $phrase): void
    {
        if (mb_strlen($phrase) < self::LONGUEUR_MINIMALE_PHRASE) {
            throw new RuntimeException('La phrase de passe doit compter au moins '.self::LONGUEUR_MINIMALE_PHRASE.' caractères.');
        }
    }

    private function chiffrerFichier(string $source, string $cible, string $phrase): void
    {
        $sel = random_bytes(16);
        $prefixe = random_bytes(8);
        $entete = self::MAGIQUE.$sel.pack('N', $this->iterations).$prefixe;
        $cle = hash_pbkdf2('sha256', $phrase, $sel, $this->iterations, 32, true);

        $entree = fopen($source, 'rb');
        $sortie = fopen($cible, 'wb');
        if ($entree === false || $sortie === false) {
            throw new RuntimeException('Fichiers de chiffrement inaccessibles.');
        }
        try {
            fwrite($sortie, $entete);
            $numero = 0;
            $morceau = (string) fread($entree, self::MORCEAU);
            do {
                $suivant = (string) fread($entree, self::MORCEAU);
                $dernier = $suivant === '' ? 1 : 0;
                $aad = $entete.pack('NC', $numero, $dernier);
                $etiquette = '';
                $chiffre = openssl_encrypt($morceau, 'aes-256-gcm', $cle, OPENSSL_RAW_DATA, $prefixe.pack('N', $numero), $etiquette, $aad, 16);
                if ($chiffre === false) {
                    throw new RuntimeException('Chiffrement impossible.');
                }
                fwrite($sortie, pack('CN', $dernier, strlen($chiffre)).$chiffre.$etiquette);
                $morceau = $suivant;
                $numero++;
            } while ($dernier === 0);
        } finally {
            fclose($entree);
            fclose($sortie);
        }
    }

    private function dechiffrerFichier(string $source, string $cible, string $phrase): void
    {
        $entree = fopen($source, 'rb');
        if ($entree === false) {
            throw new RuntimeException('Archive illisible.');
        }
        $sortie = fopen($cible, 'wb');
        if ($sortie === false) {
            fclose($entree);
            throw new RuntimeException('Dossier temporaire inaccessible.');
        }
        try {
            $entete = (string) fread($entree, strlen(self::MAGIQUE) + 28);
            if (strlen($entete) !== strlen(self::MAGIQUE) + 28 || ! str_starts_with($entete, self::MAGIQUE)) {
                throw new RuntimeException('Ce fichier n\'est pas une archive de transit ACMA.');
            }
            $sel = substr($entete, strlen(self::MAGIQUE), 16);
            $iterations = (int) unpack('N', substr($entete, strlen(self::MAGIQUE) + 16, 4))[1];
            $prefixe = substr($entete, strlen(self::MAGIQUE) + 20, 8);
            $cle = hash_pbkdf2('sha256', $phrase, $sel, $iterations, 32, true);

            $numero = 0;
            do {
                $tete = (string) fread($entree, 5);
                if (strlen($tete) !== 5) {
                    throw new RuntimeException('Archive tronquée.');
                }
                ['d' => $dernier, 'l' => $longueur] = unpack('Cd/Nl', $tete);
                if ($longueur > self::MORCEAU) {
                    throw new RuntimeException('Archive altérée.');
                }
                $chiffre = $longueur > 0 ? (string) fread($entree, $longueur) : '';
                $etiquette = (string) fread($entree, 16);
                $clair = openssl_decrypt($chiffre, 'aes-256-gcm', $cle, OPENSSL_RAW_DATA, $prefixe.pack('N', $numero), $etiquette, $entete.pack('NC', $numero, $dernier));
                if ($clair === false) {
                    throw new RuntimeException('Déchiffrement refusé : phrase de passe erronée ou archive altérée.');
                }
                fwrite($sortie, $clair);
                $numero++;
            } while ($dernier === 0);
            if (fread($entree, 1) !== '') {
                throw new RuntimeException('Archive altérée : données après la fin.');
            }
        } finally {
            fclose($entree);
            fclose($sortie);
        }
    }
}
