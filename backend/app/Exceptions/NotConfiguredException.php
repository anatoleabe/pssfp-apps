<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Levée quand un service externe (SMS, paiement, etc.) est appelé alors que sa
 * configuration n'est pas complète (env vars manquantes, credentials absents).
 *
 * Utiliser pour fail-fast au moment de l'appel plutôt qu'au boot du container :
 * ainsi un dev local sans SMS_PROVIDER complet peut quand même démarrer
 * l'application tant qu'il n'essaie pas d'envoyer un SMS.
 */
class NotConfiguredException extends RuntimeException {}
