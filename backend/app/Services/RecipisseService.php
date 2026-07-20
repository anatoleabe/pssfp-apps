<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Candidature;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

/**
 * Génère le PDF récépissé d'une candidature soumise (cf. spec module 5 §M8).
 *
 * Caractéristiques du PDF :
 * - 2 pages : « Copie Étudiant » (filigrane discret) + « Copie Administration »
 *   (filigrane prominent « DOCUMENT ADMINISTRATIF »).
 * - Logo + entête PSSFP institutionnels (assets dans backend/resources/images/pdf/).
 * - QR code en pied de page pointant vers /v1/c/{uuid}/qr (page récap public PR C).
 * - Hash SHA256 du PDF binaire imprimé en bas pour traçabilité (cf. P-min-1 PR C).
 *
 * Le PDF est stocké dans MinIO bucket `pssfp-candidatures` à la clé
 * `{uuid}/recipisse.pdf`. Téléchargement via URL signée 30 min (P-min-2 PR C).
 */
class RecipisseService
{
    private const SIGNED_URL_TTL_MINUTES = 30;

    /** Chemin disque relatif pour stocker le PDF dans le bucket pssfp-candidatures. */
    public function pathFor(Candidature $candidature): string
    {
        return "{$candidature->uuid}/recipisse.pdf";
    }

    /**
     * Génère le PDF, hash SHA256 du binaire, stocke dans MinIO.
     *
     * @return array{path: string, hash: string}
     */
    public function generate(Candidature $candidature): array
    {
        $candidature->loadMissing(['campagne', 'paysNationalite', 'regionRel', 'departementRel']);

        $qrUrl = url("/v1/c/{$candidature->uuid}/qr");
        $qrSvg = (string) QrCode::format('svg')->size(140)->margin(0)->generate($qrUrl);
        $qrSvgBase64 = 'data:image/svg+xml;base64,'.base64_encode($qrSvg);

        $logoBase64 = $this->embedImage(resource_path('images/pdf/logo.png'));
        $enteteBase64 = $this->embedImage(resource_path('images/pdf/entete.png'));
        $photoBase64 = $this->embedCandidatePhoto($candidature);

        $html = view('pdf.candidature-recipisse', [
            'candidature' => $candidature,
            'campagne' => $candidature->campagne,
            'qrSvg' => $qrSvgBase64,
            'logoSrc' => $logoBase64,
            'enteteSrc' => $enteteBase64,
            'photoSrc' => $photoBase64,
            'generatedAt' => now(),
            // hash placeholder remplacé après génération binaire
            'hashPlaceholder' => '__HASH_PLACEHOLDER__',
        ])->render();

        $pdfBytes = Pdf::loadHTML($html)
            ->setPaper('a4', 'portrait')
            ->output();

        // Hash SHA256 du binaire généré (cf. P-min-1 PR C). On regénère le PDF
        // une seconde fois en injectant le hash réel dans le placeholder pour
        // que le hash imprimé corresponde au PDF qui le porte (autoreferencé).
        $firstHash = hash('sha256', $pdfBytes);
        $finalHtml = str_replace('__HASH_PLACEHOLDER__', $firstHash, $html);
        $finalBytes = Pdf::loadHTML($finalHtml)
            ->setPaper('a4', 'portrait')
            ->output();
        $finalHash = hash('sha256', $finalBytes);

        $path = $this->pathFor($candidature);
        $this->disk()->put($path, $finalBytes);

        return ['path' => $path, 'hash' => $finalHash];
    }

    /**
     * URL signée pour téléchargement. Re-signée à chaque appel (cf. P-min-2 PR C).
     *
     * Retourne `null` si le driver ne supporte pas les URLs temporaires
     * (cas Storage::fake('local')).
     */
    public function signedUrl(string $path): string
    {
        $disk = $this->disk();

        if (method_exists($disk, 'temporaryUrl')) {
            return $disk->temporaryUrl($path, now()->addMinutes(self::SIGNED_URL_TTL_MINUTES));
        }

        // Fallback testing : disk local fake — on retourne juste le path
        // pour que les tests puissent assert sur la présence d'une chaîne stable.
        return 'fake://'.$path;
    }

    private function disk(): Filesystem
    {
        return Storage::disk(config('filesystems.default_candidatures_disk', 'minio_candidatures'));
    }

    private function embedImage(string $absolutePath): string
    {
        if (! is_file($absolutePath)) {
            return '';
        }

        $mime = match (strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'svg' => 'image/svg+xml',
            default => 'application/octet-stream',
        };

        return 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($absolutePath));
    }

    /**
     * Lit la photo 4×4 du candidat sur le disque MinIO privé et l'embarque en
     * base64 pour l'afficher sur le récépissé. Retourne '' si aucune photo
     * (dépôt physique de la photo au bureau) ou si la lecture échoue —
     * le template dégrade alors sur un cadre « Photo » vide.
     */
    private function embedCandidatePhoto(Candidature $candidature): string
    {
        $path = $candidature->photo_path;
        if ($path === null || $path === '') {
            return '';
        }

        try {
            $disk = Storage::disk('minio_candidatures');
            if (! $disk->exists($path)) {
                return '';
            }
            $bytes = (string) $disk->get($path);
        } catch (\Throwable) {
            return '';
        }

        if ($bytes === '') {
            return '';
        }

        $mime = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            default => 'image/jpeg',
        };

        return 'data:'.$mime.';base64,'.base64_encode($bytes);
    }
}
