<?php

declare(strict_types=1);

/*
 * Textes des SMS de relance aux candidats dont le dossier n'avance plus.
 *
 * Contraintes de rédaction, à respecter si ces textes sont modifiés :
 *
 * - Rester sous 160 caractères. Au-delà, l'opérateur facture deux SMS.
 * - N'employer que des caractères de l'alphabet GSM-7 : les accents é è à ù ç
 *   en passent, mais l'apostrophe typographique « ’ », les guillemets « » et
 *   les tirets longs font basculer tout le message en UCS-2, qui plafonne à
 *   70 caractères par SMS. Utiliser l'apostrophe droite.
 * - Nommer l'institution en tête : un SMS sans émetteur identifiable est pris
 *   pour du spam.
 * - Une seule action, une seule URL.
 *
 * Placeholders disponibles : :date_cloture, :url
 */
return [
    'url' => env('RELANCE_SMS_URL', 'apply.pssfp.org'),

    'messages' => [
        // Dossier complet, jamais soumis : il ne manque que le clic final.
        'ready' => 'PSSFP : votre dossier de candidature est complet mais pas encore soumis. '
            .'Validez-le sur :url avant le :date_cloture pour qu il soit examine.',

        // Seule la photo manque. Le blocage de taille ayant ete corrige, on le
        // dit explicitement : ces candidats ont deja essaye et echoue.
        'photo_only' => 'PSSFP : il ne manque que la photo pour finaliser votre candidature. '
            .'Le probleme de taille est resolu, reessayez sur :url avant le :date_cloture.',
    ],
];
