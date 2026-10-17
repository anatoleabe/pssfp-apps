<?php

declare(strict_types=1);

namespace App\Services\Library\Files;

use Illuminate\Support\Facades\Process;

/** Rendu de couverture via poppler-utils (`pdftoppm`), 600 px de large. */
final class PdftoppmCoverRenderer implements CoverRenderer
{
    public function render(string $pdfPath): ?string
    {
        $prefix = tempnam(sys_get_temp_dir(), 'cover');
        $result = Process::timeout(60)->run([
            'pdftoppm', '-jpeg', '-jpegopt', 'quality=82', '-f', '1', '-l', '1',
            '-scale-to-x', '600', '-scale-to-y', '-1', '-singlefile', $pdfPath, $prefix,
        ]);

        $output = $prefix.'.jpg';
        $bytes = $result->successful() && is_file($output) ? (string) file_get_contents($output) : null;

        @unlink($prefix);
        @unlink($output);

        return $bytes !== '' ? $bytes : null;
    }
}
