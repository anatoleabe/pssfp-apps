<?php

declare(strict_types=1);

namespace App\Services\Library\Import;

use Illuminate\Support\Str;

/**
 * Normalisation des textes de l'ancienne bibliothèque Joomla (plan 3, task 3) :
 * titres, résumés, année, langue, slug. Fonctions pures, sans base ni configuration.
 */
final class LegacyText
{
    public const SLUG_MAX_LENGTH = 160;

    private const MIN_YEAR = 1900;

    private const MAX_YEAR = 2100;

    /** Part minimale de majuscules parmi les lettres pour traiter un titre comme « crié ». */
    private const SHOUTING_RATIO = 0.8;

    private const MONTHS = 'janvier|janv|jan|january|fevrier|février|fevr|févr|fev|fév|february|feb|mars|march|mar'
        .'|avril|april|avr|apr|mai|may|juin|june|jun|juillet|july|juil|jul|aout|août|august|aug'
        .'|septembre|september|sept|sep|octobre|october|oct|novembre|november|nov|decembre|décembre|december|dec|déc';

    private const VOWELS = 'aeiouyàâäéèêëîïôöùûüÿœæ';

    /** Montant plutôt qu'année : « 2500 FCFA », « 1500 milliards ». */
    private const AMOUNT_UNITS = 'f\\s*cfa|fcfa|francs?|f|milliards?|millions?|mds?';

    /** Mots après lesquels I, V ou X seul est un chiffre romain (« TITRE V », « LIVRE I »). */
    private const ROMAN_CONTEXT = ['titre', 'livre', 'annexe', 'chapitre', 'tome', 'partie', 'section', 'volume', 'article'];

    private const BLOCK_TAGS = 'p|div|h[1-6]|ul|ol|blockquote|table|tr|section|article|header|footer|pre';

    public static function title(string $raw): string
    {
        $text = self::decodeEntities($raw);
        $text = strip_tags($text);
        $text = str_replace('_', ' ', $text);
        $text = (string) preg_replace('/[\p{Z}\s]+/u', ' ', $text);
        // Artefacts des téléversements Joomla : « cle1ceb3f 1 cle0b1ebf ».
        $text = (string) preg_replace('/\s*\bcle[0-9a-f]{6}\b(?:\s+\d{1,2}(?=\s+cle[0-9a-f]{6}\b))?/iu', '', $text);
        $text = (string) preg_replace('/\.pdf\b/iu', '', $text);
        $text = (string) preg_replace('/(?:[\s.\-]+|(?<=\p{L}))pdf(?:\s+\d{1,2})?\s*$/iu', '', $text);
        $text = (string) preg_replace('/[’‘ʼ´`]/u', "'", $text);
        $text = trim((string) preg_replace('/ {2,}/', ' ', $text));

        if (self::isShouting($text)) {
            $text = self::sentenceCase($text, self::language($text));
        }
        $text = trim((string) preg_replace('/ {2,}/', ' ', $text));

        return (string) preg_replace_callback('/^([^\p{L}]*)(\p{L})/u', fn (array $m): string => $m[1].mb_strtoupper($m[2]), $text);
    }

    /** Description Joomla (HTML) → texte brut, paragraphes séparés par une ligne vide ; vide → null. */
    public static function abstract(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return null;
        }

        $text = (string) preg_replace('#<(script|style)\b[^>]*>.*?</\1\s*>#isu', '', $html);
        $text = (string) preg_replace('#<br\s*/?>#iu', "\n", $text);
        $text = (string) preg_replace('#</?(?:'.self::BLOCK_TAGS.')\b[^>]*>#iu', "\n\n", $text);
        $text = (string) preg_replace('#</li\s*>#iu', "\n", $text);
        $text = self::decodeEntities(strip_tags($text));

