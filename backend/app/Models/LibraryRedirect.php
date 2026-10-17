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
     * Cible interne uniquement : commence par `/`, jamais `//` ni `/\` (hôte externe pour le
     * navigateur), aucun caractère de contrôle (le parseur d'URL supprime tabulations et retours
     * à la ligne : `/\t/evil.com` deviendrait `//evil.com`). Le front revérifie avant de rediriger.
     */
    public static function isSafeTarget(string $path): bool
    {
        return preg_match('#^/(?![/\\\\])#', $path) === 1 && preg_match('/[\x00-\x1F\x7F]/', $path) === 0;
    }

    /** @return Attribute<string, string> */
    protected function toPath(): Attribute
    {
        return Attribute::make(set: static function (string $value): string {
            if (! self::isSafeTarget($value)) {
                throw new InvalidArgumentException('Cible de redirection invalide : chemin interne attendu (/…), sans // ni /\\ ni caractère de contrôle.');
            }

            return $value;
        });
    }
}
