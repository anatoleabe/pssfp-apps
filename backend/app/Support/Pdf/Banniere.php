<?php

declare(strict_types=1);

namespace App\Support\Pdf;

/**
 * Bannière des documents du LMD (spec lot 24b) : les documents académiques sortent sous celle de
 * l'Université de Yaoundé II-Soa, les documents administratifs gardent celle du Ministère des finances.
 */
final class Banniere
{
    public const UNIVERSITE = 'universite';

    public const PSSFP = 'pssfp';

    /** Logo de l'Université, prêt à poser dans une balise img ; vide si le fichier manque. */
    public static function logoUniversite(): ?string
    {
        $chemin = resource_path('images/pdf/uy2.png');

        return is_file($chemin) ? 'data:image/png;base64,'.base64_encode((string) file_get_contents($chemin)) : null;
    }
}
