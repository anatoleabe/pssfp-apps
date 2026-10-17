<?php

declare(strict_types=1);

namespace App\Services\Library\Files;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/** Rendu de couverture via poppler-utils (`pdftoppm`), 600 px de large. */
final class PdftoppmCoverRenderer implements CoverRenderer
{
    public function render(string $pdfPath): ?string
    {
        $prefix = tempnam(sys_get_temp_dir(), 'cover');
        if ($prefix === false) {
            return null;
        }
        $output = $prefix.'.jpg';

        try {
            $result = Process::timeout(60)->run([
                'pdftoppm', '-jpeg', '-jpegopt', 'quality=82', '-f', '1', '-l', '1',
                '-scale-to-x', '600', '-scale-to-y', '-1', '-singlefile', $pdfPath, $prefix,
            ]);

            if (! $result->successful() || ! is_file($output)) {
                return null;
            }

            $bytes = file_get_contents($output);

            return $bytes === false || $bytes === '' ? null : $bytes;
        } catch (RuntimeException) {
            // Délai dépassé ou échec du processus : contrat « null en cas d'échec ».
            return null;
        } finally {
            @unlink($prefix);
            @unlink($output);
        }
    }
}