        $lines = array_map(
            fn (string $line): string => trim((string) preg_replace('/[^\S\n]+/u', ' ', $line)),
            explode("\n", (string) preg_replace('/[\p{Zs}\t\r\f]/u', ' ', $text)),
        );
        $text = trim((string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)));

        return $text === '' ? null : $text;
    }

    /**
     * Année déclarée si plausible (1900–2100) ; sinon année d'une date complète du titre
     * (« du 14 avril 2008 », la date de signature d'un texte) ; sinon dernière année à
     * 4 chiffres plausible du titre ; sinon null — jamais la date d'import. Une année du
     * titre au-delà de l'an prochain est un horizon (« Vision 2035 »), pas une date ; un
     * nombre suivi d'une unité monétaire (« 2500 FCFA », « 1500 milliards ») non plus.
     */
    public static function year(?int $declared, string $title, ?int $currentYear = null): ?int
    {
        if ($declared !== null && $declared >= self::MIN_YEAR && $declared <= self::MAX_YEAR) {
            return $declared;
        }

        $latest = min(self::MAX_YEAR, ($currentYear ?? (int) date('Y')) + 1);
        $plausible = fn (int $year): bool => $year >= self::MIN_YEAR && $year <= $latest;

        preg_match_all('/(?<!\d)(?:1er|\d{1,2})\s+(?:'.self::MONTHS.')\.?\s+(\d{4})(?!\d)/iu', $title, $dated);
        foreach ($dated[1] as $year) {
            if ($plausible((int) $year)) {
                return (int) $year;
            }
        }

        preg_match_all('/(?<!\d)\d{4}(?!\d)(?!\s*(?:'.self::AMOUNT_UNITS.')(?!\p{L}))/iu', $title, $years);
        foreach (array_reverse($years[0]) as $year) {
            if ($plausible((int) $year)) {
                return (int) $year;
            }
        }

        return null;
    }

    /**
     * `en` si le titre a, en mots distincts, au moins 2 marqueurs anglais forts ou 1 fort et
     * 2 faibles (public, state, control, management : aussi en usage en français), et aucun
     * mot français courant ; sinon `fr`.
     */
    public static function language(string $title): string
    {
        $words = array_unique(preg_split('/[^\p{L}]+/u', mb_strtolower($title), -1, PREG_SPLIT_NO_EMPTY) ?: []);
        $strong = count(array_intersect($words, TitleLexicon::ENGLISH_MARKERS));
        $weak = count(array_intersect($words, TitleLexicon::WEAK_ENGLISH_MARKERS));
        $french = count(array_intersect($words, TitleLexicon::FRENCH_MARKERS));

        return ($strong >= 2 || ($strong >= 1 && $weak >= 2)) && $french === 0 ? 'en' : 'fr';
    }

    /**
     * Mots de 2 à 7 lettres écrits en majuscules dans des titres « criés » et remis en
     * minuscules par title(), présents dans au moins $minTitles titres et jamais vus en
     * minuscules dans un titre en casse mixte ni connus du lexique (mots outils, mots
     * d'élision) : candidats sigles absents du lexique, à relire (beaucoup de mots ordinaires)
     * (affichés par le dry-run de l'import). Clés en majuscules, triées par fréquence.
     *
     * @param  iterable<string>  $titles  titres bruts
     * @return array<string, int>
     */
    public static function lowercasedUppercaseWords(iterable $titles, int $minTitles = 3): array
    {
        $counts = [];
        $ordinary = [];
        foreach ($titles as $raw) {
            $clean = self::decodeEntities(strip_tags($raw));
            preg_match_all('/\p{L}+/u', $clean, $source);
            if (! self::isShouting($clean)) {
                foreach ($source[0] as $word) {
                    if (mb_strtolower($word) === $word) {
                        $ordinary[mb_strtoupper($word)] = true;
                    }
                }

                continue;
            }

            preg_match_all('/\p{L}+/u', self::title($raw), $normalised);
            $uppercase = array_flip(array_filter($source[0], fn (string $w): bool => mb_strtoupper($w) === $w));
            $found = [];
            foreach ($normalised[0] as $word) {
                $upper = mb_strtoupper($word);
                $length = mb_strlen($word);
                if ($length >= 2 && $length <= 7 && isset($uppercase[$upper]) && self::wasLowercased($word)) {
                    $found[$upper] = true;
                }
            }
            foreach (array_keys($found) as $word) {
                $counts[$word] = ($counts[$word] ?? 0) + 1;
            }
        }

        $known = array_flip(array_map('mb_strtoupper', array_merge(
            TitleLexicon::FRENCH_MARKERS, TitleLexicon::ENGLISH_MARKERS, TitleLexicon::WEAK_ENGLISH_MARKERS,
            TitleLexicon::ELISION_WORDS, TitleLexicon::ELISION_EXCEPTIONS,
        )));
        $counts = array_filter(
            $counts,
            fn (int $count, string $word): bool => $count >= $minTitles && ! isset($ordinary[$word]) && ! isset($known[$word]),
            ARRAY_FILTER_USE_BOTH,
        );
        arsort($counts);

        return $counts;
    }

    /**
     * Slug conforme à `^[a-z0-9]+(-[a-z0-9]+)*$` : l'alias Joomla nettoyé, sinon le titre,
     * sinon « document » ; tronqué à $maxLength sur une limite de mot.
     */
    public static function slug(string $alias, string $title, int $maxLength = self::SLUG_MAX_LENGTH): string
    {
        $slug = self::slugify($alias);
        if ($slug === '') {
            $slug = self::slugify(self::decodeEntities($title));
        }
        if ($slug === '') {
            $slug = 'document';
        }

        if (strlen($slug) > $maxLength) {
            $cut = substr($slug, 0, $maxLength);
            $boundary = strrpos($cut, '-');
            $slug = ($slug[$maxLength] === '-' || $boundary === false) ? $cut : substr($cut, 0, $boundary);
        }

        return trim($slug, '-');
    }

    private static function slugify(string $value): string
    {
        $words = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value);
        $ascii = strtolower(Str::ascii($words, 'fr'));

        return trim((string) preg_replace('/[^a-z0-9]+/', '-', $ascii), '-');
    }

    /** Décode les entités HTML, y compris doublement encodées (« &amp;eacute; »). */
    private static function decodeEntities(string $text): string
    {
        for ($i = 0; $i < 3; $i++) {
            $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($decoded === $text) {
                break;
            }
            $text = $decoded;
        }

        return $text;
    }

    private static function isShouting(string $text): bool
    {
        $upper = preg_match_all('/\p{Lu}/u', $text);
        $lower = preg_match_all('/\p{Ll}/u', $text);

        return $upper >= 2 && $upper / ($upper + $lower) >= self::SHOUTING_RATIO;
    }

    private static function sentenceCase(string $text, string $language): string
    {
        $french = $language === 'fr';
        if ($french) {
            $text = (string) preg_replace('/(\d)(\p{L}{4,})/u', '$1 $2', $text);
            $text = (string) preg_replace('/(?<!\p{L})N\s*[°º]?\s*(?=\d)/u', 'n° ', $text);
            // Apostrophe remplacée par une espace : « L AIDE » → « L'AIDE ».
            $text = (string) preg_replace("/(?<!\\d )(?<![\\p{L}\\d'])([LD]) (?=[AEIOUYHÉÈÊÀÂÎÔ]\\p{L})/u", "$1'", $text);
        }

        return (string) preg_replace_callback(
            '/\p{L}+/u',
            fn (array $m): string => self::recase(
                $m[0][0],
                $m[0][1] > 0 && ctype_digit($text[$m[0][1] - 1]),
                preg_match('/\d\s$/', substr($text, max(0, $m[0][1] - 2), 2)) === 1,
                self::previousWord(substr($text, 0, $m[0][1])),
                $french,
            ),
            $text,
            flags: PREG_OFFSET_CAPTURE,
        );
    }

    /** Remis en minuscules par title() (la majuscule initiale du titre ne compte pas, un nom propre si). */
    private static function wasLowercased(string $word): bool
    {
        $lower = mb_strtolower($word);
        if ($word !== $lower && $word !== mb_strtoupper(mb_substr($word, 0, 1)).mb_substr($lower, 1)) {
            return false;
        }

        return ! in_array($word, TitleLexicon::PROPER_NOUNS, true);
    }

    /** Mot (ou nombre, « N° ») qui précède, en minuscules ; null en début de titre. */
    private static function previousWord(string $before): ?string
    {
        return preg_match('/([\p{L}\d°º]+)[^\p{L}\d]*$/u', $before, $m) === 1 ? mb_strtolower($m[1]) : null;
    }

    /**
     * @param  bool  $afterDigit  collé à un chiffre (« 1ER », « 2ND ») : jamais un sigle
     * @param  bool  $afterNumber  suit un nombre : une lettre seule est une cote (« 00136 A MINATD »)
     */
    private static function recase(string $word, bool $afterDigit, bool $afterNumber, ?string $previous, bool $french): string
    {
        $upper = mb_strtoupper($word);
        $lower = mb_strtolower($word);

        if (in_array($upper, TitleLexicon::ACRONYMS, true) || self::isRomanNumeral($upper)) {
            return $upper;
        }
        if ($french && in_array($upper, TitleLexicon::FRENCH_ONLY_ACRONYMS, true)) {
            return $upper;
        }
        if (in_array($upper, ['I', 'V', 'X'], true) && $previous !== null && self::announcesRomanNumeral($previous)) {
            return $upper;
        }
        if ($afterNumber && mb_strlen($word) === 1) {
            return $upper;
        }
        if (! $afterDigit && mb_strlen($word) >= 2 && preg_match('/['.self::VOWELS.']/iu', $lower) !== 1) {
            return $upper;
        }
        if (isset(TitleLexicon::PROPER_NOUNS[$lower])) {
            return TitleLexicon::PROPER_NOUNS[$lower];
        }
        if (! $french) {
            return $lower;
        }

        return TitleLexicon::FRENCH_WORDS[$lower] ?? self::restoreElision($lower) ?? $lower;
    }

    /** « lexecution » → « l'exécution », « detat » → « d'État », « locde » → « l'OCDE ». */
    private static function restoreElision(string $word): ?string
    {
        if (in_array($word, TitleLexicon::ELISION_EXCEPTIONS, true)
            || preg_match('/^([ld])([aeiouyhé].{2,})$/u', $word, $m) !== 1) {
            return null;
        }
        [, $article, $rest] = $m;
        $restored = TitleLexicon::FRENCH_WORDS[$rest]
            ?? TitleLexicon::PROPER_NOUNS[$rest]
            ?? (in_array(mb_strtoupper($rest), TitleLexicon::ACRONYMS, true) ? mb_strtoupper($rest) : null)
            ?? (in_array($rest, TitleLexicon::ELISION_WORDS, true) ? $rest : null);

        return $restored === null ? null : $article."'".$restored;
    }

    private static function announcesRomanNumeral(string $previous): bool
    {
        return in_array($previous, self::ROMAN_CONTEXT, true)
            || preg_match('/^(?:\d+|n[°º]?|no|numero|numéro)$/u', $previous) === 1;
    }

    private static function isRomanNumeral(string $word): bool
    {
        return strlen($word) >= 2 && preg_match('/^X{0,3}(?:IX|IV|V?I{0,3})$/', $word) === 1;
    }
}
