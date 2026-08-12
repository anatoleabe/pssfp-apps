<?php

declare(strict_types=1);

/*
 * Diplôme requis pour l'admission (`candidatures.diplome_requis`).
 *
 * La clé est le slug stocké en base — il ne change jamais. La valeur est le
 * libellé français, utilisé pour le rendu du récépissé PDF et l'admin Filament.
 * Le frontend a sa propre liste dans candidature/lib/dossier/options.ts : les
 * deux doivent rester alignées sur les mêmes slugs.
 */
return [
    'licence-bachelor' => 'Licence / Bachelor',
    'master' => 'Master',
];
