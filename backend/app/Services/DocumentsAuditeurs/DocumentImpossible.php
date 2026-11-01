<?php

declare(strict_types=1);

namespace App\Services\DocumentsAuditeurs;

use RuntimeException;

/** Génération refusée pour une raison à expliquer à l'agent (message sûr à afficher). */
class DocumentImpossible extends RuntimeException {}
