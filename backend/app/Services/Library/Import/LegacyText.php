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
     * titre au-delà de l'an prochain est un horizon (« Vision 2035 »), pas une date.
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

        preg_match_all('/(?<!\d)\d{4}(?!\d)/', $title, $years);
        foreach (array_reverse($years[0]) as $year) {
            if ($plausible((int) $year)) {
                return (int) $year;
            }
        }

        return null;
    }

    /** `en` si le titre a au moins 2 mots anglais courants distincts et aucun mot français courant ; sinon `fr`. */
    public static function language(string $title): string
    {
        $words = array_unique(preg_split('/[^\p{L}]+/u', mb_strtolower($title), -1, PREG_SPLIT_NO_EMPTY) ?: []);
        $english = count(array_intersect($words, TitleLexicon::ENGLISH_MARKERS));
        $french = count(array_intersect($words, TitleLexicon::FRENCH_MARKERS));

        return $english >= 2 && $french === 0 ? 'en' : 'fr';
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
                $french,
            ),
            $text,
            flags: PREG_OFFSET_CAPTURE,
        );
    }

    /**
     * @param  bool  $afterDigit  collé à un chiffre (« 1ER », « 2ND ») : jamais un sigle
     * @param  bool  $afterNumber  suit un nombre : une lettre seule est une cote (« 00136 A MINATD »)
     */
    private static function recase(string $word, bool $afterDigit, bool $afterNumber, bool $french): string
    {
        $upper = mb_strtoupper($word);
        $lower = mb_strtolower($word);

        if (in_array($upper, TitleLexicon::ACRONYMS, true) || self::isRomanNumeral($upper)) {
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
        if (preg_match('/^([ld])([aeiouyhé].{2,})$/u', $word, $m) !== 1) {
            return null;
        }
        [, $article, $rest] = $m;
        $restored = TitleLexicon::FRENCH_WORDS[$rest]
            ?? TitleLexicon::PROPER_NOUNS[$rest]
            ?? (in_array(mb_strtoupper($rest), TitleLexicon::ACRONYMS, true) ? mb_strtoupper($rest) : null)
            ?? (in_array($rest, TitleLexicon::ELISION_WORDS, true) ? $rest : null);

        return $restored === null ? null : $article."'".$restored;
    }

    private static function isRomanNumeral(string $word): bool
    {
        return strlen($word) >= 2 && preg_match('/^X{0,3}(?:IX|IV|V?I{0,3})$/', $word) === 1;
    }
}
