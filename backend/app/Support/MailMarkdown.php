<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\HtmlString;

/**
 * Texte saisi (titre, motif) inséré dans un e-mail Markdown, rendu tel quel.
 *
 * `{{ }}` échappe le HTML mais laisse le Markdown s'interpréter : un motif
 * contenant `[ici](https://…)` deviendrait un lien cliquable. La ponctuation
 * Markdown est donc neutralisée d'abord, le HTML échappé ensuite (l'ordre
 * inverse casserait les entités `&#039;`), les retours à la ligne conservés.
 */
final class MailMarkdown
{
    private const MARKDOWN_PUNCTUATION = '/([\\\\`*_{}\[\]()#+\-.!|~])/u';

    public static function plain(string $text): HtmlString
    {
        $neutralised = (string) preg_replace(self::MARKDOWN_PUNCTUATION, '\\\\$1', $text);

        return new HtmlString(nl2br(e($neutralised), false));
    }
}
