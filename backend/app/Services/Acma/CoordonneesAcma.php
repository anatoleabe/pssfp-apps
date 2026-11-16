<?php

declare(strict_types=1);

namespace App\Services\Acma;

use Illuminate\Database\Eloquent\Model;

/**
 * Lot 15i (spec de la réinscription des anciens, §4.2) : propositions de téléphones E.164 et d'e-mails depuis les
 * coordonnées brutes d'ACMA. Classe pure : l'archive garde la valeur d'origine, rien n'est stocké normalisé, et
 * l'agent vérifie toute proposition avant qu'elle ne serve (réinscription). Aucun envoi ne lit ces coordonnées.
 */
final class CoordonneesAcma
{
    /** Séparateurs entre plusieurs numéros dans un même champ ACMA. */
    private const SEPARATEURS = '#[/,;|]+|\s{2,}|\set\s#u';

    /**
     * @return list<array{e164: string, ancien_format: bool}> numéros reconnus, sans doublon, dans l'ordre du champ
     */
    public static function telephones(?string $brut): array
    {
        $propositions = [];
        foreach (preg_split(self::SEPARATEURS, trim((string) $brut)) ?: [] as $morceau) {
            $p = self::telephone($morceau);
            if ($p !== null && ! in_array($p['e164'], array_column($propositions, 'e164'), true)) {
                $propositions[] = $p;
            }
        }

        return $propositions;
    }

    /** @return array{e164: string, ancien_format: bool}|null */
    private static function telephone(string $morceau): ?array
    {
        $international = str_starts_with(trim($morceau), '+') || str_starts_with(trim($morceau), '00');
        $chiffres = (string) preg_replace('/\D+/', '', $morceau);
        if ($international) {
            $chiffres = ltrim($chiffres, '0');

            return strlen($chiffres) >= 8 && strlen($chiffres) <= 15 ? ['e164' => '+'.$chiffres, 'ancien_format' => false] : null;
        }

        return match (true) {
            // Numérotation camerounaise à neuf chiffres (depuis 2014).
            strlen($chiffres) === 9 && in_array($chiffres[0], ['2', '6'], true) => ['e164' => '+237'.$chiffres, 'ancien_format' => false],
            // Ancienne numérotation à huit chiffres : 6 devant un mobile, 2 devant un fixe. À vérifier par l'agent.
            strlen($chiffres) === 8 && in_array($chiffres[0], ['2', '3'], true) => ['e164' => '+2372'.$chiffres, 'ancien_format' => true],
            strlen($chiffres) === 8 && in_array($chiffres[0], ['5', '7', '8', '9'], true) => ['e164' => '+2376'.$chiffres, 'ancien_format' => true],
            // Indicatif sans « + » ni « 00 ».
            strlen($chiffres) >= 11 && strlen($chiffres) <= 13 && str_starts_with($chiffres, '237') => ['e164' => '+'.$chiffres, 'ancien_format' => false],
            default => null,
        };
    }

    public static function email(?string $brut): ?string
    {
        $v = mb_strtolower(trim((string) $brut));

        return $v !== '' && filter_var($v, FILTER_VALIDATE_EMAIL) !== false ? mb_substr($v, 0, 150) : null;
    }

    /**
     * Propositions pour un dossier : mobile d'abord, puis téléphone ; premier e-mail valide.
     *
     * @param  array<string, mixed>|object  $dossier  colonnes `*_source` de `acma_dossiers`
     * @return array{telephone: ?string, telephone2: ?string, email: ?string}
     */
    public static function propositions(array|object $dossier): array
    {
        $d = (array) ($dossier instanceof Model ? $dossier->getAttributes() : $dossier);
        $numeros = array_values(array_unique(array_column(array_merge(
            self::telephones($d['mobile_source'] ?? null),
            self::telephones($d['telephone_source'] ?? null),
        ), 'e164')));

        return [
            'telephone' => $numeros[0] ?? null,
            'telephone2' => $numeros[1] ?? null,
            'email' => self::email($d['email_source'] ?? null) ?? self::email($d['email2_source'] ?? null),
        ];
    }
}
