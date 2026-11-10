<?php

declare(strict_types=1);

namespace App\Logging;

use Illuminate\Log\Logger;
use Monolog\LogRecord;
use Throwable;

/**
 * Ajoute à chaque ligne du journal technique l'identifiant de l'utilisateur
 * connecté (`extra.user_id`), lu au moment de l'écriture (chantier
 * « Socle », étape 6). Branché par `tap` sur les canaux de config/logging.php.
 *
 * Ne déclenche jamais l'authentification : seul un utilisateur déjà résolu
 * est lu (hasUser), pour qu'une erreur pendant l'authentification ne
 * s'auto-alimente pas.
 */
final class AjouterUtilisateur
{
    public function __invoke(Logger $logger): void
    {
        $logger->getLogger()->pushProcessor(
            fn (LogRecord $ligne): LogRecord => $ligne->with(extra: [...$ligne->extra, 'user_id' => self::utilisateur()]),
        );
    }

    private static function utilisateur(): int|string|null
    {
        try {
            $auth = app('auth');

            return $auth->hasUser() ? $auth->id() : null;
        } catch (Throwable) {
            return null;
        }
    }
}
