<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Efface les secrets d'un message avant qu'il ne soit affiché, journalisé ou
 * persisté.
 *
 * Nécessaire parce qu'un message d'erreur ne vient pas toujours de notre code.
 * `EchoSmsProvider` passe sa clé en query — l'API l'exige — et son docblock
 * affirme qu'aucun message construit ici ne contient l'URL. C'est vrai des
 * messages qu'il construit, faux de ceux que Guzzle lève à travers lui : une
 * `ConnectionException` porte le message cURL brut, URL complète incluse.
 *
 * Ce message part aujourd'hui vers trois surfaces — la notification à l'écran
 * du bouton de test, `storage/logs/sms.log`, et la colonne
 * `candidature_relances.erreur` affichée en tooltip du journal. La rédaction se
 * fait donc au point d'entrée, sans faire confiance à la passerelle.
 */
final class SecretRedactor
{
    private const REMPLACEMENT = '[secret masqué]';

    /** @var list<string> */
    private const MOTIFS = [
        // Clé en paramètre de requête : api_key=, token=, access_key=…
        '/\b(api_?key|api_?token|access_?key|auth_?token|token|secret|password)=[^&\s"\']+/i',
        // En-tête ou mention d'un porteur.
        '/\bBearer\s+[A-Za-z0-9._~+\/\-|]+=*/i',
    ];

    public static function redact(?string $message): ?string
    {
        if ($message === null || $message === '') {
            return $message;
        }

        foreach (self::MOTIFS as $motif) {
            $message = preg_replace($motif, self::REMPLACEMENT, $message) ?? $message;
        }

        return $message;
    }
}
