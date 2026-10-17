<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/** Ancienne URL de la bibliothèque Joomla → nouveau chemin. */
class LibraryRedirect extends Model
{
    protected $fillable = ['from_path', 'to_path', 'status_code', 'hits'];

    protected $casts = ['status_code' => 'integer', 'hits' => 'integer'];

    /**
     * Segment `.` ou `..` (y compris `%2e`, sans casse), suivi de `/`, `?`, `#` ou de la fin :
     * `new URL('/a/..//evil.com', base)` donne le chemin `//evil.com`, que Next relativise en
     * `Location: //evil.com` (hôte externe). Même règle que la contrainte CHECK de la table.
     */
    public const DOT_SEGMENT = '#(^|/)(\.|%2e){1,2}([/?\#]|$)#i';

    /**
     * Cible interne uniquement : commence par `/` sans être `//`, aucune barre oblique inverse,
     * aucun caractère de contrôle (le parseur d'URL supprime tabulations et retours à la ligne :
     * `/\t/evil.com` deviendrait `//evil.com`), aucun segment `.`/`..`. Le front revérifie
     * (`library/lib/redirects.ts`) et la base l'impose aussi (contrainte CHECK).
     */
    public static function isSafeTarget(string $path): bool
    {
        return str_starts_with($path, '/')
            && ! str_starts_with($path, '//')
            && ! str_contains($path, '\\')
            && preg_match('/[\x00-\x1F\x7F]/', $path) === 0
            && preg_match(self::DOT_SEGMENT, $path) === 0;
    }

    /** @return Attribute<string, string> */
    protected function toPath(): Attribute
    {
        return Attribute::make(set: static function (string $value): string {
            if (! self::isSafeTarget($value)) {
                throw new InvalidArgumentException('Cible de redirection invalide : chemin interne attendu (/…), sans //, barre oblique inverse, caractère de contrôle ni segment « . » ou « .. ».');
            }

            return $value;
        });
    }
}
