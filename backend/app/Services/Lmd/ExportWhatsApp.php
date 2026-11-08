<?php

declare(strict_types=1);

namespace App\Services\Lmd;

use App\Models\ActiviteAnimation;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Normalizer;
use ZipArchive;

/**
 * Mesure de l'animation d'un cours dans l'export d'un groupe WhatsApp (D25 ; port de reprise/27_import_whatsapp.py).
 *
 * Protection des données : le fichier n'est lu que pour compter les messages de l'enseignant ; ni le contenu des
 * messages, ni les messages des auditeurs, ni le fichier ne sont conservés. Seuls les comptages et l'empreinte restent.
 */
class ExportWhatsApp
{
    /** Taille maximale du texte de l'export, décompressé (octets). */
    public const TAILLE_MAX = 10 * 1024 * 1024;

    /** Android : « 29/10/2026 15:26 - Nom: texte » (ou « 29/10/2026 à 15:26 ») ; iPhone : « [29/10/2026 15:26:03] Nom: texte ». */
    private const LIGNE = '/^\x{200E}?\[?(\d{1,2})\/(\d{1,2})\/(\d{2,4})(?:,| a| à)? (\d{1,2}):(\d{2})(?::\d{2})?\]?(?: -)? ([^:]+?): (.*)$/u';

    private const MEDIA = '/(m[ée]dias? omis|media omitted|fichier joint|file attached|pi[eè]ce jointe|<attached:|document omis|document omitted|image absente|image omitted|vid[ée]o absente|video omitted|\.pdf\b|\.docx?\b|\.pptx?\b|\.xlsx?\b)/iu';

    /**
     * Lit le texte de l'export (.txt, ou .zip de l'iPhone contenant _chat.txt) et calcule l'empreinte SHA-256 du fichier reçu.
     *
     * @return array{texte: string, empreinte: string}
     */
    public function lire(string $chemin): array
    {
        $taille = @filesize($chemin);
        if ($taille === false || $taille === 0) {
            throw new InvalidArgumentException('Le fichier est vide ou illisible.');
        }
        if ($taille > self::TAILLE_MAX) {
            throw new InvalidArgumentException('Le fichier dépasse 10 Mo : exportez la discussion sans médias.');
        }
        $empreinte = (string) hash_file('sha256', $chemin);
        $debut = (string) file_get_contents($chemin, false, null, 0, 4);
        $texte = str_starts_with($debut, "PK\x03\x04") ? $this->texteArchive($chemin) : (string) file_get_contents($chemin);
        $texte = mb_scrub(preg_replace('/^\x{FEFF}/u', '', mb_scrub($texte, 'UTF-8')) ?? '', 'UTF-8');

        return ['texte' => $texte, 'empreinte' => $empreinte];
    }

    /**
     * Compte, pour l'enseignant et sur la période, les documents partagés, les jours d'animation et les messages.
     *
     * @return array{comptes: array<string, int>, lignes: int}
     */
    public function compter(#[\SensitiveParameter] string $texte, #[\SensitiveParameter] string $auteur, CarbonImmutable $du, CarbonImmutable $au): array
    {
        $cible = self::normaliser($auteur);
        if ($cible === '') {
            throw new InvalidArgumentException('Indiquez le nom ou le numéro de l\'enseignant tel qu\'il apparaît dans le groupe.');
        }
        [$debut, $fin] = [$du->format('Y-m-d'), $au->format('Y-m-d')];
        $jours = [];
        $documents = 0;
        $messages = 0;
        $lues = 0;
        // Lecture ligne à ligne, sans construire le tableau des lignes (fichier de 10 Mo au plus).
        for ($ligne = strtok($texte, "\r\n"); $ligne !== false; $ligne = strtok("\r\n")) {
            if (preg_match(self::LIGNE, $ligne, $m) !== 1) {
                continue;   // suite d'un message sur plusieurs lignes, ou message du système
            }
            $annee = (int) $m[3] + (strlen($m[3]) === 2 ? 2000 : 0);
            if (! checkdate((int) $m[2], (int) $m[1], $annee)) {
                continue;
            }
            $lues++;
            $jour = sprintf('%04d-%02d-%02d', $annee, (int) $m[2], (int) $m[1]);
            if ($jour < $debut || $jour > $fin || self::normaliser($m[6]) !== $cible) {
                continue;
            }
            $jours[$jour] = true;
            if (preg_match(self::MEDIA, $m[7]) === 1) {
                $documents++;
            } else {
                $messages++;   // texte ou vocal
            }
        }
        if ($lues === 0) {
            throw new InvalidArgumentException('Aucune ligne reconnue : ce n\'est pas un export de discussion WhatsApp (Android ou iPhone).');
        }

        return [
            'comptes' => [ActiviteAnimation::DOCUMENTS => $documents, ActiviteAnimation::JOURS => count($jours), ActiviteAnimation::MESSAGES => $messages],
            'lignes' => $lues,
        ];
    }

    /** Sans accents, sans casse, sans espaces ni ponctuation ; le « + » des numéros est gardé. */
    public static function normaliser(string $t): string
    {
        $t = Normalizer::normalize($t, Normalizer::FORM_KD) ?: $t;
        $t = (string) preg_replace('/\p{Mn}/u', '', $t);

        return (string) preg_replace('/[^a-z0-9+]/', '', mb_strtolower($t));
    }

    private function texteArchive(string $chemin): string
    {
        $zip = new ZipArchive;
        if ($zip->open($chemin, ZipArchive::RDONLY) !== true) {
            throw new InvalidArgumentException('L\'archive est illisible.');
        }
        try {
            $index = $zip->locateName('_chat.txt', ZipArchive::FL_NODIR);
            for ($i = 0; $index === false && $i < $zip->numFiles; $i++) {
                if (str_ends_with(strtolower((string) $zip->getNameIndex($i)), '.txt')) {
                    $index = $i;
                }
            }
            if ($index === false) {
                throw new InvalidArgumentException('L\'archive ne contient pas de discussion (fichier texte).');
            }
            $stat = $zip->statIndex($index);
            if ($stat === false || $stat['size'] > self::TAILLE_MAX) {
                throw new InvalidArgumentException('La discussion décompressée dépasse 10 Mo.');
            }
            $texte = $zip->getFromIndex($index, self::TAILLE_MAX + 1);
            if ($texte === false || strlen($texte) > self::TAILLE_MAX) {
                throw new InvalidArgumentException('La discussion de l\'archive est illisible ou dépasse 10 Mo.');
            }

            return $texte;
        } finally {
            $zip->close();
        }
    }
}
