<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** Suppression refusée : le mot-clé a encore des sous-thèmes. */
final class MotCleHasChildrenException extends RuntimeException {}
