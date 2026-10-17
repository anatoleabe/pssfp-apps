<?php

declare(strict_types=1);

namespace App\Services\Library\Files;

interface CoverRenderer
{
    /** Octets JPEG de la première page, ou null si le rendu échoue. */
    public function render(string $pdfPath): ?string;
}
