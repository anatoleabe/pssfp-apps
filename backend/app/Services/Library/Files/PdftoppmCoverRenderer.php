<?php

declare(strict_types=1);

namespace App\Services\Library\Files;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
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
                Log::warning('Bibliothèque : couverture non rendue (pdftoppm)', [
                    'exit_code' => $result->exitCode(),
                    'stderr' => Str::limit(trim($result->errorOutput()), 500),
                ]);

                return null;
            }

            $bytes = file_get_contents($output);

            return $bytes === false || $bytes === '' ? null : $bytes;
        } catch (RuntimeException $e) {
            // Délai dépassé ou échec du processus : contrat « null en cas d'échec ».
            Log::warning('Bibliothèque : couverture non rendue (pdftoppm)', [
                'exception' => $e::class,
                'error' => Str::limit($e->getMessage(), 500),
            ]);

            return null;
        } finally {
            @unlink($prefix);
            @unlink($output);
        }
    }
}
